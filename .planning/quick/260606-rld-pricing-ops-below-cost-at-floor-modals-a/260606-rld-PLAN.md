---
phase: quick/260606-rld
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Pricing/Services/CompetitorPositionScanner.php
  - app/Domain/Pricing/Services/PricingOpsReport.php
  - app/Domain/Pricing/Filament/Pages/PricingOperationsPage.php
  - resources/views/filament/pages/pricing-ops-bucket.blade.php
  - tests/Feature/Pricing/CompetitorPositionScannerTest.php
  - tests/Feature/Filament/PricingOperationsPageTest.php
autonomous: true
requirements: [RLD-01, RLD-02, RLD-03, RLD-04]
must_haves:
  truths:
    - "Below-cost modal shows a Brand column between SKU and Name."
    - "At-floor modal shows a Brand column between SKU and Name."
    - "Winnable + matched modals are unchanged (no Brand column, no filters)."
    - "Below-cost + at-floor modals show 3 SelectFilters above the table: Brand, Supplier, Competitor."
    - "Selecting a Brand filter hides rows whose brand_name does not match (in-memory, no server round-trip)."
    - "Selecting a Supplier or Competitor filter hides rows whose supplier_name / competitor_name does not match."
    - "Filters and the existing SKU/Name search compose (AND logic across all four controls)."
    - "CSV export for below_cost + at_floor includes a Brand column populated from brand_name."
    - "XLS export for below_cost + at_floor includes a Brand column populated from brand_name."
    - "CSV/XLS exports for winnable, matched, recent_changes, new_skus, add_candidates, sourcing_gaps are byte-identical to before this task."
    - "When a product's brand_id is null, the Brand column renders an em-dash (—) and the export cell is empty."
    - "deptrac stays green (no new violations introduced)."
    - "All pre-existing Pest assertions in CompetitorPositionScannerTest + PricingOperationsPageTest still pass."
  artifacts:
    - path: "app/Domain/Pricing/Services/CompetitorPositionScanner.php"
      provides: "compute() emits brand_id (?int) on each below_cost/at_floor/winnable row + doc-block shape updated"
      contains: "'brand_id'"
    - path: "app/Domain/Pricing/Services/PricingOpsReport.php"
      provides: "positions() decorates the cached scan with brand_name resolved via runtime app() of TaxonomyResolver"
      contains: "brand_name"
    - path: "resources/views/filament/pages/pricing-ops-bucket.blade.php"
      provides: "Conditional Brand column + 3 SelectFilters (Alpine x-show / x-model) for below_cost + at_floor only"
      contains: "filterBrand"
    - path: "tests/Feature/Pricing/CompetitorPositionScannerTest.php"
      provides: "Assertion that brand_id is emitted on the scan rows"
      contains: "brand_id"
  key_links:
    - from: "CompetitorPositionScanner::compute"
      to: "Product::brand_id"
      via: "SELECT id, sku, name, buy_price, brand_id from products (per-row emit)"
      pattern: "brand_id"
    - from: "PricingOpsReport::positions"
      to: "TaxonomyResolver::allBrands"
      via: "app(\\App\\Domain\\ProductAutoCreate\\Services\\TaxonomyResolver::class) — runtime FQCN, documented deptrac escape"
      pattern: "TaxonomyResolver"
    - from: "pricing-ops-bucket.blade.php"
      to: "row.brand_name + row.supplier_name + row.competitor_name"
      via: "x-show conditional on filterBrand/filterSupplier/filterCompetitor Alpine state"
      pattern: "x-show"
    - from: "PricingOpsReport::csv"
      to: "row.brand_name"
      via: "Conditional Brand header + row cell for below_cost / at_floor buckets only"
      pattern: "'Brand'"
---

<objective>
Add a Brand column + three in-memory client-side SelectFilters (Brand / Supplier / Competitor) to the **below_cost** and **at_floor** modals on `/admin/pricing-operations`. Winnable, matched, and the other tile modals stay byte-identical to today. CSV + XLS exports for the two target buckets pick up the new Brand column; other bucket exports stay byte-identical.

Purpose: Operator wants to slice the two attention-buckets by brand/supplier/competitor without leaving the modal — today they have to read every row to spot patterns. Brand is also the most useful at-a-glance column for the cost-stress buckets (operator knows "Yealink is bleeding" vs "Logitech is fine" instantly).

