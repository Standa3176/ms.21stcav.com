---
phase: 03-pricing-engine
plan: 04
subsystem: pricing
tags: [pricing, bulk-recompute, artisan-command, queue, dry-run, should-be-unique, sync-bulk, refactor, tdd, pest, mockery]

requires:
  - phase: 01-foundation
    provides: BaseCommand (correlation_id threading), Horizon sync-bulk supervisor, ShouldDispatchAfterCommit via DomainEvent
  - phase: 02-supplier-sync
    provides: D-04 dry-run-default CLI precedent (SyncSupplierCommand), SupplierPriceChanged event, ImportIssue model
  - phase: 03-pricing-engine plan 01
    provides: PriceCalculator (integer-pennies), SupplierPriceUnusableException
  - phase: 03-pricing-engine plan 02
    provides: RuleResolver, PricingResolution, ProductPriceChanged, RecomputePriceListener (refactored here), NoPricingRuleMatchedException
provides:
  - App\Domain\Pricing\Services\PriceRecomputer (shared core called by BOTH listener AND bulk job — single source of truth)
  - App\Domain\Pricing\Services\RecomputeOutcome (readonly DTO: kind + productId + variantId + oldPennies + newPennies + resolutionSource + marginBasisPoints)
  - App\Domain\Pricing\Services\RecomputeOutcomeKind (enum: Changed / Unchanged / ZeroPriceSkipped / NoRuleMatched / ProductNotFound)
  - App\Domain\Pricing\Jobs\RecomputePriceJob (ShouldQueue + ShouldBeUnique, sync-bulk, tries=3, uniqueFor=300)
  - App\Domain\Pricing\Console\Commands\PricingRecomputeCommand (BaseCommand, dry-run default, --live opt-in)
  - AppServiceProvider singleton-binds PriceRecomputer + registers the new command via runningInConsole() guard
  - Refactored RecomputePriceListener as a thin adapter (50 → 40 lines; delegates to PriceRecomputer with persist=true)
affects: [03-05 VERIFICATION gate consumes these artefacts for the Phase 3 ship check, Phase 5 future competitor-driven recompute may call PriceRecomputer directly]

tech-stack:
  added: []  # no new packages — all on top of Phase 1 + 2 foundations + Plan 02 resolver
  patterns:
    - "Extract-to-core refactor: listener + job are thin adapters around one shared implementation (PriceRecomputer)"
    - "persist flag pattern — D-12 dry-run preserved at the core-service level so every caller inherits the gate (no caller re-implements the flag)"
    - "Per-SKU unique jobs via ShouldBeUnique + uniqueFor — concurrent batches cannot race the same product (Pitfall 8)"
    - "Bus::batch dispatch for progress visibility in Horizon + allowFailures() so a per-SKU failure doesn't abort the whole batch"
    - "Scope-flag validation as a command-perform() early return (INVALID exit 2) rather than Symfony's native InputOption constraints — matches Phase 2 D-04 precedent"
    - "Mockery partial-mock of a non-final service — dropping `final` from PriceRecomputer is the one concession for a test-seam; container singleton binding preserves the runtime constraint"

key-files:
  created:
    - app/Domain/Pricing/Services/PriceRecomputer.php
    - app/Domain/Pricing/Services/RecomputeOutcome.php
    - app/Domain/Pricing/Services/RecomputeOutcomeKind.php
    - app/Domain/Pricing/Jobs/RecomputePriceJob.php
    - app/Domain/Pricing/Console/Commands/PricingRecomputeCommand.php
    - tests/Feature/Pricing/PriceRecomputerTest.php
    - tests/Feature/Pricing/RecomputePriceJobTest.php
    - tests/Feature/Pricing/PricingRecomputeCommandDryRunTest.php
    - tests/Feature/Pricing/PricingRecomputeCommandLiveTest.php
  modified:
    - app/Domain/Pricing/Listeners/RecomputePriceListener.php  # refactored to thin adapter delegating to PriceRecomputer
    - app/Providers/AppServiceProvider.php  # singleton(PriceRecomputer::class) + commands(PricingRecomputeCommand::class) registration

