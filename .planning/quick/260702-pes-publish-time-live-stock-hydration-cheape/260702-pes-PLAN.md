---
phase: 260702-pes-publish-time-live-stock-hydration-cheape
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Sync/Services/LiveSupplierStockResolver.php
  - app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php
  - app/Console/Commands/HydrateLiveStockCommand.php
  - routes/console.php
  - tests/Unit/Sync/LiveSupplierStockResolverTest.php
  - tests/Feature/ProductAutoCreate/PublishProductStockTest.php
  - tests/Feature/Console/HydrateLiveStockCommandTest.php
must_haves:
  truths:
    - "A new LiveSupplierStockResolver resolves the CHEAPEST FRESH in-stock offer for a SKU by querying supplier_db feeds_products + stockseparate (via the JoinsStockSeparate trait, so Ingram stock is NOT masked), gated to SupplierFreshnessResolver::freshSupplierIds(). Mirrors SupplierDbSyncCommand::syncSupplierOfferSnapshots's WHERE (product_excluded=0, match LOWER(TRIM(mpn|suppliersku))). Returns {stock_quantity:int, stock_status:'instock', buy_price:?float} or null."
    - "resolveForSku returns null (never throws) when: sku blank, no fresh suppliers, no in-stock offer, or supplier_db unreachable — so callers degrade gracefully and NEVER fail a publish over stock."
    - "PublishProductJob hydrates the product's local stock via the resolver BEFORE building either Woo payload, so a product created today goes live with the real qty (e.g. SKU 43376 → 64) instead of null→0→Out-of-stock. When the resolver returns null the product's existing stock is left untouched (a genuinely-OOS SKU stays out of stock)."
    - "A new products:hydrate-live-stock command re-hydrates local stock_quantity/stock_status/buy_price from the resolver for targeted published products (default: created today; --skus / --created-since / --only-null-qty / --limit / --dry-run). MS-side only — NO Woo writes (mirrors products:hydrate-stock-from-offers invariant); operator pushes with the existing products:backfill-woo-stock."
    - "The pure selection (pickCheapestInStock) and SQL shape (buildOfferSql) are unit-tested; the 43376 three-row case (stock 0/64/0 at 71.36/76.92/79.20) yields qty 64 @ 76.92."
    - "buildBestOfferMap / syncSupplierOfferSnapshots / hydrate-stock-from-offers / generate-drafts and the StockSeparateJoinTest architecture guard remain green — the resolver USES the trait (guard-compliant), it does not fork the rule."
  artifacts:
    - path: "app/Domain/Sync/Services/LiveSupplierStockResolver.php"
      provides: "cheapest-fresh-in-stock resolver over feeds_products+stockseparate (trait) + freshness"
      contains: "pickCheapestInStock"
    - path: "app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php"
      provides: "publish-time live stock hydration before payload build"
      contains: "hydrateLiveStock"
    - path: "app/Console/Commands/HydrateLiveStockCommand.php"
      provides: "products:hydrate-live-stock backfill (MS-side, no Woo writes)"
      contains: "products:hydrate-live-stock"
    - path: "tests/Unit/Sync/LiveSupplierStockResolverTest.php"
      provides: "pure pickCheapestInStock + buildOfferSql unit tests (incl. the 43376 case)"
      contains: "pickCheapestInStock"
  key_links:
    - from: "PublishProductJob::handle()"
      to: "LiveSupplierStockResolver::resolveForSku()"
      via: "hydrateLiveStock() forceFills stock before wooStockPayload()"
      pattern: "resolveForSku"
    - from: "LiveSupplierStockResolver"
      to: "JoinsStockSeparate + SupplierFreshnessResolver"
      via: "stockColumnSelect/stockSeparateJoinClause + freshSupplierIds gate"
      pattern: "JoinsStockSeparate"
---

