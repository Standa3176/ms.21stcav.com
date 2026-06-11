---
quick_id: 260611-qcq
type: quick
slug: products-hydrate-stock-from-offers
created: 2026-06-11
status: planning
files_modified:
  - app/Console/Commands/HydrateProductStockFromOffersCommand.php
  - app/Providers/AppServiceProvider.php
  - routes/console.php
  - tests/Feature/Console/HydrateProductStockFromOffersCommandTest.php
  - .planning/STATE.md
autonomous: true
must_haves:
  truths:
    - "Running `products:hydrate-stock-from-offers --dry-run --limit=5` prints a 5-row plan table + counters with zero DB writes."
    - "Running `products:hydrate-stock-from-offers` for a SKU with a fresh in-stock supplier offer sets products.stock_quantity = supplier.stock, stock_status='instock', buy_price = supplier.price, last_synced_at = now()."
    - "Running it for a SKU with NO fresh-in-stock supplier offer sets products.stock_quantity=0, stock_status='outofstock', leaves buy_price untouched (preserves last-known cost), sets last_synced_at = now()."
    - "When two fresh suppliers both have stock>0 for the same SKU, the CHEAPEST is selected (buildBestOfferMap semantics from SupplierDbSyncCommand)."
    - "Stale supplier offers (per SupplierFreshnessResolver from 260608-g8x) are excluded — a SKU with only stale offers falls to the OOS branch."
    - "`--only-stale=24` skips products whose products.last_synced_at is < 24h old (incremental cron mode); `--only-stale=0` ignores freshness (full-catalogue mode)."
    - "Per-product UPDATE is wrapped in DB::transaction so a column-level failure rolls back THAT product, batch continues for siblings, errors counter increments."
    - "Schedule entry fires Mon-Fri 07:20 London (NOT the 07:15 slot which is taken by products:flag-missing-buy-price)."
    - "No Woo REST calls are made by this command — strict MS-side hydration only."
  artifacts:
    - path: "app/Console/Commands/HydrateProductStockFromOffersCommand.php"
      provides: "products:hydrate-stock-from-offers artisan command extending BaseCommand"
      contains: "class HydrateProductStockFromOffersCommand"
    - path: "tests/Feature/Console/HydrateProductStockFromOffersCommandTest.php"
      provides: "10 Pest cases A-J covering happy path / cheapest-pick / OOS / stale / --skus / --dry-run / --only-stale / mixed batch / partial-failure / downgrade"
      contains: "products:hydrate-stock-from-offers"
    - path: "routes/console.php"
      provides: "Mon-Fri 07:20 London schedule entry"
      contains: "products:hydrate-stock-from-offers"
    - path: "app/Providers/AppServiceProvider.php"
      provides: "Command registration so artisan list resolves it"
      contains: "HydrateProductStockFromOffersCommand"
  key_links:
    - from: "HydrateProductStockFromOffersCommand"
      to: "SupplierFreshnessResolver::freshSupplierIds()"
      via: "constructor DI"
      pattern: "SupplierFreshnessResolver"
    - from: "HydrateProductStockFromOffersCommand"
      to: "supplier_offer_snapshots table"
      via: "DB::table query (sku + supplier_id + stock>0)"
      pattern: "supplier_offer_snapshots"
    - from: "HydrateProductStockFromOffersCommand"
      to: "products table"
      via: "Product::query()->update inside DB::transaction"
      pattern: "Product::"
---

# Quick Task 260611-qcq — products:hydrate-stock-from-offers

<objective>
Close the missing supplier_offer_snapshots → products.stock_quantity step in the data flow.

**Pipeline today (broken):**
1. Supplier feeds → supplier_db (external) ✅
2. supplier_db → supplier_offer_snapshots (via supplier:db-sync — fixed in 260609-rie) ✅
3. supplier_offer_snapshots → products.stock_quantity ❌ **MISSING**
4. products → Woo (via push-divergence-to-woo from 260611-g4q) — propagates stale data ⚠

**Proven by prod probe today (2026-06-11):**
- SKU HA310-2EP: supplier_offer_snapshots row has Ingram stock=5,659 (correct, post-260609-rie)
- SKU HA310-2EP: products.stock_quantity = 0 (stale — never hydrated from the snapshot)
- The in-flight push-divergence-to-woo run is propagating that 0 to Woo, hiding real Ingram drop-ship stock from storefront customers.

This task ships a new artisan `products:hydrate-stock-from-offers` that closes step 3.

**Best-offer pick rule:** mirror `SupplierDbSyncCommand::buildBestOfferMap` — cheapest FRESH (per SupplierFreshnessResolver) supplier_offer_snapshots row with stock > 0 per SKU. One rule, two consumers. NEVER duplicate the freshness predicate — import it.