key-decisions:
  - "PriceRecomputer is the ONE implementation; listener + bulk job both delegate — extracted verbatim from the listener's Plan 02 body so behaviour parity is automatic (Plan 02 regression tests stayed green post-refactor)"
  - "ImportIssue (D-10 + D-11) is written in BOTH persist modes — the data-quality issue (zero/null buy_price) is a fact independent of the command flag; only sell_price writes + ProductPriceChanged emission are gated by persist"
  - "Dry-run default mirrors Phase 2 D-04 pattern (SyncSupplierCommand). --live + --dry-run together is a command error (INVALID exit 2), not a silent precedence rule — operator typo protection matters"
  - "sync-bulk queue (Phase 1 D-09 + Pitfall 8) — isolated from default (event listener), sync-woo-push (downstream Woo PUT), webhook-inbound. 15k-SKU recompute never starves Woo rate-limited queue"
  - "uniqueId keys on (wooProductId, variantId|'parent') — parent and variant of the same product are independently throttled so a variable product's 5 variations can recompute in parallel but a stuck first attempt and a manual re-run cannot double-emit ProductPriceChanged"
  - "PriceRecomputer dropped `final` so Mockery can construct a partial mock for the job-delegation test (J5). Runtime uniqueness preserved by container singleton binding — production still resolves one instance"
  - "Command extends BaseCommand (not Command) — correlation_id threads into every dispatched job so the Phase 3 recompute + downstream Woo PUT + audit_log rows all join on the same CID"
  - "Scope flags (--only, --brand, --category) are mutually exclusive in v1 — intersection semantics can land later if ops asks; for now 'one or the other' is the simpler mental model"
  - "Command registration via AppServiceProvider::boot() + runningInConsole() guard (Phase 2 Plan 02-03 pattern, confirmed in STATE.md entry). Laravel 12 auto-discovers commands from app/Console/Commands/ but not from app/Domain/*/Console/Commands/"

patterns-established:
  - "Extract-to-core refactor with persist-flag DRY pattern — listener/job/command share one implementation; the flag gates writes + side effects without duplicating guard logic"
  - "RecomputeOutcome DTO as the cross-caller contract — callers (listener, job, future competitor recompute) consume primitives without needing Eloquent, Carbon, or closures; safe over job serialisation"
  - "Mockery positional args for named-arg methods — when Mockery's `->with(a: 1, b: 2)` collides with named dispatch via handle(), use positional `->with(1, 2, ...)` matchers; Mockery's internal reflection reconciles"

requirements-completed: [PRCE-10]

metrics:
  duration: 20min
  started: 2026-04-19T09:42:18Z
  completed: 2026-04-19T10:02:01Z
  tasks_completed: 2
  files_changed: 11  # 9 created, 2 modified
  pest_tests_added: 29  # 12 PriceRecomputer + 6 job + 7 dry-run + 4 live
  full_suite: 418 passed / 2 skipped / 0 failed / 4620 assertions
---

# Phase 3 Plan 04: Bulk Recompute Command Summary

**`php artisan pricing:recompute` ships dry-run-by-default (D-12) with `--live` opt-in, dispatching per-SKU `RecomputePriceJob` instances onto the `sync-bulk` queue; the recompute pipeline extracted into `PriceRecomputer` so the Plan 02 event-driven listener AND the Plan 04 bulk job invoke ONE implementation — zero drift between paths, Plan 02 regression tests stayed green through the refactor.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-04-19T09:42:18Z
- **Completed:** 2026-04-19T10:02:01Z
- **Tasks:** 2 / 2
- **Files changed:** 11 (9 created, 2 modified)
- **Pest tests added:** 29 (12 PriceRecomputer + 6 job + 7 dry-run + 4 live)
- **Full suite:** 418 passed / 2 skipped / 0 failed / 4620 assertions (258s)
- **Deptrac:** 0 violations / 59 allowed

## Accomplishments