<objective>
Products created + published today go live Out-of-stock even when the supplier has stock. Proven on
SKU 43376 (Lindy): supplier_products has three feed rows — stock 0/64/0 at £71.36/76.92/79.20 — so 64
units are available, but (a) the create pipeline never persists stock (product.stock_quantity=null) and
(b) products:hydrate-stock-from-offers reads supplier_offer_snapshots, which the NIGHTLY sync only builds
for SKUs that existed at sync time — a today-created SKU has zero snapshot rows (last_synced_at=null).
So publish pushes null→0→outofstock. Fix: resolve the cheapest FRESH in-stock offer LIVE from
feeds_products+stockseparate (the same source, freshness + Ingram-split rules the sync uses) and hydrate
the product's stock at publish time; add a backfill command to repair today's already-published batch.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260702-pes-publish-time-live-stock-hydration-cheape/
@CLAUDE.md
@app/Domain/Sync/Concerns/JoinsStockSeparate.php
@app/Domain/Sync/Services/SupplierFreshnessResolver.php
@app/Domain/Sync/Commands/SupplierDbSyncCommand.php
@app/Console/Commands/HydrateProductStockFromOffersCommand.php
@app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php
@app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php
@app/Console/Commands/GenerateProductDraftsCommand.php
@tests/Feature/ProductAutoCreate/PublishProductStockTest.php
@tests/Architecture/StockSeparateJoinTest.php
</context>

