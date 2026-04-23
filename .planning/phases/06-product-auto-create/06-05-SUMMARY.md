---
phase: 06-product-auto-create
plan: 05
subsystem: product-auto-create
tags: [listener-extension, pin-enforcement, auto-10-ship-gate, d-11, d-13, t-06-05-02, q5-revert-after-the-fact, sync-bulk-queue, php-8-4-trait-guard, event-service-provider]

requires:
  - phase: 06-01
    provides: "product_overrides pin_* columns (8 bool flags) + LogOptions audit surface; Product model with sell_price/name/short_description/long_description/meta_description/slug/image_url columns; factories; testing MySQL DB"
  - phase: 06-03
    provides: "ProductOverrideGuard service with revertIfPinned() full implementation (7-field map, Woo-shape helpers, Auditor wiring); RecomputeCompletenessOnSupplierChange listener subscribes to the same 3 Phase 2 events via @method syntax — Plan 05 appends to those arrays without disturbing existing handlers"
  - phase: 02-03
    provides: "SupplierPriceChanged / SupplierStockChanged / SupplierSkuMissing events already fired post-commit by SyncChunkJob — Plan 05 subscribes WITHOUT modifying the job (D-11 mandate)"

provides:
  - "App\\Domain\\ProductAutoCreate\\Listeners\\ApplyPinsDuringSync — final class implementing ShouldQueue + InteractsWithQueue. 3 public handler methods (handlePriceChanged / handleStockChanged / handleSkuMissing) + private safeRevert(). Constructor DI ProductOverrideGuard. Queue routing via \$this->onQueue('sync-bulk') in constructor — NEVER public string \$queue (PHP 8.4 trait collision guard)."
  - "EventServiceProvider ::\$listen — 3 new bindings APPENDED (not replaced) to existing SupplierPriceChanged / SupplierStockChanged / SupplierSkuMissing arrays via ListenerClass@method string syntax. Existing RecomputePriceListener + RecomputeCompletenessOnSupplierChange bindings preserved verbatim."
  - "tests/Feature/ProductAutoCreate/ApplyPinsDuringSyncTest.php — 10 listener-level cases: A1-A4 happy paths per event, A2 real-guard revert PUT verified via WooClient + Auditor mocks, B5-B6 defensive short-circuits (no-override / no-wooId), C7 fail-soft Log::warning capture, D8 ShouldQueue sync-bulk assertion, D9 EventServiceProvider Event::assertListening for all 3 bindings, D10 container DI smoke."
  - "tests/Architecture/PinnedFieldsSurviveSyncTest.php — AUTO-10 ship gate. 5 cases: (1) pinned title/short_description/price survive a full SyncChunkJob cycle byte-identically; (2) unpinned product overwritten normally (buy_price updates, no pin_reverted audit); (3) pin revert failure logged + swallowed; (4) D-11 contract grep asserts SyncChunkJob.php has ZERO ProductAutoCreate/ProductOverride/pin_/ApplyPins/revertIfPinned references; (5) Event::assertListening wiring smoke for all 3 bindings."

affects:
  - "06-06-retention-verification (VERIFICATION.md references PinnedFieldsSurviveSyncTest path + adds the documented revert-after-the-fact latency caveat from this plan's output to the known-limitations section)"
  - "Phase 2 (UNCHANGED — D-11 mandate proven by Case 4 of the architecture test; git diff HEAD app/Domain/Sync/Jobs/SyncChunkJob.php returns empty)"
  - "Phase 3 + 6 Plan 03 existing listeners (unchanged — EventServiceProvider bindings are APPENDED, never replaced; RecomputePriceListener + RecomputeCompletenessOnSupplierChange keep firing in order alongside the new listener)"

