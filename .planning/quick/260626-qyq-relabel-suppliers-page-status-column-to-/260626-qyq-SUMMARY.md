---
phase: 260626-qyq-relabel-suppliers-page-status-column-to-
plan: 01
subsystem: sync-admin
tags: [filament, suppliers, ui-relabel, feed-status]
requires:
  - suppliers.feed_status (260626-q2b — mirror of remote feeds.status)
provides:
  - "SupplierResource 'Feed status' column = Active/Inactive/Unknown from feed_status"
affects:
  - /admin/suppliers (Filament table)
key-files:
  modified:
    - app/Domain/Sync/Filament/Resources/SupplierResource.php
    - tests/Feature/Filament/Resources/SupplierResourceTest.php
decisions:
  - "Status column reads feed_status straight (Active=1/Inactive=0/Unknown=null), mirroring the legacy dashboard's feeds.status — NOT a Fresh/Stale/Feed-off blend."
  - "Staleness stays solely on the Feed date column's red >5-working-day colour; the status column no longer folds in feed-date age."
  - "'Feed status' kept distinct from the operator 'Active' toggle (is_active, MS-side pricing exclusion)."
metrics:
  tasks_completed: 1
  files_changed: 2
  tests: "9/9 GREEN (20 assertions)"
  completed: 2026-06-26
---

# Phase 260626-qyq Plan 01: Relabel Suppliers status column to 'Feed status' Summary

Replaced the Suppliers admin table's blended 'Feed off / No data / Stale / Fresh'
badge with a 'Feed status' column read straight from `suppliers.feed_status`
(Active=1 → green, Inactive=0 → red, Unknown=null → gray), mirroring the legacy
dashboard's Status column (`feeds.status`). Staleness now lives solely on the
Feed date column's red colour.

## What changed

### `SupplierResource.php`
- The `feed_status_label` column was relabelled `'Status'` → `'Feed status'`.
- Its `getStateUsing` body — previously `Feed off` (feed_status=0) / `No data`
  (null date) / `Stale` (>5 working days) / `Fresh` — is now a pure
  `match($record->feed_status){1=>'Active',0=>'Inactive',default=>'Unknown'}`.
- The colour map is now `Active=>success, Inactive=>danger, default=>gray`.
- The column comment block and the class-level docblock were refreshed to
  describe Active/Inactive-from-`feeds.status`; the old Fresh/Stale/Feed-off
  wording was dropped (so `grep "Feed off|'Stale'|'Fresh'"` returns nothing).
- The Feed date column (real `feed_remote_date`, red >5 working days) and all
  other columns are unchanged — staleness is conveyed there, exactly like the
  legacy dash, so an Active-but-ancient feed shows 'Active' with a RED date.

### `SupplierResourceTest.php`
- The two feed-status feature cases were updated to the new wording:
  - Nuvias (`feed_status=0`) now asserts `'Inactive'` (was `'Feed off'`); the
    `'Thu 14 May 2026'` Feed-date assertion is kept.
  - The `feed_status=1` (Ingram) case now asserts `'Active'` (was `'Fresh'`).

## Why

1. The old blend duplicated the staleness signal already shown by the Feed date
   column's red colour. Pulling age out of the status column removes that
   duplication and matches the legacy dashboard the operator works from.
2. The old badge was confusable with the operator **'Active' toggle**
   (`is_active`, the MS-side pricing-exclusion control from 260626-oqr). The new
   label 'Feed status' is clearly distinct.

## Verification

| Check | Command | Result |
| ----- | ------- | ------ |
| Feature test | `php84 vendor/bin/pest tests/Feature/Filament/Resources/SupplierResourceTest.php` | 9 passed, 20 assertions |
| Old wording gone | `grep -n "Feed off\|'Stale'\|'Fresh'" SupplierResource.php` | no match (exit 1) |
| Pint (resource) | `php84 vendor/bin/pint --test app/.../SupplierResource.php` | `{"result":"pass"}` |
| Pint (test) | `php84 vendor/bin/pint --test tests/.../SupplierResourceTest.php` | `{"result":"pass"}` |

(All via Herd PHP: `~/.config/herd/bin/php84/php.exe`.)

## Deviations from Plan

None — plan executed exactly as written. The class-level docblock (beyond the
inline column comment named in the plan) was also updated to remove a residual
'Feed off' mention, which the grep verification command would otherwise have
flagged; this is within the plan's "Update the column comment" intent.

## Known Stubs

None.

## Scope notes

- No migration, no model change, no command change — pure Filament column relabel.
- Pre-existing unrelated working-tree noise (`storage/app/research/supplier-probe.json`
  deletion, untracked `.claude/`) was left untouched (out of scope).

## Deploy

Push `main`, then on the VPS:
`sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
Reload `/admin/suppliers` → the status column reads **Feed status**
Active/Inactive/Unknown.

## Self-Check: PASSED

- FOUND: `app/Domain/Sync/Filament/Resources/SupplierResource.php`
- FOUND: `tests/Feature/Filament/Resources/SupplierResourceTest.php`
- FOUND commit `ac8ea6a` (feat(260626-qyq): relabel Suppliers status column)