- `PriceRecomputer` is the single shared core for "given a Woo identity, recompute its price." Both the event-driven `RecomputePriceListener` (Plan 02, `persist=true`) AND the bulk `RecomputePriceJob` (this plan, `persist` per command flag) invoke it — no duplicated guard logic, no drift risk. Plan 02's 13 existing regression tests still pass unchanged post-refactor (listener body was extracted verbatim into the core).
- `RecomputeOutcome` readonly DTO + `RecomputeOutcomeKind` enum (`Changed` / `Unchanged` / `ZeroPriceSkipped` / `NoRuleMatched` / `ProductNotFound`) give callers structured feedback about what happened — the bulk command's future CSV report can tally directly from these.
- `pricing:recompute` command (BaseCommand-extended, correlation_id-threaded) ships with the Phase 2 D-04 dry-run-default precedent: `--all` alone → DRY-RUN, `--all --live` → LIVE writes + events, `--all --live --dry-run` → INVALID (exit 2, mutually exclusive). `--only=SKU,SKU` / `--brand=ID` / `--category=ID` provide scoped alternatives to `--all`; scope flags are mutually exclusive in v1.
- `RecomputePriceJob` implements `ShouldQueue + ShouldBeUnique` on the `sync-bulk` queue (Phase 1 D-09 + Pitfall 8). `uniqueFor = 300` covers a 5-minute retry window; `uniqueId()` keys on `(wooProductId, variantId|'parent')` so parent and variant of the same SKU throttle independently. `tries = 3, timeout = 120`; per-SKU failures isolate from the rest of the batch via `Bus::batch()->allowFailures()`.
- Command output carries an unambiguous `LIVE` or `DRY-RUN` banner plus a reminder that `WOO_WRITE_ENABLED` remains the downstream Woo push gate (Phase 1 D-08) — belt-and-braces against a typo'd production `--live` flag.

## Task Commits

1. **Task 1 — PriceRecomputer extract + listener refactor + 12 tests** — `b31716a`
2. **Task 2 — RecomputePriceJob + PricingRecomputeCommand + 17 tests** — `6cacf82`

## Files Created / Modified

### Shared core (Task 1)

- `app/Domain/Pricing/Services/PriceRecomputer.php` — new shared core, class (not `final` — Mockery seam); `recompute(wooProductId, wooVariationId, sku, correlationId, persist): RecomputeOutcome`. Writes `ImportIssue` on zero-price in BOTH persist modes (D-10 + D-11). Writes `sell_price` + dispatches `ProductPriceChanged` only when `persist=true` AND integer-penny diff (D-13).
- `app/Domain/Pricing/Services/RecomputeOutcome.php` — `final readonly class`; 7 primitive fields.
- `app/Domain/Pricing/Services/RecomputeOutcomeKind.php` — backed enum, 5 cases.
- `app/Domain/Pricing/Listeners/RecomputePriceListener.php` — **refactored** from 50 → 40 lines. Single constructor dep (`PriceRecomputer`); `handle()` forwards 4 event fields + `persist=true` to the core.
- `app/Providers/AppServiceProvider.php` — `$this->app->singleton(PriceRecomputer::class)` in `register()`.
- `tests/Feature/Pricing/PriceRecomputerTest.php` — 12 tests covering every persist × outcome combination.

### Job + command (Task 2)