<interfaces>
=== NEW: app/Domain/Sync/Services/LiveSupplierStockResolver.php ===
```php
<?php
declare(strict_types=1);
namespace App\Domain\Sync\Services;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Sync\Concerns\JoinsStockSeparate;
use Illuminate\Support\Facades\Log;

/**
 * Live per-SKU "cheapest FRESH in-stock offer" resolver.
 *
 * WHY LIVE (not supplier_offer_snapshots): a product created TODAY has no
 * snapshot rows yet (the nightly sync only snapshots SKUs that existed at sync
 * time), so hydrate-stock-from-offers can't help it — it would publish OOS
 * until the next sync+push cycle. This reads feeds_products DIRECTLY so a
 * brand-new SKU gets correct stock at publish.
 *
 * Same rule as SupplierDbSyncCommand::syncSupplierOfferSnapshots:
 *   - feeds_products + stockseparate via JoinsStockSeparate (Ingram is_stock_separate
 *     stock is NOT masked — the whole point of the 260609-rie trait).
 *   - product_excluded = 0.
 *   - match LOWER(TRIM(mpn)) = key OR LOWER(TRIM(suppliersku)) = key.
 *   - gated to fresh supplier_ids (SupplierFreshnessResolver) — a stale feed
 *     (e.g. Nuvias) never asserts stock.
 *   - cheapest by price among rows with resolved stock > 0.
 *
 * Best-effort: NEVER throws to the caller. Any failure (blank sku, no fresh
 * suppliers, unreachable supplier_db, prepare/exec error) returns null so a
 * publish is never blocked over stock.
 *
 * NOT final — Pest binds a Mockery double via the container (mirrors
 * HydrateProductStockFromOffersCommand's "not final so tests can swap").
 */
class LiveSupplierStockResolver
{
    use JoinsStockSeparate;

    public function __construct(
        private readonly IntegrationCredentialResolver $creds,
        private readonly SupplierFreshnessResolver $freshness,
    ) {}

    /**
     * @return array{stock_quantity:int, stock_status:string, buy_price:?float}|null
     */
    public function resolveForSku(string $sku): ?array
    {
        $key = strtolower(trim($sku));
        if ($key === '') {
            return null;
        }
        $freshIds = array_values($this->freshness->freshSupplierIds()->all());
        if ($freshIds === []) {
            return null;
        }
        $rows = $this->fetchOfferRows($key, $freshIds);
        if ($rows === null) {
            return null;
        }
        return $this->pickCheapestInStock($rows);
    }

    /**
     * PURE — cheapest offer with resolved stock > 0. Input rows carry
     * 'price' (string|numeric) and 'stock' (string|int, already stockseparate-
     * resolved by the SQL). Null when nothing is in stock.
     *
     * @param  array<int, array<string,mixed>>  $rows
     * @return array{stock_quantity:int, stock_status:string, buy_price:?float}|null
     */
    public function pickCheapestInStock(array $rows): ?array
    {
        $inStock = array_values(array_filter(
            $rows,
            static fn (array $r): bool => (int) ($r['stock'] ?? 0) > 0,
        ));
        if ($inStock === []) {
            return null;
        }
        usort(
            $inStock,
            static fn (array $a, array $b): int =>
                ((float) ($a['price'] ?? 0)) <=> ((float) ($b['price'] ?? 0)),
        );
        $best = $inStock[0];
        return [
            'stock_quantity' => (int) $best['stock'],
            'stock_status' => 'instock',
            'buy_price' => is_numeric($best['price'] ?? null) ? (float) $best['price'] : null,
        ];
    }

    /**
     * PURE — parameterized SQL. $freshCount '?' placeholders for supplier ids,
     * then two '?' for the match key (mpn, suppliersku). Uses the trait
     * fragments so the StockSeparateJoinTest guard passes AND Ingram stock is
     * resolved from stockseparate.
     */
    public function buildOfferSql(int $freshCount): string
    {
        $stockSelect = $this->stockColumnSelect();          // "...COALESCE(...) AS stock"
        $stockJoin = $this->stockSeparateJoinClause();      // feeds + stockseparate joins
        $in = rtrim(str_repeat('?,', max(1, $freshCount)), ',');
        return "SELECT fp.mpn, fp.suppliersku, fp.supplierid, fp.price, {$stockSelect}, fp.rrp
                FROM feeds_products fp
                {$stockJoin}
                WHERE fp.product_excluded = 0
                  AND fp.supplierid IN ({$in})
                  AND (LOWER(TRIM(fp.mpn)) = ? OR LOWER(TRIM(fp.suppliersku)) = ?)
                ORDER BY fp.updated_at DESC";
    }

    /**
     * Connect supplier_db (mysqli — same path as generate-drafts / db-sync),
     * run buildOfferSql, return rows or null on any failure.
     *
     * @param  array<int,string>  $freshIds
     * @return array<int, array<string,mixed>>|null
     */
    private function fetchOfferRows(string $key, array $freshIds): ?array
    {
        try {
            $c = $this->creds->for(IntegrationCredentialKind::SupplierDb);
            mysqli_report(MYSQLI_REPORT_OFF);
            $m = @new \mysqli(
                (string) $c['host'], (string) $c['username'], (string) $c['password'],
                (string) $c['database'], (int) ($c['port'] ?? 3306),
            );
            if ($m->connect_errno !== 0) {
                Log::warning('live_stock.connect_failed', ['error' => $m->connect_error]);
                return null;
            }
            $stmt = $m->prepare($this->buildOfferSql(count($freshIds)));
            if ($stmt === false) {
                Log::warning('live_stock.prepare_failed', ['error' => $m->error]);
                $m->close();
                return null;
            }
            $params = array_merge(array_map('strval', $freshIds), [$key, $key]);
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $m->close();
            return $rows;
        } catch (\Throwable $e) {
            Log::warning('live_stock.query_failed', ['sku' => $key, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
```

