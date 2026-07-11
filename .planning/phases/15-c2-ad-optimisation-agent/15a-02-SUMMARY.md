---
phase: 15
plan: 15a-02
subsystem: Integrations / Marketing Intelligence
tags: [ga4, google-analytics, snapshot, read-only, filament, schedule, tdd]
requires:
  - App\Domain\Integrations\Clients\GoogleAnalyticsClient (Phase 15 15a-01) — runReport() seam
  - IntegrationCredentialResolver + GoogleAnalytics credential kind (Phase 09.1 / 15a-01)
  - BaseCommand (correlation-id threading)
provides:
  - ga_channel_metrics_daily snapshot table + App\Domain\Integrations\Models\GaChannelMetric
  - GoogleAnalyticsClient::fetchChannelMetrics(from,to) — READ-ONLY protobuf→array mapper
  - google:pull-ga4 command (idempotent daily pull; safe no-op until GA4 configured)
  - Read-only GA4 Channels Filament viewer under a new 'Marketing' nav group
  - Twice-daily schedule (06:00/14:00 London) for google:pull-ga4
affects:
  - 15b (analyser services will read ga_channel_metrics_daily; Marketing Deptrac layer deferred to 15b)
tech-stack:
  added: []
  patterns:
    - "GA4 date dimension included so each row carries its own day (daily grain)"
    - "Money stored as integer pennies (purchase_revenue_pennies); (int) round(revenue*100) mapping in the command"
    - "date:Y-m-d cast (not bare 'date') for driver-portable idempotent updateOrCreate on SQLite + MariaDB"
    - "Read-only Filament resource in the presentation (Http) Deptrac layer; model in Integrations"
key-files:
  created:
    - database/migrations/2026_07_11_000000_create_ga_channel_metrics_daily_table.php
    - app/Domain/Integrations/Models/GaChannelMetric.php
    - app/Domain/Integrations/Commands/PullGa4Command.php
    - app/Domain/Integrations/Policies/GaChannelMetricPolicy.php
    - app/Domain/Integrations/Filament/Resources/GaChannelMetricResource.php
    - app/Domain/Integrations/Filament/Resources/GaChannelMetricResource/Pages/ListGaChannelMetrics.php
    - tests/Feature/Integrations/GaChannelMetricTest.php
    - tests/Feature/Integrations/PullGa4CommandTest.php
    - tests/Feature/Integrations/GaChannelMetricResourceTest.php
  modified:
    - app/Domain/Integrations/Clients/GoogleAnalyticsClient.php
    - tests/Unit/Domain/Integrations/Clients/GoogleAnalyticsClientTest.php
    - app/Providers/AppServiceProvider.php
    - app/Providers/Filament/AdminPanelProvider.php
    - routes/console.php
decisions:
  - "Included a GA4 `date` dimension (4 dims total) despite the plan text saying '3 dimensions' — the normalized output needs a per-row date and GA4 collapses the range into one row without it (Rule 2/3, correctness of the daily grain)."
  - "Model $timestamps=false — table has only pulled_at (the plan's explicit column grain lists no created/updated)."
  - "date cast pinned to date:Y-m-d so the grain updateOrCreate lookup matches on SQLite (bare 'date' serializes with a time part → re-insert → unique clash)."
  - "GaChannelMetricPolicy: viewAny/view = any authed workspace user; all mutations denied (producer-owned table)."
metrics:
  duration: ~55m
  completed: 2026-07-11
  tasks: 5
  files_created: 9
  files_modified: 5
---

# Phase 15 Plan 15a-02: GA4 Daily Pull → Snapshot → Marketing Viewer Summary

READ-ONLY end to end: a daily GA4 channel/campaign pull lands in a new
`ga_channel_metrics_daily` snapshot table via the idempotent `google:pull-ga4`
command, surfaced by a read-only Filament "GA4 Channels" viewer under a new
**Marketing** nav group, and scheduled twice-daily. Built entirely against the
15a-01 partial-mock test seam (no network, no real GA4). The scheduled command
no-ops gracefully (logs + exits 0) whenever GA4 is unconfigured, so it is safe
to ship in prod today — it starts populating the snapshot the instant an
operator saves a GA4 service-account credential.

## Tasks completed

| Task | Description | Commit |
| ---- | ----------- | ------ |
| 1 | `ga_channel_metrics_daily` migration + `GaChannelMetric` model (grain unique + date index; money in pennies) | `655c85c` |
| 2 | `GoogleAnalyticsClient::fetchChannelMetrics()` READ-ONLY pull + protobuf→array mapping; [] on null creds | `39b30cb` |
| 3 | `google:pull-ga4` command (idempotent upsert, money-mapping, --dry-run, unconfigured no-op) + registration | `c9aed5e` |
| 4 | Read-only GA4 Channels Filament viewer + 'Marketing' nav group + policy | `119546b` |
| 5 | Twice-daily schedule (06:00/14:00 London), safe no-op until configured | `688d7b6` |

## Table grain + columns

`ga_channel_metrics_daily` — one row per grain
**date × channel_group × source_medium × campaign** (named composite unique
`gcm_grain_unique`, plus a `date` index):

