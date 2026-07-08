---
phase: 260708-jou-automate-catalogue-gaps-bulk-fixes-dispa
plan: 01
subsystem: Woo Maintenance / Catalogue Gaps
tags: [filament, horizon, queue, bulk-action, catalogue-gaps, throttle]
requires:
  - config/horizon.php sync-bulk-supervisor (balance=simple, maxProcesses=1) — already exists
  - services.woo.maintenance_fix_batch_limit (260708-gab)
provides:
  - RunCatalogueGapFixJob (queued, allow-listed, sync-bulk chunk fix-runner)
  - services.woo.maintenance_fix_max_per_run ceiling
  - chunked background bulk fixes on the Catalogue Gaps page
affects:
  - app/Filament/Pages/CatalogueGapsPage.php (bulk actions only; per-row unchanged)
tech-stack:
  added: []
  patterns:
    - "SyncChunkJob queue-job convention (ShouldQueue + Dispatchable/InteractsWithQueue/Queueable/SerializesModels + $this->onQueue in ctor)"
    - "Artisan command allow-list guard (defence-in-depth) inside a queued job"
key-files:
  created:
    - app/Domain/Products/Jobs/RunCatalogueGapFixJob.php
    - tests/Feature/Products/RunCatalogueGapFixJobTest.php
  modified:
    - app/Filament/Pages/CatalogueGapsPage.php
    - config/services.php
    - tests/Feature/Products/CatalogueGapsPageTest.php
decisions:
  - "Used Artisan::shouldReceive('call') Mockery spy instead of the plan's Artisan::fake() — this Laravel build has no Artisan::fake() (Kernel::fake() undefined); matches the existing CatalogueGapsPageTest convention."
metrics:
  duration: ~25m
  completed: 2026-07-08
---

# Phase 260708-jou Plan 01: Automate Catalogue Gaps Bulk Fixes (Dispatch) Summary

One click on a Catalogue Gaps bulk fix (Source images / Backfill EAN / Resync) now queues the ENTIRE ticked selection to the background as chunked `RunCatalogueGapFixJob` on the Horizon `sync-bulk` queue (maxProcesses=1 → one batch at a time) — replacing the 260708-gab synchronous 25-cap so the operator never has to manually run the next 25.

## What Changed

### New queued/chunked model
- **`app/Domain/Products/Jobs/RunCatalogueGapFixJob.php`** (new): runs ONE chunk of a bulk fix in the background. Mirrors `SyncChunkJob`'s queue-job convention (`implements ShouldQueue`; `Dispatchable, InteractsWithQueue, Queueable, SerializesModels`; `$this->onQueue('sync-bulk')` in the constructor). Runs the fix via `Artisan::call($command, ['--skus' => $csv])`.
- **`CatalogueGapsPage::bulkFixAction()`** rewritten: takes up to `config('services.woo.maintenance_fix_max_per_run', 1000)` selected SKUs, chunks them by `config('services.woo.maintenance_fix_batch_limit', 25)`, and dispatches one `RunCatalogueGapFixJob` per chunk. Notification reports how many products were queued in how many background batches, appending `(capped at N of M — run again for the rest)` when the selection exceeds the ceiling. Added `use App\Domain\Products\Jobs\RunCatalogueGapFixJob;`.
- **Per-row `fixAction()` is UNCHANGED** — single-SKU fixes still run synchronously via `Artisan::call` (the `Artisan` import is retained for it).

### The sync-bulk throttle
- Jobs dispatch onto `sync-bulk`. `config/horizon.php`'s `sync-bulk-supervisor` is `balance=simple`, `minProcesses=1`, `maxProcesses=1`, so batches are processed strictly one-at-a-time. A big run cannot saturate prod CPU or burst the per-SKU fix APIs (Icecat / Serper / EAN-search). **No supervisor / horizon.php / deploy change is needed** — `sync-bulk` already exists.

### Guardrails
- **Allow-list (defence-in-depth):** `RunCatalogueGapFixJob::ALLOWED_COMMANDS` is exactly the three fix commands. `handle()` refuses (logs a warning + returns) any command not on the list, so the job can never execute an arbitrary artisan command name that leaks into serialized job data.
- **`tries = 1`:** a money-costing per-SKU batch is never auto-retried.
- **`timeout = 900`:** 25 image-sources can be slow; safe because sync-bulk is single-worker.
- **Ceiling:** `services.woo.maintenance_fix_max_per_run` (default 1000) caps how many products a single click can queue; the rest wait for a re-run.
- **Empty/blank SKUs:** both the page action and the job no-op on an empty selection / blank SKUs.