- `app/Domain/Pricing/Jobs/RecomputePriceJob.php` — implements `ShouldQueue + ShouldBeUnique`. Five readonly constructor args. `onQueue('sync-bulk')` in constructor; `uniqueId()` returns `recompute-price:{wooId}:{variantId|parent}`; `handle(PriceRecomputer)` delegates with named args.
- `app/Domain/Pricing/Console/Commands/PricingRecomputeCommand.php` — extends `BaseCommand`. Signature has `--all / --only= / --brand= / --category= / --live / --dry-run`. `perform()` validates flag combos → builds scope-filtered `Product::query()->with('variants')->chunkById(500, …)` → emits 1 job per product + 1 job per variant → `Bus::batch($jobs)->name(…)->onQueue('sync-bulk')->allowFailures()->dispatch()`.
- `app/Providers/AppServiceProvider.php` — added `PricingRecomputeCommand::class` to the `commands([...])` call inside `runningInConsole()` guard (Phase 2 Plan 02-03 pattern).
- `tests/Feature/Pricing/RecomputePriceJobTest.php` — 6 tests (ShouldQueue+ShouldBeUnique, queue name, uniqueFor, uniqueId shape, handle delegation via Mockery, tries=3).
- `tests/Feature/Pricing/PricingRecomputeCommandDryRunTest.php` — 7 tests (default dry-run, explicit --dry-run, --only scope, --brand scope, summary output, --live --dry-run error, no-scope error).
- `tests/Feature/Pricing/PricingRecomputeCommandLiveTest.php` — 4 tests (--live persist=true, --live --only one job, --live sync-bulk queue, LIVE banner + WOO_WRITE_ENABLED warning).

## PriceRecomputer Contract

```php
namespace App\Domain\Pricing\Services;

final readonly class RecomputeOutcome
{
    public function __construct(
        public RecomputeOutcomeKind $kind,
        public int $productId,        // 0 when ProductNotFound
        public ?int $variantId,       // non-null only on variant path
        public ?int $oldPennies,
        public ?int $newPennies,
        public ?string $resolutionSource,  // null on zero-price / no-rule / not-found
        public ?int $marginBasisPoints,
    ) {}
}

class PriceRecomputer
{
    public function __construct(
        private readonly RuleResolver $resolver,
        private readonly PriceCalculator $calculator,
    ) {}

    public function recompute(
        int $wooProductId,
        ?int $wooVariationId,
        string $sku,
        string $correlationId,
        bool $persist,
    ): RecomputeOutcome;
}
```

**Persist gate (D-12) semantics:**

| Condition                        | sell_price write | ProductPriceChanged | ImportIssue row                       |
| -------------------------------- | ---------------- | ------------------- | ------------------------------------- |
| persist=true, diff               | ✅ written       | ✅ dispatched       | none                                  |
| persist=true, equal (D-13)       | ❌ untouched     | ❌ not dispatched   | none                                  |
| persist=true, zero buy_price     | ❌ untouched     | ❌ not dispatched   | ✅ updateOrCreate (D-10 + D-11)       |
| persist=true, no rule matched    | ❌ untouched     | ❌ not dispatched   | none (logged ERROR)                   |
| persist=true, product not found  | ❌ untouched     | ❌ not dispatched   | none (logged WARNING)                 |
| persist=false, diff              | ❌ untouched     | ❌ not dispatched   | none (reports Changed — would write)  |
| persist=false, equal             | ❌ untouched     | ❌ not dispatched   | none                                  |
| persist=false, zero buy_price    | ❌ untouched     | ❌ not dispatched   | ✅ STILL written (data-quality fact)  |
| persist=false, no rule matched   | ❌ untouched     | ❌ not dispatched   | none (logged ERROR)                   |

The "ImportIssue still written in dry-run" rule is intentional — a zero/null buy_price is a real catalogue-health defect regardless of which operator tool surfaced it. The `--dry-run` flag only gates writes to `products.sell_price` and `ProductPriceChanged` dispatch, because those drive real price changes downstream. ImportIssue triage is a read-only reporting concern.

## RecomputePriceJob Contract

```php
class RecomputePriceJob implements ShouldBeUnique, ShouldQueue
{
    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 300;  // 5 min

    public function __construct(
        public readonly int $wooProductId,
        public readonly ?int $wooVariationId,
        public readonly string $sku,
        public readonly string $correlationId,
        public readonly bool $persist,
    ) {
        $this->onQueue('sync-bulk');
    }

    public function uniqueId(): string
    {
        return 'recompute-price:'.$this->wooProductId.':'.($this->wooVariationId ?? 'parent');
    }

    public function handle(PriceRecomputer $recomputer): void;
}
```

