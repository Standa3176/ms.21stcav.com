---
phase: 260707-iz9-suggestions-tier-2-add-a-readiness-selec
plan: 01
subsystem: Suggestions (Filament admin)
tags: [filament, suggestions, filter, sqlite-mariadb-portability, ux]
requires:
  - 260707-gsy readiness() column (the verdict this filter must match)
  - product_auto_create.brands_to_add_exclude (junk-brand config)
  - supplier_sku_cache (sourceable membership)
provides:
  - SuggestionResource Readiness SelectFilter (ready / needs_brand / not_sourceable)
  - SuggestionResource::sourceableExistsSql() driver-portable EXISTS helper
affects:
  - /admin/suggestions list (filter row, column tooltips, empty state, subheading)
tech-stack:
  added: []
  patterns:
    - driver-switched JSON extraction (SQLite json_extract vs MariaDB JSON_UNQUOTE(JSON_EXTRACT))
key-files:
  created:
    - tests/Feature/Suggestions/SuggestionReadinessFilterTest.php
  modified:
    - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
    - app/Domain/Suggestions/Filament/Resources/SuggestionResource/Pages/ListSuggestions.php
    - tests/Feature/Filament/Resources/SuggestionBrandFilterTest.php
decisions:
  - Readiness filter SQL reuses the SAME sourceable + brand + junk rule as the readiness() column so filter and badge cannot drift.
  - Removed the Brand-on-Woo TernaryFilter + its 3 now-orphaned helpers + the TernaryFilter import (grep-verified nothing else referenced them).
metrics:
  duration: ~35m
  completed: 2026-07-07
---

# Phase 260707-iz9 Plan 01: Suggestions Tier-2 Readiness Filter Summary

Added a driver-portable **Readiness SelectFilter** to `/admin/suggestions` (Ready / Needs brand / Not sourceable) that classifies rows identically to the 260707-gsy Readiness badge, retired the confusing Brand-on-Woo ternary it supersedes, and added low-risk polish (column tooltips, friendly empty state, page subheading).

## What shipped

- **`SuggestionResource::sourceableExistsSql()`** — new private driver-portable helper returning `EXISTS (SELECT 1 FROM supplier_sku_cache c WHERE c.sku = LOWER(TRIM(<sku>)))`, where `<sku>` is `json_extract(suggestions.evidence,'$.sku')` on SQLite (tests) vs `JSON_UNQUOTE(JSON_EXTRACT(...))` on MariaDB (prod). Mirrors the shipped `on_supplier_db` EXISTS filter. This is the SQLite↔MariaDB-sensitive part.
- **Readiness SelectFilter** — added in `filters([])` right after the `on_supplier_db` filter. `->query()` uses `sourceableExistsSql()` + the existing portable `brandJsonExpr()` + the junk config `product_auto_create.brands_to_add_exclude` (lowercased, bound as `?` placeholders):
  - `ready` = sourceable + `brand IS NOT NULL AND TRIM(brand) != ''` + `LOWER(TRIM(brand)) NOT IN (junk)`.
  - `needs_brand` = sourceable + (`brand IS NULL OR TRIM(brand) = ''` OR `LOWER(TRIM(brand)) IN (junk)`).
  - `not_sourceable` = `NOT <exists>`.
  - This is the SAME sourceable + brand + junk rule as the `readiness()` column, so the filter agrees with the badge.
- **Removed the Brand-on-Woo TernaryFilter** (`brand_on_woo`) — it showed 'none' both ways under normal data and is superseded by Readiness. Deleted the now-orphaned `brandOnWooJsonExpr()`, `jsonTrueLiteral()`, `jsonFalseLiteral()` helpers (grep-confirmed only the ternary referenced them) and the now-unused `use Filament\Tables\Filters\TernaryFilter;` import.
- **Polish (display-only):** `->tooltip(...)` on the Comp / Competitors / Comp price columns; `->emptyStateHeading('No suggestions match')` + `->emptyStateDescription(...)` on the table; `getSubheading()` one-liner on `ListSuggestions`.

