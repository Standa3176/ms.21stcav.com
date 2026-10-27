---
phase: quick-261027-duz
plan: 01
type: execute
wave: 1
depends_on: []
branch: quick/261027-duz-supplier-offer-block
files_modified:
  - database/migrations/2026_10_27_100000_create_product_supplier_blocks_table.php
  - database/migrations/2026_10_27_100100_add_supplier_cost_outlier_to_import_issues_issue_type.php
  - app/Domain/Sync/Models/ProductSupplierBlock.php
  - app/Domain/Sync/Models/ImportIssue.php
  - app/Domain/Sync/Services/ProductSupplierBlockResolver.php
  - app/Domain/Sync/Services/SupplierCostOutlierGuard.php
  - config/supplier.php
  - app/Providers/AppServiceProvider.php
  - app/Domain/Sync/Commands/SupplierDbSyncCommand.php
  - app/Console/Commands/HydrateProductStockFromOffersCommand.php
  - app/Domain/Sync/Commands/ExplainSupplierCostCommand.php
  - app/Domain/Sync/Commands/BlockSupplierOfferCommand.php
  - app/Domain/Sync/Filament/Resources/ImportIssueResource.php
  - app/Domain/Sync/Filament/Resources/ProductSupplierBlockResource.php
  - app/Domain/Sync/Filament/Resources/ProductSupplierBlockResource/Pages/ManageProductSupplierBlocks.php
  - tests/Unit/Domain/Sync/Services/SupplierCostOutlierGuardTest.php
  - tests/Unit/Domain/Sync/Services/ProductSupplierBlockResolverTest.php
  - tests/Feature/Domain/Sync/SupplierDbSyncOfferBlockTest.php
  - tests/Feature/Domain/Sync/SupplierDbSyncCostOutlierTest.php
  - tests/Feature/Console/HydrateProductStockFromOffersCommandTest.php
  - tests/Feature/Domain/Sync/BlockSupplierOfferCommandTest.php
  - tests/Feature/Filament/Resources/ProductSupplierBlockResourceTest.php
autonomous: true
requirements: [QUICK-261027-DUZ]

must_haves:
  truths:
    - "A (sku, supplier) block makes that supplier's offer invisible to cost AND stock for that sku key in supplier:db-sync, while the same supplier still counts for other keys"
    - "products:hydrate-stock-from-offers (07:20, the SECOND buy_price writer) also ignores blocked (sku, supplier) offers, so a blocked offer cannot re-enter cost 20 minutes after db-sync"
    - "When the cheapest offer is < lower-median(other suppliers' prices) / ratio (default 3), the product's existing buy_price is kept (null stays null), the outlier supplier's stock is not counted, and an unresolved supplier_cost_outlier ImportIssue is written with evidence"
    - "The guard does NOT fire when the cheap price is agreed by another supplier and only an expensive supplier is the odd one out"
    - "Guard is config-driven (config('supplier.cost_outlier_guard.enabled'/'ratio')), no env()"
    - "Operator can add/remove blocks via `supplier:block-offer {sku} {supplier} --reason=` / `--remove` and via a Filament page; supplier:explain-cost shows blocked offers as BLOCKED (reason)"
  artifacts:
    - path: "database/migrations/2026_10_27_100000_create_product_supplier_blocks_table.php"
      provides: "product_supplier_blocks table, explicitly-named unique(sku, supplier_id)"
      contains: "psb_sku_supplier_unique"
    - path: "app/Domain/Sync/Services/SupplierCostOutlierGuard.php"
      provides: "Pure outlier detection (lower median of others)"
      exports: ["detect"]
    - path: "app/Domain/Sync/Services/ProductSupplierBlockResolver.php"
      provides: "Per-request cached (sku, supplier_id) block lookup"
      exports: ["blocksFor", "isBlocked", "forget"]
    - path: "app/Domain/Sync/Commands/BlockSupplierOfferCommand.php"
      provides: "supplier:block-offer operator CLI"
  key_links:
    - from: "SupplierDbSyncCommand::buildBestOfferMap"
      to: "ProductSupplierBlockResolver::isBlocked"
      via: "per-(key, supplierid) skip inside the mpn/suppliersku key loop"
      pattern: "blocks->isBlocked"
    - from: "SupplierDbSyncCommand::buildBestOfferMap"
      to: "SupplierCostOutlierGuard::detect"
      via: "post-reduction check producing map['cost_outlier']"
      pattern: "SupplierCostOutlierGuard::detect"
    - from: "SupplierDbSyncCommand product loop"
      to: "ImportIssue::SUPPLIER_COST_OUTLIER"
      via: "resolveBuyForProduct hold + updateOrCreate"
      pattern: "SUPPLIER_COST_OUTLIER"
    - from: "HydrateProductStockFromOffersCommand"
      to: "ProductSupplierBlockResolver + SupplierCostOutlierGuard"
      via: "whereNotIn blocked supplier_ids + detect() before writing buy_price"
      pattern: "blocksFor|SupplierCostOutlierGuard"