**Uniqueness rationale (Pitfall 8):** A stuck batch cron + a manual re-run cannot double-emit `ProductPriceChanged` for the same SKU within 5 minutes. Parent vs variant of the same product throttle independently (variant-id appended to unique key), so a variable product with 5 colour variations still recomputes all 5 in parallel — only re-dispatch of the same specific variant within 300s is blocked.

## Command Flag Matrix

| Invocation                                                 | Exit | Mode     | Jobs dispatched             |
| ---------------------------------------------------------- | ---- | -------- | --------------------------- |
| `pricing:recompute`                                        | 2    | n/a      | 0 (scope required)          |
| `pricing:recompute --all`                                  | 0    | DRY-RUN  | every Product + variants    |
| `pricing:recompute --all --dry-run`                        | 0    | DRY-RUN  | every Product + variants    |
| `pricing:recompute --all --live`                           | 0    | LIVE     | every Product + variants    |
| `pricing:recompute --all --live --dry-run`                 | 2    | n/a      | 0 (mutually exclusive)      |
| `pricing:recompute --only=SKU1,SKU2`                       | 0    | DRY-RUN  | 2 (+ variants)              |
| `pricing:recompute --only=SKU1 --live`                     | 0    | LIVE     | 1 (+ variants)              |
| `pricing:recompute --only=SKU1 --brand=42`                 | 2    | n/a      | 0 (scopes mutually exclusive)|
| `pricing:recompute --brand=42`                             | 0    | DRY-RUN  | all products with brand_id=42|
| `pricing:recompute --category=10 --live`                   | 0    | LIVE     | all products with category_id=10|

Exit code 2 is Symfony's `Command::INVALID`. Errors are written to stderr via `$this->error(…)` so wrapping scripts can detect validation failure.

## Sample Output

```
Correlation: 461fcf8a-fdb9-49c5-a597-7dbf648d6072
Pricing recompute starting — mode: DRY-RUN
DRY-RUN: no sell_price writes, no ProductPriceChanged events. ImportIssue rows WILL still be written for zero-price products (data-quality fact).
Dispatching 3 RecomputePriceJob(s) onto sync-bulk queue…
Batch dispatched — id=9bf3… . Track progress in Horizon.
  processed: 3
  mode: DRY-RUN
  correlation_id: 461fcf8a-fdb9-49c5-a597-7dbf648d6072
  See /horizon for real-time progress.
```

## Verification Snapshot

- `php artisan list | grep "pricing:recompute"` → present.
- `php artisan pricing:recompute --help` → lists `--all`, `--only`, `--brand`, `--category`, `--live`, `--dry-run`.
- `php artisan pricing:recompute` → exit 2, stderr "One of --all, --only=SKU,... , --brand=ID, --category=ID is required."
- `php artisan pricing:recompute --live --dry-run` → exit 2, stderr "--live and --dry-run are mutually exclusive (D-12)."
- `vendor/bin/pest tests/Feature/Pricing/PriceRecomputerTest.php tests/Feature/Pricing/RecomputePriceJobTest.php tests/Feature/Pricing/PricingRecomputeCommandDryRunTest.php tests/Feature/Pricing/PricingRecomputeCommandLiveTest.php` → 29 passed.
- `vendor/bin/pest tests/Feature/Pricing/RecomputePriceListenerTest.php tests/Feature/Pricing/RecomputePriceListenerZeroPriceTest.php` → 13 passed (Plan 02 regression).
- `vendor/bin/pest` (full project suite) → 418 passed / 2 skipped / 0 failed / 4620 assertions.
- `vendor/bin/deptrac analyse` → 0 violations.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking issue] Dropped `final` from PriceRecomputer to unblock Mockery test J5**
- **Found during:** Task 2 first RED run — `new class extends PriceRecomputer {...}` rejected with "Cannot use final class."
- **Issue:** Job test J5 asserts `handle()` delegates to `PriceRecomputer::recompute()` with the 5 constructor args. The cleanest test seam is a Mockery partial-mock that records + verifies the call. Mockery cannot mock a `final class`.
- **Fix:** Dropped `final` from `PriceRecomputer`. Runtime uniqueness preserved by the singleton container binding in `AppServiceProvider::register()`. All other Pricing classes (`RuleResolver`, `PriceCalculator`, `RecomputeOutcome`, listener) remain `final` — the seam is narrowly scoped to the one class with a test-double need.
- **Files modified:** `app/Domain/Pricing/Services/PriceRecomputer.php`
- **Commit:** `6cacf82`

