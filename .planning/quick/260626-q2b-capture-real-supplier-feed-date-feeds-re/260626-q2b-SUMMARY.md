---
phase: 260626-q2b-capture-real-supplier-feed-date-feeds-re
plan: 01
subsystem: sync
tags: [suppliers, feed-date, filament, mysqli, scheduler, supplier-db]

requires:
  - phase: 260626-phz
    provides: SupplierResource feed-age helpers (workingDaysSince/feedAgeColor/feedAgeTooltip)
  - phase: 260626-oqr
    provides: SupplierResource scaffold + suppliers.is_active operator exclusion
provides:
  - suppliers.feed_remote_date / feed_cron_run / feed_status columns (the REAL supplier file date, mirrored locally)
  - suppliers:sync-feed-dates command (remote feeds.remote_date -> suppliers, metadata-only)
  - Suppliers admin page Feed date column reads the real feed_remote_date; truthful Status (Feed off/No data/Stale/Fresh); Upstream pull column
affects: [supplier-db-sync, pricing-stale-exclusion-followup, suppliers-admin]

tech-stack:
  added: []
  patterns:
    - "Pure testable upsert method (upsertFeedRows(array,bool)) decoupled from mysqli I/O — array-in unit tests, no remote DB"
    - "Metadata-only upsert: updateOrCreate writes ONLY name + feed_* fields, never operator-owned columns (is_active/stale_after_days/notes)"

key-files:
  created:
    - database/migrations/2026_06_26_120000_add_feed_metadata_to_suppliers_table.php
    - app/Domain/Sync/Commands/SyncSupplierFeedDatesCommand.php
    - tests/Feature/Console/SyncSupplierFeedDatesCommandTest.php
  modified:
    - app/Domain/Sync/Models/Supplier.php
    - app/Providers/AppServiceProvider.php
    - routes/console.php
    - app/Domain/Sync/Filament/Resources/SupplierResource.php
    - tests/Feature/Filament/Resources/SupplierResourceTest.php

key-decisions:
  - "Feed date now reads suppliers.feed_remote_date (the real feeds.remote_date), NOT recorded_at (the MS pull date, always today)."
  - "Zero-dates (NULL/empty/'0000-...') parse to null via parseFeedDate — no Carbon throw; shown as 'never'/'No data'."
  - "suppliers:sync-feed-dates is metadata-only — preserves operator fields and writes NO product price/stock."
  - "OUT OF SCOPE: the buy-price stale-exclusion (SupplierFreshnessResolver) is still recorded_at-based — switching it to feed_remote_date is a separate, pricing-sensitive follow-up needing its own dry-run."

patterns-established:
  - "Pure upsertFeedRows(array,bool) decouples remote-DB I/O from write logic for fast deterministic tests."
  - "Truthful status badge derived from real data + upstream feed_status (Feed off / No data / Stale / Fresh)."

requirements-completed: [QUICK-260626-q2b]

duration: ~20min
completed: 2026-06-26
---

# Phase 260626-q2b Plan 01: Capture the real supplier feed date Summary

**The Suppliers page now shows each supplier's TRUE file date (remote feeds.remote_date), so quiet suppliers surface RED with a truthful status instead of "fresh / today"; Nuvias shows Thu 14 May 2026 / RED / Feed off.**

## Performance

- **Duration:** ~20 min
- **Tasks:** 3/3
- **Files modified:** 8 (3 created, 5 modified)

## Accomplishments

### Root cause (the bug this fixes)

The Suppliers admin page derived "Feed date" from `supplier_offer_snapshots.recorded_at` — the date MeetingStore last **pulled**, stamped `today()` on every `supplier:db-sync` run regardless of whether the supplier actually refreshed. Every supplier therefore showed "fresh / today" even when its feed was weeks old.

**Probe that confirmed the real column (2026-06-26, live):** the supplier's true file date lives on the **remote** `feeds` table as `feeds.remote_date`. Nuvias (`feeds.id=1`) had `remote_date='2026-05-14 00:20:24'`, `cron_run='2026-05-20 12:53:12'`, `status=0`. MS never read it.

### The three pieces