| Column | Type | Notes |
| ------ | ---- | ----- |
| id | bigint PK | |
| date | date (indexed) | GA4 `date` dimension, normalized to Y-m-d |
| channel_group | string(128) | sessionDefaultChannelGroup |
| source_medium | string(191) | sessionSourceMedium |
| campaign | string(191, nullable) | sessionCampaignName |
| sessions | unsigned int | |
| key_events | unsigned int | GA4 `keyEvents` |
| transactions | unsigned int | |
| purchase_revenue_pennies | unsigned bigint | integer pennies — the one money-mapping |
| pulled_at | timestamp (nullable) | command write time |

Driver-portable DDL (SQLite tests / MariaDB prod); `source_medium`/`campaign`
capped at 191 so the composite unique stays within MariaDB's utf8mb4 index-length
ceiling.

## Money-mapping test result

`purchase_revenue_pennies = (int) round(purchase_revenue * 100)`.
Explicitly tested in `PullGa4CommandTest`: a canned row with
`purchase_revenue => 1234.56` persists `purchase_revenue_pennies === 123456`
(assertion passing). A `0.0` revenue row maps to `0`.

## Unconfigured no-op path — TESTED

`fetchChannelMetrics()` returns `[]` when credentials are null (unit test), and
`google:pull-ga4` logs (`google.pull_ga4.noop`) and returns exit code 0 while
writing zero rows when the client returns `[]`
(`PullGa4CommandTest` → "exits 0 and writes nothing when fetchChannelMetrics
returns [] (unconfigured no-op)", passing). This is the hard requirement that
makes the schedule safe to ship before credentials exist.

## Verify results

- **pest (touched areas):** GREEN. The four 15a-02 test files = **23 passed
  (91 assertions)**. Wider Integrations + policy-integrity run = **75 passed,
  1 skipped (337 assertions)** — the skip is pre-existing and unrelated.
- **route:list --path=admin:** exit 0; `admin/ga4-channels` →
  `filament.admin.resources.ga4-channels.index` resolves.
- **schedule:list:** shows `php artisan google:pull-ga4` (`0 5,13 * * *` UTC =
  06:00/14:00 Europe/London); exit 0.
- **pint:** all 15a-02-touched files pass `pint --test`. (Pre-existing pint
  failures in untouched Integrations files logged to `deferred-items.md`.)
- **deptrac analyse:** **0 violations**, 0 skipped, 0 errors (no new layer;
  model in Integrations, resource in the presentation/Http layer).

## Deviations from Plan

### Auto-fixed / auto-added (Rules 1–3)

**1. [Rule 2/3 — Missing critical functionality] Added the GA4 `date` dimension**
- **Found during:** Task 2
- **Issue:** The plan text says "the 3 dimensions", but the normalized output
  (and the daily grain) require a per-row `date`. Without a `date` dimension GA4
  aggregates the whole range into a single row, collapsing the daily grain.
- **Fix:** `fetchChannelMetrics()` requests 4 dimensions
  (`date`, `sessionDefaultChannelGroup`, `sessionSourceMedium`,
  `sessionCampaignName`) and normalizes GA4's `YYYYMMDD` to `Y-m-d`.
- **Files:** `GoogleAnalyticsClient.php` — **Commit:** `39b30cb`

**2. [Rule 1 — Bug] `date` cast pinned to `date:Y-m-d`**
- **Found during:** Task 3 (idempotency test failed on SQLite)
- **Issue:** The bare `'date'` cast serializes as `Y-m-d 00:00:00`; on SQLite a
  DATE column keeps the time part, so `updateOrCreate`'s grain lookup missed and
  re-inserted → UNIQUE violation (idempotency broken).
- **Fix:** `'date' => 'date:Y-m-d'` — date-only serialization, portable across
  SQLite + MariaDB.
- **Files:** `GaChannelMetric.php` — **Commit:** `c9aed5e`

**3. [Rule 1 — Bug] Docblock `*/` sequence closed a comment early**
- **Found during:** Task 4 (resource parse error)
- **Issue:** A docblock containing the path glob `app/Domain/*/Filament/*`
  embedded the literal `*/` token, prematurely closing the docblock →
  `unexpected token "class"`.
- **Fix:** reworded the comment to avoid the `*/` sequence.
- **Files:** `GaChannelMetricResource.php` — **Commit:** `119546b`

### Test-design adjustment (not a product change)

- The idempotency test originally re-bound the client mock between two
  `artisan()` calls; Artisan caches the resolved command instance in-process, so
  the second binding didn't take. Rewrote to (a) pre-seed a grain row then pull
  once and assert overwrite, and (b) run twice with the same client and assert no
  duplicate. The command's `updateOrCreate` idempotency is unchanged.

## Known Stubs

None. The viewer reads live snapshot rows; no placeholder/mock data. The table
is empty in prod only until a GA4 credential is provisioned (by design — the
scheduled no-op path).

## Guardrails honoured

- READ-ONLY end to end — no GA4/Google-Ads writes, no closed-loop/GCLID, no
  advisory agent, **no new Deptrac layer** (model in Integrations, resource is
  presentation).
- Pre-existing working-tree noise left untouched and unstaged:
  `storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, `.claude/`.
- Not pushed, not deployed. Atomic commit per task.

## Post-deploy note

This slice adds ONE migration (`ga_channel_metrics_daily`). On deploy, run
`php artisan migrate`. The schedule is live but a no-op until a `GoogleAnalytics`
integration credential (service_account_json + property_id) is saved.

## Self-Check: PASSED

All 9 created files + the SUMMARY exist on disk; all 5 task commits
(`655c85c`, `39b30cb`, `c9aed5e`, `119546b`, `688d7b6`) resolve in git log.
