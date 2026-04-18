---
phase: 02-supplier-sync
plan: 03-orchestration
subsystem: sync,events,orchestration
tags: [domain-event-retrofit, should-dispatch-after-commit, abort-guard, sync-chunk-job, mark-missing-job, sync-supplier-command, d-03, d-04, d-05, d-06, d-07, d-09, p2-i, p2-e, p2-f, sync-01, sync-03, sync-05, sync-06, sync-07, sync-09, sync-10, sync-13]

requires:
  - phase: 01-03-foundation
    provides: "DomainEvent base class (target of ShouldDispatchAfterCommit retrofit); BaseCommand + Auditor (SyncSupplierCommand inherits + emits); Context::hydrated correlation-id threading"
  - phase: 01-04-seams
    provides: "WooClient shadow-gate (writes route through SyncDiff when WOO_WRITE_ENABLED=false) + suggestions seam (sibling Phase 1 event consumer)"
  - phase: 01-05-horizon-alerting
    provides: "sync-bulk + sync-woo-push Horizon supervisors; EventServiceProvider existing JobFailed→ThrottledFailedJobNotifier map"
  - phase: 02-01-data-model
    provides: "6 migrations + 6 models + 5 policies; SyncRun state-machine + incrementCounter; consecutive_failures column (D-06b blocker fix); Product+ProductVariant factories for job tests"
  - phase: 02-02-external-clients
    provides: "WooClient::get + live writeLive (429 backoff); SupplierClient::fetchAllProducts (JWT retry-once-on-401); JwtRefreshFailedException + RateLimitExceededException"

provides:
  - "DomainEvent base class now implements Illuminate\\Contracts\\Events\\ShouldDispatchAfterCommit — events dispatched inside DB::transaction() that rolls back do NOT fire listeners (Pitfall P2-I fix). Phase 1's 2 existing subclasses (OrderReceived, CustomerRegistered) plus the 4 new Phase 2 events all inherit the invariant."
  - "4 new Phase 2 events: SupplierPriceChanged {sku, wooProductId, wooVariationId, oldPrice, newPrice, reason}, SupplierStockChanged {sku, wooProductId, wooVariationId, oldStock, newStock, reason}, SupplierSkuMissing {sku, wooProductId, wooVariationId, hadCustomMsTag, newStatus}, NewSupplierSkuDetected {sku, supplierPrice, supplierStock}."
  - "StubNewSupplierSkuListener — no-op listener so the D-09 event doesn't pile up in failed_jobs waiting for Phase 6."
  - "EventServiceProvider.protected \\$listen maps NewSupplierSkuDetected → StubNewSupplierSkuListener."
  - "App\\Domain\\Sync\\Services\\WooProductIterator — yields flat {type, sku, woo_product_id, woo_variation_id, price, stock_quantity, manage_stock, is_custom_ms, exclude_from_auto_update, attributes?} rows. Outer pagination on /products at per_page=100 until <100. For type=variable: inner pagination on /products/{id}/variations (A9 mitigation). Skips grouped/external (v1 D-02). Case-insensitive custom-ms slug match. Truthy-aware _exclude_from_auto_update meta match (yes/1/true)."
  - "App\\Domain\\Sync\\Services\\SkuMatcher — in-memory hashmap, case-sensitive per AUTO-08 convention. 10k SKUs build+match in under 100ms."
  - "App\\Domain\\Sync\\Services\\AbortGuard — STATELESS (NOT singleton) D-06 tiered abort. recordSuccess / recordFailure / triggerJwtFailure all issue atomic SQL on sync_runs columns (consecutive_failures, failed_count, total_skus, abort_reason). throwIfTriggered checks JWT flag first, then consecutive ≥ 50 (D-06b), then error rate > 20% with ≥ 500 samples (D-06a). Multi-worker supervisors share state via DB row (Checker blocker fix — two AbortGuard instances recording 25 failures each on the same run trip the 50-consecutive threshold)."
  - "App\\Domain\\Sync\\Services\\SyncDiffEngine — compares Woo↔supplier row; returns null on exact match, action=skipped with reason=exclude_from_auto_update when exclude flag set (SYNC-07), action=updated with {regular_price?, stock_quantity?} payload on diff. Price normalisation strips trailing zeros so '199.00' === '199.0' === '199'."
  - "App\\Domain\\Sync\\Exceptions\\SyncAbortException — carries reason matching SyncRun::ABORT_* constants."
  - "App\\Domain\\Sync\\Jobs\\SyncChunkJob — ShouldQueue on sync-woo-push queue. 120s timeout (P2-E bump from 90s). 3 tries with [10,30,90] backoff. Per-SKU idempotency via Product/ProductVariant.last_synced_at > run.started_at (P2-F). DB::transaction wraps Woo put + local mirror + run_item insert — rolled-back writes do NOT fire events (P2-I). Failures → sync_errors row + AbortGuard::recordFailure (NOT also \\$run->incrementCounter('failed') — disjoint columns). Successes → \\$run->incrementCounter('updated'|'skipped') + AbortGuard::recordSuccess (disjoint)."
  - "App\\Domain\\Sync\\Jobs\\MarkMissingSkusJob — sync-bulk queue, 600s timeout. Post-pass for Woo-only SKUs. Single WooClient::put branch gated by \\$shouldWrite (iter-1 Warning fix: no redundant dry/live split — WooClient's env gate handles). D-03 granular: simple w/o custom-ms → pending (write), simple WITH custom-ms → publish unchanged (no write, event still fires), variation → private (write, ignores parent tag). Populates SyncRunItem.old_price + old_stock from Woo-side missingRows (Warning 6 — D-10 11-col CSV contract)."
  - "App\\Domain\\Sync\\Commands\\SyncSupplierCommand — extends BaseCommand. Signature 'sync:supplier {--live} {--dry-run} {--resume=}'. D-04 mutually-exclusive validation (exits 1 with clear error). Default dry-run. --live persists dry_run=false on SyncRun (WOO_WRITE_ENABLED is the actual write gate). --resume={id} calls SyncRun::findResumable (scoped to aborted|failed|running) → ModelNotFoundException for completed runs. Resume iterates from cursor_page. Detects unknown SKUs (supplier-only) + dispatches NewSupplierSkuDetected + ImportIssue. Collects missing rows (Woo-only) + dispatches MarkMissingSkusJob with Woo-side price/stock for D-10 CSV columns. On JwtRefreshFailedException: flags + aborts run with reason=jwt_refresh (D-06c). On SyncAbortException: aborts with e.reason."
  - "AppServiceProvider::boot — registers SyncSupplierCommand via \\$this->commands([...]) inside runningInConsole() guard. Artisan::starting() doesn't exist on Laravel 12 Kernel; ServiceProvider::commands() is the correct pattern."
  - "routes/console.php — commented-out daily cron entry for 'sync:supplier --live' (D-05 kill-switch; Phase 7 cutover runbook enables)."
  - "12 Pest tests in DomainEventAfterCommitTest + SupplierEventDispatchTest — prove P2-I fix (rollback → no dispatch), event payload shapes, Phase 1 subclass inheritance, stub listener wiring."
  - "29 Pest tests in WooProductIteratorTest + SkuMatcherTest + AbortGuardTest + SyncDiffEngineTest — Task 2 coverage (iterator 8, matcher 3, AbortGuard 10 including B8 multi-worker + B9 atomic increment, diff engine 8)."
  - "25 Pest tests in the 7 Task 3 files — orchestrator flags, dry-run modes, per-SKU processing + idempotency + events, failure paths, missing-SKU handling incl. D-10 columns, exclude flag, resume cursor semantics."

