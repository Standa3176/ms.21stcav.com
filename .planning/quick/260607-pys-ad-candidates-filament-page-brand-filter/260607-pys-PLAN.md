---
quick_id: 260607-pys
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Pricing/Services/AdCandidateScanner.php
  - tests/Unit/Domain/Pricing/Services/AdCandidateScannerTest.php
  - app/Console/Commands/BackfillMerchantFeedCommand.php
  - tests/Feature/Console/Commands/BackfillMerchantFeedCommandTest.php
  - app/Filament/Pages/AdCandidatesPage.php
  - resources/views/filament/pages/ad-candidates.blade.php
  - tests/Feature/Filament/Pages/AdCandidatesPageTest.php
  - app/Filament/Widgets/AdCandidatesReadyWidget.php
  - app/Filament/Pages/HomeDashboardPage.php
  - app/Domain/Dashboard/Services/SnapshotAggregator.php
  - tests/Unit/Domain/Dashboard/Services/SnapshotAggregatorAdCandidatesTest.php
  - config/services.php
autonomous: true

must_haves:
  truths:
    - "Operator opens /admin/ad-candidates and sees a list of products meeting the golden-ad-target criteria"
    - "Operator picks one or more brands and the list filters to those brands only"
    - "Operator toggles min-margin / stock-required / beat-required and the list recomputes"
    - "Operator selects rows and downloads a comma-separated SKU CSV ready for Google Ads bulk upload"
    - "Home dashboard shows an Ad Candidates Ready tile with count + click-through to the page"
    - "BackfillMerchantFeedCommand and the page share ONE predicate (AdCandidateScanner) — no SQL duplication"
  artifacts:
    - path: "app/Domain/Pricing/Services/AdCandidateScanner.php"
      provides: "Single golden-ad-target predicate — scan(brandIds, minMarginPence, stockRequired, beatRequired)"
    - path: "app/Filament/Pages/AdCandidatesPage.php"
      provides: "Filament page at /admin/ad-candidates with brand-filter UI + bulk SKU CSV export"
    - path: "app/Filament/Widgets/AdCandidatesReadyWidget.php"
      provides: "Home dashboard tile showing ad-candidates count"
    - path: "app/Domain/Dashboard/Services/SnapshotAggregator.php"
      contains: "computeAdCandidatesHealth"
  key_links:
    - from: "app/Console/Commands/BackfillMerchantFeedCommand.php"
      to: "AdCandidateScanner::scan"
      via: "constructor DI + ->scan(minMarginPence: 30000)"
      pattern: "AdCandidateScanner"
    - from: "app/Filament/Widgets/AdCandidatesReadyWidget.php"
      to: "dashboard_snapshots row keyed 'ad_candidates_health'"
      via: "DashboardSnapshot::where('metric_key', 'ad_candidates_health')"
      pattern: "ad_candidates_health"
    - from: "app/Filament/Pages/AdCandidatesPage.php"
      to: "AdCandidateScanner::scan"
      via: "Livewire properties → scanner call → table records"
      pattern: "AdCandidateScanner"
---

<objective>
Ship `/admin/ad-candidates` — a Filament page that surfaces live products meeting the "golden ad target" criteria (margin ≥ £X, has competitor data, we beat the lowest comp, supplier in stock in last 7 days) with a brand multi-select filter so the operator can plan a Google Ads campaign in one screen.

Today's work (260607-cgd / 260607-g25 / 260607-hxa) ran the same SQL repeatedly via tinker; the predicate is currently duplicated inside `BackfillMerchantFeedCommand`. Extract it ONCE into `AdCandidateScanner`, refactor the backfill command to delegate, then build the UI + dashboard tile on top of the shared service.

Purpose: ad-spend planning needs a persistent surface, not a tinker session. Drift between the two SQL surfaces becomes impossible from day one.

Output: AdCandidateScanner service + refactored BackfillMerchantFeedCommand + AdCandidatesPage + AdCandidatesReadyWidget + SnapshotAggregator method + 3 test files (scanner unit, page feature, aggregator unit).
</objective>

<context>
@CLAUDE.md
@.planning/STATE.md
@app/Filament/Pages/AutoCreateHealthPage.php
@app/Domain/Pricing/Filament/Pages/PricingOperationsPage.php
@app/Filament/Widgets/HighConfidenceSourceableWidget.php
@app/Domain/Pricing/Services/CompetitorPositionScanner.php
@app/Console/Commands/BackfillMerchantFeedCommand.php
@app/Domain/Dashboard/Services/SnapshotAggregator.php
@app/Domain/ProductAutoCreate\Services\TaxonomyResolver.php
@app/Filament/Pages/HomeDashboardPage.php

