---
phase: 260626-fjg-fix-backfill-category-from-woo-crash-on-
plan: 01
subsystem: sync
tags: [woocommerce, artisan, mariadb, sqlite, php-type-coercion, backfill, category]

requires:
  - phase: 260607-v5g
    provides: "products:backfill-category-from-woo command + its A-F Pest outcome suite"
provides:
  - "String cast of the array-chunk key in BackfillCategoryFromWooCommand's write loop — sku WHERE binding is now always a string"
  - "Case G regression guard: numeric SKU binds as string (portable to SQLite via DB::listen)"
affects: [backfill-category-from-woo, woo-sync, mariadb-strict-mode]

tech-stack:
  added: []
  patterns:
    - "DB::listen binding-capture guard to catch a MariaDB-only type-coercion bug on the loosely-typed SQLite test DB (same divergence class as 260626-d76)"

key-files:
  created: []
  modified:
    - app/Console/Commands/BackfillCategoryFromWooCommand.php
    - tests/Feature/Console/BackfillCategoryFromWooCommandTest.php

key-decisions:
  - "One-line fix: cast the chunk key back to string at the top of the write loop (`$sku = (string) $sku;`) — fixes the WHERE binding, the $updatedSkus list, and sample rows in a single statement since all downstream uses read $sku after the cast."
  - "Regression guard asserts the captured UPDATE binding is a PHP string (is_string semantics via strict `!== 41074`) rather than reproducing MariaDB error 1292 — keeps the test portable to the SQLite (:memory:) test DB."

patterns-established:
  - "When a bug is MariaDB-strict-mode-only and SQLite is loosely typed, assert the SQL binding shape (via DB::listen) instead of expecting the DB to throw — same technique as quick task 260626-d76 earlier today."

requirements-completed: [QUICK-260626-fjg]

duration: 12min
completed: 2026-06-26
---

# Phase 260626-fjg Plan 01: Fix backfill-category-from-woo crash on numeric SKUs Summary

**One-line `(string) $sku` cast in the write loop stops `products:backfill-category-from-woo` from binding numeric SKUs as integers and crashing MariaDB strict mode with error 1292 — plus a SQLite-portable regression guard.**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-06-26
- **Completed:** 2026-06-26
- **Tasks:** 2 (TDD: RED + GREEN)
- **Files modified:** 2

## Accomplishments

- **Root cause closed:** `$candidates` is an associative array keyed by SKU. PHP coerces all-digit string array keys to **int**, so a SKU like `'41074'` is stored under int key `41074`. `array_chunk(..., true)` preserves the int keys, and the inner write loop `foreach ($chunk as $sku => $wooId)` therefore yielded `$sku` as an **int** for numeric SKUs. The live write `DB::table('products')->where('sku', $sku)->update(...)` then bound an integer. `products.sku` is a varchar — MariaDB (prod, strict mode) numerically coerces the whole column to compare against the int literal and threw **SQLSTATE 22007 / error 1292 'Truncated incorrect DECIMAL value: 'CQ68056''** on the first non-numeric SKU it scanned.
- **Why the existing A-F suite missed it:** SQLite (the `:memory:` test DB) is loosely typed — it never errors on an int bound against a varchar column, so the original 6-case suite stayed green while prod crashed. (Same SQLite-vs-MariaDB divergence class as sibling quick task 260626-d76, fixed earlier today, which was the inverse — a MariaDB-only `IN(subquery+LIMIT)` rejection that SQLite permitted.)
- **Portable regression guard:** Case G seeds a numeric `'41074'` (woo 5001) and a non-numeric `'CQ68056'` (woo 5002) product, registers a `DB::listen` capture of every UPDATE binding touching `products`, runs the command live, then asserts the captured sku binding is the **string** `'41074'` and that **none** of the bindings is the **integer** `41074` (strict `!== 41074`). This fails on the int-coerced code and passes after the cast — on SQLite, without needing MariaDB strict mode to reproduce 1292.
- **One-line fix:** `$sku = (string) $sku;` as the first statement inside the write loop. Because every downstream use (`->where('sku', $sku)`, `$updatedSkus[] = $sku`, the sample rows) reads `$sku` after the cast, this single line fixes all three surfaces. Behaviour is byte-identical for non-numeric SKUs.