=== EDIT: PublishProductJob::handle() ===
Add a 5th method-injected param `LiveSupplierStockResolver $stock` (Laravel resolves it). Immediately
after `$product = Product::findOrFail($this->productId);` call `$this->hydrateLiveStock($product, $stock);`.
Add the private method:
```php
/**
 * 260702-pes — hydrate local stock from the LIVE cheapest-fresh-in-stock
 * supplier offer BEFORE building the Woo payload, so a product created today
 * (stock_quantity=null, no snapshot yet) goes live with the real qty instead
 * of null→0→outofstock. Best-effort: resolver returning null (genuinely OOS /
 * supplier_db unreachable) leaves the product's existing stock untouched, and
 * a thrown error never fails the publish.
 */
private function hydrateLiveStock(Product $product, LiveSupplierStockResolver $stock): void
{
    $sku = trim((string) ($product->sku ?? ''));
    if ($sku === '') {
        return;
    }
    try {
        $offer = $stock->resolveForSku($sku);
    } catch (\Throwable $e) {
        Log::warning('auto_create.publish.live_stock_failed', [
            'product_id' => $product->id, 'sku' => $sku, 'error' => $e->getMessage(),
        ]);
        return;
    }
    if ($offer === null) {
        return;
    }
    $updates = [
        'stock_quantity' => $offer['stock_quantity'],
        'stock_status' => $offer['stock_status'],
        'last_synced_at' => now(),
    ];
    if ($offer['buy_price'] !== null) {
        $updates['buy_price'] = $offer['buy_price'];
    }
    $product->forceFill($updates)->saveQuietly();
}
```
Add `use App\Domain\Sync\Services\LiveSupplierStockResolver;` import. Do NOT change wooStockPayload,
buildCreatePayload, or the split-PUT logic — hydration just fixes the value they read.

