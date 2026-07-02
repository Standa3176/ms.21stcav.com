---
phase: 260702-hg1-brands-to-add-ui-filament-brands-to-add-
plan: 01
subsystem: ProductAutoCreate / Suggestions (Filament UI)
tags: [filament, brands, woocommerce, suggestions, operator-ui, quick-task]
requires:
  - "260702-h50 — suggestions.brands_to_add cache + evidence.brand/brand_on_woo tags"
provides:
  - "/admin/brands-to-add operator page with one-click Create-on-Woo (products/brands)"
  - "SuggestionResource Competitor / Brand / Brand-on-Woo filters"
affects:
  - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
tech-stack:
  added: []
  patterns:
    - "Filament custom Page reading a cached array (not Eloquent) — mirrors CategoryAuditPage skeleton"
    - "Driver-portable JSON expression helpers (MariaDB vs SQLite) centralised as static methods"
    - "WooClient anon-subclass recording stub bound via app()->instance() for feature tests"
key-files:
  created:
    - app/Domain/ProductAutoCreate/Filament/Pages/BrandsToAddPage.php
    - resources/views/filament/pages/brands-to-add.blade.php
    - tests/Feature/Filament/Pages/BrandsToAddPageTest.php
    - tests/Feature/Filament/Resources/SuggestionBrandFilterTest.php
  modified:
    - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
decisions:
  - "Create-on-Woo writes ONLY to products/brands (WC-native taxonomy); never product_brand (publish owns the storefront link)"
  - "Made the plan's raw MariaDB JSON SQL driver-portable so the SQLite test suite stays green AND prod-safe (SQLite↔MariaDB strict trap)"
  - "createBrand() gated with abort_unless(403) AND blade @if button-hide — defence in depth"
metrics:
  duration: ~20 min
  completed: 2026-07-02
  tasks: 2
  files: 5
  commits: 2 (feat) + 1 (docs)
---

# Quick Task 260702-hg1: Brands to Add operator UI Summary

Piece 2 of the operator-confirmed "Brands to Add" workflow (Piece 1 = 260702-h50). Builds the
Filament operator UI on top of the cache + evidence tags that Piece 1 produced: a **Brands to Add**
page listing every brand that appears on pending `new_product_opportunity` suggestions but is **not
yet on Woo**, each with a one-click **Create on Woo**, plus **Competitor / Brand / Brand-on-Woo**
filters on the Suggestions list.

## What was built

### Task 1 — Brands to Add page + Create-on-Woo + Refresh (commit `5b9a779`)

- **`app/Domain/ProductAutoCreate/Filament/Pages/BrandsToAddPage.php`** — a Filament custom `Page`
  (NOT a Resource; the data is a cached array, not Eloquent). Slug `brands-to-add`, nav group
  `Catalogue`, sort 16, auto-discovered by `AdminPanelProvider`'s existing
  `App\Domain\ProductAutoCreate\Filament\Pages` discovery path (verified at line 200 of
  AdminPanelProvider). Mirrors `CategoryAuditPage` for the page/RBAC/nav skeleton.
  - `mount()` hydrates public `$brands` (sorted count-desc) + `$generatedAt` from
    `Cache::get('suggestions.brands_to_add')` (null-safe empty state when never refreshed).
  - `createBrand(string $brand): void` — `abort_unless(hasAnyRole([admin,pricing_manager]), 403)`
    → `app(WooClient::class)->post('products/brands', ['name' => $brand])` → `Cache::forget('taxonomy.brands')`
    → removes the brand from BOTH the cached summary AND `$this->brands` (the row disappears without a
    full refresh) → success `Notification`. `woocommerce_term_exists` / "already exists" is caught
    gracefully and treated as an info-success (the brand IS on Woo, which is all Create-on-Woo
    guarantees). Any other `\Throwable` → danger notification with the message.
  - `refresh` header action → `Artisan::queue('products:refresh-brands-to-add', [])` (queued so it
    does not block) + "Refresh queued" notification. Gated `visible`/`authorize` to admin+pricing_manager.
- **`resources/views/filament/pages/brands-to-add.blade.php`** — renders a table of
  brand | products-it-would-unlock | sample SKUs, each with a `wire:click="createBrand(...)"`
  button hidden for non-writers via `@if(hasAnyRole([...]))`, the `generated_at` line, and an
  empty state.
- **RBAC:** `canAccess` / `shouldRegisterNavigation` → `hasAnyRole(['admin','pricing_manager'])`;
  `createBrand` + `refresh` gated the same way. sales / read_only cannot mount or create.

### Task 2 — Suggestions filters: Competitor, Brand, Brand-on-Woo (commit `ec3070f`)

Three filters added to `SuggestionResource::table()->filters()`, rendered AboveContent alongside
the existing filters:

- **Competitor** `SelectFilter` — options = `Competitor::orderBy('name')->pluck('name','name')`;
  matches any `evidence.competitor_sightings[].name`.
- **Brand** `SelectFilter` — options = distinct `evidence.brand` tagged on pending
  `new_product_opportunity` rows, `searchable()`.
- **Brand on Woo** `TernaryFilter` — reads `evidence.brand_on_woo` (true = Ready, brand on Woo;
  false = Needs brand added).

## RBAC + creation semantics