affects:
  - "02-04-reporting-ui (consumes SyncRunItem CSV source already populated correctly; SyncRun state-machine transitions now include abort_reason='jwt_refresh' + 'consecutive_failures' + 'error_rate' for Filament drill-down)"
  - "02-05-guardrails (Deptrac Sync→Products cross-domain now allowed; PolicyTemplateIntegrityTest unaffected; retention prune for sync_errors + sync_run_items cascades from sync_runs FK)"
  - "Phase 3+ (PricingEngine listener consumes SupplierPriceChanged — after-commit guarantees the DB mirror is persisted before recompute fires)"
  - "Phase 6 (CreateWooProductJob listens on NewSupplierSkuDetected; stub listener here is the hand-off point — replace with real implementation)"

tech-stack:
  added:
    - "Illuminate\\Contracts\\Events\\ShouldDispatchAfterCommit interface (Laravel 12 built-in) on DomainEvent base — no composer change."
  patterns:
    - "Stateless service with DB-backed counters (AbortGuard) — replaces the in-memory singleton pattern researched in v1. Per-process instance is fine; shared state lives on the sync_runs row via atomic SQL increment/update. This is the Checker blocker fix for multi-worker supervisors (2-3 sync-woo-push workers)."
    - "Disjoint-column convention: AbortGuard owns (total_skus, failed_count, consecutive_failures, abort_reason); SyncRun::incrementCounter owns (updated_count, skipped_count, missing_count, unknown_sku_count). SyncChunkJob and MarkMissingSkusJob call both as needed, never both on the same column, so totals are never double-counted."
    - "ServiceProvider::commands() inside runningInConsole() guard for artisan commands that live outside Laravel 12's auto-discovery path (app/Console/Commands/). Avoids Kernel::starting() which doesn't exist in Laravel 12 and avoids bootstrap/app.php edits (Warning 2 fix)."
    - "Single write branch in MarkMissingSkusJob — the decision table returns \\$shouldWrite as a boolean so the try-block has one WooClient call, not a redundant dry/live split. The env gate (WOO_WRITE_ENABLED) is the authoritative shadow-vs-live decision; dry_run on the SyncRun is operator intent only."
    - "Explicit correlation_id seeding in tests — VARCHAR(36) constraint means plain UUIDs only (no 'test-' prefix). Every test file's beforeEach calls Context::add('correlation_id', (string) Str::uuid()) so the Auditor + IntegrationLogger have a valid CID."

