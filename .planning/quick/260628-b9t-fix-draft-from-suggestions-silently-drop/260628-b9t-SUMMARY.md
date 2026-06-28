---
phase: 260628-b9t-fix-draft-from-suggestions-silently-drop
plan: 01
subsystem: ProductAutoCreate / Suggestions auto-create pipeline
tags: [bugfix, draft-from-suggestions, brand-matching, silent-data-loss, tdd]
requires:
  - app/Console/Commands/DraftFromSuggestionsCommand.php (existing chunk processor)
  - App\Domain\ProductAutoCreate\Services\TaxonomyResolver (Woo brand list)
provides:
  - DraftFromSuggestionsCommand::resolveBrandKey() — pure manufacturer→Woo-brand-key fallback
affects:
  - products:draft-from-suggestions inclusion gate (Suggestions auto-create + "Auto-create all in this tab")
tech-stack:
  added: []
  patterns:
    - "Pure, public, unit-testable normalisation method on a Console command (no DB); construct via app() to exercise."
key-files:
  created:
    - tests/Unit/Console/DraftFromSuggestionsBrandMatchTest.php
  modified:
    - app/Console/Commands/DraftFromSuggestionsCommand.php
decisions:
  - "Strip only the FIRST ' - ' (space-hyphen-space) segment; conservative — a bare hyphen (totally-unknown) is never split."
  - "Inclusion-only change: the brand a product ultimately receives is still resolved downstream by assign-taxonomy / PublishProductJob — resolveBrandKey only decides batch membership + the summary bucket."
metrics:
  duration: ~14m
  tasks: 1
  files: 2
  completed: 2026-06-28
---

# Phase 260628-b9t Plan 01: Fix draft-from-suggestions silently dropping 'Brand - Category' SKUs Summary

`products:draft-from-suggestions` now resolves "Brand - Category" feed manufacturers (e.g. `Yealink - Headset`) to their base Woo brand via an exact-then-suffix-strip fallback, so those SKUs enter the auto-create batch instead of being silently dropped — exact-match behaviour for already-passing SKUs is unchanged.

## Root Cause

The command filters candidate SKUs by requiring the supplier feed's `manufacturer` to EXACTLY match (case-insensitive, trimmed) a Woo brand term (`DraftFromSuggestionsCommand.php:174` pre-fix — `isset($wooBrandsByLower[$mfrLower])`). Supplier feed manufacturers are frequently "Brand - Category" shaped — e.g. SKU **BH71** has manufacturer `Yealink - Headset`, which lowercased (`yealink - headset`) does NOT equal the `yealink` brand term. The SKU was silently dropped (`No matching SKUs to draft`, nothing created, no error). Clean names (Lenovo, Lindy) passed. Confirmed on prod 2026-06-28; this is why a large share of the ~6,074 pending `new_product_opportunity` suggestions never created.

## What Changed

Added a public, pure method:

```php
public function resolveBrandKey(string $mfrLower, array $wooBrandsByLower): ?string
```

- **Exact match first** — preserves all current behaviour for clean manufacturers.
- **On miss**, if the string contains `' - '`, take the segment before the FIRST `' - '` (`explode(' - ', $x, 2)[0]`), trim it, and retry against the brand map. `yealink - headset - uk` → `yealink`.
- Defensive `trim()`; returns `null` for empty input, for a stripped lead that still isn't a known Woo brand, and for a bare hyphen with no surrounding spaces (`totally-unknown`).

Wired into the chunk processor — the three lines that previously used `$mfrLower` directly now use the resolved key:

| Before | After |
|---|---|
| `if (! isset($wooBrandsByLower[$mfrLower])) { continue; }` | `$brandKey = $this->resolveBrandKey($mfrLower, $wooBrandsByLower); if ($brandKey === null) { continue; }` |
| `in_array($mfrLower, $brandsFilter, true)` | `in_array($brandKey, $brandsFilter, true)` |
| `$canonical = $wooBrandsByLower[$mfrLower];` | `$canonical = $wooBrandsByLower[$brandKey];` |

This also means `--brands=Yealink` now includes `Yealink - Headset` SKUs, and the byBrand summary buckets them under the resolved canonical brand.

### Why low-risk

- **Inclusion-only.** It only ADMITS more SKUs into the batch; it never changes how currently-passing (exact-match) SKUs are handled — exact is always tried first and returns the same key as before.
- The brand each product ultimately receives is resolved **downstream** (assign-taxonomy / PublishProductJob), not here — here the manufacturer is used solely to decide inclusion + the summary bucket.
- Manufacturers whose LEADING token still isn't a Woo brand stay skipped (correct), as do no-manufacturer SKUs.

## TDD Cycle

- **RED** (`f08a150`): `tests/Unit/Console/DraftFromSuggestionsBrandMatchTest.php` — 8 cases (exact / lenovo+lindy exact / `yealink - headset` → `yealink` / first-`' - '`-split / untrimmed / unknown-lead → null / no-`' - '` hyphen → null / empty → null). All failed (`Method ...::resolveBrandKey does not exist`).
- **GREEN** (`6354cc2`): added `resolveBrandKey()` + wired the chunk processor. All 8 pass (9 assertions).
- **REFACTOR**: none needed — implementation matches the plan's TARGET block exactly.

Command constructed via `app(DraftFromSuggestionsCommand::class)` (constructor deps `IntegrationCredentialResolver` + `TaxonomyResolver`); `resolveBrandKey` touches no database.

## Verification

```
pest tests/Unit/Console/DraftFromSuggestionsBrandMatchTest.php   → 8 passed (9 assertions)
pint --test app/Console/Commands/DraftFromSuggestionsCommand.php → {"result":"pass"}
pint --test tests/Unit/Console/DraftFromSuggestionsBrandMatchTest.php → {"result":"pass"}
grep gate: line 174 calls resolveBrandKey; the only remaining isset($wooBrandsByLower[...])
           is INSIDE resolveBrandKey (line 394) — the old chunk-processor gate is gone.
```

## Operator Verify + Impact-Count Steps (NOT executed by Claude)

- **Deploy:** push `main`, then on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- **Verify the fix unlocks BH71:**
  `sudo -u stcav bash -c 'cd /home/stcav/ms.21stcav.com && php artisan products:draft-from-suggestions --skus=BH71,12VL0000UK,43348 --dry-run'`
  → expect `Batch: 3` (Yealink + Lenovo + Lindy); BH71 no longer dropped.
- **Impact count** (how many of the ~6,074 pending now resolve): run `products:draft-from-suggestions --dry-run --limit=0` and compare the Batch total before vs after; or a one-off probe grouping feed manufacturers by `' - '` prefix against the ~113 Woo brands.
- **Scope reminder:** this only fixes INCLUSION in the batch. The brand each product ultimately gets is still resolved downstream. No-manufacturer and truly-unknown-brand SKUs remain (correctly) skipped.

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check: PASSED

- FOUND: app/Console/Commands/DraftFromSuggestionsCommand.php (resolveBrandKey present, wired)
- FOUND: tests/Unit/Console/DraftFromSuggestionsBrandMatchTest.php
- FOUND commit: f08a150 (RED test)
- FOUND commit: 6354cc2 (GREEN fix)