---

<objective>
Stop supplier SKU collisions from setting a product's cost (incident 2026-10-27:
Yealink T74U, local SKU `1301222`, product 5969, sold at £10.80 inc VAT against a
£88.20 cost because Midwich's code `1301222` is an unrelated £7.20 item).

Two mechanisms, both at the COST SOURCE (the 08:00 `pricing:undercut-competitors`
reprices from `buy_price` and ignores `exclude_from_auto_update`/`pin_price`, so a
pricing-side fix would not hold):

1. **Per-(sku, supplier) offer block** — operator-curated, drops that supplier's
   row for that key from price AND stock.
2. **Outlier guard** — automatic; refuses to adopt a cheapest price that is less
   than 1/ratio of the lower median of the other suppliers' prices. Keeps the
   existing cost and raises an ImportIssue.

**Discovered during planning (must be covered — not in the brief):**
`products:hydrate-stock-from-offers` (scheduled Mon–Fri 07:20, `routes/console.php:195-203`)
is a SECOND `buy_price` writer — it takes the cheapest fresh in-stock row from
`supplier_offer_snapshots` (`HydrateProductStockFromOffersCommand.php` ~L180-197)
and writes `buy_price`. Fixing only `buildBestOfferMap` would be undone 20 minutes
later. Blocks and the guard MUST apply there too. `supplier_offer_snapshots.sku`
is the lowercased match key and `supplier_id` is the feed supplierid
(`SupplierDbSyncCommand.php:627-628`), so the same (sku, supplier_id) block key
works in both places.

Output: 2 migrations, model, 2 services, config block, wiring in 3 commands, 1 new
command, 1 Filament resource, ImportIssue type, tests. No data seeded — the 6
initial blocks are applied post-deploy by the operator (runbook in SUMMARY).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md
@app/Domain/Sync/Commands/SupplierDbSyncCommand.php
@app/Domain/Sync/Services/SupplierExclusionResolver.php
@app/Console/Commands/HydrateProductStockFromOffersCommand.php
@app/Domain/Sync/Commands/ExplainSupplierCostCommand.php
@database/migrations/2026_08_09_120000_add_stale_cost_no_supplier_to_import_issues_issue_type.php
@app/Domain/Sync/Filament/Resources/SupplierResource.php
@app/Domain/Sync/Filament/Resources/ImportIssueResource.php
@tests/Feature/Domain/Sync/SupplierDbSyncExclusionTest.php
@tests/Feature/Domain/Sync/SupplierDbSyncStaleCostTest.php
@tests/Feature/Filament/Resources/SupplierResourceTest.php

<environment>
- Branch first: `git checkout -b quick/261027-duz-supplier-offer-block` from `main` in the repo root
  `C:\Users\sonny.tanda\Documents\1 - Laravel Projects\meetingstore-ops-app`.
- PHP is NOT on the Bash PATH (a bare `php … | tail` exits 0 running nothing — fake green). Always use
  `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe"`. Abbreviated below as `$PHP`.
  Tests: `cd "<root>" && $PHP vendor/bin/pest <path>`; style: `$PHP vendor/bin/pint <paths>`.
- Tests run on sqlite :memory:, prod is MySQL 8 / MariaDB-strict: name every index/unique explicitly
  (64-char identifier cap) and keep the enum migration's MySQL branch listing ALL values.
- No test may touch the live supplier DB or Woo. The new logic is exercised via public methods
  (buildBestOfferMap, resolveBuyForProduct) and the snapshot-table-backed hydrate command — never perform() of db-sync.
</environment>