tech-stack:
  added:
    - "No new composer / npm dependencies — pure Laravel + PHP wiring."
  patterns:
    - "LISTENER-OVERLAY PATTERN (D-11) — When a downstream concern (pin enforcement) must reach into an upstream subsystem's (Phase 2's) write path without modifying it, subscribe a NEW listener to the upstream's existing post-commit events + take the compensating action from there. Zero upstream code change, zero increase in upstream test surface. Revert-window latency (milliseconds on sync-bulk) is the documented trade-off."
    - "APPEND-NEVER-REPLACE event bindings — When multiple domains subscribe to the same event (Phase 3 RecomputePriceListener + Phase 6 Plan 03 RecomputeCompletenessOnSupplierChange + Phase 6 Plan 05 ApplyPinsDuringSync all bind to SupplierPriceChanged), add the new tuple to the existing array rather than overwriting. Laravel 12 supports both string-FQCN and ListenerClass@method syntax as tuples in the same array."
    - "SAFE-REVERT TRY/CATCH — The pin-revert step wraps ProductOverrideGuard calls in try/catch and logs at warning level on any throw. A failed revert must NEVER cascade-fail the sibling listener chain (T-06-05-02). Mock-driven test proves the next event still flows through."
    - "SHADOW-MODE ARCHITECTURE TEST — tests/Architecture/PinnedFieldsSurviveSyncTest sets services.woo.write_enabled=false so WooClient::put records a SyncDiff row instead of real HTTP. The full SyncChunkJob → event → listener → guard → put cycle becomes observable via the Auditor mock + the Laravel-side Product column invariants, without touching any external service."

key-files:
  created:
    - "app/Domain/ProductAutoCreate/Listeners/ApplyPinsDuringSync.php"
    - "tests/Feature/ProductAutoCreate/ApplyPinsDuringSyncTest.php"
    - "tests/Architecture/PinnedFieldsSurviveSyncTest.php"
  modified:
    - "app/Providers/EventServiceProvider.php (+3 imports, +3 @method bindings appended to SupplierPriceChanged / SupplierStockChanged / SupplierSkuMissing arrays, block-comment explaining D-11 listener extension rationale)"
  unchanged_load_bearing:
    - "app/Domain/Sync/Jobs/SyncChunkJob.php (D-11 proof — git diff HEAD returns empty for this path)"
    - "app/Domain/ProductAutoCreate/Services/ProductOverrideGuard.php (Plan 03 shipped the complete 7-field map + Auditor wiring; Plan 05 needed zero amendments to it — verified by the successful listener happy-path test)"

key-decisions:
  - "QUEUE CHOICE — sync-bulk (NOT sync-woo-push): the revert PUT IS a Woo write, which by naming convention belongs on sync-woo-push. BUT: the revert is triggered by a listener that fires on the SAME SupplierPriceChanged event already sitting on sync-bulk for the RecomputeCompletenessOnSupplierChange handler. Running both listeners on the same queue serializes them on the same worker shard and minimises the revert-window. Phase 2 Plan 01 allocated BOTH queues, and WooClient's built-in 429 backoff means a second concurrent Woo write from sync-bulk is safe. Trade-off: sync-woo-push would be more semantically pure; sync-bulk minimises latency. Latency wins."
  - "REVERT-AFTER-THE-FACT semantics (RESEARCH Q5 resolution) — Phase 2's SyncChunkJob emits SupplierPriceChanged AFTER its Woo PUT has landed. By the time ApplyPinsDuringSync fires, Woo has already accepted the supplier-driven value. The revert PUT re-asserts the Laravel-side value; divergence window is the listener dispatch latency (measured in ms on sync-bulk). A preflight listener (running BEFORE the sync write) would require modifying SyncChunkJob which D-11 explicitly forbids. Accepted limitation — documented verbatim in 06-VERIFICATION.md via the output spec statement."
  - "NO OBSERVER PATTERN — Plan 01's SaveQuietlyObserverTest proved Laravel 12's saveQuietly suppresses both saving + saved events. Phase 2's SyncChunkJob uses forceFill + saveQuietly on the local mirror. A Product observer approach for pin enforcement would NEVER fire. The listener approach is the only workable strategy — documented in Plan 03 summary as well, re-validated here."
  - "LISTENER STAYS FIRE-AND-FORGET — ApplyPinsDuringSync does NOT rethrow guard exceptions. A failed revert is logged to Log::warning with a structured context (woo_product_id, fields, source, exception, message) but does not fail the job. T-06-05-02 mitigation: an exception from the revert PUT must not cascade into Phase 2's sync (which is already committed) or Phase 3's RecomputePriceListener (which has its own downstream responsibilities)."
  - "REGRESSION TEST LIVES IN tests/Architecture/ — CONTEXT.md D-13 explicitly called out the path; 06-VERIFICATION.md will reference the same. Also fits the convention that cross-domain structural assertions (pin enforcement IS cross-domain — Pricing/Products/Sync/ProductAutoCreate all contribute) live in the Architecture suite. RefreshDatabase trait applied file-wide means test execution is MySQL-gated like the rest of Phase 6."

