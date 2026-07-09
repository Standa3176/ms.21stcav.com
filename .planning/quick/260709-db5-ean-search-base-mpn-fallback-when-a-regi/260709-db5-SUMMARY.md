---
phase: 260709-db5-ean-search-base-mpn-fallback-when-a-regi
plan: 01
subsystem: ProductAutoCreate / EAN backfill
tags: [ean-search, gtin, backfill, hp-localized-sku, merchant-feed]
requires:
  - EanSearchClient (260607-hxa) — existing EAN-search.org reverse-lookup client
provides:
  - base-MPN retry on a region-suffixed EAN-search miss
affects:
  - products:backfill-merchant-feed --field=ean (operator EAN backfill)
  - auto-create pipeline (shares EanSearchClient — no behaviour change for plain feed MPNs)
tech-stack:
  patterns:
    - "queryBarcode() single-term helper + orchestrator retry in lookupGtinByMpn()"
key-files:
  modified:
    - app/Domain/ProductAutoCreate/Services/EanSearchClient.php
    - tests/Unit/Domain/ProductAutoCreate/Services/EanSearchClientTest.php
decisions:
  - "queryBarcode() takes ($brand, $search), not ($search) as the plan sketch showed — required to preserve the existing brand-match row selection (Case 2) and keep the change purely additive."
metrics:
  tests: "12 passed (20 assertions)"
  pint: "pass"
  completed: 2026-07-09
---

# Phase 260709-db5 Plan 01: EAN-search base-MPN fallback on region-suffixed miss — Summary

`EanSearchClient::lookupGtinByMpn` now retries ONCE with the base part number
(everything before the first `#`) when a region-suffixed SKU misses — recovering
GTINs for HP/Dell-style localized codes (`B5NH6AA#ABU`, `#ABB`, `#AC3`, `#UUZ`, …)
that EAN databases list under the base (`B5NH6AA`) rather than the localized code.

## What changed

### `EanSearchClient.php`
- **Extracted `queryBarcode(string $brand, string $search): ?string`** — the entire
  existing HTTP query-build + `Http::get` + parse + brand-match + per-attempt
  `log()` logic, verbatim, now keyed on `$search` instead of `$mpn`. Credential
  resolution moved inside (so a token-missing state still issues no HTTP and returns
  null before any request).
- **`lookupGtinByMpn` is now the orchestrator**: trim/guard `$mpn` → `queryBarcode($brand, $mpn)`
  → if the result is `null` AND `$mpn` contains `#`, compute
  `$base = trim((string) strstr($mpn, '#', true))` and, if non-empty and different
  from `$mpn`, `queryBarcode($brand, $base)`. Returns the result.
- Signature unchanged (`?string $brand, ?string $mpn`). Only `#` is stripped — `/`
  and spaces are left intact (real part-number chars, e.g. `CONVBDC/SDI/HDMI12G`).
- Logging stays inside `queryBarcode`, so both attempts are recorded in
  `integration_events`.

### `EanSearchClientTest.php` — 4 new cases (reuse existing Http::fake fixtures)
- **Case 9 — suffixed recovery**: `search=B5NH6AA#ABU` → no-match, `search=B5NH6AA`
  → GTIN row. Returns the GTIN; `assertSentCount(2)`.
- **Case 10 — plain happy path**: `FW-50EZ20L` matches → returns it; `assertSentCount(1)` (no retry).
- **Case 11 — plain miss**: no `#`, empty response → null; `assertSentCount(1)` (no retry).
- **Case 12 — suffixed double-miss**: both full and base miss → null; `assertSentCount(2)`.
- Existing Cases 1–8 (brand-match, empty, placeholder, HTTP error, null brand,
  token-missing, testConnection) stay green.

## Deviations from Plan

**1. [Rule 1 — Bug avoidance] `queryBarcode` signature is `($brand, $search)`, not `($search)`.**
- **Found during:** Task 1 extraction.
- **Issue:** The plan's `<interfaces>` sketch showed `queryBarcode(string $search)` and
  described `$brand` as "unused". In fact the extracted body uses `$brand` for the
  brand-match row selection (asserted by existing Case 2). Following the sketch
  literally would leave `$brand` undefined inside `queryBarcode` and break Case 2 /
  alter behaviour for branded callers.
- **Fix:** Passed `$brand` into `queryBarcode` alongside `$search`. The HTTP/parse
  logic is otherwise verbatim; the change stays purely additive.
- **Files modified:** `EanSearchClient.php`.

Otherwise the plan executed as written.

## Blast radius & budget

`EanSearchClient` is shared by the operator EAN backfill AND the auto-create pipeline.
The retry adds a SECOND query ONLY when the first misses AND the term contains `#`
(rare for auto-create's feed MPNs). Each localized miss now costs one extra ~0.03p
query — immaterial under the £2 cap (max ~28p over the ~460-SKU backlog). Non-`#`
SKUs are unaffected (single query, exactly as before).

## Operator re-run steps (post-deploy)

- **Deploy:** push `main` → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration).
- **Re-run EAN backfill on the still-missing set** — it will now try base part
  numbers for localized SKUs and (end-to-end) publish recovered GTINs to Woo:
  - Woo Maintenance → Catalogue Gaps → filter **Missing EAN** → select → **Backfill EAN** (queued, end-to-end), or
  - CLI: `sudo -u stcav php artisan products:backfill-merchant-feed --field=ean --push-to-woo` (add `--skus` to scope).
- Then `products:reconcile-woo-maintenance` + the breakdown query to see how many recovered.
- Genuinely EAN-less parts stay missing (expected).

## Verification

- `pest EanSearchClientTest` → **12 passed (20 assertions)**.
- `pint` (both files) → **{"result":"pass"}**.

## Self-Check: PASSED
- `app/Domain/ProductAutoCreate/Services/EanSearchClient.php` — contains `queryBarcode` ✓
- `tests/Unit/Domain/ProductAutoCreate/Services/EanSearchClientTest.php` — contains `#ABU` ✓