=== NEW: app/Console/Commands/HydrateLiveStockCommand.php (extends BaseCommand) ===
```php
protected $signature = 'products:hydrate-live-stock
    {--skus= : Comma-separated SKUs; overrides --created-since}
    {--created-since= : Only products created on/after this date (Y-m-d); default = today when no --skus}
    {--only-null-qty : Only products whose stock_quantity IS NULL}
    {--limit=0 : Cap product count (0=unbounded)}
    {--dry-run : Print plan + sample table without writing}';

protected $description = 'Re-hydrate products.stock_quantity/stock_status/buy_price from the LIVE cheapest-fresh-in-stock supplier offer (feeds_products+stockseparate). MS-side only — no Woo writes; push with products:backfill-woo-stock (260702-pes).';
```
perform(): build `Product::query()->where('status','publish')->whereNotNull('woo_product_id')`; apply
--skus (whereIn sku) ELSE --created-since / default today (`whereDate('created_at','>=',$date)`);
--only-null-qty adds `whereNull('stock_quantity')`; --limit. cursor() loop: `resolveForSku($product->sku)`;
null → unchanged counter; else compare to current (skip if identical) and, unless --dry-run, forceFill
stock_quantity/stock_status/last_synced_at (+buy_price when non-null) + saveQuietly. Print a sample table
(sku, current_qty, new_qty, outcome) + a counters table (scanned, updated, unchanged, no_offer). Inject
`LiveSupplierStockResolver $stock` via constructor. Register the schedule? NO — this is an operator
backfill/repair tool, run manually; do not add to routes/console.php's schedule (routes/console.php is in
files_modified ONLY IF the executor decides a scheduled nightly `--created-since=today` pass is warranted;
default = DO NOT schedule, leave routes/console.php untouched and drop it from the commit).
```
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: LiveSupplierStockResolver (cheapest fresh in-stock, trait-based)</name>
  <files>
    app/Domain/Sync/Services/LiveSupplierStockResolver.php,
    tests/Unit/Sync/LiveSupplierStockResolverTest.php
  </files>
  <behavior>
    Implement the resolver exactly per <interfaces>. Unit-test the PURE surface only (no supplier_db):
    - pickCheapestInStock with the real 43376 rows
      [{price:'71.36',stock:'0'},{price:'76.92',stock:'64'},{price:'79.20',stock:'0'}]
      → {stock_quantity:64, stock_status:'instock', buy_price:76.92}.
    - pickCheapestInStock cheapest-wins: [{price:'50',stock:'2'},{price:'40.00',stock:'5'}]
      → {stock_quantity:5, buy_price:40.0}.
    - all-zero stock → null; empty [] → null.
    - buildOfferSql(3): asserts it CONTAINS 'LEFT JOIN stockseparate', 'is_stock_separate = 1',
      'fp.product_excluded = 0', 'fp.supplierid IN (?,?,?)', 'LOWER(TRIM(fp.mpn)) = ?',
      'LOWER(TRIM(fp.suppliersku)) = ?' and has exactly 5 '?' (3 fresh + 2 match).
    Instantiate via app(LiveSupplierStockResolver::class) OR `new` with the two deps mocked — the pure
    methods don't touch them, so `app()` is fine.
  </behavior>
  <action>
    Create the service (uses JoinsStockSeparate trait — REQUIRED for the arch guard). Write the unit test.
    Run test + pint + the arch guard.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Unit/Sync/LiveSupplierStockResolverTest.php tests/Architecture/StockSeparateJoinTest.php 2>&1 | tail -15</automated>
    Expected: GREEN (pure logic + arch guard passes because the class uses the trait).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/Sync/Services/LiveSupplierStockResolver.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - Resolver returns cheapest fresh in-stock offer; pure methods unit-tested (43376 → 64); trait used; arch guard + pint green.
  </done>
</task>

<task type="auto" tdd="true" depends_on="Task 1">
  <name>Task 2: PublishProductJob hydrates live stock before payload</name>
  <files>
    app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php,
    tests/Feature/ProductAutoCreate/PublishProductStockTest.php
  </files>
  <behavior>
    Wire LiveSupplierStockResolver into handle() per <interfaces> (method-inject + hydrateLiveStock before
    the wooId branch). Extend PublishProductStockTest.php (REUSE its existing bindWooStockStub helper — do
    NOT declare a new one):
    - Path A + resolver returns {stock_quantity:64,stock_status:'instock',buy_price:50.0}: product starts
      woo_product_id=999, stock_quantity=null → assert the PUT body has stock_quantity=64, manage_stock=true,
      stock_status='instock' (resolver value beat the null local qty).
    - Path B + resolver returns {stock_quantity:64,...}: product woo_product_id=null, stock_quantity=0 →
      assert the POST body has stock_quantity=64.
    - resolver returns null: product stock_quantity=5 stays 5 in the payload (existing behaviour preserved).
    Bind the fake with `app()->instance(LiveSupplierStockResolver::class, $mock)` where
    `$mock = Mockery::mock(LiveSupplierStockResolver::class); $mock->shouldReceive('resolveForSku')->andReturn(...);`
    (class is non-final → Mockery subclasses it, constructor bypassed). The existing 2 tests keep passing:
    add a null-returning resolver bind in their setup (or a beforeEach) so they exercise the unchanged path.
  </behavior>
  <action>
    Edit PublishProductJob (import + param + hydrateLiveStock). Add the 3 new test cases + ensure the 2
    existing cases bind a null-returning resolver so live-stock hydration is a no-op for them. Run tests + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/ProductAutoCreate/PublishProductStockTest.php 2>&1 | tail -20</automated>
    Expected: GREEN (new hydration cases + the 2 original stock cases).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - Publish hydrates local stock from the resolver before both payloads; 64 flows onto the PUT/POST; null resolver preserves existing stock; tests + pint green.
  </done>
</task>

<task type="auto" tdd="true" depends_on="Task 1">
  <name>Task 3: products:hydrate-live-stock backfill command (MS-side, no Woo writes)</name>
  <files>
    app/Console/Commands/HydrateLiveStockCommand.php,
    tests/Feature/Console/HydrateLiveStockCommandTest.php
  </files>
  <behavior>
    Implement the command per <interfaces>. Feature test (bind a Mockery LiveSupplierStockResolver via
    app()->instance):
    - a publish product (woo_product_id set) SKU 'X' created today, stock_quantity=null; resolver returns
      {stock_quantity:64,stock_status:'instock',buy_price:76.92}; run `products:hydrate-live-stock`
      (no args → defaults to created-today) → assert DB row now stock_quantity=64, stock_status='instock',
      buy_price≈76.92, last_synced_at not null.
    - --dry-run leaves the row unchanged (still null).
    - resolver returns null → row unchanged, reported under a no_offer/unchanged counter.
    - --only-null-qty skips a product that already has stock_quantity set.
    Assert NO Woo write occurs — bind a throwing WooClient stub as a guard (mirror HydrateProductStock
    tests) OR simply assert the command has no WooClient dependency; the throwing-stub guard is preferred.
  </behavior>
  <action>
    Create the command (constructor-inject LiveSupplierStockResolver). DO NOT schedule it (leave
    routes/console.php untouched; drop it from the commit). Write the feature test. Run tests + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Console/HydrateLiveStockCommandTest.php 2>&1 | tail -20</automated>
    Expected: GREEN (hydrate today's null-qty products; dry-run no-op; null resolver no-op; no Woo write).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Console/Commands/HydrateLiveStockCommand.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - products:hydrate-live-stock repairs targeted published products from the live resolver, MS-side only, dry-run-safe; tests + pint green.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Unit/Sync/LiveSupplierStockResolverTest.php tests/Feature/ProductAutoCreate/PublishProductStockTest.php tests/Feature/Console/HydrateLiveStockCommandTest.php tests/Architecture/StockSeparateJoinTest.php` → GREEN