key-files:
  created:
    - "app/Domain/Sync/Events/SupplierPriceChanged.php"
    - "app/Domain/Sync/Events/SupplierStockChanged.php"
    - "app/Domain/Sync/Events/SupplierSkuMissing.php"
    - "app/Domain/Sync/Events/NewSupplierSkuDetected.php"
    - "app/Domain/Sync/Listeners/StubNewSupplierSkuListener.php"
    - "app/Domain/Sync/Exceptions/SyncAbortException.php"
    - "app/Domain/Sync/Services/WooProductIterator.php"
    - "app/Domain/Sync/Services/SkuMatcher.php"
    - "app/Domain/Sync/Services/AbortGuard.php"
    - "app/Domain/Sync/Services/SyncDiffEngine.php"
    - "app/Domain/Sync/Jobs/SyncChunkJob.php"
    - "app/Domain/Sync/Jobs/MarkMissingSkusJob.php"
    - "app/Domain/Sync/Commands/SyncSupplierCommand.php"
    - "tests/Feature/DomainEventAfterCommitTest.php"
    - "tests/Feature/SupplierEventDispatchTest.php"
    - "tests/Feature/WooProductIteratorTest.php"
    - "tests/Feature/SkuMatcherTest.php"
    - "tests/Feature/AbortGuardTest.php"
    - "tests/Feature/SyncDiffEngineTest.php"
    - "tests/Feature/SyncChunkJobTest.php"
    - "tests/Feature/SyncChunkFailureTest.php"
    - "tests/Feature/MissingSkuHandlingTest.php"
    - "tests/Feature/ExcludeFromAutoUpdateTest.php"
    - "tests/Feature/DryRunModeTest.php"
    - "tests/Feature/SyncResumeTest.php"
    - "tests/Feature/SyncSupplierCommandFlagsTest.php"
  modified:
    - "app/Foundation/Events/DomainEvent.php — + implements ShouldDispatchAfterCommit + docblock explaining P2-I retrofit"
    - "app/Providers/EventServiceProvider.php — + NewSupplierSkuDetected → StubNewSupplierSkuListener map"
    - "app/Providers/AppServiceProvider.php — + \\$this->commands([SyncSupplierCommand::class]) in boot() inside runningInConsole() guard"
    - "routes/console.php — + commented sync:supplier --live cron entry (D-05)"
    - "depfile.yaml — Sync ruleset now allows [Foundation, Products] (was just [Foundation])"
    - "deptrac.yaml — mirror edit (same content — both files exist in the repo)"

key-decisions:
  - "DomainEvent retrofit was regression-safe — Phase 1's 2 existing subclasses (OrderReceived, CustomerRegistered) plus all 92 Phase 1 tests remained green. Test A4 specifically exercises the Phase 1 subclasses inside a rolled-back transaction to prove inheritance of ShouldDispatchAfterCommit works as intended."
  - "Artisan::starting() was in the plan snippet but does NOT exist on Laravel 12's Foundation\\Console\\Kernel — the call throws `Call to undefined method`. Switched to ServiceProvider::commands() inside runningInConsole() guard. Produces the same result (command registered + discoverable via artisan list) without touching bootstrap/app.php."
  - "Deptrac Sync→Products cross-domain allow added in this plan (as anticipated in Plan 02-01's key-decisions). SyncChunkJob needs to read Product + ProductVariant for both the P2-F idempotency check and the local-mirror upsert. Ruleset updated in BOTH depfile.yaml and deptrac.yaml to keep them in sync."
  - "AbortGuard is explicitly NOT a singleton — per the Checker blocker fix. The Plan's permission-to-deviate note confirmed this, and the default container behaviour (fresh instance per resolve) is correct. Test B8 proves two independent AbortGuard instances recording 25 failures each trip the 50-consecutive threshold."
  - "SyncDiffEngine's normalisePrice preserves the raw supplier string in the payload (not the normalised form) — normalisation is only used for the equality check. So the Woo write receives '199.00' not '199'. Payload round-trip preserves the supplier's 2dp representation."
  - "MarkMissingSkusJob's single write branch (iter-1 Warning fix) — the original plan had separate dry/live branches; removed because WooClient::put() already routes through writeOrShadow() which consults config('services.woo.write_enabled'). A second branch in the job would be redundant and could drift from the env gate."
  - "SyncRunItem.old_price + old_stock for action='missing' populated from the Woo-side state passed through SyncSupplierCommand::\\$missingRows — D-10 CSV contract requires all 11 columns. Warning 6 fix now persistent in MarkMissingSkusJob + the orchestrator's $missingRows[] structure."
  - "AbortGuardTest::B1 redesigned on first red-test run — original used 450 successes + 50 trailing failures, which satisfies the error-rate check but trips the consecutive-failures check (trailing 50 in a row). Redesigned to interleave 9 successes + 1 failure × 50 blocks so consecutive_failures stays at 1 at assert time. The original bug was in the test, not the service."

