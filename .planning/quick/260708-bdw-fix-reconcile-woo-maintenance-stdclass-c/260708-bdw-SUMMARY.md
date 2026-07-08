---
phase: 260708-bdw-fix-reconcile-woo-maintenance-stdclass-c
plan: 01
subsystem: products / woo-maintenance
tags: [bugfix, woo, stdclass, reconcile, tdd]
requires:
  - "ReconcileWooMaintenanceCommand (260708-b4f) — products:reconcile-woo-maintenance"
  - "WooClient::get / normaliseResponseBody"
provides:
  - "stdClass-safe product access in the reconcile loop"
  - "stdClass test fixture matching the real Woo /products list shape"
affects:
  - app/Console/Commands/ReconcileWooMaintenanceCommand.php
  - tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php
tech-stack:
  added: []
  patterns:
    - "Cast each Woo LIST item to array before field access ($p = (array) $p) — WooClient returns list items as stdClass"
key-files:
  created: []
  modified:
    - app/Console/Commands/ReconcileWooMaintenanceCommand.php
    - tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php
decisions:
  - "Cast per-item in the command (robust for stdClass real-Woo AND array callers) rather than deep-casting inside WooClient — the broader WooClient change is a documented follow-up."
metrics:
  duration: ~10m
  completed: 2026-07-08
---

# Phase 260708-bdw Plan 01: Fix reconcile-woo-maintenance stdClass crash — Summary

**One-liner:** `products:reconcile-woo-maintenance` no longer dies with "Cannot use object of type stdClass as array" — each Woo list item is cast to array (`$p = (array) $p`) at the top of the per-product loop, and the feature-test fixture now returns stdClass objects so it reflects the real Woo shape and guards the regression.

## What changed

- **`app/Console/Commands/ReconcileWooMaintenanceCommand.php`** — added `$p = (array) $p;` as the first statement inside `foreach ($rows as $p) {`. Nothing else changed: `$p['id']`, `$p['images']`, `$p['global_unique_id']`, `$p['categories']`, `$p['stock_status']` all read as before. Still read-only on Woo (GETs only); output and behaviour unchanged.
- **`tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php`** — `reconcileFixtureRows()` now returns `(object)` product rows with `images`/`categories` as arrays of `(object) []`, matching what `WooClient` actually yields. Row 101 → 2 images / gtin `50123` / 1 category / instock; row 102 → 0 / '' / 0 / outofstock; page ≥ 2 → `[]`. All existing assertions kept.

## Root cause

`WooClient::normaliseResponseBody()` returns an already-array response (the `/products` LIST) **as-is**, so each list ITEM stays a `stdClass`; only a single top-level `stdClass` gets JSON-round-tripped into nested arrays. The command read `$p['id']` (array syntax) against those stdClass items → fatal in prod. The old array-shaped test fixture didn't match real Woo, so the bug shipped green. Making the fixture return stdClass closes the test-vs-reality gap; the `(array)` cast makes the loop safe whether the item is a stdClass (real Woo) or an array (future/other callers).

## TDD gates (RED → GREEN)

- **RED** — after switching the fixture to stdClass, the whole test file crashed: **4 failed (0 assertions)**, every case erroring `Cannot use object of type stdClass as array` at `ReconcileWooMaintenanceCommand.php:101`. Crash reproduced on the OLD command, exactly as the prod failure.
- **GREEN** — after adding `$p = (array) $p;`: **4 passed (38 assertions)**.
- Commits: `test(260708-bdw)` `127e0b4` (RED) → `fix(260708-bdw)` `2292f52` (GREEN).

## Verification

- `~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php` → **4 passed (38 assertions)** with the stdClass fixture (was 4 failed before the cast).
- `~/.config/herd/bin/php84/php.exe vendor/bin/pint --test` (command + test) → `{"result":"pass"}`.

## Deviations from Plan

None — the plan's `<interfaces>` were used verbatim. Only the two intended files touched; command stays read-only on Woo; output/behaviour otherwise unchanged.

## Operator notes (deploy + re-run)

- **Deploy:** push `main` → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration — code only).
- **Re-run reconciliation (now works):** `php artisan products:reconcile-woo-maintenance --dry-run` (preview) then without `--dry-run` (full read-only paged reconcile), then re-run the gap-count probe from the 260708-b4f verification to see the REAL shop-wide numbers.
- **Follow-up (not this task):** any future Woo LIST consumer must `(array)`-cast each item, OR change `WooClient` to always deep-cast list items — the broader, safer fix.

## Self-Check: PASSED

- FOUND: app/Console/Commands/ReconcileWooMaintenanceCommand.php (contains `(array) $p`)
- FOUND: tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php (contains `(object)`)
- FOUND commit: 127e0b4 (test)
- FOUND commit: 2292f52 (fix)
