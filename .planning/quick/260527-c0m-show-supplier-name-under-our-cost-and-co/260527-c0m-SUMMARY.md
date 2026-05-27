---
phase: quick-260527-c0m
plan: 01
subsystem: pricing
tags: [pricing-ops, competitor-position, deptrac, display-only]
requires:
  - CompetitorPositionScanner (existing read model)
  - SupplierOfferSnapshot (Products domain)
  - competitors table (read via raw DB::select)
provides:
  - supplier_name + competitor_name per scanner row (string|null, batched)
  - bucket-popup sub-lines showing where each price comes from
affects:
  - PricingOpsReport (consumes new keys via cache; no change needed)
  - pricing-ops-bucket popup (renders new sub-lines)
tech-stack:
  added: []
  patterns:
    - "Cross-domain name resolution without a domain dependency: raw DB::select on competitors (deptrac-safe), bound placeholders"
    - "Batched id->name maps built after chunkById (no per-row queries in the loop)"
key-files:
  created: []
  modified:
    - app/Domain/Pricing/Services/CompetitorPositionScanner.php
    - tests/Feature/Pricing/CompetitorPositionScannerTest.php
    - resources/views/filament/pages/pricing-ops-bucket.blade.php
decisions:
  - "lowestCompetitorExVatByKey() renamed to lowestCompetitorByKey() returning {ex, competitor_id}; tie-break = lowest competitor_id wins (ksort + strict-lower replace)"
  - "Supplier name = cheapest-current SupplierOfferSnapshot (recorded_at DESC, price ASC), recency-windowed to the scan's maxAgeDays so aged-out offers don't surface stale suppliers"
  - "Internal _competitor_id key stashed per row during the loop, then stripped before the public return shape"
metrics:
  duration: ~22 min
  completed: 2026-05-27
  tasks: 2
  files: 3
---

# Phase quick-260527-c0m Plan 01: Show supplier + competitor name in the Pricing Ops bucket popup — Summary

Adds `supplier_name` and `competitor_name` (both `string|null`, batched) to every `CompetitorPositionScanner::compute()` row and renders them as compact muted sub-lines under "Our cost (ex)" and "Lowest comp (ex)" in the bucket popup — so operators can see which supplier we're costing at and which competitor is undercutting without leaving the modal.

## What was built

**Task 1 — Scanner (TDD)**
- `lowestCompetitorExVatByKey()` → renamed `lowestCompetitorByKey()`, now returns `array<string, array{ex:int, competitor_id:int}>`. It surfaces the *winning* competitor_id per key alongside the min price, with a deterministic tie-break: `ksort($comps)` then replace only on a *strictly* lower price, so on a price tie the lowest competitor_id wins.
- `competitorNamesByIds(array $ids)` — one raw `DB::select('SELECT id, name FROM competitors WHERE id IN (?, ?, ...)', $ids)` with bound placeholders (never interpolated; T-c0m-01 mitigation). Does **not** import `App\Domain\Competitor\Models\Competitor` — keeps Pricing free of a Competitor-domain dependency (deptrac-safe), mirroring how the scanner already reads `competitor_prices`.
- `cheapestSupplierNameByProductId(array $productIds, int $maxAgeDays)` — one `SupplierOfferSnapshot` query (Products domain, deptrac-allowed) ordered `recorded_at DESC, price ASC`, grouped first-per-product in PHP. Matches PriceHistoryPage's cheapest-current selection (the offer that set `buy_price`). Recency-windowed to the scan's `maxAgeDays`; returns null for products with no qualifying offer.
- Both maps are built **after** `chunkById` from the kept rows only — no per-row queries inside the loop (perf constraint honored). Kept rows are indexed by product_id and the winning competitor_id is stashed on each row (`_competitor_id`), then names are applied and the internal key is stripped before the public return.
- `@return` docblock updated for all three buckets (below_cost / at_floor / winnable) to include `supplier_name:?string, competitor_name:?string` — PHPStan level 6 clean.
- Test extended with 3 new cases: supplier+competitor name resolution, null supplier_name when no snapshot exists, deterministic tie-break on lowest competitor_id.

