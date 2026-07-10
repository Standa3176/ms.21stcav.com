# 260710-obl — Suggestions Brand filter: show the whole list (optionsLimit)

**Type:** GSD quick task (TDD, atomic commit). Executor does NOT push/deploy.

## Problem (operator-reported)
On **Suggestions → Brand filter**, the scrollable dropdown only shows brands up to
about the letter **C**. No data is missing and the cache is fine: the brand
`SelectFilter` at `SuggestionResource.php:506` is `->searchable()` with **no
`->optionsLimit()`**, so it falls back to Filament 3's default of **50** options.
The distinct brand list (from `brandFilterOptions()`, pre-warmed by
`products:refresh-brands-to-add`) is well over 50 and sorted alphabetically, so the
un-searched scroll list stops partway through the "C"s. Typing in the box already
finds later brands (client-side search over the full array) — only the scroll list
is capped.

Confirmed: `optionsLimit` appears nowhere in `app/`.

## Fix (one line + test)
`app/Domain/Suggestions/Filament/Resources/SuggestionResource.php` — on the `brand`
`SelectFilter` (the block at ~L506–521, right after `->searchable()`), add:

```php
->optionsLimit(1000)
```

Rationale for a fixed generous cap over the default 50: the option list is a small
**cached static array** of distinct brands (the expensive JSON scan was already
removed in 260703-qc0), so rendering the full list in the Choices dropdown is cheap;
1000 sits well above the realistic distinct-brand ceiling with headroom. Do NOT touch
`brandFilterOptions()`, the `->query()` closure, the cache, or any other filter
(the `competitor` filter has few options and is unaffected).

## Test (TDD — write first, watch it fail on default 50, then pass)
Extend `tests/Feature/Filament/Resources/SuggestionBrandFilterTest.php` (existing
Livewire/RefreshDatabase harness). Add a case that pins the configured limit so this
can never silently regress to 50 — a functional `filterTable()` test canNOT catch it
(filterTable sets the value directly and ignores the render limit):

- Seed **> 50** pending `new_product_opportunity` suggestions with distinct
  `evidence.brand` values (e.g. `Brand-000`..`Brand-060`), reusing `seedBrandSuggestion`
  (competitor can be an existing seeded name).
- Retrieve the brand filter from the booted table and assert its options limit clears
  the seeded brand count, e.g.:

```php
$limit = Livewire::test(ListSuggestions::class)
    ->instance()->getTable()->getFilter('brand')->getOptionsLimit();
expect($limit)->toBeGreaterThanOrEqual(61); // > the 61 seeded brands, and > Filament's default 50
```

  (If `getFilter('brand')` / `getOptionsLimit()` accessors differ in this Filament
  version, use the closest idiomatic accessor; the assertion must fail at the default
  50 and pass at the fix. Clear `SuggestionResource::BRAND_FILTER_OPTIONS_CACHE_KEY`
  in the test if the cached option list would otherwise mask the seeded brands.)
- Keep it driver-portable (SQLite test / MariaDB prod — memory: sqlite-mariadb-strict-trap).

## Verify
- `pest tests/Feature/Filament/Resources/SuggestionBrandFilterTest.php` GREEN (new + 2 existing cases).
- `pest tests/Feature/SuggestionInboxTest.php tests/Feature/SuggestionResourceQueryCountTest.php` GREEN (no regression to the resource/table boot).
- `pint` pass on the touched files.

## Out of scope / guardrails
- No migration, no seeder, no push, no deploy.
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json` deletion, `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- Atomic commit: `fix(260710-obl): show full brand list in Suggestions filter (optionsLimit)`.