requirements-completed:
  - SYNC-01
  - SYNC-03
  - SYNC-05
  - SYNC-06
  - SYNC-07
  - SYNC-09
  - SYNC-10
  - SYNC-13

duration: ~25 min
completed: 2026-04-18
---

# Phase 02 Plan 03: Orchestration Summary

**Phase 2 sync pipeline wired end-to-end: DomainEvent retrofitted with ShouldDispatchAfterCommit so per-SKU transaction rollbacks cannot leak downstream events (Pitfall P2-I); 4 new domain events + 1 stub listener delivered; 4 stateless services (iterator, matcher, AbortGuard, diff engine) composed by 2 jobs (SyncChunkJob sync-woo-push, MarkMissingSkusJob sync-bulk) + 1 command (sync:supplier with --live/--dry-run/--resume flag validation). AbortGuard uses DB-backed atomic counters on sync_runs — Checker blocker fix for multi-worker supervisors. Disjoint-column convention between AbortGuard (failed_count/consecutive_failures/total_skus) and SyncRun::incrementCounter (updated/skipped/missing/unknown_sku) prevents double-counting. 66 new Pest tests green; full suite 192 passed + 2 skipped (was 126 after Plan 02-02 + 12 Task1 + 29 Task2 + 25 Task3 = 192 net); Deptrac 0 violations (Sync→Products cross-domain allowed per Plan 02-01 anticipated update); zero Phase 1 regressions across all 92 baseline tests.**

## Performance

- **Duration:** ~25 min execution
- **Started:** 2026-04-18T21:41Z
- **Completed:** 2026-04-18T22:05Z
- **Tasks:** 3
- **Commits:** 3 task commits (+ 1 forthcoming metadata commit)
- **Files created:** 26 (13 production + 13 tests)
- **Files modified:** 6 (DomainEvent, EventServiceProvider, AppServiceProvider, routes/console.php, depfile.yaml, deptrac.yaml)

## Task Commits

1. **Task 1** — `97eeb3d` `refactor(02-03): retrofit DomainEvent with ShouldDispatchAfterCommit (Pitfall P2-I) + 4 events + stub listener` — 9 files, +423 / -1
2. **Task 2** — `124d37a` `feat(02-03): add DB-backed AbortGuard + iterator/matcher/diff services (D-06 SYNC-05)` — 9 files, +1155 / -0
3. **Task 3** — `31d28dd` `feat(02-03): sync:supplier orchestrator + SyncChunkJob + MarkMissingSkusJob + cron skeleton` — 14 files, +1480 / -2

## Accomplishments

### DomainEvent retrofit (Task 1 — P2-I fix)

Before: `abstract class DomainEvent` (Dispatchable + SerializesModels). A DB::transaction that rolled back after `event(new SomeDomainEvent(...))` would STILL fire listeners on commit elsewhere — a real risk for SyncChunkJob's per-SKU transactions.

After: `abstract class DomainEvent implements ShouldDispatchAfterCommit`. Laravel's event dispatcher now buffers the dispatch until the enclosing transaction commits; rollback discards the buffered dispatch. Phase 1's 2 subclasses (OrderReceived, CustomerRegistered) plus all 4 new Phase 2 events inherit the invariant automatically.

**Regression evidence:** full Phase 1 suite (92 tests) ran green after the retrofit — no Phase 1 test relied on events firing inside-rolled-back transactions.

### 4 Phase 2 events + stub listener

| Event | Payload fields | Fired by |
|---|---|---|
| `SupplierPriceChanged` | sku, wooProductId, wooVariationId?, oldPrice, newPrice, reason='supplier_sync' | SyncChunkJob after `put` with `regular_price` key |
| `SupplierStockChanged` | sku, wooProductId, wooVariationId?, oldStock, newStock, reason='supplier_sync' | SyncChunkJob after `put` with `stock_quantity` key |
| `SupplierSkuMissing` | sku, wooProductId, wooVariationId?, hadCustomMsTag, newStatus | MarkMissingSkusJob per missing row |
| `NewSupplierSkuDetected` | sku, supplierPrice, supplierStock | SyncSupplierCommand per supplier-only SKU (D-09) |

`StubNewSupplierSkuListener::handle()` logs receipt and returns — Phase 6 replaces with `CreateWooProductJob` dispatch.

### AbortGuard — DB-backed D-06 tiered abort

| Trigger | Column read | Threshold |
|---|---|---|
| (a) error_rate | `failed_count / total_skus` | > 20% AND total_skus ≥ 500 |
| (b) consecutive_failures | `consecutive_failures` | ≥ 50 |
| (c) jwt_refresh | `abort_reason` | Any (set by `triggerJwtFailure`) |

