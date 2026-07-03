---
phase: 260703-qc0-cache-the-suggestions-brand-filter-optio
plan: 01
subsystem: suggestions / filament
tags: [cache, filament, suggestions, brand-filter, performance, timeout]
requires:
  - SuggestionResource Brand SelectFilter (260702-hg1)
  - RefreshBrandsToAddCommand (260702-h50) — evidence.brand tags + Cache::put summary
  - self::brandJsonExpr() driver-portable JSON extraction
provides:
  - SuggestionResource::brandFilterOptions() — cached (5-min TTL) distinct brand list
  - BRAND_FILTER_OPTIONS_CACHE_KEY = 'suggestions.brand_filter_options'
  - refresh-brands-to-add pre-warm (forget + rebuild) of the filter-options cache
affects:
  - /admin/suggestions render path — no longer runs a per-render distinct-JSON scan over ~8,826 rows
tech-stack:
  added: []
  patterns:
    - "Cache::remember wrapper around an expensive per-render Filament filter ->options() query (5-min TTL)"
    - "scheduled command pre-warms the web-path cache off the web path (forget + rebuild after its own Cache::put)"
    - "pure caching wrapper — cached result byte-identical to the live query; ->query() (the WHERE) untouched"
    - "driver-portable via existing self::brandJsonExpr() (SQLite tests / MariaDB prod)"
    - "unique test-helper name (makeNpoSuggestionWithBrand) — SuggestionBrandFilterTest already declares seedBrandSuggestion/brandFilterAdmin; Pest loads all files"
key-files:
  created:
    - tests/Feature/Suggestions/BrandFilterOptionsCacheTest.php
  modified:
    - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
    - app/Console/Commands/RefreshBrandsToAddCommand.php
decisions:
  - "5-min (300s) TTL fallback: even if the scheduled pre-warm is skipped, the admin page runs the scan at most once per 5 minutes rather than on every render."
  - "Pre-warm placed INSIDE the command's non-dry-run block, right after Cache::put(self::CACHE_KEY, ...): --dry-run writes nothing (no evidence tags, no cache), so it must not pre-warm either; and forget+rebuild AFTER tagging so the list reflects the tags just written."
  - "Cache::has() (not a non-empty assertion) is the pre-warm proof: Cache::remember stores even an empty array, so 'populated' = key present. In the command test the mysqli walk fast-fails → brands re-tagged null → rebuilt set is empty but the key is present, which is the pre-warm guarantee."
metrics:
  tasks: 1
  duration: ~25m
  completed: 2026-07-03
---

# Quick Task 260703-qc0: Cache the Suggestions Brand Filter Options Summary

Wrap the Brand `SelectFilter`'s distinct-JSON scan in a 5-minute cache (pre-warmed by `products:refresh-brands-to-add`) so `/admin/suggestions` stops running an ~8,826-row scan on every render and no longer 30s-times-out under load. Pure caching wrapper — behaviour identical.

## Root cause

The `/admin/suggestions` page intermittently died with **"Maximum execution time of 30 seconds exceeded"** (FatalError on Filament render). Once `products:refresh-brands-to-add` (260702-h50) tagged `evidence.brand` on all pending `new_product_opportunity` rows, the Brand `SelectFilter->options()` closure ran a **DISTINCT JSON extraction over the whole `suggestions` table (~8,826 rows) on EVERY page render** just to populate the dropdown. Under load that scan is what blew the 30s wall.

## The fix

**`SuggestionResource`:**
- New `public const BRAND_FILTER_OPTIONS_CACHE_KEY = 'suggestions.brand_filter_options'`.
- New cached static `brandFilterOptions(): array` wrapping the SAME query (`kind='new_product_opportunity'` + `whereNotNull(self::brandJsonExpr())` + `distinct()` + `pluck` + `filter()->sort()->mapWithKeys()`) in `Cache::remember(KEY, 300, ...)`.
- The Brand filter's `->options()` is now `fn (): array => self::brandFilterOptions()`. The filter's `->query()` (the WHERE applied when a brand is picked) and every other filter are UNCHANGED.

Cold cache costs at most **one** scan per 5 minutes; a warm cache is instant.