<interfaces>
<!-- Contracts and patterns the executor needs. Extracted from the codebase. -->

From CompetitorPositionScanner (the structural analog — windowed SQL + batched name decoration + chunkById loop):
- `compute(int $maxAgeDays, ?int $floorBps): array{below_cost,at_floor,winnable,...}`
- Reads competitor_prices via `DB::select` with `ROW_NUMBER() OVER (PARTITION BY competitor_id, sku ORDER BY recorded_at DESC)` to get the latest current row per (competitor, sku)
- Reads supplier_offer_snapshots via similar window function for `cheapest-current` per product
- Match-key is `strtolower(trim((string) $product->sku))` — MIRROR THIS so the scanner agrees with the backfill command's existing key shape
- Decorates rows with brand/supplier/competitor names AFTER aggregation, never via SQL JOIN (keeps deptrac surface clean)

From TaxonomyResolver:
- `public function allBrands(): array` — returns `array<int, array{id:int, name:string}>`, cached 1h
- Used for: brand-id → name decoration on scanner rows + Brand multi-select options on the page

From SnapshotAggregator (existing computeSuggestionsTriageHealth pattern from 260606-lhp):
- Method registered in `computeAll()` map under a metric_key
- Returns flat `array<string, int|null>` payload
- Defensive try/catch — must not 500 the dashboard refresh
- Read via `DashboardSnapshot::where('metric_key', '<key>')->first()` and `$snapshot?->metric_value_json`

From AutoCreateHealthPage (the closest Filament page analog — embedded table, header filters via Livewire props, per-row actions, nav badge, admin gate):
- `extends Filament\Pages\Page implements HasTable` + `use InteractsWithTable`
- `protected static ?string $slug` for URL
- `protected static ?string $navigationGroup = 'Operations'`
- `protected static ?int $navigationSort = 105` (between Home=10/12 and AutoCreateHealth=110)
- `canAccess(): bool` for page gate
- `table(Table $table): Table` — Filament 3 table builder
- For non-Eloquent rows: `$table->records(fn () => $this->rows())` with `Collection` of arrays/stdClass — see Filament 3 docs (the page builds rows in-memory via the scanner, not via getTableQuery)

From HighConfidenceSourceableWidget (dashboard tile analog from 260606-lhp):
- `extends StatsOverviewWidget`
- `public static function canView(): bool` — RBAC gate
- `protected function getStats(): array` — returns `array<int, Stat>`
- Read snapshot via `DashboardSnapshot::where('metric_key', '...')->first()`; cast `metric_value_json` array
- Pre-applied filter URL: `http_build_query(['tableFilters' => [...]])` style (NOT applicable here — the page defaults are correct, just link to /admin/ad-candidates)

From BackfillMerchantFeedCommand (the drift-prevention target):
- Constructor signature: `__construct(IntegrationCredentialResolver, TaxonomyResolver, IcecatClient, EanSearchClient)`
- Today the command inlines its "live products + missing EAN" candidate selection (NOT the full golden-target predicate — see Task 2 caveat below)
- Adds `AdCandidateScanner` as a 5th constructor dep
- The "golden target" SQL in current production runs is the operator's tinker query, NOT the command's existing query. The refactor REPLACES the command's CURRENT candidate-selection (just `status=publish + missing EAN`) with a CALL TO `AdCandidateScanner::scan(minMarginPence: 30000, stockRequired: true, beatRequired: true)` for the GOLDEN backfill path