**Task 2 — Bucket popup blade**
- Under the "Our cost (ex)" `<td>`, a muted sub-line (`text-xs text-gray-500 dark:text-gray-400`) renders `supplier_name`; under "Lowest comp (ex)", the `competitor_name`. Both `Str::limit(…, 28)`, null-safe via `! empty()`, rendered through Blade `{{ }}` (auto-escaped; T-c0m-03 XSS mitigation). No new header columns. Inline page panels (`pricing-operations.blade.php`) and `PricingOpsReport::csv()` untouched (popup-only scope).

## Verification results

| Gate | Command | Result |
|------|---------|--------|
| Pest | `pest tests/Feature/Pricing/CompetitorPositionScannerTest.php` | **PASS** — 7 passed, 23 assertions (4 existing + 3 new) |
| PHPStan L6 | `phpstan analyse …/CompetitorPositionScanner.php` (level 6) | **PASS** — no errors (docblock shapes match runtime) |
| Deptrac | `deptrac analyse --config-file=depfile.yaml` | **PASS for touched file** — scanner has **zero** violations; no Competitor import added |
| Pint | `pint …/CompetitorPositionScanner.php …Test.php …bucket.blade.php` | **PASS** — no changes needed |
| Task 2 grep | `supplier_name` / `competitor_name` in bucket blade | **PASS** — both present (lines 42–49) |

PHP run via the Herd binary (`C:/Users/sonny.tanda/.config/herd/bin/php84/php.exe`, PHP 8.4.19); `php` is not on PATH.

## Deviations from Plan

### Auto-fixed / worked-around issues

**1. [Rule 3 — Blocking, pre-existing] PHPStan committed config incompatible with installed PHPStan 2.1.50**
- **Found during:** Task 1 verification.
- **Issue:** `phpstan.neon` (committed in FOUND-02, unmodified by me) contains `checkMissingIterableValueType` and `checkGenericClassInNonGenericObjectType` — both removed in PHPStan 2.x. Running `phpstan analyse` aborts with "Invalid configuration" before analysing any file, so the level-6 gate cannot run against the committed config.
- **Resolution:** Pre-existing config rot, out of scope (I did not modify the committed config). To still honor the gate, I ran the analysis via a **throwaway** temporary config (larastan include + level 6, no dead keys), pointed at the scanner only, then deleted it. Result: clean, no errors. **The committed `phpstan.neon` was left unchanged.** Recommend a separate task to migrate the config to PHPStan 2.x (drop the two dead keys).

### Deptrac — pre-existing violations (NOT introduced by this task)

`deptrac analyse` reports 41 total violations, but **none** involve `CompetitorPositionScanner` (the only source file I changed). The Pricing→Competitor violations live in `PricingOperationsPage` (committed in `058fd96`), and the rest in Sync commands/services — all pre-existing, untouched files. The scanner correctly resolves competitor names via raw `DB::select` and introduces no new boundary violation. The deptrac gate **passes for the touched file**.

No other deviations — plan executed as written.

## Known Stubs

None.

## Threat Flags

None — no new network endpoints, auth paths, file access, or schema changes. Names originate from our own DB and are echoed via auto-escaped Blade. Mitigations T-c0m-01 (bound placeholders) and T-c0m-03 (Blade escaping) are implemented as planned.

## Manual check still pending (human-check from Task 2)

Open Pricing Operations → click a tile (e.g. "Competitor below our cost") → confirm the supplier name shows under "Our cost (ex)" and the competitor name under "Lowest comp (ex)", muted/compact, rows with no resolvable supplier show nothing, the table stays compact, and the filter box still works.

## Commits

- `39b946d` test(quick-260527-c0m): add failing tests for supplier_name + competitor_name in scanner (TDD RED)
- `1642079` feat(quick-260527-c0m): resolve supplier_name + competitor_name in scanner (batched) (TDD GREEN)
- `d74a604` feat(quick-260527-c0m): show supplier + competitor name in bucket popup

## Self-Check: PASSED

- app/Domain/Pricing/Services/CompetitorPositionScanner.php — FOUND (committed 1642079)
- tests/Feature/Pricing/CompetitorPositionScannerTest.php — FOUND (committed 39b946d)
- resources/views/filament/pages/pricing-ops-bucket.blade.php — FOUND (committed d74a604)
- Commits 39b946d, 1642079, d74a604 — all present in git log.