`throwIfTriggered` checks in priority order: JWT first, then consecutive, then rate. **Test B8 proves multi-worker correctness**: two independent AbortGuard instances recording 25 failures each on the same run id trip the 50-consecutive threshold via the shared DB column.

### WooProductIterator — flat SKU stream

- Outer pagination on `/products` at `per_page=100`; stops when a page returns <100 rows.
- For `type=variable` parents: inner pagination on `/products/{id}/variations` (A9 mitigation for >100 variations).
- Grouped/external products skipped (v1 D-02 scope).
- `custom-ms` slug match is case-insensitive (`Custom-MS`, `CUSTOM-MS`, `custom-ms` all match).
- `_exclude_from_auto_update` meta match handles `yes`, `'1'`, `1`, `true`.
- Variations inherit `is_custom_ms` + `exclude_from_auto_update` from parent.

### SyncDiffEngine — decision table

| Input | Output action |
|---|---|
| `exclude_from_auto_update=true` | `skipped` with reason=`exclude_from_auto_update`, empty payload |
| `supplierRow=null` | `null` (delegated to MarkMissingSkusJob) |
| Exact match (price normalised, stock int) | `null` (no-op) |
| Price only | `updated` with `{regular_price: "..."}` |
| Stock only | `updated` with `{stock_quantity: N}` |
| Both | `updated` with both keys |

Price normalisation strips trailing zeros for equality check but the raw supplier string is persisted into the payload (so Woo receives `199.00` not `199`).

### SyncChunkJob — sync-woo-push (120s, 3 tries)

Per-SKU flow:
1. `AbortGuard::throwIfTriggered` — bail fast if abort criteria met.
2. P2-F idempotency check — skip if `Product/ProductVariant.last_synced_at > run.started_at`.
3. Build diff; null → recordSuccess + continue.
4. `skipped` action → write run_item + `$run->incrementCounter('skipped')` + `recordSuccess`.
5. `updated` action → `DB::transaction { woo->put + write run_item + local mirror upsert }` → dispatch events (after-commit via P2-I) → `$run->incrementCounter('updated')` + `recordSuccess`.
6. Per-SKU catch → `sync_errors` row + run_item action='failed' + `recordFailure` (NOT also `$run->incrementCounter('failed')` — disjoint columns, Checker blocker convention).
7. `SyncAbortException` — re-thrown; orchestrator catches at the run level.
8. After every SKU: `$run->update(['cursor_page' => $this->page, 'cursor_sku' => $sku])` for resume correctness.

### MarkMissingSkusJob — sync-bulk (600s)

D-03 granular decision table:

| Row type | is_custom_ms | newStatus | shouldWrite |
|---|---|---|---|
| simple | false | pending | true |
| simple | true | publish | false (no Woo write, event still fires) |
| variation | any | private | true |

Every row writes `ImportIssue` (TYPE_MISSING_AT_SUPPLIER) + `SyncRunItem` (ACTION_MISSING with all 11 D-10 columns populated: old_price/old_stock from Woo state, new_price/new_stock null) + dispatches `SupplierSkuMissing`. Failures logged to `sync_errors` and processing continues.

### SyncSupplierCommand — CLI surface

```
php artisan sync:supplier                       # dry-run implicit (D-04)
php artisan sync:supplier --dry-run             # dry-run explicit (same as above)
php artisan sync:supplier --live                # live writes (additionally gated by WOO_WRITE_ENABLED)
php artisan sync:supplier --live --dry-run      # exit 1 + "mutually exclusive" error
php artisan sync:supplier --resume=7            # resume aborted run id=7 from cursor_page
php artisan sync:supplier --resume=7 --live     # resume + flip dry_run to false
```

Resume semantics:
- `SyncRun::findResumable($id)` scope = whereIn status (aborted, failed, running). Completed/queued runs → `ModelNotFoundException`.
- Iterator starts at `$run->cursor_page > 0 ? cursor_page : 1`.
- P2-F per-SKU idempotency check ensures already-synced SKUs are not re-pushed.

### D-09 unknown-SKU + D-03 missing-SKU flows (orchestrator)

After all chunks dispatch, the command:
- Computes supplier-only SKUs → creates ImportIssue (TYPE_UNKNOWN_SKU) + fires NewSupplierSkuDetected + increments `unknown_sku_count` for each.
- Computes Woo-only SKUs → packs (sku, type, woo_product_id, woo_variation_id, is_custom_ms, woo_price, woo_stock) into `$missingRows` + dispatches `MarkMissingSkusJob` once with the batch (D-10 11-col contract preserved).

### JwtRefreshFailedException → abort (D-06c)

Orchestrator's outer try/catch flags the run via `AbortGuard::triggerJwtFailure($run->id)` + `$run->abort(SyncRun::ABORT_JWT_REFRESH, $e->getMessage())` + `return FAILURE`. Distinct handler from generic `SyncAbortException` so the JWT branch is obvious in the abort path.