**2. [Rule 1 — Bug, self-inflicted] `array_map` on BusFake jobs Collection**
- **Found during:** Task 2 Dry-run tests D3 + D5 first GREEN run — `TypeError: array_map(): Argument #2 ($array) must be of type array, Illuminate\\Support\\Collection given`.
- **Issue:** `BusFake` stores `$batch->jobs` as a `Collection`, not an array. My test helpers used `array_map($fn, $batch->jobs)` + `sort()`.
- **Fix:** Switched to `collect($batch->jobs)->map(…)->sort()->values()->all()` — works on both Collection and array shapes.
- **Files modified:** `tests/Feature/Pricing/PricingRecomputeCommandDryRunTest.php`
- **Commit:** `6cacf82`

**3. [Rule 1 — Bug, self-inflicted] Mockery named-args vs handle()'s named dispatch**
- **Found during:** Task 2 RecomputePriceJobTest J5 first GREEN run — "Undefined array key 0" deep in PriceRecomputer::recompute() because Mockery recorded the incoming call with zero positional args.
- **Issue:** `$mock->shouldReceive('recompute')->with(wooProductId: 101, …)` combined with `$recomputer->recompute(wooProductId: $this->wooProductId, …)` caused Mockery's reflection-based matcher to misalign. The named-arg → positional-arg reconciliation at the mock boundary hit a gap.
- **Fix:** Switched the test's `->with(…)` to positional: `->with(101, 202, 'SKU-J5', 'cid', false)`. Mockery's positional matcher reflects the called method signature cleanly. The production `handle()` keeps its named-arg style for readability.
- **Files modified:** `tests/Feature/Pricing/RecomputePriceJobTest.php`
- **Commit:** `6cacf82`

**4. [Rule 1 — Bug, self-inflicted] Symfony-wrapped `warn()` text truncation**
- **Found during:** Task 2 Live test L4 — "Output does not contain WOO_WRITE_ENABLED" despite the warn line containing exactly that token.
- **Issue:** Symfony's block-style warning output wraps long text at ~80 columns by default. My single-line warn carried "LIVE mode will write … WOO_WRITE_ENABLED …" which got wrapped across two internal lines; the output-matcher scan didn't find the exact token on a single line.
- **Fix:** Split the LIVE message into a short `warn()` (LIVE mode assertion) + a separate `line()` (the WOO_WRITE_ENABLED reminder). Output matches cleanly; operator still sees both messages.
- **Files modified:** `app/Domain/Pricing/Console/Commands/PricingRecomputeCommand.php`
- **Commit:** `6cacf82`

### Manual Policy Decisions

None. Plan 03-04 had no `checkpoint:decision` tasks; every D-0x lock (D-09 queue segregation, D-10 + D-11 ImportIssue semantics, D-12 dry-run default, D-13 integer-pennies gate) was honoured exactly.

## Pointer for Plan 05 (VERIFICATION gate)

- Manual probe: run `php artisan pricing:recompute --all --dry-run` on a realistic seed; confirm Horizon shows a `sync-bulk` batch labeled `pricing:recompute dry-run` + processed count matches `Product::count() + ProductVariant::count()`.
- Manual probe: run with `--live` and confirm `ProductPriceChanged` events fire for each changed row (inspect `integration_events` join by `correlation_id`).
- Deptrac ruleset: add a dedicated Pricing-layer architectural test if one doesn't already exist — the layer now has its own Jobs + Commands subtree (SYNC-04-style `-WpDirectDb` is already denied at the Pricing layer? Check current ruleset; add if not).
- Ship-gate regression: the golden-fixture calculator test (Plan 01) + the 13 listener tests + the 29 tests added here form the full Phase 3 regression surface. Plan 05 should run all of them + `vendor/bin/deptrac analyse` as the automated bar.
- `RecomputeOutcome[]` is the natural input for a CSV report mirroring Phase 2 D-10's column set (sku / woo_product_id / variant_id / action / old_price / new_price / correlation_id). If Plan 05 wants to ship a per-run report file, the bulk command can collect outcomes in a new `RecomputePriceBatch` service + write via `spatie/simple-excel`.