From HomeDashboardPage (where to register the tile):
- Two registration sites: `getWidgets()` array (Filament discovery + render order) AND `getDashboardSections()` array (the visual grouping under "Needs attention" / "Today's sync" / "Catalogue" / "System health")
- Add `AdCandidatesReadyWidget::class` to BOTH `getWidgets()` AND the "Needs attention" section's widget list
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: AdCandidateScanner service + Pest unit test (5-row matrix)</name>
  <files>
    app/Domain/Pricing/Services/AdCandidateScanner.php
    tests/Unit/Domain/Pricing/Services/AdCandidateScannerTest.php
  </files>
  <behavior>
    - Row A (£250 margin, in stock, undercuts comp) → INCLUDED at all default thresholds (minMarginPence=19900, stockRequired=true, beatRequired=true)
    - Row B (£250 margin, in stock, ABOVE comp by £20) → EXCLUDED when beatRequired=true; INCLUDED when beatRequired=false
    - Row C (£250 margin, NO supplier stock in last 7 days, undercuts comp) → EXCLUDED when stockRequired=true; INCLUDED when stockRequired=false
    - Row D (£100 margin, in stock, undercuts comp) → EXCLUDED when minMarginPence=19900; INCLUDED when minMarginPence=9900
    - Row E (£250 margin, brand=X, in stock, undercuts comp) → INCLUDED when brandIds=[X]; EXCLUDED when brandIds=[Y]
    - Brand-filter test: scan(brandIds=[X]) returns only brand X rows; scan(brandIds=[]) returns all matching rows
    - Returned Collection shape: each row has keys sku, name, brand_id, brand_name, sell_price_pence, buy_price_pence, margin_pence, lowest_comp_pence, beat_pct_bps, stock, best_supplier, slug, woo_product_id
  </behavior>
  <action>
    Create `app/Domain/Pricing/Services/AdCandidateScanner.php` as a final class with one public method:

    `public function scan(array $brandIds = [], int $minMarginPence = 19900, bool $stockRequired = true, bool $beatRequired = true): Collection`

    Implementation contract:
    1. Build the base query on `products` table: where status='publish', where type='simple' (mirror CompetitorPositionScanner), where buy_price > 0 and sell_price > 0. If `$brandIds !== []`, add `whereIn('brand_id', $brandIds)`.
    2. Compute margin in pence: `(sell_price - buy_price) * 100` (sell/buy are decimal-as-string GBP per existing migrations). Filter `margin_pence >= $minMarginPence`.
    3. Resolve lowest current competitor per product via a windowed SQL pass on `competitor_prices` (mirror `CompetitorPositionScanner::lowestCompetitorByKey` — ROW_NUMBER() OVER (PARTITION BY competitor_id, sku ORDER BY recorded_at DESC), 30-day window). Match by lowercase-trim of product.sku against competitor sku/mpn.
    4. If `$beatRequired === true`, filter to rows where sell_price_pence < min(competitor.price_pennies_gross) / 1 (use gross — operator-facing). Compute `beat_pct_bps = intdiv((sell_pence - lowest_comp_pence) * 10000, lowest_comp_pence)` (negative = we undercut).
    5. If `$stockRequired === true`, join supplier_offer_snapshots windowed by `ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY recorded_at DESC)` within last 7 days, require `stock > 0`. Resolve `best_supplier` via the same row.
    6. Decorate `brand_name` from `TaxonomyResolver::allBrands()` (build id→name map ONCE before the loop, cached 1h via the resolver itself — never per-row).
    7. Return a `\Illuminate\Support\Collection` of stdClass rows (NOT Eloquent — aggregated/decorated shape, mirrors `CompetitorPositionScanner::compute()` row shape).

    Constructor: `__construct(private readonly TaxonomyResolver $taxonomy)`. Use `DB::select` for the window-function passes (raw SQL, parameter-bound — NEVER interpolate $brandIds or filter values into the SQL string). Deptrac: Pricing already depends on ProductAutoCreate per existing 260606-rld allow-list — no new arrow.

    Pest test file: `tests/Unit/Domain/Pricing/Services/AdCandidateScannerTest.php`. Use `RefreshDatabase`. Seed 5 products + matching competitor_prices + supplier_offer_snapshots rows for the 5-row matrix described in behavior. Assert each `scan(...)` call returns exactly the expected SKU set.

    Reference patterns: `CompetitorPositionScanner::lowestCompetitorByKey` for the window-function shape; `CompetitorPositionScanner::cheapestSupplierNameByProductId` for the supplier batched lookup; `tests/Unit/Domain/Pricing/Services/CompetitorPositionScannerTest.php` (if it exists; otherwise mirror `tests/Unit/Domain/Pricing/Services/PricingFloorReportTest.php`) for the test scaffold.

    No env() calls. No new config keys. No commits to Suggestions table.

    Atomic commit when verify passes: `feat(pricing): AdCandidateScanner — shared golden-ad-target predicate (260607-pys)`
  </action>
  <verify>
    <automated>cd "C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app" &amp;&amp; ./vendor/bin/pest tests/Unit/Domain/Pricing/Services/AdCandidateScannerTest.php --stop-on-failure</automated>
  </verify>
  <done>
    AdCandidateScanner.php exists, all 5+ Pest cases pass (each of A/B/C/D/E behaviors above + brand-filter), atomic commit landed.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Refactor BackfillMerchantFeedCommand to delegate golden-target selection to AdCandidateScanner</name>
  <files>
    app/Console/Commands/BackfillMerchantFeedCommand.php
    tests/Feature/Console/Commands/BackfillMerchantFeedCommandTest.php
  </files>
  <behavior>
    - The command's existing 6 BackfillMerchantFeedCommandTest cases A–F all stay GREEN (public CLI surface unchanged — same `--field`, `--skus`, `--dry-run`, `--resync`, `--icecat-fallback`, `--max-icecat-spend-pence` semantics)
    - When `--skus=` is omitted, the EAN/brand/category candidate selection MUST now route through `AdCandidateScanner::scan(minMarginPence: 30000, stockRequired: true, beatRequired: true)` and only consider SKUs in that golden set
    - When `--skus=<list>` is passed, behavior is byte-identical to today (operator explicit override wins; scanner is bypassed) — this preserves all existing test expectations that pass `--skus`
    - New test case G: `--skus=` omitted + AdCandidateScanner stubbed to return 3 known SKUs → command's candidate selection is exactly those 3 SKUs (not the whole 3,778-row missing-EAN pool from today's behavior)
  </behavior>
  <action>
    Refactor `BackfillMerchantFeedCommand` so the candidate-SKU selection for each field path (EAN, brand, category) delegates to `AdCandidateScanner` when `--skus` is not provided:

    1. Add `AdCandidateScanner` as a 5th constructor dependency: `__construct(IntegrationCredentialResolver, TaxonomyResolver, IcecatClient, EanSearchClient, AdCandidateScanner)`. Add the import.
    2. In each of `backfillEan`, `backfillBrand`, `backfillCategory`: when `$skusFilter === []`, BEFORE building the existing `Product::query()` candidate set, fetch the golden-target SKU list via `$this->adCandidates->scan(minMarginPence: 30000, stockRequired: true, beatRequired: true)->pluck('sku')->all()` and pass that list as the SKU constraint (`->whereIn('sku', $goldenSkus)`). When `$skusFilter !== []`, leave the existing logic untouched (operator override).
    3. Threshold rationale (document in code comment): page default is £199 for the campaign-planning use case (D-page); backfill default stays at £300 (the historical backfill threshold — different operator concern, different operational use case). Both call the same scanner with different thresholds. Single SQL surface.
    4. Update the existing BackfillMerchantFeedCommandTest cases A–F to inject a fake AdCandidateScanner returning a permissive SKU set so the existing test fixtures continue to pass (or — simpler — bind a mock that returns Collection::make() of all seeded SKUs in the test setup).
    5. Add test case G: omit `--skus`, bind a stub AdCandidateScanner returning 3 specific SKUs, seed 5 products (3 in golden set + 2 outside), assert command processes ONLY the 3 golden SKUs.

    Do NOT change CLI flags. Do NOT change the 4-row vs 7-row outcome table shape. Do NOT change provider switching (ean_search/icecat config). Do NOT touch IcecatClient or EanSearchClient.

    Atomic commit when verify passes: `refactor(backfill): use AdCandidateScanner for golden-target selection (260607-pys)`
  </action>
  <verify>
    <automated>cd "C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app" &amp;&amp; ./vendor/bin/pest tests/Feature/Console/Commands/BackfillMerchantFeedCommandTest.php --stop-on-failure</automated>
  </verify>
  <done>
    All BackfillMerchantFeedCommandTest cases (A–F existing + G new) pass. Command public CLI surface unchanged. AdCandidateScanner is the sole SQL surface for golden-target selection. Atomic commit landed.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: AdCandidatesPage — Filament page at /admin/ad-candidates with brand filter + SKU CSV bulk action</name>
  <files>
    app/Filament/Pages/AdCandidatesPage.php
    resources/views/filament/pages/ad-candidates.blade.php
    config/services.php
    tests/Feature/Filament/Pages/AdCandidatesPageTest.php
  </files>
  <behavior>
    - GET /admin/ad-candidates returns 200 for admin and pricing_manager; 403 for sales and read_only
    - Table renders ad candidates at default thresholds (£199 margin, stock required, beat required, all brands)
    - Setting filterBrandIds=[X] via Livewire wire:model:live recomputes the table to brand X rows only
    - Setting filterMinMarginPounds=100 widens the result set vs default 199
    - Toggling filterStockRequired=false includes products with no supplier stock
    - Toggling filterBeatRequired=false includes products where we don't undercut
    - Bulk action "Copy SKU CSV" on a selection of N rows streams a downloadable comma-separated CSV of those SKUs
    - Bulk action "Send to Google Ads" fires a Filament warning notification pointing to Phase 15 + ads.google.com
    - Per-row action "View on storefront" opens new tab to `config('services.woo.storefront_url')."/?p={woo_product_id}"`
    - Per-row action "Edit in admin" navigates to /admin/products/{id}/edit
  </behavior>
  <action>
    1. Create `app/Filament/Pages/AdCandidatesPage.php` mirroring `AutoCreateHealthPage` shape:
       - `final class AdCandidatesPage extends Page implements HasTable` + `use InteractsWithTable`
       - `protected static ?string $slug = 'ad-candidates'` (URL `/admin/ad-candidates`)
       - `protected static ?string $navigationGroup = 'Operations'`
       - `protected static ?string $navigationIcon = 'heroicon-o-megaphone'`
       - `protected static ?string $navigationLabel = 'Ad Candidates'`
       - `protected static ?int $navigationSort = 105` (between NotificationCentrePage=100 and AutoCreateHealthPage=110)
       - `protected static string $view = 'filament.pages.ad-candidates'`
       - `canAccess(): bool` → `auth()->user()?->hasAnyRole(['admin','pricing_manager']) ?? false`

    2. Livewire properties for filter state (NO persistent storage — operator state lives in component memory per scope decision):
       - `public array $filterBrandIds = [];`
       - `public int $filterMinMarginPounds = 199;`
       - `public bool $filterStockRequired = true;`
       - `public bool $filterBeatRequired = true;`

    3. `table(Table $table): Table` — build with `->records(fn () => $this->rows())` style for the Collection-of-arrays shape returned by AdCandidateScanner. Columns (mirror PricingOperationsPage `pricing-ops-bucket.blade` column shapes):
       - SKU: mono, copyable, searchable, with row-click linking to `/admin/products/{id}/edit` (use `->url(...)` on TextColumn)
       - Name: `->limit(60)->tooltip(full name)`
       - Brand: `->badge()->color('gray')`
       - Margin £: sortable DESC default, formatted `'£'.number_format(margin/100, 2)`, success color when margin_pence > 50000 (£500+)
       - Sell £: formatted £ with 2dp
       - Lowest Competitor £: formatted £ with 2dp
       - Beat %: `->state(fn ($row) => round($row->beat_pct_bps/100, 1).'%')->color(fn ($row) => $row->beat_pct_bps < 0 ? 'success' : 'danger')`
       - Stock: badge — success when >10, warning 1-10, gray 0
       - Best Supplier: text, placeholder '—'

    4. Per-row actions array on the table:
       - `Action::make('view_storefront')` — `->url(fn ($row) => config('services.woo.storefront_url')."/?p={$row->woo_product_id}")->openUrlInNewTab()->icon('heroicon-o-globe-alt')`
       - `Action::make('edit_admin')` — `->url(fn ($row) => "/admin/products/{$row->product_id}/edit")->icon('heroicon-o-pencil')`

    5. Bulk actions:
       - `BulkAction::make('copy_sku_csv')` — assembles comma-separated SKU list from selection, streams a CSV download via `response()->streamDownload(fn() => echo "sku\n".implode(',', $skus), "ad-candidates-skus-{$timestamp}.csv")`
       - `BulkAction::make('send_to_google_ads')` — fires `Notification::make()->warning()->title('Google Ads API integration is Phase 15')->body('For now use Copy SKU CSV + manual upload at ads.google.com. SKUs: '.implode(',', $skus))->send()`

    6. Header filter UI in `resources/views/filament/pages/ad-candidates.blade.php` — Livewire-bound inputs above the table (`wire:model.live` on all four properties). Brand multi-select fed by `$this->brandOptions = collect($this->taxonomy->allBrands())->mapWithKeys(fn ($b) => [$b['id'] => $b['name']])->all()`. Numeric input for min margin pounds, toggles for stock-required + beat-required. Footer summary banner: total count + total margin potential (sum of margin_pence) + average margin per SKU.

    7. Add to `config/services.php` a new key:
       ```
       'woo' => [
           'storefront_url' => env('WOO_STOREFRONT_URL', 'https://meetingstore.co.uk'),
       ],
       ```
       (env() inside config is the only legal place per 260606-c4o EnvUsageTest guardrail.) Read via `config('services.woo.storefront_url')` from the page.

    8. Pest feature test `tests/Feature/Filament/Pages/AdCandidatesPageTest.php` (use Livewire test helper):
       - Test 1: admin gets 200, pricing_manager gets 200, sales gets 403, read_only gets 403
       - Test 2: default filters render the expected SKU set from seeded fixtures
       - Test 3: setting filterBrandIds via `->set('filterBrandIds', [$brandX->id])` recomputes to brand X only
       - Test 4: bulk Copy SKU CSV action returns a streamed response with the right SKUs
       - Test 5: bulk Send to Google Ads action dispatches a Filament Notification (use `Notification::assertNotified()` per Filament 3 test helpers)

    Atomic commit when verify passes: `feat(ads): AdCandidatesPage — brand-filterable golden ad targets at /admin/ad-candidates (260607-pys)`
  </action>
  <verify>
    <automated>cd "C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app" &amp;&amp; ./vendor/bin/pest tests/Feature/Filament/Pages/AdCandidatesPageTest.php --stop-on-failure</automated>
  </verify>
  <done>
    Page renders at /admin/ad-candidates, role gates correct, filter wiring works, both bulk actions function, Pest 5/5 GREEN. Atomic commit landed.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 4: SnapshotAggregator::computeAdCandidatesHealth + Pest aggregator test</name>
  <files>
    app/Domain/Dashboard/Services/SnapshotAggregator.php
    tests/Unit/Domain/Dashboard/Services/SnapshotAggregatorAdCandidatesTest.php
  </files>
  <behavior>
    - `computeAdCandidatesHealth()` returns `['count' => int, 'total_margin_pence' => int, 'average_margin_pence' => int]`
    - Calls `AdCandidateScanner::scan()` at default thresholds (no brand filter, £199, stock + beat required)
    - count = scanner row count; total_margin_pence = sum of margin_pence; average = total / count (0 when count=0, no div-by-zero)
    - Registered in `computeAll()` under `'ad_candidates_health' => $this->computeAdCandidatesHealth()` so `dashboard:refresh` persists it every 5 min
    - Defensive try/catch — Throwable returns `['count' => 0, 'total_margin_pence' => 0, 'average_margin_pence' => 0]` (matches the existing computeSuggestionsTriageHealth pattern from 260606-lhp)
  </behavior>
  <action>
    1. Add `AdCandidateScanner` as a constructor dep on `SnapshotAggregator` — OR resolve via `app(AdCandidateScanner::class)` inside `computeAdCandidatesHealth()` (mirror how the existing computeSuggestionsTriageHealth method touches `Suggestion::query()` directly without a constructor injection; pick the lower-friction option that doesn't break existing aggregator tests).
    2. Add public method `computeAdCandidatesHealth(): array` to `SnapshotAggregator`. Implementation:
       - Try block: call `AdCandidateScanner::scan()` with defaults, compute count + sum + average
       - Catch Throwable: `report($e)` + return zero payload
    3. Register in `computeAll()`: add `'ad_candidates_health' => $this->computeAdCandidatesHealth(),` to the returned array
    4. Add Pest test `tests/Unit/Domain/Dashboard/Services/SnapshotAggregatorAdCandidatesTest.php`:
       - Test 1: seed 3 products meeting golden criteria with margins £200/£300/£400 → assert count=3, total_margin_pence=90000, average_margin_pence=30000
       - Test 2: empty DB → assert count=0, total=0, average=0 (no DivisionByZeroError)
       - Test 3: bind a scanner stub that throws → assert zero payload returned + report() called (use `Log::spy()` or just assert the return shape)

    Atomic commit when verify passes: `feat(dashboard): computeAdCandidatesHealth aggregator method (260607-pys)`
  </action>
  <verify>
    <automated>cd "C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app" &amp;&amp; ./vendor/bin/pest tests/Unit/Domain/Dashboard/Services/SnapshotAggregatorAdCandidatesTest.php --stop-on-failure</automated>
  </verify>
  <done>
    SnapshotAggregator has computeAdCandidatesHealth registered in computeAll, returns the 3-key payload, defensive on errors, Pest 3/3 GREEN. Atomic commit landed.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 5: AdCandidatesReadyWidget — Home dashboard tile + register in HomeDashboardPage</name>
  <files>
    app/Filament/Widgets/AdCandidatesReadyWidget.php
    app/Filament/Pages/HomeDashboardPage.php
  </files>
  <behavior>
    - On Home dashboard, an "Ad Candidates Ready" tile renders with count from the ad_candidates_health snapshot
    - Description shows "Click to plan your next campaign" + total margin potential as a £ amount
    - Tile is clickable, navigates to /admin/ad-candidates (no pre-applied filter — page defaults are correct)
    - Color: success when count >= 1, gray when 0
    - RBAC: admin + pricing_manager only (sales/read_only see silent absence — Filament `canView()` returns false)
    - Reads from DashboardSnapshot::where('metric_key', 'ad_candidates_health'); NEVER hits AdCandidateScanner on render (would be a hot DB query on every dashboard load)
  </behavior>
  <action>
    1. Create `app/Filament/Widgets/AdCandidatesReadyWidget.php` mirroring `HighConfidenceSourceableWidget` exactly:
       - `final class AdCandidatesReadyWidget extends StatsOverviewWidget`
       - `protected static ?string $pollingInterval = null;`
       - `protected int|string|array $columnSpan = 1;`
       - `public static function canView(): bool` → admin + pricing_manager
       - `protected function getStats(): array` — read `DashboardSnapshot::where('metric_key', 'ad_candidates_health')->first()`, cast array, extract count + total_margin_pence + average_margin_pence
       - Return one `Stat::make('Ad Candidates Ready', number_format($count))`:
         - `->description("£".number_format($total/100, 2)." potential margin · Click to plan your next campaign")`
         - `->descriptionIcon('heroicon-m-megaphone')`
         - `->color($count >= 1 ? 'success' : 'gray')`
         - `->url('/admin/ad-candidates')`
         - Stale ring extra attributes via the same `isStale()` check pattern as HighConfidenceSourceableWidget

    2. Register in `app/Filament/Pages/HomeDashboardPage.php`:
       - Add `use App\Filament\Widgets\AdCandidatesReadyWidget;` import
       - Add `AdCandidatesReadyWidget::class` to `getWidgets()` array — place it next to HighConfidenceSourceableWidget + SuggestionsQueueHealthWidget (Row 2 — Actions cluster)
       - Add `AdCandidatesReadyWidget::class` to the "Needs attention" section in `getDashboardSections()` alongside HighConfidenceSourceableWidget

    Do NOT add a separate test for the widget — its behavior is fully covered by the aggregator test (Task 4) + the page test (Task 3). Filament widget render is just a thin Snapshot read; the aggregator owns the math.

    Atomic commit when verify passes: `feat(dashboard): AdCandidatesReadyWidget tile on home (260607-pys)`
  </action>
  <verify>
    <automated>cd "C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app" &amp;&amp; php artisan route:list 2>&amp;1 | grep -E "(ad-candidates|admin)" | head -5 &amp;&amp; ./vendor/bin/pest tests/Unit/Domain/Dashboard/Services/SnapshotAggregatorAdCandidatesTest.php tests/Feature/Filament/Pages/AdCandidatesPageTest.php --stop-on-failure</automated>
  </verify>
  <done>
    AdCandidatesReadyWidget.php exists, registered in both getWidgets() and the "Needs attention" section of HomeDashboardPage. Aggregator + page tests still green. Atomic commit landed.
  </done>
</task>

<task type="auto">
  <name>Task 6: Verify (no commit) — focused + architecture + tinker probe</name>
  <files>(verification only — no file writes)</files>
  <action>
    Run final verification gates (NO new commit):

    1. Focused tests all GREEN:
       ```
       cd "C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app" && ./vendor/bin/pest \
         tests/Unit/Domain/Pricing/Services/AdCandidateScannerTest.php \
         tests/Feature/Console/Commands/BackfillMerchantFeedCommandTest.php \
         tests/Feature/Filament/Pages/AdCandidatesPageTest.php \
         tests/Unit/Domain/Dashboard/Services/SnapshotAggregatorAdCandidatesTest.php \
         --stop-on-failure
       ```

    2. Architecture guardrails still GREEN (we MUST NOT have introduced env() in app/ or a vacuous auto_create_status predicate):
       ```
       ./vendor/bin/pest tests/Architecture/EnvUsageTest.php tests/Architecture/AutoCreatedPredicateTest.php
       ```

    3. Route registered:
       ```
       php artisan route:list | grep ad-candidates
       ```
       MUST show `GET admin/ad-candidates` resolving to AdCandidatesPage. (No artisan command should exist for this — it's a Filament page only, not a command.)

    4. Tinker probe — confirm the aggregator returns the 3-key payload:
       ```
       php artisan tinker --execute="dd(app(App\Domain\Dashboard\Services\SnapshotAggregator::class)->computeAdCandidatesHealth());"
       ```
       MUST print an array with keys count, total_margin_pence, average_margin_pence.

    5. Drift-prevention grep — AdCandidateScanner is the sole golden-target SQL surface:
       ```
       grep -rn "competitor_prices" app/Console/Commands/BackfillMerchantFeedCommand.php
       ```
       Expect ZERO matches (the SQL now lives only in AdCandidateScanner). If matches exist, the refactor is incomplete — go back to Task 2.

    6. Full Pest suite — baseline per 260607-hxa was 1,896 passed / 219 failed / 3 skipped. Run:
       ```
       ./vendor/bin/pest --compact 2>&1 | tail -10
       ```
       Acceptable: passed ≥ 1,896 + new test count (Task 1: 5+, Task 2: 1 new G case, Task 3: 5, Task 4: 3 = ~14+ new) AND failed ≤ 219 (zero NEW failures).
       Unacceptable: any NEW failure introduced by this work. If any, fix before closing — do not ship.

    No commit on this task — all atomic commits already landed in Tasks 1–5.
  </action>
  <verify>
    <automated>cd "C:/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app" &amp;&amp; ./vendor/bin/pest tests/Unit/Domain/Pricing/Services/AdCandidateScannerTest.php tests/Feature/Console/Commands/BackfillMerchantFeedCommandTest.php tests/Feature/Filament/Pages/AdCandidatesPageTest.php tests/Unit/Domain/Dashboard/Services/SnapshotAggregatorAdCandidatesTest.php tests/Architecture/EnvUsageTest.php tests/Architecture/AutoCreatedPredicateTest.php --stop-on-failure</automated>
  </verify>
  <done>
    All focused tests GREEN, env() + auto_create_status guardrails GREEN, route registered, tinker probe returns the 3-key payload, grep proves zero competitor_prices references in BackfillMerchantFeedCommand, full suite has zero NEW failures vs 260607-hxa baseline.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser → /admin/ad-candidates | Authenticated operator filter inputs cross into Livewire properties |
| Livewire → AdCandidateScanner | Filter args (brandIds[], minMarginPence, bool flags) cross into raw SQL parameters |
| Bulk action → CSV download | Selected row SKU set crosses into a streamed HTTP response |
| Bulk action → Google Ads notification | SKU set crosses into a Filament Notification body (visible to other admin users in their notification feed) |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-pys-01 | Tampering | AdCandidateScanner SQL | mitigate | All brandIds + filter values bound via parameter placeholders in DB::select (NEVER interpolated). Pest test asserts brandIds=[1] produces a DB::select call with a parameter binding, not a SQL-literal IN-list. |
| T-pys-02 | Information Disclosure | AdCandidatesPage | mitigate | canAccess() restricts to admin + pricing_manager; sales/read_only get 403. Pest test asserts each role's HTTP status. |
| T-pys-03 | Information Disclosure | AdCandidatesReadyWidget | mitigate | canView() restricts to admin + pricing_manager; widget silently absent for sales/read_only. |
| T-pys-04 | Denial of Service | AdCandidatesReadyWidget render | mitigate | Reads from dashboard_snapshots (cached payload), NEVER calls scanner on render. Hot path is one indexed SELECT, not a windowed-SQL scan. |
| T-pys-05 | Tampering | Bulk Copy SKU CSV | accept | SKUs are public storefront identifiers; no PII; admin-only access already restricts. CSV streamed (no temp file written to disk). |
| T-pys-06 | Information Disclosure | Send to Google Ads notification | accept | Notification body lists SKUs (already visible to the same admin user in the table). Notification visibility scoped to the actor; not broadcast. |
| T-pys-07 | Spoofing | AdCandidateScanner brand filter | accept | Brand IDs are integer FKs to a known taxonomy; no user-controlled string interpolation. whereIn binds the array. |
| T-pys-SC | Tampering | composer/npm installs | n/a | This quick adds ZERO new composer or npm packages — pure first-party PHP. Package Legitimacy Gate not triggered. |
</threat_model>

<verification>
- All 4 Pest test files GREEN (scanner unit + backfill feature + page feature + aggregator unit)
- Architecture guardrails (env() + auto_create_status) GREEN — no new violations
- `php artisan route:list` includes `GET admin/ad-candidates`
- Full Pest suite has ZERO new failures vs 260607-hxa baseline (1,896 / 219 / 3)
- Grep confirms `competitor_prices` appears ZERO times in BackfillMerchantFeedCommand (drift-prevention proof)
- Tinker probe confirms SnapshotAggregator::computeAdCandidatesHealth returns the 3-key payload shape
</verification>

<success_criteria>
- Operator visits `/admin/ad-candidates` after deploy, picks one or more brands from the multi-select, sees the table recompute live, selects rows, downloads the SKU CSV — ready for ads.google.com bulk upload. End-to-end without leaving the admin chrome.
- Home dashboard's "Needs attention" section shows the Ad Candidates Ready tile with the current count + total margin potential.
- BackfillMerchantFeedCommand produces byte-identical output when run with `--skus=<list>` (operator override) and the same outcome shape when run without `--skus` (now narrowed to golden targets via the scanner).
- Future changes to the golden-target predicate only need to happen in `AdCandidateScanner::scan` — both the page AND the backfill command pick it up automatically.
- Phase 15 Google Ads API integration has a clean handoff: the placeholder action already exists, only the implementation swap is needed.
</success_criteria>

<output>
Create `.planning/quick/260607-pys-ad-candidates-filament-page-brand-filter/260607-pys-SUMMARY.md` when done, following the GSD summary template — capture the 5 atomic commits + the verify run output (full suite delta vs 260607-hxa baseline).
</output>
