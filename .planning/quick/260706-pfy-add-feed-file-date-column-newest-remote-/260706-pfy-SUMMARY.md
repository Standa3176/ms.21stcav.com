---
phase: 260706-pfy-add-feed-file-date-column-newest-remote-
plan: 01
subsystem: competitor
tags: [filament, competitor-feeds, display-only, tdd]
requires:
  - "260705-pw3: pure freshnessColorFor() + config('competitor.last_run_lag_hours')"
provides:
  - "CompetitorResource 'Feed file date' column (newest remote_file_date, no N+1)"
  - "CompetitorResource::latestActiveFeedFileDate() memoised reference"
affects:
  - "Settings > Competitor Feeds list page"
tech-stack:
  added: []
  patterns:
    - "withMax() table query modifier for per-row aggregate (no N+1)"
    - "static memoised cross-row reference for a Filament ->color() closure"
key-files:
  created:
    - tests/Feature/Competitor/CompetitorFeedFileDateColumnTest.php
  modified:
    - app/Domain/Competitor/Filament/Resources/CompetitorResource.php
decisions:
  - "Reused the pw3 pure freshnessColorFor() helper for the new column — no duplication."
  - "Reference (latestActiveFeedFileDate) is max over ACTIVE competitors' feeds only, so a dormant/inactive feed can't raise the bar and make live feeds look behind."
metrics:
  duration: ~20m
  completed: 2026-07-06
  tasks: 1
  files: 2
---

# Phase 260706-pfy Plan 01: Feed file date column (newest remote_file_date) Summary

Added a **Feed file date** column to Settings > Competitor Feeds showing each competitor's newest `remote_file_date` (the actual feed-FILE creation date) with the pw3 behind-the-latest-run red rule — the date the operator asked for that was previously absent from this page.

## What & Why

The operator wanted the competitor FEED FILE creation date on Settings > Competitor Feeds. Prod confirmed it was genuinely absent: the page only showed **Last Ingest** (`last_ingest_at`, ~05:05, when the app *processed* the feed). The actual feed-file date (`remote_file_date`, ~00:00–04:00, when the competitor's CSV was *produced*) lived only on the FTP Feeds page. This plan surfaces it here alongside Last Ingest.

## Changes (additive / display-only)

`app/Domain/Competitor/Filament/Resources/CompetitorResource.php`:
- Imports: `CompetitorFtpFeed`, `Illuminate\Database\Eloquent\Builder`.
- `->modifyQueryUsing(fn (Builder $q) => $q->withMax('ftpFeeds', 'remote_file_date'))` on the table — exposes `$record->ftp_feeds_max_remote_file_date` in one aggregate (**no N+1**).
- New memoised `latestActiveFeedFileDate(): ?Carbon` = `CompetitorFtpFeed::whereHas('competitor', active)->max('remote_file_date')` — the newest feed-file date across ACTIVE competitors' feeds, computed once per request. Ignores inactive competitors.
- New **Feed file date** `TextColumn` placed right after `last_ingest_at`: `->dateTime('Y-m-d H:i')`, `->placeholder('— none')`, `->color(...)` via the **reused** pw3 `freshnessColorFor(parsed date, latestActiveFeedFileDate(), config lag)`, plus a tooltip explaining behind/with the latest run.
- Tooltip on the existing **Feeds** count column: "Number of feed files configured for this competitor".

Nothing else touched — `last_ingest_at` (incl. its pw3 colouring), other columns, actions, badges, and the delete modal are unchanged.

`tests/Feature/Competitor/CompetitorFeedFileDateColumnTest.php` (RefreshDatabase, fixed clock): 3 active competitors with feeds at today-04:00 (A), today-03:00 (B), and 30h-behind (C) + 1 INACTIVE competitor with a more-recent (04:30) feed. Asserts `latestActiveFeedFileDate()` == A's 04:00 (ignores the inactive competitor's newer feed), `freshnessColorFor(C,ref,24)=='danger'`, `(B,ref,24)=='success'`, and null-feed → 'danger'. Guards the NEW reference query + active-scope + null path (the pure colour rule itself is already unit-tested in pw3).

## Verification

- `pest tests/Feature/Competitor/CompetitorFeedFileDateColumnTest.php tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php` → **10 passed (13 assertions)** — 2 new feature tests + 8 pw3 unit tests still green.
- `pint --test` on the resource → **pass**.

Confirmed: the Feed file date column reads the newest `remote_file_date` per competitor and colours a >24h-behind competitor **red** (danger), while a within-24h feed is green.

## Deviations from Plan

None — plan executed exactly as written (the `<interfaces>` code was used verbatim).

## Deploy Note (operator)

- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- Settings > Competitor Feeds now shows TWO dates: **Feed file date** (when the competitor's CSV was produced — `remote_file_date`, the "creation date" you wanted) and **Last Ingest** (when the app processed it). Both go RED when behind the latest run (>24h older than the newest, tunable via `COMPETITOR_LAST_RUN_LAG_HOURS`). **Feeds** column = number of configured feed files (now tooltipped).
- With current prod data all feeds are same-day (00:00–04:00) so all show GREEN; a competitor whose file stops updating goes red once it's a day behind the newest.

## Self-Check: PASSED
- FOUND: app/Domain/Competitor/Filament/Resources/CompetitorResource.php
- FOUND: tests/Feature/Competitor/CompetitorFeedFileDateColumnTest.php
- FOUND commit: 11f5d80