1. **Migration + model (Task 1).** Adds `feed_remote_date` (datetime, nullable), `feed_cron_run` (datetime, nullable), `feed_status` (integer, nullable) + an index on `feed_remote_date` to the `suppliers` table. `Supplier` model fillable + casts updated (`feed_remote_date`/`feed_cron_run` => `datetime`, `feed_status` => `integer`).

2. **`suppliers:sync-feed-dates` command (Task 2, TDD).** Connects to the same remote SupplierDb MySQL VPS that `supplier:db-sync` uses (via the `SupplierDb` credential + mysqli), runs `SELECT id, name, remote_date, cron_run, status FROM feeds`, and calls the **pure** `upsertFeedRows(array $rows, bool $dryRun): array`. That method does `updateOrCreate(['supplier_id'=>$sid], [name + 3 feed_* fields])` — **metadata-only**: it writes NO price/stock and NEVER clobbers operator-owned `is_active` / `stale_after_days` / `notes` (proven by a preservation test). `parseFeedDate` nulls zero/empty dates so MySQL `'0000-00-00 00:00:00'` never throws. Scheduled **Mon-Fri 06:55 London** (just before the 07:00 price sync) via `routes/console.php`.

3. **Suppliers page rework (Task 3).** The **Feed date** column now reads the real `feed_remote_date` (sortable, formatted `D j M Y`, badge-coloured by the existing 5-working-day rule). The misleading recorded_at-based **Freshness** badge is replaced by a truthful **Status**: `Feed off` (feed_status===0) / `No data` (feed_remote_date null) / `Stale` (>5 working days, red) / `Fresh` (green). A new **Upstream pull** column shows `feed_cron_run`. The now-unused `SupplierFreshnessResolver` import was removed from the resource.

### The upstream insight (surfaced, not fixed here)

Nuvias's feed is **disabled upstream** (`feeds.status=0`) and the upstream puller last ran 2026-05-20. MS now shows the truth (RED / Feed off / 14 May), but the actual fix is on the supplier-feed server, separate from MeetingStore.

## Tests

| Suite | Result |
|-------|--------|
| `SyncSupplierFeedDatesCommandTest` (new) | 6 passed, 29 assertions |
| `SupplierResourceTest` (updated) | 9 passed, 20 assertions |
| `SupplierFeedAgeTest` (regression, helpers unchanged) | 12 passed |
| `tests/Feature/Domain/Sync/` (regression) | 13 passed (full run with helper unit = 25 passed, 38 assertions) |

- `schedule:list | grep sync-feed-dates` → present (`55 5 * * 1-5` UTC = 06:55 BST London, Mon-Fri).
- `grep latestRecordedAtFor|->make('freshness') SupplierResource.php` → no match (recorded_at-based columns gone).
- `pint --test` on all new/changed files → `{"result":"pass"}`.

## Deviations from Plan

None — plan executed exactly as written. (Command registration in `AppServiceProvider::boot` was implied by the existing pattern for `app/Domain/Sync/Commands/` commands and added accordingly; not a behavioural deviation.)

## Flagged Follow-up (pricing — NOT done here)

The automatic buy-price **stale-supplier exclusion** (`SupplierFreshnessResolver`) is ALSO `recorded_at`-based and therefore a no-op in prod. Switching it to `feed_remote_date` would change live buy-prices and needs its own dry-run + sign-off. The operator's manual `is_active` toggle (260626-oqr) already excludes Nuvias from pricing today.

## Operator Runbook

1. **Deploy:** push `main`, then on the VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (runs the new migration).
2. **Populate dates immediately** (don't wait for the 06:55 cron):
   `sudo -u stcav bash -c 'cd /home/stcav/ms.21stcav.com && php artisan suppliers:sync-feed-dates'`
3. Reload `/admin/suppliers` — Nuvias shows **Thu 14 May 2026**, RED, status **Feed off**, Upstream pull 20 May 2026.
4. The page now shows the TRUE supplier file date. Nuvias's underlying problem is upstream (feed disabled, puller last ran 2026-05-20) — fix on the supplier-feed server, separate from MS.

## Self-Check: PASSED