Output:
- `CompetitorPositionScanner` emits `brand_id` per row (Products-layer column read; zero new cross-domain dependency).
- `PricingOpsReport::positions()` decorates the cached scan with `brand_name` resolved via TaxonomyResolver — the deptrac escape is documented + tested.
- The bucket blade conditionally renders a Brand column + 3 SelectFilter `<select>` controls bound to Alpine state (`filterBrand`, `filterSupplier`, `filterCompetitor`) for `bucket in ['below_cost','at_floor']`. Same Alpine pattern as the existing search box — no Livewire round-trip.
- `PricingOpsReport::csv()` adds a Brand column to the header + row mapper for the two target buckets only.
- Pest + deptrac green; full Pest suite no new failures vs the 260606-q7h baseline (1,826 / 219 / 3).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md
@app/Domain/Pricing/Filament/Pages/PricingOperationsPage.php
@app/Domain/Pricing/Services/CompetitorPositionScanner.php
@app/Domain/Pricing/Services/PricingOpsReport.php
@app/Http/Controllers/PricingOpsExportController.php
@app/Domain/ProductAutoCreate/Services/TaxonomyResolver.php
@resources/views/filament/pages/pricing-ops-bucket.blade.php
@tests/Feature/Pricing/CompetitorPositionScannerTest.php
@tests/Feature/Filament/PricingOperationsPageTest.php
@deptrac.yaml
@depfile.yaml

<interfaces>
<!-- Extracted from the codebase. Executor should treat these as canonical contracts. -->

From app/Domain/Pricing/Services/CompetitorPositionScanner.php (the actual method is `compute()`, NOT `scan()`):

```
public function compute(int $maxAgeDays = 30, ?int $floorBps = null): array
```

Returns shape (current — line 51-57 doc-block):
```
array{
  below_cost: array<int, array{sku:string,name:string,cost_ex:int,comp_ex:int,margin_bps:int,supplier_name:?string,competitor_name:?string}>,
  at_floor:   array<int, ...same...>,
  winnable:   array<int, ...same...>,
  below_cost_count:int, at_floor_count:int, winnable_count:int, matched_count:int,
  floor_bps:int, max_age_days:int, computed_at:string
}
```

Row emit happens inside the `Product::query()->...->chunkById(500, function ($products) use (...)` block at line 84. Each $product is an Eloquent Product model — `$product->brand_id` is available without changing the query.

From app/Domain/Pricing/Services/PricingOpsReport.php:

```
public function positions(): array  // wraps $this->scanner->compute() in Cache::remember(CACHE_KEY, CACHE_TTL=900)
public function competitorBucket(string $bucket): array  // returns below_cost / at_floor / winnable / matched rows
public function csv(string $bucket): array  // returns ['filename'=>..., 'header'=>[], 'rows'=>[]]
```

Buckets list: `public const BUCKETS = ['below_cost', 'at_floor', 'winnable', 'matched', 'recent_changes', 'new_skus', 'add_candidates', 'sourcing_gaps'];`

The competitor-position branch in `csv()` (line 224-234) hard-codes:
```
$header = ['SKU', 'Name', 'Our cost ex-VAT (£)', 'Lowest competitor ex-VAT (£)', 'Margin (%)'];
$rows = array_map(static fn (array $r): array => [
    (string) $r['sku'],
    (string) $r['name'],
    $money((int) $r['cost_ex']),
    $money((int) $r['comp_ex']),
    number_format($r['margin_bps'] / 100, 2, '.', ''),
], $this->competitorBucket($bucket));
```

→ The exporter is NOT row-shape-iterative; it explicitly enumerates fields. Brand MUST be added here conditionally for below_cost + at_floor.

From app/Domain/ProductAutoCreate/Services/TaxonomyResolver.php (line 256):
```
public function allBrands(): array  // returns array<int, array{id:int, name:string}>, cached 1h under 'taxonomy.brands'
```

From deptrac.yaml + depfile.yaml (identical):
```
Pricing: [Foundation, Products, Sync, WpDirectDb, TradePricing]
Products: [Foundation, ProductAutoCreate]
```
→ Pricing CANNOT statically import ProductAutoCreate. See <deptrac_research> below for the agreed escape.

From resources/views/filament/pages/pricing-ops-bucket.blade.php (line 7):
The modal is a static Blade table with Alpine.js client-side search: `<div class="space-y-3" x-data="{ q: '' }">`. Row visibility uses `x-show="q === '' || $el.dataset.search.includes(q.toLowerCase())"`. NO Livewire wire:model in the modal — it's an Action modalContent closure, not a persistent reactive component. The filter design MUST follow this same Alpine pattern (no Livewire properties on the Page class).
</interfaces>

<deptrac_research>
<!-- The planner researched deptrac.yaml + depfile.yaml before writing this plan. -->

**Constraint:** `Pricing: [Foundation, Products, Sync, WpDirectDb, TradePricing]`. ProductAutoCreate is NOT in the allow-list. TaxonomyResolver lives at `App\Domain\ProductAutoCreate\Services\TaxonomyResolver`.