## Threat Flags

None new. The plan's `<threat_model>` surface (T-03-04-01 through T-03-04-08) was fully honoured:
- T-03-04-01 (DoS on sync-woo-push): mitigated by `sync-bulk` queue isolation (J2 test) + ShouldBeUnique 300s window (J3 test).
- T-03-04-02 (zero-price leak in bulk): mitigated by shared PriceRecomputer core writing ImportIssue + skipping sell_price in BOTH modes (Tests 5, 6).
- T-03-04-03 (accidental --live on prod): mitigated by dry-run default (D1), --live + --dry-run error (D6), LIVE banner + WOO_WRITE_ENABLED reminder (L4).
- T-03-04-04 (who ran what when): mitigated by BaseCommand correlation_id threading into every dispatched job.
- T-03-04-05, -06, -07, -08: accept / mitigated — no new surface introduced by this plan beyond the existing Woo-write-gate + sync_runs audit path.

## Self-Check: PASSED

**Files verified on disk (all present):**
- ✅ `app/Domain/Pricing/Services/PriceRecomputer.php` — `class PriceRecomputer` (non-final), `bool $persist` param, `ImportIssue::updateOrCreate`, `saveQuietly`, constructor DI of `RuleResolver` + `PriceCalculator`.
- ✅ `app/Domain/Pricing/Services/RecomputeOutcome.php` — `final readonly class RecomputeOutcome`.
- ✅ `app/Domain/Pricing/Services/RecomputeOutcomeKind.php` — `enum RecomputeOutcomeKind: string` with 5 cases.
- ✅ `app/Domain/Pricing/Jobs/RecomputePriceJob.php` — `implements ShouldBeUnique, ShouldQueue`, `sync-bulk`, `tries=3`, `uniqueFor=300`, `uniqueId()`.
- ✅ `app/Domain/Pricing/Console/Commands/PricingRecomputeCommand.php` — `extends BaseCommand`, `pricing:recompute` signature, `mutually exclusive` text, `perform()` method.
- ✅ `app/Domain/Pricing/Listeners/RecomputePriceListener.php` — 40 lines, `PriceRecomputer` injection, delegates with `persist: true`.
- ✅ `app/Providers/AppServiceProvider.php` — `PriceRecomputer::class` singleton + `PricingRecomputeCommand::class` in `commands()`.
- ✅ 4 new test files under `tests/Feature/Pricing/` (PriceRecomputerTest + RecomputePriceJobTest + 2 command tests).

**Commits verified (both present in git log):**
- ✅ `b31716a` — refactor(03-04): extract PriceRecomputer shared core + dry-run mode
- ✅ `6cacf82` — feat(03-04): add pricing:recompute command + RecomputePriceJob

**End-to-end verification:**
- ✅ `php artisan list 2>&1 | grep -q "pricing:recompute"` — command registered.
- ✅ `php artisan pricing:recompute --help` — lists `--live`, `--dry-run`, `--only`, `--brand`, `--category`, `--all`.
- ✅ `php artisan pricing:recompute --live --dry-run` — exit 2, stderr "mutually exclusive".
- ✅ `php artisan pricing:recompute` (no flags) — exit 2, stderr requires a scope.
- ✅ `vendor/bin/pest` (full project suite) — 418 passed / 2 skipped / 0 failed / 4620 assertions.
- ✅ `vendor/bin/deptrac analyse` — 0 violations / 59 allowed.
