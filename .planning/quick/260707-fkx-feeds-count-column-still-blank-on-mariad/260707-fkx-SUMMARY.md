---
phase: 260707-fkx-feeds-count-column-still-blank-on-mariad
plan: 01
subsystem: Competitor
tags: [filament, competitor, sqlite-mariadb-divergence, quick-task]
requires: []
provides: "Engine-independent Feeds count column on CompetitorResource"
affects:
  - app/Domain/Competitor/Filament/Resources/CompetitorResource.php
  - tests/Feature/Competitor/CompetitorFeedsCountColumnTest.php
tech-stack:
  added: []
  patterns:
    - "Filament computed ->state() relation count instead of ->counts() aggregate for MariaDB safety"
key-files:
  created: []
  modified:
    - app/Domain/Competitor/Filament/Resources/CompetitorResource.php
    - tests/Feature/Competitor/CompetitorFeedsCountColumnTest.php
decisions:
  - "Feeds column resolves via per-row ftpFeeds()->count() (~5-row settings table, N+1 negligible)"
  - "Dropped ->sortable() on the Feeds column — a computed ->state can't DB-sort"
metrics:
  duration: "~10 min"
  completed: 2026-07-07
---

# Phase 260707-fkx Plan 01: Feeds Count Column Still Blank on MariaDB Summary

Replaced the Feeds column's fragile `->counts('ftpFeeds')` aggregate with an engine-independent per-row `->state(fn (Competitor $record) => $record->ftpFeeds()->count())`, fixing the column that stayed blank on prod (MariaDB) even after the 260707-f06 key rename.

## What Changed

`CompetitorResource::table()` — the Feeds column (`ftp_feeds_count`):
- Removed `->counts('ftpFeeds')` (the fragile aggregate).
- Removed `->sortable()` (a computed `->state` has no DB column to sort by).
- Added `->state(fn (Competitor $record): int => $record->ftpFeeds()->count())`.
- Kept `->label('Feeds')` and `->tooltip(...)`.

Untouched (as mandated): the `->modifyQueryUsing(withMax('ftpFeeds','remote_file_date'))` modifier, the Feed file date column, the Last Ingest colouring, and all other columns/actions.

## Why the f06 Key Rename Wasn't Enough (SQLite↔MariaDB Divergence)

f06 (b872801) correctly renamed the column key to `ftp_feeds_count` to match Filament's `withCount('ftpFeeds')` alias. That made the SQLite test suite green — because on SQLite the `withCount` subquery **and** the table's `->modifyQueryUsing(withMax('ftpFeeds','remote_file_date'))` modifier both resolve and populate `ftp_feeds_count` on the model. On MariaDB (prod) the two aggregate selects combined did **not** populate the `ftp_feeds_count` attribute, so the column read `null` → blank.

This is the project's recurring SQLite↔MariaDB trap (see MEMORY): tests run on SQLite, prod is MariaDB strict, so green tests do not prove prod-safe aggregate-select behaviour. The bug was structurally invisible to the SQLite test suite.

The fix sidesteps aggregate-select behaviour entirely: a plain per-row `COUNT` via the relation query runs identically on SQLite and MariaDB and cannot be affected by subquery/aggregate-select semantics.

## Trade-off

The Feeds column is no longer DB-sortable (it is now a computed value). Acceptable for a ~5-row settings list. If sorting is wanted back, it can be re-added via a `withCount` + `orderBy` that is explicitly MariaDB-verified.

## Deviations from Plan

None — plan executed exactly as written.

## Verification

- `pest CompetitorFeedsCountColumnTest.php` → GREEN (1 passed, 5 assertions; column state = 2 and 0 via the `->state` closure).
- `pest CompetitorFeedFileDateColumnTest.php CompetitorIngestFreshnessColorTest.php` (pfy + pw3 regression) → GREEN (10 passed, 13 assertions).
- `pint --test` on `CompetitorResource.php` and the test file → PASS.

## Deploy Note (Operator)

- Deploy: push `main` → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- After deploy the Feeds column should show the count on prod (MariaDB). If it is STILL blank, that would point to something outside the query (unlikely with `->state`) — screenshot and probe the live render directly.
- NOT pushed / NOT deployed by this task — committed locally only.

## Self-Check: PASSED