- Page + Create/Refresh actions gated to **admin + pricing_manager** (mirrors
  CategoryAuditPage / ImportIssueResource). sales / read_only get 403.
- **Create-on-Woo writes ONLY to `products/brands`** — the WC-native brands taxonomy that
  `TaxonomyResolver::allBrands()` (and therefore the create-filter) reads. It does **NOT** touch
  `product_brand`; publish handles the storefront brand link. Strictly **operator-triggered per
  brand — no auto-create, no Claude spend.**

## Operator flow

1. Prime the data (Piece 1): `php artisan products:refresh-brands-to-add` — tags suggestions +
   fills the `suggestions.brands_to_add` cache.
2. `/admin/brands-to-add` lists the missing brands + how many pending products each would unlock +
   sample SKUs (newest-refresh info shown).
3. Click **Create on Woo** per brand → the term is added to `products/brands`, the
   `taxonomy.brands` cache is forgotten, and the row disappears.
4. Re-run `products:refresh-brands-to-add` (or use the **Refresh list** header action) to rebuild,
   then `products:draft-from-suggestions` (or the Suggestions bulk action) to create the
   newly-unblocked products.
5. The Suggestions list now filters by **Competitor**, **Brand**, and **Brand on Woo** (Ready vs
   Needs brand added).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Made the filters' JSON SQL driver-portable (SQLite↔MariaDB strict trap)**
- **Found during:** Task 2 — the Task 2 test failed with `SQLSTATE[HY000]: General error: 1 no
  such function: JSON_UNQUOTE`.
- **Issue:** The plan's `<interfaces>` prescribed raw MariaDB JSON SQL (`JSON_UNQUOTE`,
  `JSON_SEARCH(... '$.competitor_sightings[*].name')`, `JSON_EXTRACT(...) = true`). Prod is MariaDB
  but the Pest suite runs on SQLite, which has none of those functions and returns `1`/`0` (not
  `true`/`false`) for JSON booleans. Rendering the Suggestions list page 500'd on SQLite because the
  Brand `options()` closure eager-evaluates the query.
- **Fix:** Added centralised static helper methods (`brandJsonExpr`, `brandOnWooJsonExpr`,
  `jsonTrueLiteral`, `jsonFalseLiteral`) that switch on `DB::connection()->getDriverName()`, and gave
  the Competitor filter an SQLite `json_each()` branch alongside the MariaDB `JSON_SEARCH` branch —
  matching the existing driver-switch pattern in `Suggestion::scopeHighConfidenceSourceable`. Prod
  MariaDB SQL semantics are unchanged; the suite now passes on SQLite. (Aligns with the known
  "SQLite↔MariaDB strict trap" — green tests must stay prod-safe.)
- **Files modified:** `app/Domain/Suggestions/Filament/Resources/SuggestionResource.php`
- **Commit:** `ec3070f`

**2. [Rule 3 - Blocking] `pluck(DB::raw(...))` needs an alias**
- **Found during:** Task 2 — `Undefined property: stdClass::$...brand'` when plucking the raw
  brand expression.
- **Issue:** `->pluck(DB::raw($brandExpr))` uses the raw SQL string as the result-column name.
- **Fix:** Aliased the expression: `->pluck(DB::raw($brandExpr.' as brand'))`.
- **Commit:** `ec3070f`

**3. [Test-shape] `createBrand` 403 asserted on a page instance, not via Livewire `->call()`**
- Livewire's `->call()` snapshot machinery mangles the `abort(403)` HttpException into an internal
  `ErrorException` ("Trying to access array offset on null"), so the RBAC test invokes
  `(new BrandsToAddPage)->createBrand('Trantec')` directly and asserts the `HttpException` status
  is 403 + that the recording WooClient stub recorded zero posts. Pure test-shape choice; no
  production behaviour change. (Commit `5b9a779`.)

## Verification

- `pest tests/Feature/Filament/Pages/BrandsToAddPageTest.php` → **7 passed (20 assertions)** GREEN.
- `pest tests/Feature/Filament/Resources/SuggestionBrandFilterTest.php` → **3 passed (12 assertions)** GREEN.
- `pest tests/Feature/Filament/Resources/ tests/Feature/Filament/Pages/` → **37 passed (112 assertions), 0 regressions** GREEN.
- Broader Suggestion regression (`SuggestionInboxTest` + `SuggestionResourceQueryCountTest`) green.
- `artisan route:list | grep brands-to-add` → `GET|HEAD admin/brands-to-add filament.admin.pages.brands-to-add` present.
- `pint --test` on all 4 changed/new source + test files → **PASS**.
- **Create-on-Woo posts only `products/brands`** — asserted in the feature test: the recording
  WooClient stub records exactly one `post('products/brands', ['name' => 'Trantec'])` and nothing
  else; operator-triggered via the `createBrand` Livewire action.

## Not done (out of scope / by design)

- No push / deploy — stopped after local commit (per instructions).
- No scheduled cron for Create-on-Woo (operator-triggered per brand by design).
- No `product_brand` write (publish owns the storefront link).

## Self-Check: PASSED

- Files exist: BrandsToAddPage.php, brands-to-add.blade.php, BrandsToAddPageTest.php,
  SuggestionBrandFilterTest.php, SuggestionResource.php (modified) — all present.
- Commits exist: `5b9a779` (Task 1 feat), `ec3070f` (Task 2 feat) — both in `git log`.