<interfaces>
From app/Domain/Sync/Commands/SupplierDbSyncCommand.php (current):
- __construct(IntegrationCredentialResolver $resolver, SupplierFreshnessResolver $freshness, bool $excludeStaleSuppliersFromBuyPrice = true, ?SupplierExclusionResolver $exclusion = null) — nullable last param resolved via app(); tests construct it with named args.
- public function buildBestOfferMap(array $rows): array<string, array{buy:?string, stock:int, supplier:?string, in_stock:bool, matched_via:string}>
  Rows: ['mpn','suppliersku','supplierid','supplier_name','price','stock']. Each row indexed under BOTH lowercased mpn and suppliersku. Operator-exclusion filter, then stale filter, then per-key reduction (acc: instock_price/str/supplier, any_price/str/supplier, total_stock).
- Product loop (~L333-360): $row = $map[$key]; $newBuy = $row['buy']; $newStock = $row['stock']; $existingBuy = $local->buy_price === null ? null : (string) $local->buy_price; buyChanged/stockChanged numeric compare; then dry-run counter or Product::update + ProductPriceSnapshot::updateOrCreate.
- public function surfaceStaleCostIssue(Product $local, string $key, string $correlationId, bool $dryRun): string — the ImportIssue::updateOrCreate pattern to copy (unresolved tuple sku/woo_product_id/woo_variation_id=null/issue_type/resolved_at=null; values detected_at/last_seen_at/notes/correlation_id). hasAutoUpdateCarveOut($local) is private.
- Summary line (~L425) prints counters; add held-outlier counters to it.

From app/Domain/Sync/Models/ImportIssue.php: constants TYPE_MISSING_AT_SUPPLIER, TYPE_UNKNOWN_SKU, TYPE_MISSING_COST_PRICE, TYPE_EXCLUDE_FLAG_NO_METADATA, STALE_COST_NO_SUPPLIER; fillable sku, woo_product_id, woo_variation_id, issue_type, detected_at, last_seen_at, resolved_at, notes (TEXT nullable), correlation_id (NOT NULL).
ImportIssueResource.php has three match arms to extend (label ~L85, colour ~L107, filter label ~L131).

From HydrateProductStockFromOffersCommand: __construct(private readonly SupplierFreshnessResolver $freshness); per product $key = strtolower(trim(sku)); offer query on supplier_offer_snapshots where sku=$key, whereIn supplier_id $freshIdsForQuery, stock>0, orderBy price asc, orderByDesc recorded_at, first(); in-stock → qty=offer.stock, buy=offer.price; else qty 0 / outofstock / buy null sentinel (= leave buy_price untouched).

