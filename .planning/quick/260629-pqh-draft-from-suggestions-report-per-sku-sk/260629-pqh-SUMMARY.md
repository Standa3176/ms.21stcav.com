---
phase: 260629-pqh-draft-from-suggestions-report-per-sku-sk
plan: 01
subsystem: product-auto-create
tags: [draft-from-suggestions, operator-ux, reporting, console]
requires:
  - DraftFromSuggestionsCommand::resolveBrandKey (260628-b9t)
provides:
  - DraftFromSuggestionsCommand::classifySkip (pure skip-reason helper)
  - per-SKU skip breakdown in products:draft-from-suggestions output
affects:
  - app/Console/Commands/DraftFromSuggestionsCommand.php
tech-stack:
  added: []
  patterns:
    - pure-helper-unit-test (construct command via container, no DB)
    - outer-scope accumulator surviving chunk closure via use (&$var)
key-files:
  created:
    - tests/Unit/Console/DraftFromSuggestionsSkipReportTest.php
  modified:
    - app/Console/Commands/DraftFromSuggestionsCommand.php
decisions:
  - "Reporting-only: candidate selection (resolveBrandKey) left byte-identical; no SKU that drafts today stops drafting."
  - "$seenInFeed (all matched rows, ignoring manufacturer) is the signal that distinguishes not_sourceable from no_manufacturer — $supMap only ever holds non-empty manufacturers."
  - "Auto-create-the-missing-brand half deferred pending product_brand vs WC-native products/brands taxonomy-source decision."
metrics:
  duration: ~25m
  completed: 2026-06-29
  tasks: 2
  files: 2
---

# Quick Task 260629-pqh: Report Per-SKU Skip Reasons in draft-from-suggestions Summary

`products:draft-from-suggestions` now tells the operator WHY every non-candidate SKU was skipped — three actionable buckets — instead of silently dropping them and ending with a bare "No matching SKUs to draft".

## The Problem

The auto-create pre-filter walked Suggestions (or an explicit `--skus` list), joined each SKU against `supplier_products` for its manufacturer, and kept only SKUs that were sourceable AND whose manufacturer resolved to a Woo brand. Every SKU that failed any gate was dropped with a bare `continue`. When zero SKUs survived, the run printed only:

```
No matching SKUs to draft.
```

Operators saw "N SKU(s) queued" in the Filament tab, then nothing — no idea whether the SKU wasn't sourceable, had a blank manufacturer, or had a brand that simply isn't on Woo yet. Confirmed repeatedly 2026-06-28/29 with not-sourceable SKUs (e.g. A75DM66D — only competitors carry it) and sourceable-but-brand-not-on-Woo SKUs (e.g. S4.04-B-EB-GD5 / Trantec).

## What Changed

### `classifySkip()` — pure decision helper (Task 1, TDD)

```php
public function classifySkip(bool $inFeed, bool $hasManufacturer, bool $brandResolved): ?string
{
    if (! $inFeed)          { return 'not_sourceable'; }
    if (! $hasManufacturer) { return 'no_manufacturer'; }
    if (! $brandResolved)   { return 'brand_not_on_woo'; }
    return null; // valid candidate
}
```

`null` means "this is a candidate, not a skip". Unit-tested with 5 cases via `app(DraftFromSuggestionsCommand::class)` (no DB touched): `not_sourceable` (incl. inFeed-false dominating even when the other flags are true), `no_manufacturer`, `brand_not_on_woo`, and the candidate (`null`) path.

### Skip buckets + `$seenInFeed` (Task 2)

- `$skips` is an outer-scope 4-bucket array (`not_sourceable`, `no_manufacturer`, `brand_not_on_woo`, `brand_filtered`) declared alongside `$candidates`/`$byBrand` and captured by reference (`use (&$skips)`) so it accumulates across all chunks.
- A new `$seenInFeed` set is built in the SQL fetch loop. It records EVERY matched `suppliersku` + `mpn` regardless of manufacturer. This is the key signal: the existing `if ($mfr === '') continue;` keeps blank-manufacturer rows OUT of `$supMap`, so `$supMap` alone can't tell "no feed row at all" from "feed row with blank manufacturer". `$seenInFeed` makes that distinction possible (`not_sourceable` vs `no_manufacturer`).
- The per-SKU loop's bare `continue` skips are replaced with `classifySkip`-driven bucket recording. The `brand_not_on_woo` bucket stores `Manufacturer (SKU)` so the operator sees exactly which brand to add under Products → Brands.
- A `brand_filtered` bucket records candidates the operator's explicit `--brands` list excluded (it's a real candidate, just filtered out).