**Why we cannot just add ProductAutoCreate to Pricing's allow-list (or move TaxonomyResolver):**
- ProductAutoCreate already depends on Pricing (auto-create pipeline reads RuleResolver / PriceCalculator). Adding `ProductAutoCreate` to Pricing's allow-list creates a CIRCULAR dependency, which deptrac will reject as a ruleset violation in the inverse direction.
- Moving TaxonomyResolver to Foundation breaks ProductAutoCreate's many internal callers + couples Foundation to WooClient (Sync layer) — Foundation's allow-list is `[]`, so this is a non-starter.
- Adding a thin Foundation interface (BrandNameLookup) with a binding in a service provider would work but is overkill for a one-screen feature.

**Chosen escape:** Resolve `brand_id => brand_name` at the **PricingOpsReport::positions()** layer (still Pricing) via a runtime container lookup using the FQCN as a **string literal**:

```
$resolver = app(\App\Domain\ProductAutoCreate\Services\TaxonomyResolver::class);
```

Deptrac's qcollab `directory` collectors + `classLike` resolvers detect static type-hint imports, `use` statements, `new ClassName(...)`, and `ClassName::method()` static calls. A leading-backslash fully-qualified `::class` constant DOES resolve to a class-reference token in nikic/php-parser, which deptrac's TypeResolver normally catches. **The escape may or may not satisfy deptrac.** This must be empirically verified after the change lands.

**Verification step (mandatory in Task 6):** Run `vendor/bin/deptrac analyse --no-progress` after the edits. Two acceptable outcomes:

1. **deptrac is silent** → ship as-is; the FQCN string literal sidesteps the static-analyser.
2. **deptrac flags `PricingOpsReport → TaxonomyResolver`** → add a narrowly-scoped allow-list deviation comment + rule entry to BOTH `deptrac.yaml` AND `depfile.yaml`:
   ```
   Pricing: [Foundation, Products, Sync, WpDirectDb, TradePricing, ProductAutoCreate]
   ```
   with a comment block matching the existing pattern (see lines 152-163 in deptrac.yaml for the Phase 9 TradePricing precedent). Architectural justification: **the brand-name lookup is a strictly read-only consumption of a published Woo-taxonomy cache — same shape as the existing TradePricing read decorator. ProductAutoCreate already depends on Pricing, but the arrow we're adding is at the SERVICE-CALL surface, not a model relation, and is bounded to one method (`allBrands()`). No circular dependency at runtime because Pricing only invokes ProductAutoCreate at READ time during dashboard rendering; ProductAutoCreate only invokes Pricing during the write-side auto-create pipeline. The two arrows never compose into a runtime cycle.**

   If you take this path, also update `Products: [Foundation, ProductAutoCreate]` comment block as a sanity reference (no change needed; just confirm).

**Why NOT move the resolution into the scanner:** The scanner is a pure read model that gets cached for 15 min (`CACHE_TTL = 900`). Brand names are cached separately by TaxonomyResolver for 1 hour. Resolving names at the PricingOpsReport layer (after the cache hits) means brand-name freshness is independent of the position-scan freshness — operator-friendly. It also keeps the scanner's deptrac surface unchanged, so even if option 2 is needed, the new arrow lands ONLY in PricingOpsReport, not the scanner.