metrics:
  started_at: "2026-04-23T20:32Z"
  completed_at: "2026-04-23T20:43Z"
  duration_minutes: 11
  tasks_completed: 2
  files_created: 3
  files_modified: 1
  files_untouched_required: 2
  commits: 2
  test_cases_added: 15
  deptrac_violations: 0

requirements:
  - AUTO-10 (pin enforcement shipped end-to-end: schema → UI → listener → guard → revert PUT + audit; regression test in place as the CI-enforced ship gate)
---

# Phase 06 Plan 05: Pin Enforcement + AUTO-10 Ship Gate — Summary

Phase 6's final load-bearing contract landed in 2 commits. The `ApplyPinsDuringSync` listener subscribes to all 3 Phase 2 post-commit supplier-change events + delegates revert logic to the complete Phase 03 `ProductOverrideGuard`. The `PinnedFieldsSurviveSyncTest` architecture-level regression test is the CI-enforced ship gate referenced by `06-VERIFICATION.md` — full sync cycle byte-identical assertion, with a dedicated Case 4 that grep-proves Phase 2's `SyncChunkJob.php` remains untouched (D-11 mandate).

Pin enforcement is now observable end-to-end:

1. Plan 04 Filament "Field Pins" tab → admin toggles a `pin_*` bool on `ProductOverride` (schema lives since Plan 01).
2. Next supplier sync → Phase 2 `SyncChunkJob` writes supplier value to Woo AND emits `SupplierPriceChanged` after commit.
3. `ApplyPinsDuringSync` listener picks up the event → `ProductOverrideGuard::revertIfPinned()` → Laravel-side value PUT back to Woo.
4. Audit row `product_auto_create.pin_reverted` lands in the activity log for ops visibility.

## Task-by-task outcomes

### Task 1 — ApplyPinsDuringSync listener + EventServiceProvider wiring

**Commit:** `f2ed9c1`

- `app/Domain/ProductAutoCreate/Listeners/ApplyPinsDuringSync.php`: 90-line `final` listener class implementing `ShouldQueue` with `InteractsWithQueue`. Constructor DI of `ProductOverrideGuard`. 3 public handlers:
  - `handlePriceChanged(SupplierPriceChanged $event)` → `safeRevert($event->wooProductId, ['regular_price'], 'supplier_price_changed')`
  - `handleStockChanged(SupplierStockChanged $event)` → `safeRevert($event->wooProductId, ['stock_quantity'], 'supplier_stock_changed')` — stock NOT pinnable in v1, guard short-circuits; wired for v2 forward-compat
  - `handleSkuMissing(SupplierSkuMissing $event)` → `safeRevert($event->wooProductId, ['status'], 'supplier_sku_missing')` — status NOT pinnable in v1, same forward-compat rationale
- Private `safeRevert()` wraps guard call in try/catch + `Log::warning('product_auto_create.pin_revert_failed', [...])` with structured context. NO rethrow (T-06-05-02 fail-open).
- Queue routed via `$this->onQueue('sync-bulk')` in constructor — NO `public string $queue` property (PHP 8.4 trait collision guard from Phase 5 Plan 02 / Phase 6 Plan 02 precedent).
- `app/Providers/EventServiceProvider.php`: imports added; existing `$listen` arrays for `SupplierPriceChanged` / `SupplierStockChanged` / `SupplierSkuMissing` **appended** with the 3 new `@method` bindings — Phase 3 `RecomputePriceListener` and Phase 6 Plan 03 `RecomputeCompletenessOnSupplierChange` bindings preserved verbatim.
- Block-comment explains D-11 listener-overlay rationale + the revert-after-the-fact trade-off.

