# 260710-obl — Suggestions Brand filter: show the whole list (optionsLimit) — SUMMARY

**Status:** COMPLETE · **Commit:** `c353256` (pushed to origin/main) · **Deployed:** prod @ c353256 (2026-07-10)

## Problem
Operator report: on **Suggestions → Brand filter**, the scrollable dropdown only
showed brands up to about the letter **C**. Not a data or cache defect — the `brand`
`SelectFilter` (`SuggestionResource.php:506`) was `->searchable()` with **no
`->optionsLimit()`**, so it fell back to Filament 3's default of **50** options
(verified `= 50` default in `vendor/filament/tables/src/Filters/SelectFilter.php`).
The distinct brand list from the cached `brandFilterOptions()` runs well past 50 and
is sorted alphabetically, so the un-searched scroll list stopped in the "C"s. Typing
in the box already searched the full array (the operator workaround).

## Change (1 line + test, 2 files, 41 insertions)
- `app/Domain/Suggestions/Filament/Resources/SuggestionResource.php` — added
  `->optionsLimit(1000)` immediately after `->searchable()` on the `brand` filter,
  with an explanatory comment. Chosen over the default 50 because the option list is
  a small **cached static array** (the expensive JSON scan was already removed in
  260703-qc0), so rendering the full list is cheap; 1000 sits well above the realistic
  distinct-brand ceiling. `brandFilterOptions()`, the `->query()` closure, the cache,
  and the `competitor` filter were untouched.
- `tests/Feature/Filament/Resources/SuggestionBrandFilterTest.php` — TDD regression:
  seeds 61 distinct pending `new_product_opportunity` brands, clears
  `BRAND_FILTER_OPTIONS_CACHE_KEY`, then asserts
  `Livewire::test(ListSuggestions::class)->instance()->getTable()->getFilter('brand')->getOptionsLimit() >= 61`.
  RED confirmed before the fix (`Failed asserting that 50 is ... greater than 61`).
  Note: a functional `filterTable()` test canNOT catch this — it sets the value
  directly and ignores the render limit; the bug lives only in the rendered dropdown
  length, so the assertion targets the configured limit.

## Verify (all GREEN)
- `SuggestionBrandFilterTest.php` — 3 passed (new case + 2 existing `filterTable` cases).
- `SuggestionInboxTest.php` + `SuggestionResourceQueryCountTest.php` — 17 passed, no resource/table-boot regression.
- `pint` — pass on both touched files.

## Notes
- NO migration, NO seeder. Driver-portable (test reads config only, no JSON SQL).
- Pre-existing working-tree noise (`supplier-probe.json`, `CompetitorIngestFreshnessColorTest.php`, `.claude/`) left untouched.