2. `pint --test` on the three new/edited source files → PASS
3. Sanity: `grep -n "resolveForSku" app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php` → present.

Operator notes (for SUMMARY.md, NOT executed by Claude):
- Deploy: push main → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- Fix TODAY's already-published batch (two steps — hydrate MS-side, then push to Woo):
    php artisan products:hydrate-live-stock --dry-run          # preview (defaults to created-today)
    php artisan products:hydrate-live-stock                    # write local stock
    php artisan products:backfill-woo-stock --dry-run          # preview the Woo push
    php artisan products:backfill-woo-stock                    # push local stock → Woo
  (To target the whole recent batch regardless of date: `--created-since=2026-06-25` or `--skus=43376,...`.)
- Verify 43376: `php artisan products:hydrate-live-stock --skus=43376 --dry-run` should show current_qty=—/0
  → new_qty=64. After the push, meetingstore.co.uk/product/... shows In stock (64).
- From now on EVERY publish hydrates stock first (PublishProductJob), so newly-created products go live
  with correct stock automatically — no window. A genuinely OOS-everywhere or stale-feed-only SKU correctly
  stays out of stock (resolver returns null → existing stock left as-is).
- NOT fixed here (separate gap, flag if it matters): generate-drafts still writes drafts with no stock, so
  a DRAFT sitting in review shows qty null until publish hydrates it. Only the live storefront matters, so
  this is cosmetic; can be closed later by calling the resolver in generate-drafts too.
</verification>

<success_criteria>
- Publishing a product resolves + persists the cheapest FRESH in-stock supplier offer (Ingram-correct via the trait) before the Woo payload, so new products go live in-stock (43376 → 64) instead of OOS.
- products:hydrate-live-stock repairs today's batch MS-side; the existing backfill-woo-stock pushes it.
- Pure resolver logic unit-tested; publish + command feature-tested; StockSeparateJoin arch guard + pint green; no new duplication of the freshness/stock rule.
</success_criteria>

<output>
Create `.planning/quick/260702-pes-publish-time-live-stock-hydration-cheape/260702-pes-SUMMARY.md` documenting
the root cause (create writes no stock + snapshots don't cover new SKUs), the LiveSupplierStockResolver
(cheapest-fresh-in-stock over feeds_products+stockseparate via the trait), the publish-time hydration, the
hydrate-live-stock backfill command, the unit/feature tests, and the two-step operator repair for today's batch.
</output>