### Printing

A new private `printSkipBreakdown($skips, $explicit)` prints per-bucket counts. On the `--skus` path (`$explicit === true`) it additionally lists each skipped SKU + reason (the operator picked those SKUs and wants per-SKU detail). On the Suggestion-walk path it prints counts only (could be thousands). It is called:

1. After the existing `Batch: N product(s)` summary block, and
2. On the `$count === 0` early-return path (before its `return SUCCESS`), so zero-candidate runs now explain themselves instead of printing only "No matching SKUs to draft".

## Reporting-Only — Selection Unchanged

This adds reporting and nothing else. Candidate selection is byte-identical to before:

- `resolveBrandKey` is consulted only when a non-empty manufacturer exists (`$hasMfr`), exactly as the old `if (! isset($supMap[$key])) continue;` gate did.
- The `--brands` `in_array` filter and the `$candidates[$sku] = $canonical` assignment are unchanged.

No SKU that drafts today stops drafting; no SKU that's dropped today starts drafting. Verified by the full Console feature suite: **108 passed, 584 assertions** (no regression to candidate selection or drafting).

## Deferred

The auto-create-the-missing-brand half of the operator's request (create the missing Woo brand automatically when `brand_not_on_woo`) is **deferred** — it needs the brand-taxonomy-source decision first: `product_brand` and WC-native `products/brands` are out of sync (see memory `meetingstore-brand-display`). Do it with a dry-run; don't re-pollute brands.

## Verification

| Check | Command | Result |
| --- | --- | --- |
| Unit (classifySkip 5 cases) | `pest tests/Unit/Console/DraftFromSuggestionsSkipReportTest.php` | 5 passed (5 assertions) |
| Console feature regression | `pest tests/Feature/Console/` | 108 passed (584 assertions) |
| Style | `pint --test app/Console/Commands/DraftFromSuggestionsCommand.php` | `{"result":"pass"}` |
| Wiring grep | `grep -nE 'classifySkip\|seenInFeed\|\$skips' ...` | `$skips` init + `use (&$skips)`, `$seenInFeed` built in fetch loop, classifySkip in per-SKU loop, breakdown printed twice |

## Commits

| Commit | Type | What |
| --- | --- | --- |
| c2c5520 | test(260629-pqh) | RED: failing classifySkip() unit test (5 cases) |
| 44a670d | feat(260629-pqh) | GREEN: pure classifySkip() helper |
| 6fefbfd | feat(260629-pqh) | wire skip buckets + $seenInFeed + print breakdown |

## Operator Verify Steps (post-deploy — NOT run by Claude)

1. Deploy: push `main`, then on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
2. Verify the skip breakdown now prints with reasons:

   ```
   php artisan products:draft-from-suggestions --skus=A75DM66D,S4.04-B-EB-GD5 --dry-run
   ```

   Expected:

   ```
   Skipped 2 SKU(s):
     not sourceable (no supplier carries it): 1
     brand not on Woo (add under Products → Brands): 1
       - A75DM66D: not sourceable
       - Trantec (S4.04-B-EB-GD5): brand not on Woo
   ```

3. Deferred follow-up: auto-create the missing brand — needs the product_brand vs WC-native products/brands taxonomy-source decision first (do with a dry-run; don't re-pollute brands).

## Self-Check: PASSED

- `app/Console/Commands/DraftFromSuggestionsCommand.php` — FOUND (modified, classifySkip + printSkipBreakdown + $seenInFeed present)
- `tests/Unit/Console/DraftFromSuggestionsSkipReportTest.php` — FOUND
- Commits c2c5520 / 44a670d / 6fefbfd — FOUND in `git log`