## Verification (Herd php84)

- `pest tests/Feature/Suggestions/SuggestionReadinessFilterTest.php` — **4 passed** (ready→[A], needs_brand→[B,C], not_sourceable→[D]; filter verdict matches `readiness()` column for A–D).
- `pest tests/Feature/Suggestions tests/Unit/Suggestions` — **37 passed (155 assertions)**, no regressions.
- `pest tests/Feature/Filament/Resources/SuggestionBrandFilterTest.php` — **2 passed** (brand + competitor cases; the obsolete brand_on_woo case removed).
- `pint --test` on `SuggestionResource.php` + `ListSuggestions.php` — `{"result":"pass"}`.

Confirmed the Readiness filter's `ready` / `needs_brand` / `not_sourceable` sets match the `readiness()` column verdict for the seeded rows A(Barco→Ready), B(''→Needs brand), C(Specials junk→Needs brand), D(not in cache→Not sourceable).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Removed the obsolete brand_on_woo test case**
- **Found during:** Task 1 (removing the Brand-on-Woo TernaryFilter).
- **Issue:** `tests/Feature/Filament/Resources/SuggestionBrandFilterTest.php` had an `it('brand_on_woo=false narrows...')` case that calls `filterTable('brand_on_woo', false)`. Retiring the ternary makes that filter non-existent, so the case would fail with "a table filter with name [brand_on_woo] exists" assertion.
- **Fix:** Deleted that single `it()` block and updated the file docblock; kept the brand + competitor cases and the seed helper (which still writes the harmless `evidence.brand_on_woo` tag). Removing a feature's now-obsolete test is expected when the plan mandates removing the feature.
- **Files modified:** `tests/Feature/Filament/Resources/SuggestionBrandFilterTest.php`
- **Commit:** 4e87136

Otherwise the plan executed exactly as written (interfaces code used verbatim; RED → GREEN, no refactor commit needed).

## Additive-only confirmation

The Readiness column, the SKU/Comp/Comp price columns, the default Pending + new_product_opportunity filters, the `auto_create_full` bulk action, and all other filters/actions are unchanged. Only additions: the new filter + helper + tooltips + empty state + subheading, and the retirement of the Brand-on-Woo ternary.

## Remaining Tier-2/3 follow-ups

- Split the failure kinds (crm_push_failed / auto_create_failed / agent_guardrail_blocked) into their own view.
- Rename the 'Comp' column to something clearer; trim/group the filter row (now 6 filters).
- The 5 pre-existing Suggestions test failures (correlation_id NOT NULL test-data + a BindingResolutionException) still owed a cleanup — logged in the 260707-gsy deferred items, out of scope here.

## Operator notes

- **Deploy:** push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
- Suggestions now has a **Readiness** filter (Ready / Needs brand / Not sourceable) matching the Readiness badge — filter to **Ready** and Auto-create the lot with confidence. The confusing Brand-on-Woo filter is gone. Columns are tooltipped; the page has a one-line explainer + friendly empty state.
- **Portability:** the sourceable check is driver-switched (SQLite json_extract in tests / MariaDB JSON_UNQUOTE(JSON_EXTRACT) in prod). After deploy, verify the Ready filter count looks right on prod — it should equal the green-badge row count.

## Self-Check: PASSED

- FOUND: app/Domain/Suggestions/Filament/Resources/SuggestionResource.php (SelectFilter::make('readiness') + sourceableExistsSql)
- FOUND: app/Domain/Suggestions/Filament/Resources/SuggestionResource/Pages/ListSuggestions.php (getSubheading)
- FOUND: tests/Feature/Suggestions/SuggestionReadinessFilterTest.php
- FOUND commit 69cd693 (RED test)
- FOUND commit 4e87136 (GREEN implementation)