Suppliers: suppliers.supplier_id VARCHAR(16), suppliers.name. Singletons registered in AppServiceProvider ~L368-374; commands registered in the commands list ~L762 (next to ExplainSupplierCostCommand). Filament resources in app/Domain/Sync/Filament/Resources are auto-discovered. SupplierResource::canWrite() = hasAnyRole(['admin','pricing_manager']).
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Foundations — blocks table + resolver, outlier guard (pure, TDD), enum value, config</name>
  <files>database/migrations/2026_10_27_100000_create_product_supplier_blocks_table.php, database/migrations/2026_10_27_100100_add_supplier_cost_outlier_to_import_issues_issue_type.php, app/Domain/Sync/Models/ProductSupplierBlock.php, app/Domain/Sync/Models/ImportIssue.php, app/Domain/Sync/Services/ProductSupplierBlockResolver.php, app/Domain/Sync/Services/SupplierCostOutlierGuard.php, config/supplier.php, app/Providers/AppServiceProvider.php, tests/Unit/Domain/Sync/Services/SupplierCostOutlierGuardTest.php, tests/Unit/Domain/Sync/Services/ProductSupplierBlockResolverTest.php</files>
  <behavior>
    SupplierCostOutlierGuard::detect(array $pricesBySupplier, string $chosenSupplierId, float $ratio): ?array — input is supplier_id => that supplier's lowest positive price for the key (chosen supplier included):
    - Yealink: ['MIDWICH'=>7.20,'NIMANS'=>88.20], chosen MIDWICH, ratio 3 → non-null; evidence median_of_others=88.20, chosen_price=7.20
    - Epson CoverPlus: ['MIDWICH'=>50,'A'=>189,'B'=>225] → fires (lower median of [189,225] = 189; 50 < 63)
    - Poly G7500: ['TECHDATA'=>564.82,'INGRAM'=>3941.83] → fires
    - Harmless, 3 suppliers: ['NIMANS'=>5.00,'X'=>5.50,'LINDYCOLL'=>50.00], chosen NIMANS → null (lower median of [5.50,50.00] = 5.50)
    - Harmless, 4 suppliers: [5.00, 5.20, 5.50, 60.00] chosen 5.00 → null
    - Single supplier → null; chosen supplier absent from map → null; ratio <= 1 → null (guard inert)
    - Boundary: chosen exactly == median/ratio → null (strict less-than)
    - Prices <= 0 in the map are ignored
    ProductSupplierBlockResolver:
    - blocksFor('1301222') returns ['MIDWICH' => 'reason'] after a row is created; keys looked up lowercased+trimmed (blocksFor(' 1301222 ') same)
    - isBlocked('1301222','MIDWICH') true; isBlocked('1301222','NIMANS') false
    - cached: a row inserted after first read is NOT seen until forget()
  </behavior>
  <action>
    Write the two unit test files first (RED), run them, then implement (GREEN).

    Migration 2026_10_27_100000: create `product_supplier_blocks` — id; `sku` string(191) NOT NULL (stored normalised: lowercase + trim, it is the buildBestOfferMap / supplier_offer_snapshots match key, NOT necessarily the product's own SKU — an alias key can be blocked too); `product_id` nullable foreignId → products, nullOnDelete, display/convenience only (resolved from sku at write time; matching never uses it); `supplier_id` string(16) NOT NULL (matches suppliers.supplier_id; no FK — feed supplier ids may not all be in suppliers); `reason` text NOT NULL; `created_by_user_id` nullable foreignId → users, nullOnDelete; timestamps. Name everything explicitly: unique(['sku','supplier_id'], 'psb_sku_supplier_unique'), index('product_id','psb_product_id_index'), FK names 'psb_product_id_fk' and 'psb_created_by_fk'. Seed nothing.

    Migration 2026_10_27_100100: copy 2026_08_09_120000 exactly in structure, adding 'supplier_cost_outlier' as the 6th value — MySQL branch ALTER ... MODIFY COLUMN with ALL six values NOT NULL; SQLite branch drop index import_issues_issue_type_index → drop column → re-add enum with six values ->after('woo_variation_id') → re-add index; down() restores the five-value set. Add `public const SUPPLIER_COST_OUTLIER = 'supplier_cost_outlier';` to ImportIssue with a one-line docblock entry in the class header list (follow the STALE_COST_NO_SUPPLIER comment style).

    Model ProductSupplierBlock (app/Domain/Sync/Models, declare(strict_types=1), fillable sku, product_id, supplier_id, reason, created_by_user_id; belongsTo product + createdBy User). Add a `saving` hook that normalises sku to strtolower(trim()) and uppercases nothing else — supplier_id is stored trimmed as given (feed ids are compared as strings), and fills product_id when null via Product::whereRaw('LOWER(TRIM(sku)) = ?', [$sku])->value('id').

    ProductSupplierBlockResolver (final, mirrors SupplierExclusionResolver: lazy ?array cache, forget()). Loads ALL rows once into array<string sku, array<string supplier_id, string reason>>. Methods: blocksFor(string $key): array<string,string>; isBlocked(string $key, string $supplierId): bool; forget(): void. Register as singleton in AppServiceProvider next to SupplierExclusionResolver with a 261027-duz comment.

    SupplierCostOutlierGuard (final, pure, no container, no DB): public static function detect(array $pricesBySupplier, string $chosenSupplierId, float $ratio): ?array. Drop non-positive prices; return null if ratio <= 1, chosen missing, or fewer than 1 other supplier. Others = all other suppliers' prices sorted ascending; median = the LOWER median, `$others[intdiv(count($others) - 1, 2)]` — this is the deliberate choice that makes the harmless "cheap price agreed by another supplier, one expensive odd-one-out" pattern NOT fire (document this in the docblock with the 3-supplier example). Fire when chosen_price < median / ratio. Return ['chosen_supplier_id','chosen_price','median_of_others','ratio','others' => supplier_id=>price].

    config/supplier.php: add a `cost_outlier_guard` array — 'enabled' => true, 'ratio' => 3 — plain literals (the EnvUsageTest arch test forbids env() outside config, and this file's convention is literal policy tunables), with a comment citing 261027-duz and the Yealink example.
  </action>
  <verify>
    <automated>cd "/c/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app" && "/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" vendor/bin/pest tests/Unit/Domain/Sync/Services/SupplierCostOutlierGuardTest.php tests/Unit/Domain/Sync/Services/ProductSupplierBlockResolverTest.php tests/Architecture</automated>
  </verify>
  <done>Both unit files green incl. all Yealink/Epson/Poly/harmless/boundary cases; migrations run on sqlite (RefreshDatabase); Architecture suite (EnvUsageTest) still green; resolver singleton registered.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Wire blocks + guard into both buy_price writers (db-sync map + product loop, hydrate-from-offers) with ImportIssue</name>
  <files>app/Domain/Sync/Commands/SupplierDbSyncCommand.php, app/Console/Commands/HydrateProductStockFromOffersCommand.php, app/Domain/Sync/Filament/Resources/ImportIssueResource.php, tests/Feature/Domain/Sync/SupplierDbSyncOfferBlockTest.php, tests/Feature/Domain/Sync/SupplierDbSyncCostOutlierTest.php, tests/Feature/Console/HydrateProductStockFromOffersCommandTest.php</files>
  <behavior>
    buildBestOfferMap (synthesised rows, like SupplierDbSyncExclusionTest):
    - Block (1301222, MIDWICH): rows MIDWICH £7.20 stock 50 suppliersku=1301222 mpn=MW-CABLE-X, NIMANS £88.20 stock 3 suppliersku=1301222 → map['1301222'] buy '88.20', supplier Nimans, stock 3 (Midwich stock gone); map['mw-cable-x'] still present with buy '7.20' (block is per KEY not per row)
    - No block, same rows, guard enabled → map['1301222']['cost_outlier'] non-null (chosen MIDWICH 7.20, median 88.20) and stock = 3 (outlier supplier's stock excluded); with config('supplier.cost_outlier_guard.enabled') false → cost_outlier null, buy '7.20', stock 53 (today's behaviour preserved)
    - Harmless 3-supplier key → cost_outlier null, buy = cheap price
    - Blocked offers are removed BEFORE the guard (blocking Midwich leaves Nimans alone → no outlier)
    - Same supplier with two rows for one key → guard uses that supplier's min price
    resolveBuyForProduct (public, testable without mysqli):
    - outlier row + product buy_price 88.20 → returns buy '88.2000'-equivalent (existing kept), writes ONE unresolved ImportIssue issue_type supplier_cost_outlier whose notes contain the sku, 'MIDWICH'/'Midwich', '7.20' and '88.20'; re-run updates last_seen_at, no duplicate
    - outlier row + product buy_price null → returns null, still writes the issue
    - outlier row + dryRun → returns existing buy, writes nothing
    - non-outlier row → returns row['buy'], writes nothing
    products:hydrate-stock-from-offers (seed supplier_offer_snapshots + fresh suppliers per existing test file pattern):
    - blocked (sku, supplier) cheapest snapshot is skipped → buy_price/stock from next supplier
    - outlier cheapest (7.20 vs 88.20) → buy_price unchanged, stock taken from the non-outlier supplier's row
  </behavior>
  <action>
    Write the two new feature test files + new cases appended to HydrateProductStockFromOffersCommandTest (RED), then implement.

    SupplierDbSyncCommand:
    (a) Constructor: add a 5th nullable param `?ProductSupplierBlockResolver $blocks = null`, resolved via app() like $exclusion (keeps every existing `new SupplierDbSyncCommand(..., excludeStaleSuppliersFromBuyPrice: …)` call compiling). Update the class header docblock with a 261027-duz paragraph after the 260626-oqr one (incident, block + guard, hydrate also covered).
    (b) buildBestOfferMap: inside the `foreach ([['mpn',$mpn],['suppliersku',$sku]] as [$via,$key])` loop, right after the empty-key `continue`, skip when `$this->blocks->isBlocked($key, $sid)` where $sid = (string) ($row['supplierid'] ?? ''). This drops price AND stock for that key only. Extend the accumulator per key with `'by_supplier' => []` mapping supplier id (supplierid, falling back to supplier_name when empty) → ['price' => lowest positive float, 'stock' => summed in-stock int, 'name' => supplier label]; also track the supplier id of the in-stock and any winners (instock_sid / any_sid). After reduction, when config('supplier.cost_outlier_guard.enabled') is true, call SupplierCostOutlierGuard::detect(price-per-supplier, winning sid, (float) config('supplier.cost_outlier_guard.ratio', 3)). Add `'cost_outlier' => ?array` to every map entry (null when not fired; when fired include the guard evidence plus 'chosen_supplier' name and per-other supplier names), and when fired set 'stock' to total_stock minus by_supplier[winning sid]['stock'] (planner discretion: an offer we refuse to trust for cost is not trusted for stock either — consistent with block semantics). Leave 'buy' as the raw cheapest value so explain/logging can see it; the hold is applied in the product loop. Update the @return shape docblock.
    (c) Add `public function resolveBuyForProduct(Product $local, string $key, array $row, string $correlationId, bool $dryRun): ?string`. No outlier → return $row['buy']. Outlier → return the existing buy_price as string (or null when null — null already makes pricing skip it via ZeroPriceSkipped), and unless dry-run write ImportIssue::updateOrCreate on the unresolved tuple (sku, woo_product_id, woo_variation_id null, issue_type SUPPLIER_COST_OUTLIER, resolved_at null) with detected_at/last_seen_at now, correlation_id, and notes: one human sentence ("Cheapest offer Midwich £7.20 is < 1/3 of the median of other suppliers (£88.20) — buy_price held at £X (261027-duz). Check for a supplier SKU collision; block with supplier:block-offer.") followed by ` evidence=` + json_encode of the guard evidence. If the existing buy_price equals the outlier price (numerically), append "Existing buy_price already equals the outlier — likely already contaminated; block the supplier and re-run supplier:db-sync." Do NOT apply hasAutoUpdateCarveOut here — the undercut job ignores those flags, so carve-out products need the hold too. In the product loop replace `$newBuy = $row['buy'];` with a call to resolveBuyForProduct and count held outliers ($heldOutlier / $wouldHoldOutlier, captured by reference like the stale counters), appending `cost_outlier_held=%d` to the Done summary line (would_… in dry-run). Log::warning('supplier_db_sync.cost_outlier_held', [...]) once per held product.

    HydrateProductStockFromOffersCommand: resolve ProductSupplierBlockResolver via app() in perform() (do not change the constructor — the test swaps the freshness resolver through the container). Per product: $blocked = array_keys($blocks->blocksFor($key)); add `->when($blocked !== [], fn ($q) => $q->whereNotIn('supplier_id', $blocked))` to the offer query. When the guard is enabled, build price-per-supplier from all fresh, non-blocked, price>0 snapshot rows for the key at that key's MAX(recorded_at) (min price per supplier_id); if detect() fires for the cheapest offer's supplier_id, set $newBuyPrice = null (existing sentinel = leave buy_price untouched) and re-pick the stock row from the cheapest in-stock offer whose supplier_id != the outlier (none → qty 0 / outofstock as today). No ImportIssue here — db-sync at 07:00 already raised it; add a Log::info('hydrate_stock.cost_outlier_held', ...). Comment both edits with 261027-duz and why (second buy_price writer).

    ImportIssueResource: add SUPPLIER_COST_OUTLIER arms — label 'Supplier cost outlier (held)', colour 'danger', and the filter label.
  </action>
  <verify>
    <automated>cd "/c/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app" && "/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" vendor/bin/pest tests/Feature/Domain/Sync tests/Feature/Sync tests/Feature/Console/HydrateProductStockFromOffersCommandTest.php tests/Unit/Domain/Sync</automated>
  </verify>
  <done>New block/outlier/hydrate tests green; ALL pre-existing SupplierDbSync* (exclusion, stale supplier, stale cost, stock-separate, command) and hydrate tests still green unchanged (guard is inert for single-supplier and agreeing-supplier fixtures); ImportIssueResource renders the new type.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Operator surfaces — supplier:block-offer CLI, explain-cost BLOCKED marker, Filament blocks page</name>
  <files>app/Domain/Sync/Commands/BlockSupplierOfferCommand.php, app/Domain/Sync/Commands/ExplainSupplierCostCommand.php, app/Providers/AppServiceProvider.php, app/Domain/Sync/Filament/Resources/ProductSupplierBlockResource.php, app/Domain/Sync/Filament/Resources/ProductSupplierBlockResource/Pages/ManageProductSupplierBlocks.php, tests/Feature/Domain/Sync/BlockSupplierOfferCommandTest.php, tests/Feature/Filament/Resources/ProductSupplierBlockResourceTest.php</files>
  <behavior>
    - `supplier:block-offer 1301222 MIDWICH --reason="Midwich 1301222 is an unrelated £7.20 item"` → one row sku '1301222', supplier_id 'MIDWICH', product_id resolved when a product with that SKU exists; exit 0
    - Same command again with a new reason → updates reason, still one row (idempotent upsert on the unique pair)
    - Missing --reason when adding → exit FAILURE, no row
    - `--remove` → deletes the pair, exit 0; removing a non-existent pair → warns, exit 0
    - Supplier argument may be the supplier_id or a case-insensitive suppliers.name ('midwich' → its supplier_id); unknown name that is not a known id → warns but still stores the raw value (feed ids may be absent from suppliers)
    - SKU '83Z49AA#ABU' is stored as '83z49aa#abu'
    - Filament: admin + pricing_manager can mount the page, create a block (created_by_user_id = auth id) and delete it; sales/read_only can mount but cannot create/delete
  </behavior>
  <action>
    BlockSupplierOfferCommand (app/Domain/Sync/Commands, extends the same BaseCommand as ExplainSupplierCostCommand, perform()): signature `supplier:block-offer {sku : Match key (local SKU or alias)} {supplier : suppliers.supplier_id or supplier name} {--reason= : Why this offer is wrong (required when adding)} {--remove : Delete the block instead}`. Resolve supplier: exact supplier_id match first, else Supplier where LOWER(name) = lower(arg) → its supplier_id, else warn and use the raw trimmed value. Add = ProductSupplierBlock::updateOrCreate on (normalised sku, supplier_id) with reason; created_by_user_id null (CLI). Print the resulting row and a hint to run `supplier:explain-cost {sku}` then `supplier:db-sync`. Register in AppServiceProvider commands list next to ExplainSupplierCostCommand with a 261027-duz comment.

    ExplainSupplierCostCommand: inject nothing new in the constructor — resolve ProductSupplierBlockResolver via app() in perform(), call forget() first so the CLI sees fresh rows. For each offer row, a row is blocked when isBlocked(lowercased mpn) or isBlocked(lowercased suppliersku) matches $key for that supplierid — practically: blocked = isBlocked($key, supplierid) (the command already searches by the given key). Show 'BLOCKED' in the EXCL column (widen to 8) and append ` — BLOCKED (reason)` to the line; skip blocked rows in the cheapest selection exactly like excluded ones; update the "mirrors buildBestOfferMap" comment. After choosing, run SupplierCostOutlierGuard::detect over the non-blocked positive per-supplier prices when the guard is enabled, and if it fires print a warn line: "OUTLIER GUARD: cheapest is < 1/N of median of others — supplier:db-sync will HOLD the existing buy_price and raise a supplier_cost_outlier issue."

    Filament ProductSupplierBlockResource (app/Domain/Sync/Filament/Resources, auto-discovered; navigationGroup 'Sync & CRM' like SupplierResource, sort 26, icon heroicon-o-no-symbol, label 'Supplier offer blocks'). Single ManageRecords page (Pages/ManageProductSupplierBlocks) with header CreateAction (modal) and row DeleteAction — no separate create/edit pages, keeps the diff small. Form: TextInput sku required (helper text: "Supplier match key — usually the product SKU"), Select supplier_id required searchable, options Supplier::orderBy('name')->pluck('name','supplier_id') rendered as "Name (ID)"; Textarea reason required. CreateAction mutateFormDataUsing sets created_by_user_id = auth()->id(). Table columns: sku (searchable), product.name (or product sku link text), supplier_id with supplier name via a lookup, reason (limit 60, tooltip full), createdBy.name, created_at since. canCreate/canDelete/canDeleteAny gate on hasAnyRole(['admin','pricing_manager']) mirroring SupplierResource::canWrite; viewAny open to the same roles that can view SupplierResource. Model saving hook (Task 1) handles sku normalisation + product_id resolution, so the form needs no extra logic. Call ProductSupplierBlockResolver::forget() is unnecessary (new request per page).

    Tests: BlockSupplierOfferCommandTest via $this->artisan(...)->assertExitCode(); ProductSupplierBlockResourceTest mirroring SupplierResourceTest's role setup (Spatie Role::create, Livewire::test(ManageProductSupplierBlocks::class) ->callAction('create', data: [...]) and ->callTableAction('delete', $record), assertActionHidden/assertTableActionHidden for sales/read_only). An ExplainSupplierCost test is NOT required (it needs the live mysqli feed) — verify it with `php -l` and the artisan list instead.
  </action>
  <verify>
    <automated>cd "/c/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app" && "/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" vendor/bin/pest tests/Feature/Domain/Sync/BlockSupplierOfferCommandTest.php tests/Feature/Filament/Resources/ProductSupplierBlockResourceTest.php tests/Feature/Filament/Resources/SupplierResourceTest.php && "/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Domain/Sync/Commands/ExplainSupplierCostCommand.php && "/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan list supplier | grep -c "block-offer"</automated>
  </verify>
  <done>CLI add/update/remove/name-resolution tests green; Filament RBAC + create/delete tests green; explain-cost lints and prints BLOCKED for blocked offers; `supplier:block-offer` listed. Then run the full suite once (`$PHP vendor/bin/pest`) and `$PHP vendor/bin/pint` on touched files; commit per task on branch quick/261027-duz-supplier-offer-block.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| remote supplier feed → buildBestOfferMap / supplier_offer_snapshots | untrusted third-party codes/prices decide our cost (the incident) |
| Filament admin user → product_supplier_blocks | operator writes that change cost selection |
| CLI operator → supplier:block-offer | shell-access ops, trusted |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-261027-01 | Tampering | buildBestOfferMap + hydrate (supplier code collision sets cost) | mitigate | per-(key, supplier) block applied in BOTH buy_price writers + automatic lower-median outlier guard holding existing cost |
| T-261027-02 | Elevation | ProductSupplierBlockResource create/delete | mitigate | canCreate/canDelete gated on hasAnyRole(['admin','pricing_manager']); RBAC feature test |
| T-261027-03 | Repudiation | who blocked an offer and why | mitigate | reason NOT NULL + created_by_user_id + timestamps on every row |
| T-261027-04 | Denial of service | guard holds a genuine price drop | accept | cost is held (not lost) and surfaced as an unresolved ImportIssue; operator can disable via config or accept by blocking the expensive supplier |
| T-261027-05 | Information disclosure | ImportIssue notes evidence | accept | internal admin-only supplier prices, same data already in supplier:explain-cost |
</threat_model>

<verification>
- `$PHP vendor/bin/pest` full suite green (sqlite).
- Grep gates: `grep -v '^\s*//' app/Domain/Sync/Commands/SupplierDbSyncCommand.php | grep -c "blocks->isBlocked"` ≥ 1; same file `grep -c "SupplierCostOutlierGuard::detect"` ≥ 1; `grep -c "blocksFor" app/Console/Commands/HydrateProductStockFromOffersCommand.php` ≥ 1; `grep -rn "env(" app/Domain/Sync app/Console/Commands/HydrateProductStockFromOffersCommand.php` → no new hits.
- Migration index names ≤ 64 chars and explicit (`psb_sku_supplier_unique`, `psb_product_id_index`, `psb_product_id_fk`, `psb_created_by_fk`).
</verification>

<success_criteria>
- Yealink fixture: with block → cost 88.20 from Nimans, stock 3; without block → guard holds existing cost and raises supplier_cost_outlier issue; guard disabled → legacy behaviour.
- Harmless agreeing-supplier fixture never trips the guard.
- Hydrate-from-offers cannot reintroduce a blocked or outlier price.
- Operator can manage blocks from CLI and Filament; explain-cost shows BLOCKED (reason).
</success_criteria>

<output>
Create `.planning/quick/261027-duz-block-wrong-supplier-offers-per-product-/261027-duz-SUMMARY.md` when done. It MUST include this post-deploy runbook (migrate first, then run on the VPS as the app user):

1. `php artisan migrate --force`
2. `php artisan supplier:block-offer 1301222 <Midwich id> --reason="Midwich 1301222 is an unrelated £7.20 item; Nimans 1301222 = Yealink T74U (incident 2026-10-27)"`
3. `php artisan supplier:block-offer cp05osswh815 <Midwich id> --reason="Midwich £50/£58 vs others £189–£225 — wrong item"`
4. same for `cp05osswh740`, `cp05osswh988`, `cp05rtbsha29` (Midwich)
5. `php artisan supplier:block-offer "83z49aa#abu" <Tech Data id> --reason="Tech Data £564.82 vs Ingram £3,941.83 — pending confirmation"`
6. `php artisan supplier:explain-cost 1301222` (expect Midwich BLOCKED, Nimans chosen), then `php artisan supplier:db-sync` and re-check product 5969 buy_price = 88.20; then let/trigger `pricing:undercut-competitors` and confirm sell price recovers.
7. Review new `supplier_cost_outlier` ImportIssues in Filament after the next 07:00 run.

Look up the exact Midwich / Tech Data supplier_id values with `supplier:explain-cost` or the Suppliers page (the CLI also accepts the supplier name). Note in SUMMARY: blocks are per match KEY — if a product matches via an alternative SKU alias, block the alias key too.
</output>