### Config keys (`config/services.php`, inside `woo`)
- **New:** `'maintenance_fix_max_per_run' => (int) env('WOO_MAINTENANCE_FIX_MAX_PER_RUN', 1000)` — hard per-click queue ceiling.
- **Repurposed comment:** `maintenance_fix_batch_limit` (still `WOO_MAINTENANCE_FIX_BATCH_LIMIT`, default 25) is now documented as the CHUNK size (SKUs per `RunCatalogueGapFixJob`) for background bulk fixes, not a hard per-click cap.

## Tests

- **`tests/Feature/Products/RunCatalogueGapFixJobTest.php`** (new): allowed command runs once with the joined `--skus` CSV; every allow-listed command accepted (dataset); disallowed command never calls Artisan; empty/blank SKUs are a no-op; blank SKUs dropped from the CSV; `Queue::assertPushedOn('sync-bulk', ...)`; `tries === 1`.
- **`tests/Feature/Products/CatalogueGapsPageTest.php`**: the two 260708-gab synchronous-cap cases were rewritten to the queued model with `Queue::fake()` — asserts the right number of `RunCatalogueGapFixJob` pushed (`ceil(min(N,maxPerRun)/chunkSize)`), the union of all jobs' `->skus` equals the queued set (none lost/duplicated), ceiling enforced (chunk 1 + ceiling 2 + 4 selected → 2 jobs, a genuine subset), and each job carries the correct command per fix type (dataset). Existing gap-filter / per-row / missing_brand cases stay green.

Verification (`~/.config/herd/bin/php84/php.exe vendor/bin/pest`):
- `RunCatalogueGapFixJobTest` + `CatalogueGapsPageTest`: **22 passed (114 assertions)**.
- Pint on all 5 files: **pass**.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `Artisan::fake()` unavailable in this Laravel build**
- **Found during:** Task 1 (first test run).
- **Issue:** The plan's `RunCatalogueGapFixJobTest` used `Artisan::fake()` / `Artisan::assertRan()` / `Artisan::assertNotRun()`, but this build throws `Call to undefined method Illuminate\Foundation\Console\Kernel::fake()`.
- **Fix:** Switched to the `Artisan::shouldReceive('call')->once()->with(...)->andReturn(0)` / `->never()` Mockery spy pattern already used by the existing `CatalogueGapsPageTest` row-action cases. Same assertions (command name, `--skus` CSV, invocation count), portable across the codebase's Laravel version.
- **Files modified:** `tests/Feature/Products/RunCatalogueGapFixJobTest.php`.

## Driver Portability

Tests run on SQLite `:memory:` + `RefreshDatabase`; prod is MariaDB. No raw SQL added — the change is queue dispatch + config + Filament action logic only. No portability risk.

## Deploy Note

- **No migration.** Push `main` → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- **Horizon must be running** (it already is). The `sync-bulk-supervisor` (1 worker) processes the batches. **No `config/horizon.php` change** and no new supervisor.
- **Behaviour:** on Catalogue Gaps, tick as many products as you like and click a bulk fix. It now QUEUES the whole selection in background batches of `WOO_MAINTENANCE_FIX_BATCH_LIMIT` (25) and works through them one batch at a time — no more "run the next 25". A single click queues at most `WOO_MAINTENANCE_FIX_MAX_PER_RUN` (default 1000); above that it queues the first 1000 and asks you to run again. Watch progress at `/horizon`. Per-row (single-product) fixes still run immediately/synchronously.

## Self-Check: PASSED
- `app/Domain/Products/Jobs/RunCatalogueGapFixJob.php` — FOUND
- `app/Filament/Pages/CatalogueGapsPage.php` — FOUND
- `config/services.php` — FOUND (maintenance_fix_max_per_run present)
- `tests/Feature/Products/RunCatalogueGapFixJobTest.php` — FOUND
- `tests/Feature/Products/CatalogueGapsPageTest.php` — FOUND
- Commit hash recorded below.