**`RefreshBrandsToAddCommand::perform()`:**
- Right after the existing `Cache::put(self::CACHE_KEY, ...)` (the brands-to-add summary), it `Cache::forget`s `BRAND_FILTER_OPTIONS_CACHE_KEY` then calls `SuggestionResource::brandFilterOptions()` to rebuild it.
- So the scheduled Mon-Fri 07:50 run keeps the filter list both **fresh** (reflects the tags just written) and **warm** (off the web path) — the admin page never has to run the scan itself under normal operation. Pint auto-imported `SuggestionResource` (short class name) into the command.

Both are byte-identical to the prior live-query result and driver-portable (SQLite in tests via `brandJsonExpr()`, MariaDB `JSON_UNQUOTE` in prod).

## Test

`tests/Feature/Suggestions/BrandFilterOptionsCacheTest.php` (SQLite, RefreshDatabase) — 3 cases:
1. Seeds pending NPO rows (`Yealink`, `Sony`, a `Yealink` dup, one with no brand) → `brandFilterOptions()` returns `['Sony'=>'Sony','Yealink'=>'Yealink']` and `Cache::has(KEY)` is true after.
2. After caching, insert a NEW brand → still the OLD cached set (proves it's cached, not re-queried); `Cache::forget` + call again → now includes the new brand (proves rebuild).
3. `products:refresh-brands-to-add` leaves the key populated — `TaxonomyResolver` anon-stub returns one brand (so the command doesn't abort), `IntegrationCredentialResolver` anon-stub points the mysqli walk at `127.0.0.1:1` so it fast-fails to an all-empty manufacturer map, and the command still reaches its cache write + pre-warm.

Helper `makeNpoSuggestionWithBrand` is uniquely named because `SuggestionBrandFilterTest.php` already declares `seedBrandSuggestion`/`brandFilterAdmin` and Pest loads every test file (a redeclaration would fatal).

## Verification (Herd php `~/.config/herd/bin/php84/php.exe`)

- Plan suite: `pest BrandFilterOptionsCacheTest + BrandsToAddIndexTest + RefreshBrandsToAddWalkTest` → **15 passed (50 assertions)**.
- Regression: `+ SuggestionBrandFilterTest + BrandsToAddPageTest` → **25 passed (82 assertions), 0 regressions**.
- `pint --test` on both changed source files → `{"result":"pass"}`.
- Confirmed `brandFilterOptions()` is cached (TTL 300s) and pre-warmed by `products:refresh-brands-to-add`.

## Deviations from Plan

None — plan executed exactly as written (RED → GREEN, no refactor needed). The only automatic adjustment was Pint's own reformat: it imported `App\Domain\Suggestions\Filament\Resources\SuggestionResource` into the command (short class name) rather than the plan's inline FQCN, to satisfy `fully_qualified_strict_types`/`ordered_imports`. Behaviour identical.

## Commits

- `3d79744` — test(260703-qc0): add failing test for cached Brand filter options + pre-warm (RED)
- `041f640` — feat(260703-qc0): cache the Suggestions Brand filter options + pre-warm (GREEN)

## Operator notes (NOT run by Claude — local commits only, NOT pushed)

- Deploy when box load is back near ~1: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
- After deploy, warm the cache once so the first page load is instant:
  `php artisan products:refresh-brands-to-add` (also rebuilds the brands-to-add list) — or it warms itself on the next scheduled 07:50 run / first page load (one scan, then cached 5 min).
- The Suggestions page should stop 30s-timing-out — the Brand filter no longer scans ~8,826 rows per render.
- Unrelated but observed the same session (flag to hosting, not fixed here): (a) duplicate `queue:work --stop-when-empty` workers competing with Horizon on the default queue (cron hygiene); (b) the auto-create pipeline stalled under load (not a bug) — consider moving `RunAutoCreatePipelineJob` to the sync-bulk queue + per-SKU sub-jobs as a follow-up.

## Self-Check: PASSED
- FOUND: tests/Feature/Suggestions/BrandFilterOptionsCacheTest.php
- FOUND: app/Domain/Suggestions/Filament/Resources/SuggestionResource.php (brandFilterOptions + BRAND_FILTER_OPTIONS_CACHE_KEY)
- FOUND: app/Console/Commands/RefreshBrandsToAddCommand.php (pre-warm)
- FOUND commit: 3d79744 (test/RED)
- FOUND commit: 041f640 (feat/GREEN)