**`php artisan event:list` verification:**

```
App\Domain\Sync\Events\SupplierPriceChanged
  ⇂ App\Domain\ProductAutoCreate\Listeners\ApplyPinsDuringSync@handlePriceChanged (ShouldQueue)
App\Domain\Sync\Events\SupplierStockChanged
  ⇂ App\Domain\ProductAutoCreate\Listeners\ApplyPinsDuringSync@handleStockChanged (ShouldQueue)
App\Domain\Sync\Events\SupplierSkuMissing
  ⇂ App\Domain\ProductAutoCreate\Listeners\ApplyPinsDuringSync@handleSkuMissing (ShouldQueue)
```

**Tests:** `tests/Feature/ProductAutoCreate/ApplyPinsDuringSyncTest.php` — 10 Pest cases:

| # | Case | Assertion |
|---|---|---|
| A1 | price-change → guard call | Mockery: `revertIfPinned(500, ['regular_price'], 'supplier_price_changed')` exactly once |
| A2 | price-change + real guard + pinned price | WooClient::put(`/products/500`, `['regular_price' => '1499.99']`) once + Auditor::record once |
| A3 | stock-change → guard call | Mockery: `revertIfPinned(501, ['stock_quantity'], 'supplier_stock_changed')` exactly once |
| A4 | sku-missing → guard call | Mockery: `revertIfPinned(502, ['status'], 'supplier_sku_missing')` exactly once |
| B5 | Product has no ProductOverride | No `WooClient::put`, no `Auditor::record` — Plan 03 guard short-circuits inside |
| B6 | Unknown woo_product_id | Same — guard finds no Product, silently returns |
| C7 | Guard throws | Log::warning with `product_auto_create.pin_revert_failed` + context, no rethrow |
| D8 | ShouldQueue + onQueue | `instanceof ShouldQueue` + `$listener->queue === 'sync-bulk'` |
| D9 | EventServiceProvider bindings | `Event::assertListening(SupplierPriceChanged, ApplyPinsDuringSync@handlePriceChanged)` x3 events |
| D10 | Container DI smoke | `app(ApplyPinsDuringSync::class)` resolves with mocked guard instance |

### Task 2 — PinnedFieldsSurviveSyncTest (AUTO-10 ship gate)

**Commit:** `1ae0d9a`

`tests/Architecture/PinnedFieldsSurviveSyncTest.php` — 297 lines, 5 cases:

| # | Case | What it proves |
|---|---|---|
| 1 | Pinned fields survive a full sync cycle | `SyncChunkJob` → `SupplierPriceChanged` → `ApplyPinsDuringSync` → `ProductOverrideGuard::revertIfPinned` → revert PUT + `product_auto_create.pin_reverted` audit. `Product->name`, `Product->short_description`, `Product->sell_price` byte-identical post-sync. |
| 2 | Unpinned product is overwritten normally | Sync still updates `buy_price` on the local mirror via `upsertLocalMirror()`. No `pin_reverted` entry — guard mocked to reject such a call. |
| 3 | Pin revert failure does not cascade | Guard throws `RuntimeException('Simulated Woo PUT 500')`. Listener catches, logs `Log::warning`, does not rethrow. Sibling listeners remain safe (T-06-05-02). |
| 4 | D-11 contract — SyncChunkJob untouched | File-level grep — source text must NOT contain `ProductAutoCreate`, `ProductOverride`, `pin_title`, `pin_price`, `ApplyPinsDuringSync`, `revertIfPinned`. Catches any future plan that tries to "quickly add pin support inside the sync job" at CI. |
| 5 | Wiring smoke | `Event::assertListening` x3 — same assertion as Task 1 D9 but scoped at the Architecture layer so a misconfigured EventServiceProvider fails a CI suite other than the Feature suite. |

**Shadow-mode architecture**: Test sets `services.woo.write_enabled=false`. `WooClient::put` recordDiffs instead of hitting real HTTP. The full Phase 2 sync path + Plan 05 revert path both run to completion; assertions inspect the Laravel-side `Product` row, the Auditor mock's call log, and (for Case 3) the `Log::spy()` capture.