**Composability:** no Woo writes here. The push commands (260611-g4q / -f1y) handle storefront sync. Operator can run hydrate without pushing if they want to inspect MS state first.

Purpose: stop propagating phantom OOS to the storefront for SKUs where suppliers actually have stock.
Output: new command + schedule entry + 10 Pest cases + STATE.md row.
</objective>

<context>
@.planning/STATE.md
@CLAUDE.md
@app/Domain/Sync/Services/SupplierFreshnessResolver.php
@app/Domain/Products/Models/SupplierOfferSnapshot.php
@app/Domain/Products/Models/Product.php
@app/Console/Commands/PushDivergenceToWooCommand.php
@app/Console/Commands/PushVisibilityToWooCommand.php
@app/Domain/Sync/Commands/SupplierDbSyncCommand.php
@routes/console.php
@app/Providers/AppServiceProvider.php
@tests/Feature/Console/PushDivergenceToWooCommandTest.php
</context>

<scope_confirmation>
**Task 1 probe findings (resolved up-front so Tasks 2-6 don't re-investigate):**

1. **ProductOverride has NO `pin_stock` column.** Existing pins are SEO/content-only:
   `pin_title`, `pin_short_description`, `pin_long_description`, `pin_meta_description`,
   `pin_image`, `pin_slug`, `pin_brand`, `pin_category`. **Implication for Task 2:**
   skip the `pinned_skipped` counter — there is no pin to respect. Note the limitation
   in the command docblock as a "follow-up: add pin_stock if operator override semantics
   are needed for stock." Task 4's Case J is REPLACED (see Task 4 below).

2. **SupplierOfferSnapshot column shape:** `sku` (lowercase-trimmed via matchKey),
   `product_id` (nullable FK), `supplier_id` (string), `supplier_name`, `price`
   (decimal:4), `stock` (integer), `rrp`, `recorded_at` (date cast). Driver-aware
   freshness via SupplierFreshnessResolver — supplier-level fresh/amber/stale, NOT
   per-snapshot. The `freshOnly()` scope on the model uses the `__NO_FRESH_SUPPLIERS__`
   sentinel for empty-whereIn safety — Task 2 mirrors that pattern.

3. **Product table columns + casts confirmed:** `stock_quantity` integer cast,
   `stock_status` string (no enum), `buy_price` decimal:4 cast, `last_synced_at`
   datetime cast. Both `stock_quantity` and `last_synced_at` already exist (no
   migration needed).

4. **PushDivergenceToWooCommand BaseCommand pattern:** `class X extends BaseCommand`
   (not final — allows test container override); `perform()` returns `SymfonyCommand::SUCCESS`
   or `FAILURE`; counters held in local ints; `$this->table([cols], [[row]...])` at end.
   Mirror this shape.

5. **Schedule slot collision found:** 07:15 Mon-Fri London is ALREADY occupied by
   `products:flag-missing-buy-price` (routes/console.php line 114-119). The brief's
   "between 07:00 supplier:db-sync and 07:30 suggestions:auto-apply" assumption is
   wrong — Task 3 uses **07:20 Mon-Fri** instead (a free 5-minute slot wedged between
   `flag-missing-buy-price` at 07:15 and `suggestions:auto-apply` at 07:30). The
   sequence becomes: 07:00 supplier:db-sync → 07:15 flag-missing-buy-price → **07:20
   hydrate-stock-from-offers (NEW)** → 07:30 suggestions:auto-apply. Hydrate runs
   AFTER flag-missing-buy-price so a freshly-pending product doesn't get its
   stock_status hydrated to instock the same minute it was demoted to pending —
   if the operator-intended demotion-then-rehydrate ordering matters, this 5-min
   ordering preserves it.

6. **Best-offer pick mirrors buildBestOfferMap** (SupplierDbSyncCommand lines 179-180,
   buildBestOfferMap signature elsewhere in the same file): cheapest IN-STOCK price
   across all (sku, supplier_id) pairs with the latest recorded_at per supplier.
   Task 2 implementation re-uses the same per-SKU query shape — no new helper class.

NO commit on Task 1. Findings encoded above; Tasks 2-6 execute on them.
</scope_confirmation>

<tasks>

<task type="auto">
  <name>Task 1: Probe + scope confirmation (no commit)</name>
  <files>(read-only)</files>
  <action>
Read the four files below and confirm the findings encoded in the &lt;scope_confirmation&gt; block above. NO writes — this task only validates that the planner's assumptions still hold at execution time.

Verify:
1. `app/Domain/Pricing/Models/ProductOverride.php` — confirm `pin_stock` is NOT in `$fillable` or `$casts`. If you find it (planner missed it), bail and ask the operator before continuing.
2. `app/Domain/Products/Models/SupplierOfferSnapshot.php` — confirm columns: sku, supplier_id, supplier_name, price, stock, rrp, recorded_at. Confirm the `freshOnly()` scope uses `__NO_FRESH_SUPPLIERS__` sentinel.
3. `app/Domain/Products/Models/Product.php` — confirm `stock_quantity`, `stock_status`, `buy_price`, `last_synced_at` are all in `$fillable` + correct casts.
4. `routes/console.php` — confirm 07:20 Mon-Fri London is a free slot (07:00 supplier:db-sync, 07:15 flag-missing-buy-price, 07:30 suggestions:auto-apply, 07:45 suppliers:check-stale are taken; 07:20 is free).

If ALL four confirm, proceed to Task 2. If any disagree with the &lt;scope_confirmation&gt; block, STOP and surface the discrepancy.
  </action>
  <verify>
    <automated>echo "Probe-only task — no automated verify; Task 2 verify covers the engine."</automated>
  </verify>
  <done>The four assumptions above hold against the current codebase, and the executor is ready to write the command in Task 2.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: HydrateProductStockFromOffersCommand + AppServiceProvider registration</name>
  <files>app/Console/Commands/HydrateProductStockFromOffersCommand.php, app/Providers/AppServiceProvider.php</files>
  <behavior>
- Given a Product with sku='HA310-2EP' and a fresh supplier_offer_snapshots row (Ingram, stock=5659, price=£1.00, recorded_at=today): running the command sets products.stock_quantity=5659, stock_status='instock', buy_price=1.0000, last_synced_at≈now().
- Given two fresh supplier offers for the same sku (Ingram £1.00 stock=10 + WestCoast £2.00 stock=5): the command selects Ingram (cheapest fresh in-stock) → stock_quantity=10, buy_price=1.0000.
- Given a sku with only STALE supplier offers (per SupplierFreshnessResolver classification): stock_quantity=0, stock_status='outofstock', buy_price UNCHANGED.
- Given `--dry-run`: prints a sample table, makes zero DB writes.
- Given `--only-stale=24`: skips products whose last_synced_at is less than 24h ago.
- The command never calls WooClient (asserted in Task 4 via container guard).
- Per-product UPDATE failures are rolled back via DB::transaction; batch continues for siblings; errors counter increments.
  </behavior>
  <action>
Create `app/Console/Commands/HydrateProductStockFromOffersCommand.php`. Mirror the scaffolding of `PushDivergenceToWooCommand` (260611-g4q).

**Class shape:**
- `class HydrateProductStockFromOffersCommand extends BaseCommand` (NOT final — tests may need container override; mirrors 260611-g4q rationale).
- Private const `SOURCE_TABLE = 'supplier_offer_snapshots'` for grep-discoverability.
- Constructor DI: `public function __construct(private readonly SupplierFreshnessResolver $freshness) { parent::__construct(); }`.

**Signature:**
```
products:hydrate-stock-from-offers
  {--skus= : Comma-separated SKU list; default = all live publish products with woo_product_id}
  {--limit=0 : Cap product count (0=unbounded)}
  {--only-stale=24 : Skip products whose last_synced_at is younger than N hours; 0 disables the freshness gate}
  {--dry-run : Print plan + sample table without writing}
  {--chunk=500 : Cursor chunk size (memory tuning, NOT a Woo throttle — no Woo calls)}
```

**Description:** `"Hydrate products.stock_quantity / stock_status / buy_price from supplier_offer_snapshots (cheapest fresh in-stock supplier per SKU). MS-side only — no Woo writes (260611-qcq)."`

**`perform()` logic:**

1. Parse options: `$skusRaw = $this->option('skus')`; `$limit = max(0, (int) $this->option('limit'))`; `$staleHours = max(0, (int) $this->option('only-stale'))`; `$dryRun = (bool) $this->option('dry-run')`; `$chunkSize = max(1, (int) $this->option('chunk'))`.

2. Resolve fresh supplier ids: `$freshIds = $this->freshness->freshSupplierIds()->all();`. Empty-set sentinel: `$freshIdsForQuery = $freshIds === [] ? ['__NO_FRESH_SUPPLIERS__'] : $freshIds;` (mirrors SupplierOfferSnapshot::scopeFreshOnly's empty-whereIn safety).

3. Build candidate Product query:
   ```php
   $q = Product::query()
       ->where('status', 'publish')
       ->whereNotNull('woo_product_id');
   ```
   If `--skus`: parse comma-separated list, trim, lowercase, dedupe; `$q->whereIn('sku', $list);` (override the publish/woo_product_id guard? NO — keep the guard; --skus narrows further. Surface a warn if `--skus` includes a sku that fails the guard so the operator isn't surprised.)

   If `--only-stale > 0`:
   ```php
   $q->where(function ($q) use ($staleHours) {
       $q->whereNull('last_synced_at')
         ->orWhere('last_synced_at', '<', now()->subHours($staleHours));
   });
   ```

   If `--limit > 0`: `$q->limit($limit)`.

4. Print plan header: `[dry-run]` or `[LIVE]` prefix + counter intent.

5. Initialise counters:
   ```php
   $scanned = 0; $updatedInStock = 0; $updatedOutOfStock = 0;
   $unchanged = 0; $errors = 0; $samples = [];
   ```

6. Stream `$q->cursor()` in chunks of `$chunkSize` (use `chunkById` if you prefer the safer pagination — both work; `cursor()` is fine because we DON'T mutate the ordering column).

7. **Per-product loop:**
   a. `$scanned++;`
   b. Resolve cheapest fresh in-stock supplier offer for THIS sku:
      ```php
      $key = strtolower(trim((string) $product->sku));
      if ($key === '') { $unchanged++; continue; }

      $offer = DB::table(self::SOURCE_TABLE)
          ->where('sku', $key)
          ->whereIn('supplier_id', $freshIdsForQuery)
          ->where('stock', '>', 0)
          ->orderBy('price', 'asc')      // cheapest first
          ->orderByDesc('recorded_at')   // tie-break: most recent
          ->first();
      ```
      (Index hint: existing index on `supplier_offer_snapshots(sku, supplier_id)` from 260504-muq plus `(sku, recorded_at)` if present is enough; if Task 1 probe found no index, accept the table scan — single nightly run, full-catalogue is ~5k-50k SKUs.)

   c. **Compute new state:**
      - **Found** (offer in-stock):
        ```php
        $newStockQty = (int) $offer->stock;
        $newStockStatus = 'instock';
        $newBuyPrice = $offer->price; // string from DB::table
        $outcome = 'in_stock';
        ```
      - **Not found** (no fresh supplier with stock):
        ```php
        $newStockQty = 0;
        $newStockStatus = 'outofstock';
        $newBuyPrice = null; // sentinel: leave column untouched
        $outcome = 'out_of_stock';
        ```

   d. **Compute changed?** compare against current product state (int compare for stock; case-sensitive string compare for status; numeric compare for buy_price). Unchanged → `$unchanged++; continue;`.

   e. **Dry-run branch:** capture up-to-20 sample rows in `$samples[]` array (sku / current MS qty / proposed qty / current status / proposed status / outcome). Continue.

   f. **Live write — per-product transaction:**
      ```php
      try {
          DB::transaction(function () use ($product, $newStockQty, $newStockStatus, $newBuyPrice) {
              $updates = [
                  'stock_quantity' => $newStockQty,
                  'stock_status' => $newStockStatus,
                  'last_synced_at' => now(),
              ];
              if ($newBuyPrice !== null) {
                  $updates['buy_price'] = $newBuyPrice;
              }
              Product::where('id', $product->id)->update($updates);
          });
          if ($outcome === 'in_stock') $updatedInStock++; else $updatedOutOfStock++;
      } catch (\Throwable $e) {
          $errors++;
          Log::warning('hydrate_stock_from_offers.row_failed', [
              'product_id' => $product->id,
              'sku' => $product->sku,
              'error' => $e->getMessage(),
          ]);
      }
      ```

8. **Output:**
   - If dry-run: render the 20-row sample table via `$this->table(['sku','current_qty','proposed_qty','current_status','proposed_status','outcome'], $samples)`.
   - Always render the counters table:
     ```
     scanned                  N
     updated_in_stock         N
     updated_out_of_stock     N
     unchanged                N
     errors                   N
     ```
     (No `pinned_skipped` — see Task 1 probe finding.)

9. Return `SymfonyCommand::SUCCESS`.

**Add a class-level docblock** documenting:
- WHY this exists (proves the 260611-qcq gap with the HA310-2EP example from the brief).
- WHY buy_price is preserved when going OOS (cost still valid even when OOS — keeps margin math sane).
- WHY no Woo writes here (composability — separation of concerns).
- IMPORT of SupplierFreshnessResolver (drift-prevention reference to 260608-g8x).
- Follow-up note: ProductOverride has no pin_stock column yet — if operator override semantics are needed for stock in future, add the column + respect it here.

**AppServiceProvider registration:**
- Add `use App\Console\Commands\HydrateProductStockFromOffersCommand;` near the other alphabetised `App\Console\Commands\*` imports (between `BackfillMerchantFeedCommand` and `PushDivergenceToWooCommand`).
- Add the class entry to the `$this->commands([...])` array in the `runningInConsole()` block. Place it near `PushDivergenceToWooCommand::class` (also a 260611-* command) with a docblock comment referencing 260611-qcq + the gap it closes.

**Atomic commit:** `feat(products): hydrate-stock-from-offers command + AppServiceProvider registration (260611-qcq)`
  </action>
  <verify>
    <automated>php artisan list | grep -v '^#' | grep -c 'hydrate-stock-from-offers'</automated>
  </verify>
  <done>Command class exists, registered in AppServiceProvider, `php artisan list` resolves `products:hydrate-stock-from-offers`, signature includes all 5 options, perform() implements the cheapest-fresh-in-stock pick + per-product DB::transaction + counter table. One atomic commit.</done>
</task>

<task type="auto">
  <name>Task 3: routes/console.php — Mon-Fri 07:20 London schedule</name>
  <files>routes/console.php</files>
  <action>
Insert a new Schedule block AFTER the existing `products:flag-missing-buy-price` block (07:15) and BEFORE `suggestions:auto-apply` (07:30). Use 07:20 — confirmed free in Task 1 probe.

```php
// Quick task 260611-qcq — products:hydrate-stock-from-offers Mon-Fri 07:20 London.
// Closes the supplier_offer_snapshots → products.stock_quantity gap (proved on
// prod 2026-06-11 with HA310-2EP: snapshot had Ingram stock=5659, products
// had stock_quantity=0). Slots BETWEEN flag-missing-buy-price (07:15) and
// suggestions:auto-apply (07:30) so a freshly-pending product doesn't get
// its stock_status hydrated the same minute it was demoted to pending.
// --only-stale=24 keeps the daily cron incremental — first manual full-catalogue
// rehydrate is `--only-stale=0` (run by hand post-deploy).
Schedule::command('products:hydrate-stock-from-offers --only-stale=24')
    ->cron('20 7 * * 1-5') // Mon-Fri at 07:20 (cron DOW: 1=Mon ... 5=Fri)
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Hydrate products.stock_quantity from supplier_offer_snapshots (260611-qcq); Mon-Fri 07:20 London');
```

Verify the file still parses (`php -l routes/console.php` or `php artisan schedule:list`).

**Atomic commit:** `chore(schedule): products:hydrate-stock-from-offers Mon-Fri 07:20 London cron (260611-qcq)`
  </action>
  <verify>
    <automated>php artisan schedule:list 2>&1 | grep -c 'products:hydrate-stock-from-offers'</automated>
  </verify>
  <done>Schedule entry exists at 07:20 Mon-Fri London with onOneServer + withoutOverlapping(30) + timezone Europe/London; `php artisan schedule:list` resolves it; routes/console.php still passes `php -l`. One atomic commit.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 4: Pest cases A-J HydrateProductStockFromOffersCommandTest</name>
  <files>tests/Feature/Console/HydrateProductStockFromOffersCommandTest.php</files>
  <behavior>
10 Pest cases A-J cover every documented outcome. Each case is independent — fixtures created inline via Product::factory() + DB::table('supplier_offer_snapshots') insert.

Per Task 1 finding, **Case J is REPLACED** because ProductOverride has no pin_stock column: J now verifies the in-stock→out-of-stock downgrade actually persists when offers dry up between runs.
  </behavior>
  <action>
Create `tests/Feature/Console/HydrateProductStockFromOffersCommandTest.php`. Mirror the inline-helper pattern from `PushDivergenceToWooCommandTest` (260611-g4q): keep helpers above the `it(...)` blocks; create fixtures inline per case (no shared beforeEach state).

**Helper functions (top of file):**
- `makeSnapshot(string $sku, string $supplierId, int $stock, float $price, ?Carbon $recordedAt = null): void` — inserts into supplier_offer_snapshots via `DB::table()` with sensible defaults (supplier_name = "Supplier {$supplierId}", recorded_at = today()).
- `bindFreshSuppliers(array $supplierIds): void` — uses `$this->mock(SupplierFreshnessResolver::class)` to make `freshSupplierIds()` return `collect($supplierIds)`. Bind as singleton so the command resolves the mock.
- `assertNoWooCallsMade(): void` — guard. Bind `WooClient` to an anonymous subclass that throws on any GET/PUT. Asserts the command never touches Woo.

**Cases:**

- **A: Single fresh supplier with stock → instock + buy_price set.**
  Fixture: Product factory sku='A-001', stock_quantity=0, stock_status='outofstock', buy_price=null. supplier_offer_snapshots row: supplier_id='10', stock=5659, price=1.00, recorded_at=today.
  Bind fresh suppliers = ['10'].
  Run: `Artisan::call('products:hydrate-stock-from-offers');`
  Assert: $product->refresh(); stock_quantity=5659, stock_status='instock', buy_price=1.0000, last_synced_at not null. Counters: `updated_in_stock=1`.

- **B: Two fresh suppliers with stock → cheapest wins.**
  Fixture: sku='B-001'. Two rows: supplier 10 stock=10 price=1.00; supplier 20 stock=5 price=2.00. Fresh=['10','20'].
  Assert: stock_quantity=10, buy_price=1.0000. Counter `updated_in_stock=1`.

- **C: Fresh suppliers but all stock=0 → outofstock + buy_price UNCHANGED.**
  Fixture: sku='C-001' factory with buy_price=99.99 (existing cost). Two rows: supplier 10 stock=0 price=1.00; supplier 20 stock=0 price=2.00. Fresh=['10','20'].
  Assert: stock_quantity=0, stock_status='outofstock', buy_price=99.9900 (PRESERVED). Counter `updated_out_of_stock=1`.

- **D: STALE supplier with stock>0 is IGNORED → falls to OOS branch.**
  Fixture: sku='D-001' buy_price=50.00. One row: supplier 99 stock=200 price=3.00 recorded_at=today. Fresh=[] (supplier 99 is stale per resolver).
  Assert: stock_quantity=0, stock_status='outofstock', buy_price=50.0000. Counter `updated_out_of_stock=1`. (Tests `__NO_FRESH_SUPPLIERS__` sentinel path.)

- **E: `--skus=ABC123` narrows to that SKU.**
  Fixture: Two products, sku='E-001' and sku='E-002'. Both have fresh offers stock>0. Fresh=['10'].
  Run with `--skus=E-001`.
  Assert: E-001 updated; E-002 unchanged (stock_quantity=0 still). `scanned=1`.

- **F: `--dry-run` writes nothing.**
  Fixture: 5 products with offers that WOULD change them. Fresh=['10'].
  Run with `--dry-run`.
  Assert: all 5 products unchanged (stock_quantity still 0, last_synced_at still null). Counter table prints; sample table prints (5 rows).

- **G: `--only-stale=24` skips recently-synced products.**
  Fixture: Two products. sku='G-001' last_synced_at = 2h ago (skip). sku='G-002' last_synced_at = 25h ago (process). Both have fresh in-stock offers.
  Run with `--only-stale=24`.
  Assert: G-001 unchanged. G-002 updated to instock. `scanned=1`.

- **H: Mixed batch — 3 products, 3 different outcomes.**
  Fixture: P1 fresh+in-stock (→ updated_in_stock); P2 fresh+stock=0 (→ updated_out_of_stock); P3 no offers at all (→ updated_out_of_stock).
  Assert: counters `updated_in_stock=1, updated_out_of_stock=2`. P1, P2, P3 all have last_synced_at = now (P2 and P3 buy_price preserved).

- **I: Partial failure — one product's transaction rolls back, batch continues.**
  Fixture: 3 products P1/P2/P3 all eligible for in-stock update. Use `DB::beforeExecuting` listener to throw on the UPDATE statement for product_id matching P2's id.
  Run live.
  Assert: P1 + P3 updated to instock; P2 unchanged (rollback); `errors=1, updated_in_stock=2`.

- **J: Downgrade — instock → outofstock when offers dry up.**
  Fixture: sku='J-001' Product factory pre-set with stock_quantity=10, stock_status='instock', buy_price=2.00. Snapshot row: supplier 10 stock=0 (was in-stock yesterday, today shows 0). Fresh=['10'].
  Run live.
  Assert: stock_quantity=0, stock_status='outofstock', buy_price=2.0000 (preserved — cost still valid). Counter `updated_out_of_stock=1`.

**Also assert no-Woo guard at the suite level:** in the top-of-file `beforeEach` or in each test, bind WooClient to a stub that throws on get()/put(). If the command erroneously gains a Woo call later, the whole suite fails. (Cheap insurance.)

**Atomic commit:** `test(products): hydrate-stock-from-offers Pest cases A-J (260611-qcq)`
  </action>
  <verify>
    <automated>php artisan test --filter=HydrateProductStockFromOffersCommandTest 2>&1 | tail -20</automated>
  </verify>
  <done>10 Pest cases A-J GREEN. Test file imports SupplierFreshnessResolver via container mock (not direct instantiation). Suite-level WooClient guard asserts zero Woo interaction. One atomic commit.</done>
</task>

<task type="auto">
  <name>Task 5: Verify (no commit)</name>
  <files>(read-only)</files>
  <action>
Run the verification commands below. NO commit on this task — diagnose any failures + fix before proceeding to Task 6.

1. **Command exists in artisan:**
   `php artisan list 2>&1 | grep hydrate-stock-from-offers`
   Expect: 1 line `products:hydrate-stock-from-offers   Hydrate products.stock_quantity from supplier_offer_snapshots...`.

2. **Schedule registered:**
   `php artisan schedule:list 2>&1 | grep hydrate-stock-from-offers`
   Expect: 1 line showing the Mon-Fri 07:20 cron.

3. **Focused suite GREEN:**
   `php artisan test --filter=HydrateProductStockFromOffersCommandTest`
   Expect: 10/10 pass.

4. **Regression — these MUST stay GREEN (touched-area sanity):**
   ```
   php artisan test \
     --filter='PushDivergenceToWooCommandTest|PushVisibilityToWooCommandTest|SupplierDbSyncCommandStockSeparateTest|SupplierFreshnessResolverTest|AdCandidateScannerTest'
   ```
   Expect: zero new failures vs baseline.

5. **Full suite baseline check** (260611-g4q baseline: 2,005 / 222 / 3):
   `php artisan test` → expect **~2,015 / 222 / 3** (+10 pass / 0 new fails). If new fails appear, fix before Task 6. If pass count is less than 2,014, investigate (a test may be skipped under wrong DB driver).

6. **Smoke check — in-memory dry-run:**
   `php artisan products:hydrate-stock-from-offers --dry-run --limit=5`
   Expect: either "0 candidates" against the test DB, or a 5-row plan + counter table. Either is a pass — what matters is **no exception**.

7. **No-Woo guard sanity:** grep the new command for any WooClient references — should return zero matches:
   ```
   grep -v '^[[:space:]]*//\|^[[:space:]]*\*' \
        app/Console/Commands/HydrateProductStockFromOffersCommand.php \
     | grep -c 'WooClient\|->put(\|->get(\|->post('
   ```
   Expect: `0`. (Comment-stripped grep so any documentation reference doesn't false-positive.)

If ANY of steps 1-7 fail, STOP and fix. Do NOT proceed to Task 6 with a regression.
  </action>
  <verify>
    <automated>php artisan test --filter=HydrateProductStockFromOffersCommandTest 2>&1 | grep -E 'PASS|FAIL|Tests:'</automated>
  </verify>
  <done>All 7 verify steps GREEN; baseline-relative test counts confirm +10 pass / 0 new fails. No commit on this task.</done>
</task>

<task type="auto">
  <name>Task 6: STATE.md update + planning commit</name>
  <files>.planning/STATE.md</files>
  <action>
1. Append a new row to the `## Quick Tasks Completed` table in `.planning/STATE.md`:
   ```
   | 2026-06-11 | products:hydrate-stock-from-offers closes the supplier_offer_snapshots → products.stock_quantity gap (Mon-Fri 07:20 London) | feat(products) | <COMMIT_SHA> |
   ```
   (Resolve `<COMMIT_SHA>` from the Task 2 commit — the "feat(products)" commit. Tasks 3 and 4 are chore/test commits that don't lead the row.)

2. Update the frontmatter at the top of `.planning/STATE.md`:
   - `last_updated:` → current timestamp
   - `last_activity: 2026-06-11`
   - `stopped_at:` — prepend a fresh stopped_at paragraph summarising 260611-qcq (rotate the existing one to `old_stopped_at:` per the existing pattern). Keep the summary 3-6 sentences: gap closed, proven by HA310-2EP, command + schedule + 10 Pest cases, Pest delta vs baseline (~2,015 / 222 / 3), 4 commits, operator post-deploy action: first manual run `php artisan products:hydrate-stock-from-offers --only-stale=0 --dry-run` then `--only-stale=0` live for full-catalogue hydration; subsequent daily cron is incremental `--only-stale=24`.

3. Do NOT touch the `## Current Position` or `## Performance Metrics` sections — those track phase work, not quick tasks.

**Atomic commit:** `docs(state): 260611-qcq hydrate-stock-from-offers shipped (260611-qcq)`
  </action>
  <verify>
    <automated>grep -c '260611-qcq' .planning/STATE.md</automated>
  </verify>
  <done>STATE.md has new row in Quick Tasks table; frontmatter `last_activity` + `stopped_at` updated; one atomic commit on STATE.md.</done>
</task>

</tasks>

<verification>
**Phase-level verification (Task 5 covers the focused checks):**

1. Command resolves: `php artisan list | grep -v '^#' | grep -c hydrate-stock-from-offers` == 1
2. Schedule entry resolves: `php artisan schedule:list | grep -c hydrate-stock-from-offers` == 1
3. Focused tests GREEN: 10/10 in HydrateProductStockFromOffersCommandTest
4. Regression touched-area GREEN: PushDivergenceToWooCommandTest + PushVisibilityToWooCommandTest + SupplierDbSyncCommandStockSeparateTest + SupplierFreshnessResolverTest + AdCandidateScannerTest
5. Pest delta vs 260611-g4q baseline (2,005 / 222 / 3): **+10 pass / 0 new fails / 0 new skipped** → expected 2,015 / 222 / 3
6. No-Woo invariant: zero WooClient/->put()/->get()/->post() in the new command (comment-stripped grep)
7. STATE.md has new row in Quick Tasks Completed table

**Commit count:** 4 atomic commits (Task 2 + Task 3 + Task 4 + Task 6). Tasks 1 and 5 are read-only (probe + verify).
</verification>

<success_criteria>
- [ ] `app/Console/Commands/HydrateProductStockFromOffersCommand.php` exists and `class HydrateProductStockFromOffersCommand extends BaseCommand`
- [ ] Constructor injects `SupplierFreshnessResolver` (drift-prevention IMPORT, NOT duplication)
- [ ] Cheapest-fresh-in-stock supplier selection mirrors `SupplierDbSyncCommand::buildBestOfferMap` semantics
- [ ] `--only-stale=24` default in the schedule; manual full-catalogue rehydrate uses `--only-stale=0`
- [ ] No Woo REST calls — pure MS-side hydration (asserted in tests)
- [ ] Per-product `DB::transaction` for partial-failure safety
- [ ] Stock-status semantics: `instock` if cheapest fresh has stock; `outofstock` if no fresh supplier has stock
- [ ] `buy_price` ONLY updated when an in-stock fresh supplier is resolved — preserves last-known cost when OOS (margin math sanity)
- [ ] Empty fresh-set sentinel `'__NO_FRESH_SUPPLIERS__'` (mirrors 260608-g8x + SupplierOfferSnapshot::scopeFreshOnly)
- [ ] Schedule fires Mon-Fri 07:20 London (NOT 07:15 — that slot was already taken by flag-missing-buy-price)
- [ ] 10 Pest cases A-J GREEN; suite-wide no-Woo guard active
- [ ] `php artisan schedule:list` resolves the new entry; `php artisan list` resolves the new command
- [ ] STATE.md updated with the new quick-task row + refreshed stopped_at
- [ ] 4 atomic commits — feat (Task 2) + chore (Task 3) + test (Task 4) + docs (Task 6)
- [ ] DivergenceScanner, PushDivergenceToWooCommand, PushVisibilityToWooCommand UNTOUCHED (this is hydrate-only — push commands handle the storefront side)
</success_criteria>

<post_deploy_operator_action>
After deploy:

1. **First manual full-catalogue rehydrate (dry-run):**
   `php artisan products:hydrate-stock-from-offers --only-stale=0 --dry-run`
   Expect: counters showing roughly the cutover-parity-gap size (likely a few hundred to a couple thousand `updated_in_stock` + `updated_out_of_stock` rows; `unchanged` should be the majority of the publish catalogue).

2. **First manual full-catalogue rehydrate (LIVE):**
   `php artisan products:hydrate-stock-from-offers --only-stale=0`
   Expect: same counters as the dry-run; products table now in sync with supplier_offer_snapshots.

3. **Spot-check HA310-2EP (the SKU that proved the gap):**
   - In tinker: `Product::where('sku','HA310-2EP')->first()->only(['stock_quantity','stock_status','buy_price','last_synced_at'])`
   - Expect: stock_quantity=5659 (or whatever today's Ingram number is), stock_status='instock', last_synced_at = ~now().

4. **Re-run the push-divergence-to-woo (260611-g4q):**
   `php artisan products:push-divergence-to-woo --dry-run`
   Expect: the stock_quantity diff count should DROP dramatically (the staleness that 260611-g4q was propagating is now closed).

5. **Confirm cron fires tomorrow:**
   - Mon-Fri 07:20 London: `tail -F storage/logs/laravel.log` from 07:19 onwards, expect the hydrate command to fire AFTER `flag-missing-buy-price` (07:15) and BEFORE `suggestions:auto-apply` (07:30).

6. **Optional — re-run the stock-divergence audit (260609-nku):**
   `php artisan products:audit-stock-divergence`
   Expect: phantom-units count drops further now that MS-side stock is hydrated from supplier truth (some of the phantoms the audit was finding were real-side staleness that hydration now fixes).
</post_deploy_operator_action>

<output>
This is a quick task — no summary file required. STATE.md row + 4 atomic commits ARE the artifact trail.
</output>