### routes/console.php (D-05 kill-switch)

```php
// Phase 2 (D-05) — Daily supplier sync. COMMENTED OUT; Phase 7 cutover runbook
// enables this entry once parity with the legacy Stock Updater plugin is proven.
// The commented entry itself is the kill-switch — no separate SYNC_CRON_LIVE flag.
// Schedule::command('sync:supplier --live')
//     ->dailyAt('02:00')
//     ->onOneServer()
//     ->withoutOverlapping(60)
//     ->onQueue('sync-bulk')
//     ->timezone('Europe/London')
//     ->description('Daily 21stcav.com supplier sync (D-05 — enable post-Phase-7-cutover)');
```

`php artisan schedule:list` does NOT show `sync:supplier` (verified).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] `Artisan::starting()` does not exist on Laravel 12 Console Kernel**

- **Found during:** Task 3, first `php artisan list` after registering the command.
- **Issue:** Plan snippet specified `$this->app->booted(fn () => Artisan::starting(fn ($artisan) => $artisan->resolve(SyncSupplierCommand::class)));`. Running `php artisan list` produced `Call to undefined method Illuminate\Foundation\Console\Kernel::starting()`.
- **Fix:** Replaced with Laravel 12's standard pattern: `if ($this->app->runningInConsole()) { $this->commands([SyncSupplierCommand::class]); }` in `AppServiceProvider::boot()`. Same effect (command discoverable via `artisan list`), no `bootstrap/app.php` touched (Warning 2 preserved).
- **Files modified:** `app/Providers/AppServiceProvider.php`
- **Committed in:** `31d28dd` (Task 3)
- **Verification:** `php artisan list | grep sync:supplier` returns the row.

**2. [Rule 3 — Blocking] Deptrac Sync → Products cross-domain not yet allowed**

- **Found during:** Task 3, `vendor/bin/pest` → DeptracTest failure with 6 violations (SyncChunkJob imports Product + ProductVariant).
- **Issue:** Plan 02-01's key-decisions anticipated this exactly: _"Deptrac ruleset unchanged — Plan 02-01 Sync models do NOT import any Products classes yet (no cross-domain calls); that only happens in Plan 02-03 when AbortGuard + DiffEngine need Product lookups. The 'Sync:[+Products]' ruleset tweak is deferred to Plan 02-03's ruleset PR."_ Plan 02-03 legitimately needed Product + ProductVariant for the P2-F idempotency check and the local-mirror upsert — cannot be factored away cleanly.
- **Fix:** Updated BOTH `depfile.yaml` and `deptrac.yaml` — `Sync: [Foundation, Products]` (was `[Foundation]`). Matches the anticipated ruleset update. No other layer affected.
- **Files modified:** `depfile.yaml`, `deptrac.yaml`
- **Committed in:** `31d28dd` (Task 3)
- **Verification:** `vendor/bin/deptrac analyse` → 0 violations.

**3. [Rule 1 — Bug] AbortGuardTest B1 test design accidentally triggered B4's threshold**

- **Found during:** Task 2, first Pest run.
- **Issue:** Original B1 used 450 sequential `recordSuccess` + 50 sequential `recordFailure`. The trailing 50 failures = 50 consecutive failures = D-06(b) trigger. So B1 (meant to prove "error-rate-only doesn't trigger at 10%") actually triggered the consecutive_failures check.
- **Fix:** Rewrote B1 to interleave (9 success + 1 failure) × 50 blocks. Same end counters (total_skus=500, failed_count=50, rate=10%) but consecutive_failures stays at 1. Assertion updated to match. Test passes.
- **Files modified:** `tests/Feature/AbortGuardTest.php`
- **Committed in:** `124d37a` (Task 2)
- **Verification:** All 10 AbortGuardTest tests pass.

**4. [Rule 3 — Blocking] PHP not on Windows git-bash PATH**

- **Found during:** Task 1, first `vendor/bin/pest` invocation.
- **Issue:** `/usr/bin/env: 'php': No such file or directory`. PHP is installed via Laravel Herd at `C:/Users/sonny.tanda/.config/herd/bin/php84/php.exe` but not on the shell PATH.
- **Fix:** Prefixed `php` onto PATH inline in each test command: `export PATH="/c/Users/sonny.tanda/.config/herd/bin/php84:$PATH" && php vendor/bin/pest ...`. Not a code fix — just a session-level environment fix for the executor.
- **Files modified:** None.
- **Verification:** `php --version` → `PHP 8.4.19 (cli)`.

---

**Total deviations:** 4 auto-fixed (1 bug, 3 blockers — two from the plan's own snippets, one from Plan 02-01's anticipated deferral). No Rule 4 architectural asks. Plan contract shipped in full — all 3 tasks complete, all 66 new tests passing, all 8 SYNC requirements (01/03/05/06/07/09/10/13) backed by tests.