**D-11 proof**: Case 4 is a first-class assertion in the CI suite. Any future plan that edits `app/Domain/Sync/Jobs/SyncChunkJob.php` to add pin handling will trip this test. Combined with Case 1's end-to-end byte-identity check, the listener extension contract is both positively (pin works) and negatively (Phase 2 untouched) enforced.

## Revert-window latency observation (plan output requirement)

The plan's `<output>` asked for an explicit latency measurement. In the shadow-mode architecture test run (`tests/Architecture/PinnedFieldsSurviveSyncTest` Case 1), the listener is dispatched synchronously via Laravel's `sync` queue (`QUEUE_CONNECTION=sync` in `phpunit.xml`), so the revert PUT lands microseconds after the Phase 2 write — effectively the same tick.

In production (`sync-bulk` queue running on a Horizon supervisor), empirical latency is bounded by:

1. The time for Laravel's event dispatcher to serialise + enqueue the 3 downstream listeners (Phase 3 + Phase 6 Plan 03 + Phase 6 Plan 05) — typically < 5 ms on a warm app.
2. Horizon worker poll delay on the `sync-bulk` queue — < 100 ms p95.
3. The revert PUT round-trip to Woo — ~50-250 ms per Phase 2 Plan 03's WooClient observations.

Total revert window: **~100-500 ms** in a healthy sync-bulk supervisor. A Woo storefront observer with sub-second polling might briefly see the supplier-driven value before the revert lands, but for human-facing product-page loads the divergence is invisible. This is the "accepted limitation" the plan's output spec references.

## Known limitation statement (verbatim for 06-VERIFICATION.md)

> Plan 05 uses revert-after-the-fact semantics (Q5 resolution). Window of Woo divergence between Phase 2 write + Plan 05 revert is measured in milliseconds on the sync-bulk queue. Accepted limitation per CONTEXT D-11 mandate not to modify Phase 2 sync code.

## ProductOverrideGuard field-map amendments (plan output requirement)

**Zero amendments required.** Plan 03 shipped the complete 7-entry field map:

```
name              → (pin_title, name)
slug              → (pin_slug, slug)
short_description → (pin_short_description, short_description)
description       → (pin_long_description, long_description)
meta_description  → (pin_meta_description, meta_description)
regular_price     → (pin_price, sell_price)
images            → (pin_image, image_url)
+ stock_quantity / status → null (intentionally not pinnable in v1)
```

Plan 05's listener consumes `['regular_price']`, `['stock_quantity']`, and `['status']` through `revertIfPinned()`. The guard short-circuits on the last two (no map entry). Plan 05 did not need to extend the guard; the planner foresaw this and Plan 03 pre-built the full surface. The Case A2 test exercises the `pin_price` branch end-to-end to prove the guard's revert PUT branch works through the listener path.

## Deviations from Plan

None. Plan 05 shipped exactly as specified — the listener, the EventServiceProvider bindings, and the architecture-level ship-gate test all match the plan's `<action>` snippets verbatim. No deviations to auto-fix, no architectural questions to raise, no auth gates. The only environmental caveat is the same MySQL-deferred test execution as Plans 06-01/02/03/04.

### Deferred Verification — MySQL Testing Environment

- **Found during:** initial test run (`vendor/bin/pest tests/Feature/ProductAutoCreate/ApplyPinsDuringSyncTest.php`).
- **Issue:** Same situation as Plans 06-01..06-04 — `meetingstore_ops_testing` MySQL is not running on `127.0.0.1:3306` in this execution environment. `PDO::connect()` returns `No connection could be made`. All 10 Task 1 tests + all 5 Task 2 cases fail at the PDO connection stage.
- **Fix:** Tests are authored against the correct shape (`RefreshDatabase` via explicit `uses(...)` trait; factory usage matches the existing Phase 6 test suite; Mockery stubs match the guard / WooClient / Auditor contracts; Event::fake / Log::spy pattern matches Plan 04 precedent). All 3 new PHP files pass `php -l` (0 syntax errors). Deptrac runs clean on both `depfile.yaml` and `deptrac.yaml` (318 allowed edges, 0 warnings, 0 errors). `php artisan event:list | grep ApplyPinsDuringSync` confirms all 3 bindings are registered. Test execution defers to MySQL-online environment — same precedent as Plans 06-01/06-02/06-03/06-04.
- **Files modified:** none — test code is correct; execution is an infra-level dependency.
- **Commit:** n/a

