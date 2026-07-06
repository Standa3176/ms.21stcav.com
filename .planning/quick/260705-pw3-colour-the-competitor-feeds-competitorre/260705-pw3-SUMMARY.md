---
phase: 260705-pw3-colour-the-competitor-feeds-competitorre
plan: 01
subsystem: competitor-feeds
tags: [filament, competitor, ui, display-only, tdd]
requires:
  - CompetitorResource (existing last_ingest_at column)
  - Competitor model (STATUS_ACTIVE, is_active, last_ingest_at datetime cast)
provides:
  - CompetitorResource::freshnessColorFor (pure colour rule)
  - CompetitorResource::latestActiveIngestAt (memoised reference)
  - config('competitor.last_run_lag_hours')
affects:
  - Settings > Competitor Feeds list (Last Ingest column colour + tooltip)
tech-stack:
  added: []
  patterns:
    - "PURE unit-tested colour helper + memoised DB reference (one MAX() per render, not per row)"
    - "Mirrors the supplier/FTP-feed freshness colouring convention"
key-files:
  created:
    - tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php
  modified:
    - app/Domain/Competitor/Filament/Resources/CompetitorResource.php
    - config/competitor.php
decisions:
  - "'Behind the latest run' = null OR > config('competitor.last_run_lag_hours', 24) hours older than the newest ACTIVE (status=active + is_active) last_ingest_at."
  - "Strict lt on the boundary — a feed exactly lagHours behind is still GREEN (from the run), only strictly-more-than goes RED."
  - "Reference is the newest active ingest, not now() — so same-run timing skew doesn't false-flag; distinct from the 48h stale_feed_hours alert."
metrics:
  duration: ~15m
  completed: 2026-07-05
  commits: 2
  tests-added: 8
---

# Quick Task 260705-pw3: Colour the Competitor Feeds Last Ingest column Summary

Colour-coded the existing `last_ingest_at` ("Last Ingest") column on Settings > Competitor
Feeds (`CompetitorResource`): **RED** when a competitor is behind the latest feed run,
**GREEN** when it arrived with it, **GRAY** when there's no reference yet. Additive,
display-only, config-tunable — mirrors the supplier/FTP-feed freshness colouring.

## The red rule

A competitor is "behind the latest run" when:

- `last_ingest_at IS NULL`, **or**
- `last_ingest_at` is more than `config('competitor.last_run_lag_hours', 24)` hours older
  than the **newest** `last_ingest_at` across **ACTIVE** competitors (status=active +
  is_active).

A feed within the tolerance of the newest active ingest counts as "from the last run"
(green), so same-run timing skew doesn't false-flag — but a feed that missed the most recent
run (a day+ behind) goes red even while under the 48h stale threshold. This **complements**
(does not change) the existing `competitor.stale_feed_hours` (48h) alert and the FTP Feeds
page's `remote_file_date` red rule.

## What changed

### `config/competitor.php`
New key near `stale_feed_hours`:
```php
'last_run_lag_hours' => (int) env('COMPETITOR_LAST_RUN_LAG_HOURS', 24),
```

### `app/Domain/Competitor/Filament/Resources/CompetitorResource.php`
- Added `use Carbon\Carbon;`.
- **PURE** `freshnessColorFor(?Carbon $lastIngestAt, ?Carbon $latestRun, int $lagHours): string`
  — `latestRun` null → `'gray'`; `lastIngestAt` null → `'danger'`; else
  `$lastIngestAt->lt($latestRun->copy()->subHours(max(0, $lagHours)))` ? `'danger'` : `'success'`.
  Strict `lt`, so the exact boundary is still green.
- **Memoised** `latestActiveIngestAt(): ?Carbon` — `MAX(last_ingest_at)` over active +
  is_active competitors, computed **once per render** via a static memo (two static props:
  a `?Carbon` value + a `bool` loaded-flag), so the per-row `->color()` closure doesn't run a
  `MAX()` per row.
- Wired the **existing** `last_ingest_at` `TextColumn` with `->color(...)` (calls the pure fn
  with the record's ingest, the memoised reference, and the config lag) plus a `->tooltip(...)`
  that explains a red cell ("Behind the latest run (… before the newest feed)" / "From the
  latest feed run" / "Never ingested — not from the last feed run"; returns null when there's
  no reference).

**Additive / display-only:** the column's `->dateTime`/`->placeholder`/data/sort, the resource
query, the status badge, the ToggleColumn, the actions, and every other column are unchanged.

## Unit cases (pure `freshnessColorFor`, Carbon fixtures, no DB)

`tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php` — 8 cases:

| Case | lastIngest | latestRun | lag | Expected |
|------|-----------|-----------|-----|----------|
| no reference | set | null | 24 | gray |
| never ingested | null | set | 24 | danger |
| exactly with run | == latest | set | 24 | success |
| within tolerance | 2h before | set | 24 | success |
| missed the run | 30h before | set | 24 | danger |
| exact boundary (strict lt) | 24h before | set | 24 | success |
| lag 0, strictly before | 1s before | set | 0 | danger |
| lag 0, equal | == latest | set | 0 | success |

TDD: RED-confirmed (8 failed — method absent) → GREEN (8 passed).

## Verification (Herd php84)

- `pest tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php` → **8 passed (8 assertions)**.
- `pint --test` on resource + config + test → `{"result":"pass"}`.
- `pest tests/Feature/Competitor` → **202 passed, 2 failed**. The 2 failures are
  **pre-existing, unrelated** `ShieldRestorationProtocolTest` RBAC guardrails (a stray
  `app/Foundation/Integration/Policies/` directory + a `{{ }}` placeholder leak in a Policy
  file) — this task touched only a Filament Resource, a config key, and a unit test, none of
  which can affect Policy files or the Foundation tree. Logged to `deferred-items.md`, out of
  scope. All 202 other competitor feature tests pass.
- Confirmed the acceptance behaviour: a competitor **30h** behind the newest active ingest
  colours **danger**; one **within 24h** colours **success**.

## Deviations from Plan

None — plan executed exactly as written (RED → GREEN, no refactor needed). The 2 pre-existing
Shield failures were deferred per the scope boundary (not caused by this change).

## Deploy / tolerance note

- **Deploy:** push `main` → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`
  (no migration). NOT pushed / NOT deployed by this task — local commits only.
- **Settings > Competitor Feeds:** the Last Ingest column now shows RED for any competitor
  behind the latest feed run (or never ingested), GREEN for those in the latest run. Hover a
  cell for the reason.
- **Tolerance knob:** `COMPETITOR_LAST_RUN_LAG_HOURS` (default 24) — lower it (e.g. 12) if
  feeds run more than once a day and you want same-day laggards flagged sooner; raise it for
  every-other-day feeds.
- **Reference caveat:** the reference is the newest `last_ingest_at` across ACTIVE
  competitors. If all feeds are behind together (whole run missed), they compare against the
  newest of themselves — pair this with the existing 48h `competitor.stale_feed_hours` alert
  which catches a whole-catalogue stall.

## Self-Check: PASSED

- FOUND: `app/Domain/Competitor/Filament/Resources/CompetitorResource.php` (contains `freshnessColorFor`)
- FOUND: `config/competitor.php` (contains `last_run_lag_hours`)
- FOUND: `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`
- FOUND commit: `21d6d8f` (test, RED)
- FOUND commit: `2ce78d6` (feat, GREEN)

## Commits

- `21d6d8f` — `test(260705-pw3): add failing unit test for competitor last-ingest freshness colour`
- `2ce78d6` — `feat(260705-pw3): colour Competitor Feeds Last Ingest red when behind latest run`