## Authentication Gates

None encountered. The `SyncSupplierCommand` catches `JwtRefreshFailedException` from SupplierClient and flips the run to `aborted` with `abort_reason=jwt_refresh` — so a credential failure in production would propagate as a normal abort with a clear runbook entry (operator populates `SUPPLIER_API_USERNAME/PASSWORD` + runs `--resume={id} --live`), not a silent failure.

Tests use `Http::fake()` / `Queue::fake()` / `Event::fake()` throughout; no real network calls needed.

## Issues Encountered

1. **Deptrac deprecation warnings** — the `qossmic/deptrac-shim` package emits PHP deprecation notices on Laravel 12's strict runtime (`AbstractString::splice $length implicit nullable`). Cosmetic only; Deptrac's analysis completes successfully. Not addressed here; deferred to a future upgrade of `qossmic/deptrac`.

2. **B1b test timing on MySQL write-heavy asserts** — interleaved 99 failures + 400 successes = 499 `->increment()` UPDATEs + 1 more, on Windows dev MySQL ~10s per test. Not a blocker at 192-test suite runtime (~115s total), but Phase 5+ parallel execution is worth tracking.

3. **Php CLI startup overhead** — Laravel Herd's php.exe boot is ~1-1.5s on Windows, so every `php artisan ...` in tests contributes. Not a real concern in CI (Linux).

## User Setup Required

None for Plan 02-03 test coverage — fully mocked.

For **production use** (Phase 7 cutover runbook):
- Populate `.env`: `SUPPLIER_API_USERNAME`, `SUPPLIER_API_PASSWORD` (from 21stcav.com ops).
- Populate `.env`: `WOO_URL`, `WOO_CONSUMER_KEY`, `WOO_CONSUMER_SECRET` (from `meetingstore.co.uk` Woo admin → REST API).
- When ready for live writes: set `WOO_WRITE_ENABLED=true` in `.env` AND uncomment the `Schedule::command('sync:supplier --live')` block in `routes/console.php`.
- Smoke test: `php artisan sync:supplier` (dry-run, no env flips) — should print "Sync run id=N (dry_run=true...)".

## Next Plan Readiness

### Plan 02-04 (reporting + Filament Resources) can assume

- `SyncRunItem::forRun($id)->chunk(500, ...)` streams all 11 D-10 CSV columns for the SyncReportCsvGenerator — `old_price` and `old_stock` are now populated even for `action='missing'` rows.
- `SyncRun::status` may be `completed`, `aborted` (with `abort_reason` in {error_rate, consecutive_failures, jwt_refresh, manual}), or `failed` — SyncRunResource's status badge can switch on these values.
- `sync_runs.updated_count`, `.skipped_count`, `.failed_count`, `.missing_count`, `.unknown_sku_count`, `.consecutive_failures` are all live and authoritative — dashboard can read directly.
- `ImportIssue::unresolved()->ofType(ImportIssue::TYPE_UNKNOWN_SKU)` surfaces D-09 rows; `->ofType(ImportIssue::TYPE_MISSING_AT_SUPPLIER)` surfaces D-03 rows.
- 4 domain events publish from the sync pipeline; Plan 02-04's email report can subscribe listeners if needed (most likely not — report is generated synchronously at run-complete).

### Plan 02-05 (guardrails + retention prunes) can assume

- `PolicyTemplateIntegrityTest` grep target unchanged; no new policies shipped in Plan 02-03.
- `sync_errors` + `sync_run_items` continue to cascade on `sync_runs` FK (Plan 02-01 contract). Retention prune on `sync_runs` sweeps both.
- Deptrac `Sync` layer now allows `Products` — do not revert this in Plan 02-05 (Sync→Products is a legitimate cross-domain call per Plan 02-01 decisions).
- The `SYNC-04` Deptrac negative test (Woo access only via `WooClient`) can proceed — the `Automattic\WooCommerce\Client` class is imported ONLY in `app/Providers/AppServiceProvider.php` (binding site) and used ONLY via `app/Domain/Sync/Services/WooClient.php` (sole consumer).

### Phase 3+ (pricing engine) can assume

