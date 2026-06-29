---
phase: 260629-rct-draft-from-suggestions-pick-the-woo-bran
plan: 01
subsystem: product-auto-create
tags: [draft-from-suggestions, supplier-feed, brand-resolution, woo, bugfix]
requires:
  - "DraftFromSuggestionsCommand::resolveBrandKey (260628-b9t)"
  - "DraftFromSuggestionsCommand::classifySkip + skip buckets (260629-pqh)"
provides:
  - "DraftFromSuggestionsCommand::firstResolvableBrandKey (multi-manufacturer brand-preferring selection)"
  - "$supMap as manufacturer-LISTS per key (append + dedup)"
affects:
  - "products:draft-from-suggestions candidate selection (warranty/add-on row no longer hijacks brand)"
tech-stack:
  added: []
  patterns:
    - "Pure unit-tested helper on a console command, constructed via app() with no DB"
key-files:
  created:
    - "tests/Unit/Console/DraftFromSuggestionsMultiMfrTest.php"
  modified:
    - "app/Console/Commands/DraftFromSuggestionsCommand.php"
decisions:
  - "Selection prefers the FIRST manufacturer (in feed-fetch order) that resolves to a Woo brand; order-independent on outcome because only resolvability decides."
  - "brand_not_on_woo skip report names a representative manufacturer (first in the list) — preserves 260629-pqh operator-facing detail."
metrics:
  duration: "~7 min"
  completed: "2026-06-29"
  commits: 2
  tasks: 2
  files: 2
---

# Phase 260629-rct Plan 01: draft-from-suggestions — pick the Woo brand among multiple supplier manufacturers Summary

Fixed a real prod bug where a product carrying a warranty/protection-plan supplier feed row (sharing its MPN) was wrongly skipped as "brand not on Woo": `$supMap` now collects ALL manufacturers per key and the candidate loop picks the first that resolves to a Woo brand via the existing `resolveBrandKey`.

## The Bug

CONFIRMED on prod 2026-06-29 and surfaced by the 260629-pqh skip report: SKU **HD226** matched TWO `supplier_products` rows under the same MPN:

- `BSHD226`   | mfr=**BrightSign**   | stk=118  — the real product (BrightSign IS a Woo brand)
- `MSBSHD226` | mfr=**Protect Plus** | stk=0    — a warranty/protection plan (not a brand)

The chunk processor built `$supMap` as `$supMap[$key] = $mfr` (LAST row wins), so HD226 ended up mapped to "Protect Plus", which isn't a Woo brand → the SKU was skipped ("brand not on Woo") even though it's a creatable, in-stock BrightSign product. Any product carrying a warranty/add-on row that shares its MPN/SKU hit this.

## What Changed

**`$supMap` → manufacturer-LISTS.** In the SQL fetch loop, each matched `suppliersku`/`mpn` key now accumulates a deduped list of manufacturers (`$supMap[$k] ??= []; if (! in_array($mfr, $supMap[$k], true)) { $supMap[$k][] = $mfr; }`) instead of overwriting with the last-fetched value. The `$seenInFeed` map (260629-pqh) is unchanged.

**`firstResolvableBrandKey()` — new pure helper.** Public, unit-tested, no DB:

```php
public function firstResolvableBrandKey(array $manufacturers, array $wooBrandsByLower): array
{
    foreach ($manufacturers as $mfr) {
        $bk = $this->resolveBrandKey(mb_strtolower(trim((string) $mfr)), $wooBrandsByLower);
        if ($bk !== null) { return [$bk, $mfr]; }
    }
    return [null, null];
}
```

Returns `[brandKey, matchedManufacturer]`; `[null, null]` if none resolve.

**Per-SKU loop** now reads the manufacturer list, calls `firstResolvableBrandKey()`, and drives the existing `classifySkip()` + skip buckets off `$brandKey !== null`. The `brand_not_on_woo` bucket names a representative (first) manufacturer so operators still know which brand to add.

## Why It's Safe (only ever improves)

- **Single-manufacturer SKUs (the common case) behave exactly as before.** A one-element list short-circuits `firstResolvableBrandKey()` on the first (only) element — identical resolve result, identical skip classification.
- The change can only turn a **wrongly-skipped** multi-manufacturer SKU into a candidate. It never drops a SKU that passes today: if a manufacturer resolved before, it still resolves now (it's still in the list).
- `not_sourceable` / `no_manufacturer` buckets are untouched; `brand_not_on_woo` only fires when NONE of the SKU's manufacturers resolve.

## Tests

- New unit test `tests/Unit/Console/DraftFromSuggestionsMultiMfrTest.php` — 6 cases (TDD RED→GREEN), incl. the HD226 `['Protect Plus','BrightSign'] → ['brightsign','BrightSign']` case, order-independence, suffix-strip via `resolveBrandKey`, none-resolvable, and empty list.
- Full Console suite (`tests/Feature/Console/` + `tests/Unit/Console/`): **142 passed (619 assertions)** — no regression. The 260628-b9t brand-match and 260629-pqh skip-report unit tests stay green.
- `pint --test` on the command file: **PASS**.

## Deviations from Plan

None — plan executed exactly as written (both `<interfaces>` CHANGE blocks applied verbatim; helper signature matches).

## Operator Verify + Impact Recount (NOT executed here)

- **Deploy:** push `main`, then on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- **Spot-check HD226:** `php artisan products:draft-from-suggestions --skus=HD226 --dry-run` should now print `Batch: 1 product(s) … BrightSign` (no longer skipped as Protect Plus). Then create for real: `--skus=HD226 --auto-approve --no-confirm`.
- **Impact recount:** this also unblocks every other product that had a warranty/protection-plan row hijacking its brand. Re-run a `--limit=0 --dry-run` to see the new (higher) creatable Batch total vs the pre-fix run.

## Commits

- `903313c` test(260629-rct): add firstResolvableBrandKey helper + unit test
- `cd04759` fix(260629-rct): collect all manufacturers per key + prefer brand-resolving one

## Self-Check: PASSED

- FOUND: app/Console/Commands/DraftFromSuggestionsCommand.php (firstResolvableBrandKey present; $supMap built as lists)
- FOUND: tests/Unit/Console/DraftFromSuggestionsMultiMfrTest.php
- FOUND commit: 903313c
- FOUND commit: cd04759