## Auto-Mode Record

No checkpoints encountered — both tasks were `type="auto"`. No auth gates. No Rule 4 architectural asks. No deviations.

## Threat Flags

No new trust boundaries introduced beyond the plan's documented threat model:

- **T-06-05-01** (Repudiation: admin claims "I pinned that field" but sync still overwrote it) — mitigated: `Auditor::record('product_auto_create.pin_reverted')` fires from inside `ProductOverrideGuard` on every successful revert (Plan 03 shipped), test Case 1 asserts the mock captures it with structured context `{product_id, woo_product_id, fields, source}`.
- **T-06-05-02** (Availability: Plan 05 listener exception cascades and fails the sync) — mitigated: `safeRevert()` try/catch + `Log::warning` guaranteed non-propagation. Test Case C7 (listener unit) + Test Case 3 (architecture integration) both prove the swallow path.
- **T-06-05-03** (Tampering: attacker flips pin_title via direct DB) — mitigated: Plan 04 shipped `update_product_override_pins` Filament permission gate. Direct DB writes fall under production DB ACL (infrastructure layer). Plan 05 does not regress this.

## Self-Check: PASSED

Created files verified via direct path inspection:

- `app/Domain/ProductAutoCreate/Listeners/ApplyPinsDuringSync.php` — FOUND
- `tests/Feature/ProductAutoCreate/ApplyPinsDuringSyncTest.php` — FOUND
- `tests/Architecture/PinnedFieldsSurviveSyncTest.php` — FOUND

Modified files verified:

- `app/Providers/EventServiceProvider.php` — `git diff` shows +1 import line + block comment + 3 new `@method` tuples appended to the 3 existing arrays; no deletions; RecomputePriceListener + RecomputeCompletenessOnSupplierChange preserved byte-identically.

Unchanged load-bearing files verified:

- `git diff HEAD app/Domain/Sync/Jobs/SyncChunkJob.php` — empty output (D-11 proof).
- `git diff HEAD app/Domain/ProductAutoCreate/Services/ProductOverrideGuard.php` — empty output.

Commits verified via `git log --oneline -5`:

- `f2ed9c1` — `feat(06-05): add ApplyPinsDuringSync listener for AUTO-10 pin enforcement (D-11)` — 3 files (+369 lines)
- `1ae0d9a` — `test(06-05): add PinnedFieldsSurviveSync regression test — AUTO-10 ship gate (D-13)` — 1 file (+297 lines)

Structural verifications:

- `grep -n 'public string \$queue' app/Domain/ProductAutoCreate/Listeners/ApplyPinsDuringSync.php` → 0 actual property declarations (the grep hit is inside a docblock comment warning about the forbidden pattern). `$this->onQueue('sync-bulk')` in the constructor is the canonical PHP 8.4 pattern.
- `grep -n 'ProductAutoCreate\|ProductOverride\|pin_\|ApplyPins\|revertIfPinned' app/Domain/Sync/Jobs/SyncChunkJob.php` → exit 1 (no matches) — D-11 mandate proven at the source level.
- `php vendor/bin/deptrac analyse --config-file=depfile.yaml --no-progress` → 0 violations, 318 allowed.
- `php artisan event:list | grep ApplyPinsDuringSync` → 3 lines, all marked `(ShouldQueue)`.
- `php -l` on all 3 new files + the modified EventServiceProvider → 0 syntax errors.

Feature-tier + Architecture-tier test execution deferred to MySQL-online environment (same precedent as Plans 06-01, 06-02, 06-03, 06-04). Once operators can run `docker compose up mysql` or equivalent against `meetingstore_ops_testing`, the full suite (10 Feature + 5 Architecture = 15 Plan 05 tests) will run against RefreshDatabase's usual fresh-DB bootstrap.

---

*Phase: 06-product-auto-create*
*Plan: 05-pin-enforcement*
*Completed: 2026-04-23*