## Task Commits

1. **Task 1: Add failing numeric-SKU binding guard (RED)** — `c446e30` (test)
2. **Task 2: Cast chunk key to string in write loop (GREEN)** — `c0dcb58` (fix)

**Plan metadata:** (final docs commit — SUMMARY + STATE row)

## Files Created/Modified

- `app/Console/Commands/BackfillCategoryFromWooCommand.php` — added `$sku = (string) $sku;` (line 204) as the first statement in the inner write loop, with an incident-anchored comment explaining the PHP int-coercion → MariaDB 1292 chain.
- `tests/Feature/Console/BackfillCategoryFromWooCommandTest.php` — appended Case G (numeric + non-numeric SKU seed, DB::listen binding capture, string-binding guard).

## Decisions Made

None beyond the plan — followed the plan exactly. The plan's prescribed `(string) $sku` cast and the DB::listen binding-shape guard were both implemented as written.

## Deviations from Plan

None — plan executed exactly as written.

- RED behaved exactly as the plan predicted: expectation 3 (the strict `!== 41074` int-binding guard) failed against the unmodified command; expectations 1 and 2 (behavioural writes) and Cases A-F stayed green. The plan's RRED fallback (asserting `is_int` directly) was **not** needed — the runner did not normalise bindings, so the canonical `every(... !== 41074)` guard tripped on its own.
- GREEN: 7/7 (A-G) green on first run after the cast; pint `{"result":"pass"}`; `grep -n "(string) \$sku"` → one match at line 204 inside the write loop.

## Issues Encountered

None.

## Test Results

- `vendor/bin/pest tests/Feature/Console/BackfillCategoryFromWooCommandTest.php` → **7/7 GREEN** (A, B, C, D, E, F, G — 30 assertions).
  - Pre-fix (RED): A-F passed, **G failed** on the int-binding guard (line 281).
  - Post-fix (GREEN): A-G all pass.
- `grep -n "(string) \$sku" app/Console/Commands/BackfillCategoryFromWooCommand.php` → 1 match (line 204, inside the write loop).
- `vendor/bin/pint --test app/Console/Commands/BackfillCategoryFromWooCommand.php` → `{"result":"pass"}`.
- Full Pest delta vs baseline: **+1 pass / 0 new fails** (Case G added; A-F unchanged).

## Safe-resume property of the re-run

The crash was mid-run and non-transactional, so SKUs processed before the failing chunk were written; the rest were not. The default candidate query filters `whereNull('category_id')`, so a re-run after this fix **resumes cleanly** — already-written rows are excluded. No data corruption, no manual cleanup required.

## Operator action (post-deploy — NOT run by Claude)

1. **Deploy:** push `main`, then on the VPS:
   `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`
   (No migration.)
2. **Re-run the backfill** — it resumes safely (`whereNull('category_id')` excludes rows already written before the crash):
   `sudo -u stcav bash -c 'cd /home/stcav/ms.21stcav.com && php artisan products:backfill-category-from-woo'`
3. **Refresh the audit snapshot** so `/admin/category-audit` reflects the fix:
   `sudo -u stcav bash -c 'cd /home/stcav/ms.21stcav.com && php artisan products:audit-categories'`

## Sibling reference

Same SQLite-vs-MariaDB type-divergence class as quick task **260626-d76** (fixed earlier today): d76 was a MariaDB-only `IN(subquery + LIMIT)` rejection (error 1235) that SQLite permitted; this one is a MariaDB-only int→varchar strict-mode coercion (error 1292) that SQLite tolerated. Both were invisible to the SQLite test suite and both are now closed by a binding/SQL-shape guard that bites on SQLite.

## Next Phase Readiness

- Fix is shipped locally (2 atomic commits). Awaiting operator deploy + re-run per the steps above.
- No blockers. No follow-ups owed.

## Self-Check: PASSED

- `260626-fjg-SUMMARY.md` — FOUND
- Commit `c446e30` (test/RED) — FOUND
- Commit `c0dcb58` (fix/GREEN) — FOUND

---
*Phase: 260626-fjg-fix-backfill-category-from-woo-crash-on-*
*Completed: 2026-06-26*