- `SupplierPriceChanged` event fires reliably AFTER the DB transaction commits (P2-I guarantee) — listeners can safely call `Product::where('woo_product_id', $e->wooProductId)->first()` and the row WILL carry the new `buy_price` (set by SyncChunkJob's local mirror upsert inside the same transaction).
- `reason` field defaults to `supplier_sync`; Phase 3 listeners can reason on this (e.g. skip recompute if `reason=manual_override`).

### Phase 6 (auto-create-product) can assume

- `NewSupplierSkuDetected` event fires once per supplier-only SKU per run with `sku + supplierPrice + supplierStock`. 
- `StubNewSupplierSkuListener` can be swapped for `CreateWooProductJob` without touching producer code — the EventServiceProvider map is the only coupling point.
- `ImportIssue` rows (TYPE_UNKNOWN_SKU) persist the same SKUs for human triage — if Phase 6's auto-create fails, the unresolved ImportIssue remains for ops.

### Known concerns for later plans

1. **MarkMissingSkusJob runs synchronously per-SKU** — 15k missing SKUs at 2 SKUs/sec (with Woo rate-limit + 429 backoff) = ~2 hours. If profiling shows this as the long tail of a run, Plan 02-05 could split the job's `missingRows` into chunks and dispatch N parallel MarkMissingSkusJobs on a new `sync-missing` queue.

2. **WooProductIterator materialises each page's SKUs before yielding** — for 15k SKUs × per_page=100 = 150 pages, each chunked into one SyncChunkJob with up to 100 SKUs (or 100 variations for a variable product). The SyncChunkJob's serialised payload includes the FULL supplier feed (~2MB Redis object per job × 150 jobs = ~300MB peak Horizon memory). Still within tolerance for a single-VPS deploy, but Phase 5+ could optimise by caching the supplier feed in Redis with a `sync_run_id` key and passing only the key into each job.

3. **SyncChunkJob writes `$run->update(['cursor_page' => ..., 'cursor_sku' => ...])` after every SKU** — 15k UPDATEs per run. On a production MySQL this is ~0.5ms/UPDATE = 7.5s total — tolerable. If profiling pressures this, throttle the cursor write to every-N-SKUs-or-every-minute.

4. **DomainEvent retrofit is REGRESSION-SAFE but downstream-behaviour-altering** — any future test that deliberately dispatches an event inside an intentionally-rolled-back transaction MUST now assert "event NOT dispatched" (rather than the pre-retrofit "event dispatched"). No existing tests relied on the pre-retrofit behaviour, but future authors should be aware.

## Threat Flags

No new trust boundaries beyond those documented in the plan's `<threat_model>`. All 7 STRIDE mitigations covered:

- **T-02-03-01** (cursor tampering) — SyncRun::findResumable's whereIn-status scope and immutable correlation_id are intact.
- **T-02-03-02** (DoS via runaway feed) — AbortGuard's 20% error-rate + 50-consecutive + JWT-fail triggers provide natural brakes.
- **T-02-03-03** (rolled-back events leaking downstream) — fixed by this plan's P2-I retrofit + proven by Test A3.
- **T-02-03-04** (malicious unknown-SKU injection) — accepted; Phase 6's human-approval gate before CreateWooProductJob.
- **T-02-03-05** (exclude-flag tampering) — Woo snapshot semantics (iterator reads once) + sync_run_item audit trail.
- **T-02-03-06** (abort/resume repudiation) — Auditor::record on run.started / resumed / aborted / completed with correlation_id batch_uuid.
- **T-02-03-07** (supplier feed serialised in Horizon UI) — accepted; admin-only Horizon access.

## Self-Check: PASSED

- Created files verified:
  - 13 production files under `app/Domain/Sync/{Events,Listeners,Services,Jobs,Commands,Exceptions}/` FOUND
  - 13 test files under `tests/Feature/` FOUND
  - `app/Foundation/Events/DomainEvent.php` modified to include `implements ShouldDispatchAfterCommit` FOUND
  - `app/Providers/EventServiceProvider.php` modified to include NewSupplierSkuDetected → StubNewSupplierSkuListener map FOUND
  - `app/Providers/AppServiceProvider.php` modified to register SyncSupplierCommand via `$this->commands([...])` FOUND
  - `routes/console.php` modified to include commented sync:supplier --live cron entry FOUND
  - `depfile.yaml` + `deptrac.yaml` modified to allow Sync → Products FOUND
- Commits verified via `git log --oneline`:
  - `97eeb3d` Task 1 FOUND
  - `124d37a` Task 2 FOUND
  - `31d28dd` Task 3 FOUND
- Artisan registration verified:
  - `php artisan list | grep sync:supplier` → returns the row
  - `php artisan sync:supplier --live --dry-run` → exit 1 + "mutually exclusive" error
  - `php artisan schedule:list | grep supplier` → returns nothing (correctly commented out)
- Test results:
  - `vendor/bin/pest --filter="DomainEventAfterCommit|SupplierEventDispatch"` — 12 passed
  - `vendor/bin/pest --filter="WooProductIterator|SkuMatcher|AbortGuard|SyncDiffEngine"` — 29 passed
  - `vendor/bin/pest --filter="SyncChunkJobTest|SyncChunkFailure|MissingSkuHandling|ExcludeFromAutoUpdate|DryRunMode|SyncResume|SyncSupplierCommandFlags"` — 25 passed
  - **Full suite: 192 passed, 2 skipped** (0 regressions from Phase 1's 92 + Phase 2's 34 previous)
- `vendor/bin/deptrac analyse --no-progress` → 0 violations

---

*Phase: 02-supplier-sync*
*Plan: 03-orchestration*
*Completed: 2026-04-18*