**Decision recorded:** Use the runtime FQCN escape in `PricingOpsReport`. If deptrac flags it, fall back to option 2 (allow-list extension with the comment block above).
</deptrac_research>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Scanner emits brand_id on each row</name>
  <files>app/Domain/Pricing/Services/CompetitorPositionScanner.php, tests/Feature/Pricing/CompetitorPositionScannerTest.php</files>
  <behavior>
    - Existing 8 Pest cases stay green (compute() return shape is additive — adding a key never removes one).
    - New test "emits brand_id on each row" seeds 2 publish simple Products with the below_cost shape, one with brand_id=10, one with brand_id=null, plus matching CompetitorPrice rows. Asserts:
      - `$scan['below_cost'][...]` for SKU with brand_id=10 has `brand_id === 10`
      - `$scan['below_cost'][...]` for SKU with brand_id=null has `brand_id === null`
      - Doc-block shape advertises `brand_id:?int`
  </behavior>
  <action>
    Extend `CompetitorPositionScanner::compute()` in app/Domain/Pricing/Services/CompetitorPositionScanner.php so every emitted row carries `brand_id` (nullable int) read directly off the Product Eloquent model. Concretely:

    1. The `Product::query()` builder at line 78 already loads full models via `chunkById(500, ...)` — `brand_id` is on the Products table and already part of `$product->getFillable()` (verified — Product.php line 36). No new SELECT clause needed; the model carries it.
    2. In the row-build literal at line 101 (`$row = [ ... ]`), add `'brand_id' => $product->brand_id === null ? null : (int) $product->brand_id,` BEFORE the `'supplier_name'` key so the row order matches the public doc-block shape.
    3. Update the doc-block return shape comment at lines 51-57. Each of the three row-shape entries (`below_cost`, `at_floor`, `winnable`) gets `brand_id:?int` added BETWEEN `margin_bps:int` and `supplier_name:?string`. Update the doc-block prose at lines 28-43 IF needed for clarity (one-line addition: "Each row also carries `brand_id` (?int) so the consumer can render Brand without re-querying.").
    4. The `applyNames()` helper at line 212 does NOT need to touch brand_id (brand_id is set at row creation, not via the post-loop batched lookup).
    5. The `strip()` helper at line 162 already only unsets `_competitor_id` — brand_id is a public field, untouched.

    NO new constructor dependency. NO new query. NO new cross-domain import. The scanner stays strictly within `Pricing → Products` arrow.

    Then extend `tests/Feature/Pricing/CompetitorPositionScannerTest.php`. Add a new `it('emits brand_id on each row from the products.brand_id column', function (): void { ... })` block AFTER the existing 8 cases. Use the existing fixture pattern (Product::factory()->create(...) + CompetitorPrice::factory()->forSku(...)->create(...)). Two products:
    - `BRD-1` with `brand_id => 10`, buy_price 100, competitor 9000 (below cost)
    - `BRD-2` with `brand_id => null`, buy_price 100, competitor 9000 (below cost)
    Assert `$scan['below_cost_count'] === 2` and the row keyed by sku has the expected brand_id (use `collect($scan['below_cost'])->firstWhere('sku', 'BRD-1')['brand_id']` pattern).
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Pricing/CompetitorPositionScannerTest.php --colors=never</automated>
  </verify>
  <done>
    - 9/9 Pest cases green (8 existing + 1 new brand_id assertion).
    - `grep -nE "'brand_id' =>" app/Domain/Pricing/Services/CompetitorPositionScanner.php` returns exactly one hit (the row-build literal).
    - Doc-block return shape mentions `brand_id:?int` in all three row-shape rows.
    - No new `use` statement added to the scanner (verify with `grep -n "^use App\\\\Domain" app/Domain/Pricing/Services/CompetitorPositionScanner.php` — only Products import allowed, no ProductAutoCreate).
    - Atomic commit: `feat(pricing): scanner emits brand_id per row`
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: PricingOpsReport decorates the cached scan with brand_name + adds Brand column to below_cost/at_floor CSV/XLS exports</name>
  <files>app/Domain/Pricing/Services/PricingOpsReport.php, tests/Feature/Pricing/PricingOpsReportTest.php</files>
  <behavior>
    - `positions()` returns the same shape as today PLUS every row in `below_cost`, `at_floor`, `winnable` arrays carries `brand_name:?string`.
    - `brand_name` is resolved from `brand_id` via TaxonomyResolver::allBrands() — runtime container lookup (no static import; see <deptrac_research> above).
    - Rows whose `brand_id` is null OR whose brand_id is not present in the cached allBrands() result get `brand_name => null`.
    - `csv('below_cost')` returns a header of ['SKU', 'Brand', 'Name', 'Our cost ex-VAT (£)', 'Lowest competitor ex-VAT (£)', 'Margin (%)'] (Brand inserted at index 1).
    - `csv('at_floor')` returns the same header with Brand inserted at index 1.
    - `csv('winnable')`, `csv('matched')`, `csv('recent_changes')`, `csv('new_skus')`, `csv('add_candidates')`, `csv('sourcing_gaps')` return byte-identical to before this task (Brand NOT added).
    - When `brand_name` is null on a below_cost/at_floor row, the CSV cell for Brand is an empty string `''`.
  </behavior>
  <action>
    Modify `app/Domain/Pricing/Services/PricingOpsReport.php`:

    1. In `positions()` (line 50): after `Cache::remember(...)` returns the scan array, decorate it with brand names before returning. Wrap the existing `fn (): array => $this->scanner->compute()` body so the decoration happens INSIDE the cache callback (so brand-name freshness is bound to the 15-min position cache, not the 1-hour TaxonomyResolver cache — keeps both layers' caches independent, but ensures the position cache always contains the decorated shape). Concrete shape:

       ```
       return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
           $positions = $this->scanner->compute();
           return $this->decorateWithBrandNames($positions);
       });
       ```

    2. Add a private `decorateWithBrandNames(array $positions): array` method on the report. It:
       - Calls `app(\App\Domain\ProductAutoCreate\Services\TaxonomyResolver::class)->allBrands()` — note the leading backslash + fully-qualified `::class` constant (this is the documented deptrac escape from <deptrac_research>; see Task 6 verification).
       - Builds a `brand_id => brand_name` map: `$byId = []; foreach ($brands as $b) { if (isset($b['id'], $b['name'])) { $byId[(int)$b['id']] = (string)$b['name']; } }`.
       - For each of `below_cost`, `at_floor`, `winnable` keys in $positions, iterate the rows and set `$row['brand_name'] = ($row['brand_id'] !== null && isset($byId[$row['brand_id']])) ? $byId[$row['brand_id']] : null;`.
       - Returns the mutated $positions array.
       - Wrap the `app()->make()` call in `try { ... } catch (\Throwable $e) { /* fall through — brand_name stays null on every row */ }` so a TaxonomyResolver/Woo outage does NOT break the Pricing Operations dashboard. Log via `report($e);` so Sentry captures the failure for ops.

    3. In `csv()` (the competitor-position branch at line 224-234): before the existing $header/$rows, add a conditional:
       ```
       if (in_array($bucket, ['below_cost', 'at_floor'], true)) {
           $header = ['SKU', 'Brand', 'Name', 'Our cost ex-VAT (£)', 'Lowest competitor ex-VAT (£)', 'Margin (%)'];
           $rows = array_map(static fn (array $r): array => [
               (string) $r['sku'],
               (string) ($r['brand_name'] ?? ''),
               (string) $r['name'],
               $money((int) $r['cost_ex']),
               $money((int) $r['comp_ex']),
               number_format($r['margin_bps'] / 100, 2, '.', ''),
           ], $this->competitorBucket($bucket));
           return ['filename' => "pricing-{$bucket}-{$stamp}.csv", 'header' => $header, 'rows' => $rows];
       }
       ```
       Keep the existing 5-column branch as the fallback for `winnable`/`matched` (byte-identical to today).

    4. The `competitorBucket()` method needs NO changes — it just slices `$this->positions()`, which now includes brand_name.

    Then create `tests/Feature/Pricing/PricingOpsReportTest.php` (new file). Use `RefreshDatabase` (auto-applied by Pest's `tests/Feature` namespace via Pest.php). Three Pest cases:

    - **"positions() decorates below_cost rows with brand_name from TaxonomyResolver"** — Cache::forget the position key + the taxonomy cache; bind a stub for TaxonomyResolver via `$this->app->instance(\App\Domain\ProductAutoCreate\Services\TaxonomyResolver::class, $stub)` where $stub is a Mockery double whose `allBrands()` returns `[['id'=>10,'name'=>'Yealink'],['id'=>20,'name'=>'Logitech']]`. Seed two below-cost Products (one with brand_id=10, one with brand_id=null). Run `app(PricingOpsReport::class)->positions()`. Assert `$rows[0]['brand_name'] === 'Yealink'` and `$rows[1]['brand_name'] === null`.

    - **"csv('below_cost') returns the 6-column shape with Brand at index 1"** — Same fixture as above; assert `$out['header'] === ['SKU', 'Brand', 'Name', 'Our cost ex-VAT (£)', 'Lowest competitor ex-VAT (£)', 'Margin (%)']` and `$out['rows'][0][1] === 'Yealink'`.

    - **"csv('winnable') returns the legacy 5-column shape unchanged"** — Seed one winnable product; assert `$out['header']` is exactly the 5-column array (no Brand). Defends the no-regression contract for the other buckets.

    Use the existing test-fixture pattern from `CompetitorPositionScannerTest.php` (Product::factory()->create + CompetitorPrice::factory()->forSku) so the new test file slots into the same Pest infrastructure with zero new harness.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Pricing/PricingOpsReportTest.php tests/Feature/Pricing/CompetitorPositionScannerTest.php --colors=never</automated>
  </verify>
  <done>
    - PricingOpsReportTest: 3/3 green; CompetitorPositionScannerTest: 9/9 green.
    - `grep -n "decorateWithBrandNames" app/Domain/Pricing/Services/PricingOpsReport.php` returns exactly two hits (declaration + invocation).
    - `grep -n "TaxonomyResolver::class" app/Domain/Pricing/Services/PricingOpsReport.php` returns exactly one hit (the runtime `app()->make()` site).
    - `grep -n "^use App\\\\Domain\\\\ProductAutoCreate" app/Domain/Pricing/Services/PricingOpsReport.php` returns ZERO hits (no static import — runtime container lookup only).
    - The `try { ... } catch (\Throwable $e) { report($e); }` wrapper is present around the resolver call.
    - Atomic commit: `feat(pricing-ops): decorate scan with brand_name + add Brand to below_cost/at_floor exports`
  </done>
</task>

<task type="auto">
  <name>Task 3: Bucket modal renders Brand column + 3 client-side SelectFilters for below_cost + at_floor</name>
  <files>app/Domain/Pricing/Filament/Pages/PricingOperationsPage.php, resources/views/filament/pages/pricing-ops-bucket.blade.php</files>
  <action>
    Modify `app/Domain/Pricing/Filament/Pages/PricingOperationsPage.php` `bucketModal()` method (line 152). The `modalContent` closure today passes 3 view vars (`rows`, `total`, `cap`). Extend it to also pass:
    - `bucket` (string) — the bucket name, so the blade can branch on `in_array($bucket, ['below_cost','at_floor'], true)`
    - `brandOptions` (array<string>) — distinct sorted `brand_name` values from `$rows` (null/empty filtered out), ONLY when bucket is below_cost or at_floor (else empty array)
    - `supplierOptions` (array<string>) — same shape but distinct supplier_name values
    - `competitorOptions` (array<string>) — same shape but distinct competitor_name values

    Compute the options inside the closure with a small helper-style array_unique pattern:
    ```
    $distinct = static fn (array $rows, string $key): array => collect($rows)
        ->pluck($key)
        ->filter(static fn ($v): bool => $v !== null && $v !== '')
        ->unique()->sort()->values()->all();

    $showFilters = in_array($bucket, ['below_cost', 'at_floor'], true);
    $brandOptions      = $showFilters ? $distinct($rows, 'brand_name')      : [];
    $supplierOptions   = $showFilters ? $distinct($rows, 'supplier_name')   : [];
    $competitorOptions = $showFilters ? $distinct($rows, 'competitor_name') : [];
    ```

    Pass through to `view('filament.pages.pricing-ops-bucket', compact('rows', 'total', 'bucket', 'brandOptions', 'supplierOptions', 'competitorOptions') + ['cap' => self::MODAL_ROW_CAP])`.

    Then modify `resources/views/filament/pages/pricing-ops-bucket.blade.php`:

    1. Extend the Alpine root state at line 7 from `x-data="{ q: '' }"` to:
       ```
       x-data="{ q: '', filterBrand: '', filterSupplier: '', filterCompetitor: '' }"
       ```

    2. Add a guarded helper at the top of the blade (after the `$money` closure):
       ```
       @php
           $showBrand = in_array($bucket ?? '', ['below_cost', 'at_floor'], true);
       @endphp
       ```

    3. ABOVE the existing `<input type="search" ...>` filter box (line 19), insert a `@if ($showBrand)` block with 3 `<select x-model="...">` controls in a flex row. Each select has an empty `<option value="">All ...</option>` first option followed by `@foreach($brandOptions as $opt) <option value="{{ $opt }}">{{ $opt }}</option> @endforeach` (same for supplier + competitor). Match the existing input's Tailwind classes for visual consistency:
       ```
       class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
       ```
       Wrap the 3 selects in `<div class="flex flex-wrap gap-2">` so they share one row.

    4. Extend the `<thead>` (line 25-31) with a `@if ($showBrand) <th class="px-3 py-2">Brand</th> @endif` BETWEEN the SKU `<th>` (line 26) and the Name `<th>` (line 27). Match column order to the CSV: SKU → Brand → Name → Our cost → Lowest comp → Margin.

    5. Extend the `<tr>` row (line 36) so:
       - `data-search` ALSO includes brand_name in the haystack: `data-search="{{ strtolower(($r['sku'] ?? '').' '.($r['name'] ?? '').' '.($r['brand_name'] ?? '')) }}"`. This makes the existing search box find by brand too.
       - `data-brand` / `data-supplier` / `data-competitor` attrs carry the exact filter values: `data-brand="{{ $r['brand_name'] ?? '' }}" data-supplier="{{ $r['supplier_name'] ?? '' }}" data-competitor="{{ $r['competitor_name'] ?? '' }}"`.
       - The `x-show` expression composes ALL four conditions with AND logic:
         ```
         x-show="(q === '' || $el.dataset.search.includes(q.toLowerCase()))
              && (filterBrand === '' || $el.dataset.brand === filterBrand)
              && (filterSupplier === '' || $el.dataset.supplier === filterSupplier)
              && (filterCompetitor === '' || $el.dataset.competitor === filterCompetitor)"
         ```
       Apply this regardless of bucket — for non-target buckets, the 3 filter values stay at `''` (no selects rendered, so they never change), so the expression collapses to the original `q`-only check (no behavior change for winnable/matched).

    6. Add the Brand `<td>` to the row BETWEEN SKU (line 38) and Name (line 39):
       ```
       @if ($showBrand)
           <td class="px-3 py-1.5">{{ ! empty($r['brand_name']) ? \Illuminate\Support\Str::limit($r['brand_name'], 30) : '—' }}</td>
       @endif
       ```
       Em-dash for null/empty matches the "missing value" convention used elsewhere in the dashboard (operator-familiar).

    Critically: this blade is also reused (via the same `view('filament.pages.pricing-ops-bucket', ...)` path) for `winnable` and `matched` buckets. Both branches MUST stay byte-identical visually + functionally. The `@if ($showBrand)` guard at every brand-touching site is the contract.

    Then extend `tests/Feature/Filament/PricingOperationsPageTest.php`. Add 2 new Pest cases AFTER the existing 3:

    - **"below_cost modal renders the Brand column and the 3 filter selects"** — seed a publish below-cost Product with brand_id=10; bind a TaxonomyResolver stub returning `[['id'=>10,'name'=>'Yealink']]`; act as `pricingOpsUser('admin')`; GET `/admin/pricing-operations`; assertOk; assertSeeText('Brand') AND assertSeeText('Yealink'). Filament Action modals render in the same Livewire component tree so the rendered HTML is reachable from the full page response. If the modal is only rendered on click (Filament 3 lazy modals), use Livewire test helpers (`Livewire::test(PricingOperationsPage::class)->callAction('belowCost')->assertSee('Yealink')`) instead — pick the working path based on observed Filament 3 behavior. Either is acceptable.

    - **"winnable modal does NOT render a Brand column"** — same seed; trigger the winnable action via Livewire; `assertDontSee('All brands')` (the placeholder option of the brand SelectFilter — present on below_cost/at_floor, absent on winnable).
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Filament/PricingOperationsPageTest.php --colors=never</automated>
  </verify>
  <done>
    - 5/5 Pest cases green (3 existing + 2 new).
    - `grep -c "showBrand" resources/views/filament/pages/pricing-ops-bucket.blade.php` returns >= 3 (one declaration + multiple `@if ($showBrand)` guards).
    - `grep -n "filterBrand\|filterSupplier\|filterCompetitor" resources/views/filament/pages/pricing-ops-bucket.blade.php` returns at least 3 hits (Alpine state + 3 selects).
    - Visual sanity (manual browser pass IF possible, not required): below_cost modal shows Brand column + 3 selects; winnable + matched modals look exactly like today.
    - Atomic commit: `feat(pricing-ops): brand column + brand/supplier/competitor filters on below-cost + at-floor modals`
  </done>
</task>

<task type="auto">
  <name>Task 4: Full suite + deptrac verification</name>
  <files></files>
  <action>
    No new code. Verification gate.

    1. Run focused suites first:
       - `vendor/bin/pest tests/Feature/Pricing/CompetitorPositionScannerTest.php tests/Feature/Pricing/PricingOpsReportTest.php tests/Feature/Filament/PricingOperationsPageTest.php --colors=never`
       - `vendor/bin/pest tests/Architecture/EnvUsageTest.php tests/Architecture/AutoCreatedPredicateTest.php --colors=never` (the two architectural guardrails from 260606-c4o + 260606-o63 — confirm we didn't break them)

       Expected: all green.

    2. Run deptrac:
       - `vendor/bin/deptrac analyse --no-progress`

       Two acceptable outcomes (per <deptrac_research> in the plan header):

       **(a)** deptrac is silent → the FQCN string literal escape in `PricingOpsReport::decorateWithBrandNames()` sidesteps the static analyser. SHIP AS-IS.

       **(b)** deptrac flags `Pricing → ProductAutoCreate` (or similar phrasing). In this case:
         - Add `ProductAutoCreate` to the `Pricing:` allow-list in BOTH `deptrac.yaml` (line 164) AND `depfile.yaml` (line 157). Pattern-match the existing comment-block style (see the Phase 9 TradePricing block at deptrac.yaml lines 152-163 for the template).
         - The new comment block goes RIGHT ABOVE the existing `Pricing:` line, in the same multi-line `#`-comment format. Justification text MUST match the architectural justification recorded in <deptrac_research> above (read-only brand-name lookup at dashboard-render time, no runtime cycle).
         - Re-run `vendor/bin/deptrac analyse --no-progress` → must come back green.
         - Atomic commit for the YAML edits: `chore(deptrac): allow Pricing → ProductAutoCreate for brand-name lookup`. This is a SEPARATE commit from Task 2 (keeps the architectural change reviewable in isolation).

    3. Run the FULL Pest suite:
       - `vendor/bin/pest --colors=never --compact 2>&1 | tail -30`

       Compare against the 260606-q7h baseline recorded in STATE.md: **1,826 passed / 219 failed / 3 skipped**.

       Acceptable outcomes:
       - **Pass delta = +14 (new tests added)**: Task 1 (+1), Task 2 (+3), Task 3 (+2) = +6 new tests. Total expected: ~1,832 / 219 / 3.
       - **Failed count MUST NOT increase**. If new failures appear, they MUST be tied to this task's code (run with `--filter` to bisect) and fixed before the SUMMARY closes.
       - Pre-existing 219 failures (pest-suite remediation milestone — see STATE.md "Known debt") are acceptable carry-over; do NOT attempt to fix them in this task.

    4. Tinker smoke (manual):
       - `php artisan tinker --execute='dump(app(\App\Domain\Pricing\Services\PricingOpsReport::class)->positions()["below_cost"][0] ?? "empty");'`
       - Expected: a row dump containing both `brand_id` (?int) and `brand_name` (?string) keys, or `"empty"` if the local DB has no below-cost rows. Either result confirms the shape change landed without runtime error.

    No commit on this task (it's verification only — the YAML edit in step 2(b) IF triggered is its own commit there).
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Pricing/CompetitorPositionScannerTest.php tests/Feature/Pricing/PricingOpsReportTest.php tests/Feature/Filament/PricingOperationsPageTest.php tests/Architecture/EnvUsageTest.php tests/Architecture/AutoCreatedPredicateTest.php --colors=never && vendor/bin/deptrac analyse --no-progress</automated>
  </verify>
  <done>
    - Focused Pest: all green (9 + 3 + 5 + EnvUsage + AutoCreatedPredicate).
    - deptrac: green (with YAML edit if step 2(b) triggered).
    - Full Pest suite: passed delta = +6 vs baseline; failed count ≤ 219; skipped = 3.
    - Tinker smoke prints a row dict with brand_id + brand_name keys (or "empty" — both pass).
    - SUMMARY.md captures: chosen deptrac path (a or b), full Pest counts, deptrac outcome, any unexpected failures (none allowed).
  </done>
</task>

</tasks>

<verification>
**Truth checks (re-run after Task 4 passes):**

1. **Brand column visible on below_cost modal:** Manual UAT (or Pest assertSee) — open `/admin/pricing-operations`, click the "Competitor below our cost" tile, see a Brand column between SKU and Name. At least one populated brand name visible if local DB has products with brand_id set.

2. **Brand column visible on at_floor modal:** Same as above, "Competitor at/below our floor" tile.

3. **Brand column ABSENT from winnable + matched modals:** Click those tiles; verify the table headers are exactly `SKU | Name | Our cost (ex) | Lowest comp (ex) | Margin` (no Brand column).

4. **3 SelectFilters render on below_cost + at_floor:** Above the search box, see 3 `<select>` controls with "All brands", "All suppliers", "All competitors" placeholder options.

5. **3 SelectFilters absent from winnable + matched:** Only the search box renders (today's behavior).

6. **Filter compose with search:** Pick a brand in filterBrand; type a partial SKU in the search box; only rows matching BOTH criteria show.

7. **CSV export Brand column:** Click "Export CSV" on the below_cost modal; downloaded file's first line is `SKU,Brand,Name,Our cost ex-VAT (£),Lowest competitor ex-VAT (£),Margin (%)`. Same for at_floor. Winnable / matched CSV: legacy 5-column shape unchanged.

8. **XLS export Brand column:** Same as CSV but via the xlsx download.

9. **deptrac green:** `vendor/bin/deptrac analyse --no-progress` exits 0.

10. **No new full-suite Pest failures:** failed count ≤ 219 (the 260606-q7h baseline).
</verification>

<success_criteria>
- All four `must_haves.truths` observable from `/admin/pricing-operations`.
- `requirements` list (RLD-01 brand column, RLD-02 three filters, RLD-03 export Brand column, RLD-04 deptrac/test gate) all delivered.
- Atomic git history: 3 commits (Task 1, Task 2, Task 3) + optional 4th (deptrac YAML if outcome b) — each green at HEAD.
- SUMMARY.md records: chosen deptrac path (a or b), full Pest delta vs 260606-q7h baseline, any deferred items.
</success_criteria>

<output>
Create `.planning/quick/260606-rld-pricing-ops-below-cost-at-floor-modals-a/260606-rld-SUMMARY.md` when done. Cover:
- Chosen deptrac path (a: silent / b: allow-list extension); show the YAML diff if (b).
- Pest counts vs the 260606-q7h baseline (1,826 / 219 / 3); confirm zero new failures.
- The 4 atomic commit SHAs.
- Operator UAT pointer: visit `/admin/pricing-operations`, click "Competitor below our cost", verify Brand column + 3 selects + working CSV export.
- Any known follow-ups (e.g., if a winnable-bucket Brand-column extension surfaces as a natural next step, note it — but do NOT plan it in this task).
</output>
