---
phase: 03-pricing-engine
plan: 04
type: execute
wave: 3
depends_on:
  - 03-02
files_modified:
  - app/Domain/Pricing/Services/PriceRecomputer.php
  - app/Domain/Pricing/Jobs/RecomputePriceJob.php
  - app/Domain/Pricing/Console/Commands/PricingRecomputeCommand.php
  - app/Domain/Pricing/Listeners/RecomputePriceListener.php
  - app/Providers/AppServiceProvider.php
  - tests/Feature/Pricing/PricingRecomputeCommandDryRunTest.php
  - tests/Feature/Pricing/PricingRecomputeCommandLiveTest.php
  - tests/Feature/Pricing/RecomputePriceJobTest.php
  - tests/Feature/Pricing/PriceRecomputerTest.php
autonomous: true
requirements:
  - PRCE-10

must_haves:
  truths:
    - "php artisan pricing:recompute defaults to DRY-RUN — no sell_price writes, no ProductPriceChanged, only a per-run report"
    - "php artisan pricing:recompute --live explicitly opts in to persistence + event emission"
    - "--live and --dry-run together is a command error (mutually exclusive per Phase 2 D-04 pattern)"
    - "Command dispatches a queued batch on the sync-bulk queue (per Phase 1 D-09 supervisor segregation)"
    - "Each product/variant gets its own RecomputePriceJob so failures are isolated and Horizon shows progress"
    - "RecomputePriceJob uses withoutOverlapping (Pitfall 8) so two concurrent batches for same SKU cannot race"
    - "PriceRecomputer service is the shared core called by both the listener AND the bulk job (DRY)"
    - "Zero/null buy_price is handled identically in bulk: ImportIssue row via updateOrCreate, idempotent (D-11)"
    - "Batch summary surfaces: processed count, changed count, unchanged count, skipped_zero_price count, failed count"
  artifacts:
    - path: "app/Domain/Pricing/Services/PriceRecomputer.php"
      provides: "Shared recompute core — called by listener + bulk job; returns RecomputeOutcome DTO"
      min_lines: 60
    - path: "app/Domain/Pricing/Jobs/RecomputePriceJob.php"
      provides: "Per-product queued job on sync-bulk queue; uses withoutOverlapping"
      contains: "sync-bulk"
    - path: "app/Domain/Pricing/Console/Commands/PricingRecomputeCommand.php"
      provides: "Artisan command with --live / --dry-run / --only=sku,sku / --brand= / --category="
      contains: "pricing:recompute"
    - path: "tests/Feature/Pricing/PricingRecomputeCommandDryRunTest.php"
      provides: "Dry-run does not write sell_price, does not emit ProductPriceChanged"
      contains: "Event::assertNotDispatched"
    - path: "tests/Feature/Pricing/PricingRecomputeCommandLiveTest.php"
      provides: "Live run writes sell_price + dispatches ProductPriceChanged per changed SKU"
      contains: "ProductPriceChanged"
    - path: "tests/Feature/Pricing/RecomputePriceJobTest.php"
      provides: "Job unit: withoutOverlapping key, sync-bulk queue assignment, retry count"
      contains: "sync-bulk"
  key_links:
    - from: "app/Domain/Pricing/Console/Commands/PricingRecomputeCommand.php"
      to: "app/Domain/Pricing/Jobs/RecomputePriceJob.php"
      via: "Bus::batch() dispatch"
      pattern: "RecomputePriceJob::dispatch"
    - from: "app/Domain/Pricing/Jobs/RecomputePriceJob.php"
      to: "app/Domain/Pricing/Services/PriceRecomputer.php"
      via: "constructor DI + handle()"
      pattern: "PriceRecomputer"
    - from: "app/Domain/Pricing/Listeners/RecomputePriceListener.php"
      to: "app/Domain/Pricing/Services/PriceRecomputer.php"
      via: "extracted shared core"
      pattern: "PriceRecomputer"
---

<objective>
Ship the bulk recompute command so a pricing manager (or an automated cutover playbook) can recompute every product's final price after editing a rule, without waiting for the next daily supplier sync. Extract the listener's core logic into `PriceRecomputer` so both the event-driven listener (Plan 02) AND the bulk job use ONE implementation — no drift between the two paths. Dry-run is the default (Phase 2 D-04 pattern carried through D-12); `--live` is the opt-in; `--dry-run --live` together is a command error.

Purpose: Phase 3 success criterion #5 — "php artisan pricing:recompute --all dispatches a queued batch that recomputes every product's final price and surfaces progress in Horizon". The command is ops' tool for whole-catalogue re-pricing; it MUST segregate onto the sync-bulk queue (Phase 1 D-09 + Pitfall 8) so it never blocks webhook handlers. `withoutOverlapping` at the job level prevents a stuck first batch + a new cron from double-writing. The refactor moving the recompute core OUT of the listener AND into PriceRecomputer is deliberate: the listener becomes a thin adapter, the bulk job becomes another thin adapter, and the core's behaviour has ONE test surface.

Output:
- `App\Domain\Pricing\Services\PriceRecomputer` — shared core: takes Product (+ optional ProductVariant), returns RecomputeOutcome DTO (changed | unchanged | zero_price_skipped | no_rule_matched)
- `App\Domain\Pricing\Jobs\RecomputePriceJob` — implements ShouldQueue + ShouldBeUnique; runs on `sync-bulk` queue; per-SKU atomic
- `App\Domain\Pricing\Console\Commands\PricingRecomputeCommand` — extends BaseCommand, dispatches Bus::batch() of RecomputePriceJob's, tails progress
- Refactored `RecomputePriceListener` — thin wrapper delegating to PriceRecomputer (same public behaviour)
- AppServiceProvider registers PriceRecomputer as a singleton
- 4 test files: dry-run command, live command, job contract, recomputer unit
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/03-pricing-engine/03-CONTEXT.md
@.planning/phases/03-pricing-engine/03-01-SUMMARY.md
@.planning/phases/03-pricing-engine/03-02-SUMMARY.md
@.planning/phases/02-supplier-sync/02-03-SUMMARY.md
@.planning/research/PITFALLS.md
@CLAUDE.md
@app/Console/Commands/BaseCommand.php
@app/Domain/Pricing/Listeners/RecomputePriceListener.php
@app/Domain/Pricing/Services/RuleResolver.php
@app/Domain/Pricing/Services/PriceCalculator.php
@app/Domain/Pricing/Events/ProductPriceChanged.php
@app/Domain/Sync/Jobs/SyncChunkJob.php
@app/Domain/Sync/Console/Commands/SyncSupplierCommand.php
@app/Providers/AppServiceProvider.php

<interfaces>
<!-- Plan 05 guardrails reference these; future Phase 5 competitor suggestions may call PriceRecomputer directly -->

```php
namespace App\Domain\Pricing\Services;

enum RecomputeOutcomeKind: string
{
    case Changed = 'changed';
    case Unchanged = 'unchanged';
    case ZeroPriceSkipped = 'zero_price_skipped';
    case NoRuleMatched = 'no_rule_matched';
    case ProductNotFound = 'product_not_found';
}

final readonly class RecomputeOutcome
{
    public function __construct(
        public RecomputeOutcomeKind $kind,
        public int $productId,
        public ?int $variantId,
        public ?int $oldPennies,
        public ?int $newPennies,
        public ?string $resolutionSource,
        public ?int $marginBasisPoints,
    ) {}
}

final class PriceRecomputer
{
    public function __construct(
        private readonly RuleResolver $resolver,
        private readonly PriceCalculator $calculator,
    ) {}

    /**
     * Recompute the final price for a given Product (optionally scoped to a variant).
     *
     * When $persist=true AND the price changed, writes sell_price + dispatches ProductPriceChanged.
     * When $persist=false, returns the outcome WITHOUT writing or dispatching (dry-run mode).
     *
     * Zero/null buy_price writes an ImportIssue (missing_cost_price) via updateOrCreate in BOTH modes
     * (the issue is real regardless of dry-run; persistence flag only gates sell_price + event).
     *
     * @param  int  $wooProductId            Woo's identity key
     * @param  int|null  $wooVariationId     Woo variation id if variant-scoped
     * @param  string  $sku                   SKU for issue logging
     * @param  string  $correlationId        Threads into events + issue rows
     * @param  bool  $persist                 true=live, false=dry-run (D-12)
     */
    public function recompute(
        int $wooProductId,
        ?int $wooVariationId,
        string $sku,
        string $correlationId,
        bool $persist,
    ): RecomputeOutcome;
}
```

RecomputePriceJob contract:
```php
namespace App\Domain\Pricing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RecomputePriceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 300;  // 5 min — prevents double-dispatch for same SKU within a batch

    public function __construct(
        public readonly int $wooProductId,
        public readonly ?int $wooVariationId,
        public readonly string $sku,
        public readonly string $correlationId,
        public readonly bool $persist,
    ) {
        $this->onQueue('sync-bulk');  // Phase 1 D-09 + Pitfall 8
    }

    public function uniqueId(): string
    {
        return "recompute-price:{$this->wooProductId}:".($this->wooVariationId ?? 'parent');
    }

    public function handle(PriceRecomputer $recomputer): void;
}
```

Command signature:
```
php artisan pricing:recompute [--all] [--live] [--dry-run] [--only=SKU1,SKU2] [--brand=brand_id] [--category=category_id]

Examples:
  pricing:recompute --all                     # D-12 default: dry-run across full catalogue
  pricing:recompute --all --live              # live write across full catalogue
  pricing:recompute --only=LOG-C930E,JBL-001  # dry-run a subset
  pricing:recompute --brand=42 --live         # live, brand-scoped
```

Validation: --live + --dry-run = error. --only + --brand = error (scopes mutually exclusive). --all required unless --only/--brand/--category given.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: PriceRecomputer service + refactor RecomputePriceListener to delegate (unified core)</name>
  <files>
    app/Domain/Pricing/Services/PriceRecomputer.php,
    app/Domain/Pricing/Services/RecomputeOutcome.php,
    app/Domain/Pricing/Services/RecomputeOutcomeKind.php,
    app/Domain/Pricing/Listeners/RecomputePriceListener.php,
    app/Providers/AppServiceProvider.php,
    tests/Feature/Pricing/PriceRecomputerTest.php
  </files>
  <read_first>
    .planning/phases/03-pricing-engine/03-02-SUMMARY.md,
    app/Domain/Pricing/Listeners/RecomputePriceListener.php,
    app/Domain/Pricing/Services/RuleResolver.php,
    app/Domain/Pricing/Services/PriceCalculator.php,
    app/Domain/Pricing/Events/ProductPriceChanged.php,
    app/Domain/Products/Models/Product.php,
    app/Domain/Products/Models/ProductVariant.php,
    app/Domain/Sync/Models/ImportIssue.php,
    app/Providers/AppServiceProvider.php
  </read_first>
  <behavior>
    - Test 1 (persist=true + price changed): recompute(wooProductId=X, ..., persist=true) returns outcome.kind=Changed; DB sell_price updated; ProductPriceChanged dispatched once.
    - Test 2 (persist=true + price unchanged): outcome.kind=Unchanged; DB untouched; no event dispatched.
    - Test 3 (persist=false + price would change): outcome.kind=Changed (still reports what WOULD change); DB sell_price UNCHANGED; no event dispatched. oldPennies != newPennies fields populated.
    - Test 4 (persist=false + price same): outcome.kind=Unchanged; no event; no DB change.
    - Test 5 (persist=true + zero buy_price): outcome.kind=ZeroPriceSkipped; ImportIssue row created (missing_cost_price); NO ProductPriceChanged; sell_price UNCHANGED.
    - Test 6 (persist=false + zero buy_price): SAME — ImportIssue still written (the issue is real regardless of dry-run), no sell_price change, no event.
    - Test 7 (persist=true + no rule matched): outcome.kind=NoRuleMatched; no DB change; no event; no ImportIssue (a logging concern, not a data-quality concern).
    - Test 8 (persist=true + product not found): outcome.kind=ProductNotFound; nothing touched.
    - Test 9 (variant path): recompute with wooVariationId → product_variants.sell_price updated; outcome.variantId populated.
    - Test 10 (idempotent ImportIssue): two consecutive recompute() calls for same zero-price product → ONE ImportIssue row (updateOrCreate on resolved_at IS NULL); last_seen_at bumped.
    - Test 11 (correlation_id threaded to ProductPriceChanged): passed correlationId === dispatched event's correlationId (via Context::add before dispatch).
    - Test 12 (listener delegation — regression check): after refactor, the Plan 02 RecomputePriceListenerTest still passes (listener just delegates to PriceRecomputer with persist=true).
  </behavior>
  <action>
    Step 1 — author the enum + DTO under `app/Domain/Pricing/Services/`:
    - `RecomputeOutcomeKind.php`: PHP 8.1+ enum backed by string with 5 cases per <interfaces>.
    - `RecomputeOutcome.php`: final readonly class with the 7 fields per <interfaces>.

    Step 2 — author `app/Domain/Pricing/Services/PriceRecomputer.php` by EXTRACTING the current listener logic. Port the listener's `handle()` body almost verbatim but parameterise:
    - Replace `$event->wooProductId` → `$wooProductId` parameter
    - Replace `$event->sku` → `$sku` parameter
    - Replace `$event->correlationId` → `$correlationId` parameter
    - Add `bool $persist` gate: wrap the `$target->forceFill(...)->saveQuietly()` + `ProductPriceChanged::dispatch(...)` block inside `if ($persist) { ... }`.
    - Instead of returning void, return a RecomputeOutcome with the appropriate kind based on the path taken.
    - Leave `ImportIssue::updateOrCreate(...)` OUTSIDE the $persist guard — an unusable supplier price is a data-quality fact, not a dry-run artefact.
    - Class-level PHPDoc cites Plan 02 (extracted from listener) + Plan 04 (consumed by bulk job).

    Step 3 — refactor `app/Domain/Pricing/Listeners/RecomputePriceListener.php` to delegate:
    ```php
    final class RecomputePriceListener implements ShouldQueue
    {
        public string $queue = 'default';

        public function __construct(private readonly PriceRecomputer $recomputer) {}

        public function handle(SupplierPriceChanged $event): void
        {
            $this->recomputer->recompute(
                wooProductId: $event->wooProductId,
                wooVariationId: $event->wooVariationId,
                sku: $event->sku,
                correlationId: $event->correlationId,
                persist: true,  // listener is always live — the Woo-write-gate is a separate concern for the downstream listener
            );
        }
    }
    ```
    This removes ~80 lines of duplicated logic; listener stays < 20 lines.

    Step 4 — update `app/Providers/AppServiceProvider.php`. Bind PriceRecomputer as a singleton (it's stateless but singleton avoids repeat DI resolution cost in a bulk batch):
    ```php
    $this->app->singleton(\App\Domain\Pricing\Services\PriceRecomputer::class);
    ```
    Preserve Gate::policy bindings from Plan 01.

    Step 5 — author `tests/Feature/Pricing/PriceRecomputerTest.php` with all 12 behaviours. Use `Event::fake([ProductPriceChanged::class])` in beforeEach. Each test calls `app(PriceRecomputer::class)->recompute(...)` with the relevant parameters and asserts outcome.kind + DB state + event dispatched/not-dispatched.

    Step 6 — run the existing Plan 02 listener test to confirm no regression:
    ```
    vendor/bin/pest tests/Feature/Pricing/RecomputePriceListenerTest.php tests/Feature/Pricing/RecomputePriceListenerZeroPriceTest.php --stop-on-failure
    ```
    Then run the new test:
    ```
    vendor/bin/pest tests/Feature/Pricing/PriceRecomputerTest.php --stop-on-failure
    ```
    Both MUST pass (Plan 02 tests still pass post-refactor; new 12-test suite passes).

    **DO NOT:**
    - Do NOT change the listener's observable behaviour (queue name, signature, dispatched event contract). Plan 02 tests are the regression net.
    - Do NOT skip the ImportIssue write in dry-run mode — data quality issues exist regardless of the command flag.
    - Do NOT dispatch ProductPriceChanged in dry-run mode — the whole point of dry-run (D-12) is to preview without triggering downstream effects.
    - Do NOT add retry logic inside PriceRecomputer — the JOB decides retries (tries=3 on the job class).
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Pricing/PriceRecomputerTest.php tests/Feature/Pricing/RecomputePriceListenerTest.php tests/Feature/Pricing/RecomputePriceListenerZeroPriceTest.php --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `test -f app/Domain/Pricing/Services/PriceRecomputer.php` returns 0
    - `test -f app/Domain/Pricing/Services/RecomputeOutcome.php` returns 0
    - `test -f app/Domain/Pricing/Services/RecomputeOutcomeKind.php` returns 0
    - `grep -q "final readonly class RecomputeOutcome" app/Domain/Pricing/Services/RecomputeOutcome.php`
    - `grep -q "enum RecomputeOutcomeKind" app/Domain/Pricing/Services/RecomputeOutcomeKind.php`
    - `grep -q "bool \$persist" app/Domain/Pricing/Services/PriceRecomputer.php`
    - `grep -q "ImportIssue::updateOrCreate" app/Domain/Pricing/Services/PriceRecomputer.php`
    - `grep -q "saveQuietly" app/Domain/Pricing/Services/PriceRecomputer.php`
    - `grep -q "PriceRecomputer" app/Domain/Pricing/Listeners/RecomputePriceListener.php`
    - `wc -l app/Domain/Pricing/Listeners/RecomputePriceListener.php` reports < 50 lines (refactor dropped bulk of logic)
    - `grep -q "PriceRecomputer::class" app/Providers/AppServiceProvider.php`
    - `vendor/bin/pest tests/Feature/Pricing/PriceRecomputerTest.php --stop-on-failure` exits 0
    - `vendor/bin/pest tests/Feature/Pricing/RecomputePriceListenerTest.php --stop-on-failure` exits 0 (regression)
    - `vendor/bin/pest tests/Feature/Pricing/RecomputePriceListenerZeroPriceTest.php --stop-on-failure` exits 0 (regression)
    - Combined test count >= 25 passing (12 PriceRecomputer + 8 listener + 5 listener-zero)
  </acceptance_criteria>
  <done>
    PriceRecomputer is the single source of truth for the "given a SKU, recompute its price" behaviour. Listener is a thin adapter. Dry-run mode preserved at the core level. Plan 02 tests still green.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: RecomputePriceJob + PricingRecomputeCommand + dry-run/live tests</name>
  <files>
    app/Domain/Pricing/Jobs/RecomputePriceJob.php,
    app/Domain/Pricing/Console/Commands/PricingRecomputeCommand.php,
    app/Providers/AppServiceProvider.php,
    tests/Feature/Pricing/RecomputePriceJobTest.php,
    tests/Feature/Pricing/PricingRecomputeCommandDryRunTest.php,
    tests/Feature/Pricing/PricingRecomputeCommandLiveTest.php
  </files>
  <read_first>
    .planning/phases/03-pricing-engine/03-CONTEXT.md,
    app/Domain/Pricing/Services/PriceRecomputer.php,
    app/Console/Commands/BaseCommand.php,
    app/Domain/Sync/Console/Commands/SyncSupplierCommand.php,
    app/Domain/Sync/Jobs/SyncChunkJob.php,
    config/horizon.php,
    app/Providers/AppServiceProvider.php
  </read_first>
  <behavior>
    Job tests:
    - Test J1 (ShouldQueue + ShouldBeUnique): reflection shows both interfaces.
    - Test J2 (sync-bulk queue): `(new RecomputePriceJob(1, null, 'SKU', 'uuid', true))->queue === 'sync-bulk'`.
    - Test J3 (uniqueFor bounded): job's uniqueFor property is 300.
    - Test J4 (uniqueId shape): uniqueId() returns 'recompute-price:1:parent' for null variant, 'recompute-price:1:5' for variant id 5.
    - Test J5 (handle delegates to PriceRecomputer): mock PriceRecomputer, dispatch job synchronously, verify recompute() called once with the 5 constructor args.
    - Test J6 (failure → Horizon retries): tries=3 property set.

    Command dry-run tests:
    - Test D1 (default is dry-run): seed 3 products with non-matching sell_price, `php artisan pricing:recompute --all` (no --live) exits 0, OUTPUT contains "DRY-RUN", products.sell_price UNCHANGED in DB, Event::fake asserts ProductPriceChanged NOT dispatched.
    - Test D2 (--dry-run explicit also works): same effect as D1.
    - Test D3 (--only scopes): `pricing:recompute --only=SKU-001,SKU-002` dispatches exactly 2 jobs, not the whole catalogue.
    - Test D4 (report summary): output contains lines matching `/processed:\\s+\\d+/`, `/changed:\\s+\\d+/`, `/unchanged:\\s+\\d+/`, `/skipped_zero_price:\\s+\\d+/`, `/failed:\\s+\\d+/`.
    - Test D5 (--brand scope): filter products by brand_id, dispatch only those jobs.
    - Test D6 (--live + --dry-run error): exit code non-zero, stderr contains "mutually exclusive".
    - Test D7 (no scope flags at all): exit code non-zero, stderr contains "--all required" (or similar).

    Command live tests:
    - Test L1 (--live writes): seed same 3 products, `pricing:recompute --all --live`, Bus::fake intercepts Bus::batch, assert 3 RecomputePriceJob instances dispatched with persist=true.
    - Test L2 (--live --only=SKU-001 dispatches one job with persist=true): assert job's persist flag.
    - Test L3 (--live uses sync-bulk queue): Bus::fake assertDispatched(fn $job => $job->queue === 'sync-bulk').
    - Test L4 (--live report includes "LIVE" banner): stdout contains "LIVE" AND a warning line about the WOO_WRITE_ENABLED gate still being required for downstream Woo push.
  </behavior>
  <action>
    Step 1 — RED: author tests/Feature/Pricing/RecomputePriceJobTest.php with 6 tests above.

    Step 2 — RED: author tests/Feature/Pricing/PricingRecomputeCommandDryRunTest.php with 7 tests. Use `Artisan::call('pricing:recompute', [...])` or Symfony's CommandTester. Use `Bus::fake()` in most tests to intercept job dispatch without actually running the queue. In tests that do run the core (to verify DB state stays untouched under dry-run), skip Bus::fake and let jobs run synchronously via `sync` driver.

    Step 3 — RED: author tests/Feature/Pricing/PricingRecomputeCommandLiveTest.php with 4 tests. Use `Bus::fake()` for job-dispatch assertions.

    Step 4 — run: `vendor/bin/pest tests/Feature/Pricing/RecomputePriceJobTest.php tests/Feature/Pricing/PricingRecomputeCommandDryRunTest.php tests/Feature/Pricing/PricingRecomputeCommandLiveTest.php --stop-on-failure` — MUST FAIL (job + command don't exist).

    Step 5 — GREEN: author `app/Domain/Pricing/Jobs/RecomputePriceJob.php` per <interfaces>. Key points:
    - `implements ShouldQueue, ShouldBeUnique`
    - `$tries = 3`, `$timeout = 120`, `$uniqueFor = 300`
    - Constructor calls `$this->onQueue('sync-bulk')`
    - `uniqueId()` returns `"recompute-price:{$this->wooProductId}:"` appended with variationId or 'parent'
    - `handle(PriceRecomputer $recomputer): void` calls `$recomputer->recompute(...)` with the stored constructor args

    Step 6 — GREEN: author `app/Domain/Pricing/Console/Commands/PricingRecomputeCommand.php`. Extend `BaseCommand` (Phase 1 pattern — auto-threads correlation_id, uses `perform()` not `handle()`):
    ```php
    namespace App\Domain\Pricing\Console\Commands;

    use App\Console\Commands\BaseCommand;
    use App\Domain\Pricing\Jobs\RecomputePriceJob;
    use App\Domain\Products\Models\Product;
    use Illuminate\Support\Facades\Bus;
    use Illuminate\Support\Facades\Context;

    final class PricingRecomputeCommand extends BaseCommand
    {
        protected $signature = 'pricing:recompute
                                {--all : Recompute the full catalogue}
                                {--only= : CSV list of SKUs to recompute (scope filter)}
                                {--brand= : Limit to a single brand_id (scope filter)}
                                {--category= : Limit to a single category_id (scope filter)}
                                {--live : Persist writes + emit ProductPriceChanged (default is DRY-RUN per D-12)}
                                {--dry-run : Explicit dry-run flag — default behaviour but documented}';

        protected $description = 'Recompute final prices for a scope of products (default: dry-run across --all).';

        protected function perform(): int
        {
            if ($this->option('live') && $this->option('dry-run')) {
                $this->error('--live and --dry-run are mutually exclusive.');
                return self::INVALID;
            }

            $scopes = array_filter([
                'only' => $this->option('only'),
                'brand' => $this->option('brand'),
                'category' => $this->option('category'),
                'all' => $this->option('all'),
            ]);
            if (count($scopes) === 0) {
                $this->error('One of --all, --only, --brand, --category is required.');
                return self::INVALID;
            }
            // --only / --brand / --category are mutually exclusive (simplification for v1)
            $scopeFlags = ['only', 'brand', 'category'];
            $activeScopes = array_intersect(array_keys($scopes), $scopeFlags);
            if (count($activeScopes) > 1) {
                $this->error('--only, --brand, --category are mutually exclusive scopes.');
                return self::INVALID;
            }

            $persist = $this->option('live');
            $mode = $persist ? 'LIVE' : 'DRY-RUN';
            $this->info("Pricing recompute starting — mode: {$mode}");
            if ($persist) {
                $this->warn('Live mode will write products.sell_price and emit ProductPriceChanged. The Woo push still requires WOO_WRITE_ENABLED=true.');
            }

            $query = Product::query()->whereNotNull('sku');
            if ($only = $this->option('only')) {
                $skus = array_map('trim', explode(',', (string) $only));
                $query->whereIn('sku', $skus);
            }
            if ($brand = $this->option('brand')) {
                $query->where('brand_id', (int) $brand);
            }
            if ($category = $this->option('category')) {
                $query->where('category_id', (int) $category);
            }

            $correlationId = (string) Context::get('correlation_id');
            $jobs = [];

            $query->chunkById(500, function ($chunk) use (&$jobs, $correlationId, $persist) {
                foreach ($chunk as $product) {
                    $jobs[] = new RecomputePriceJob(
                        wooProductId: (int) $product->woo_product_id,
                        wooVariationId: null,
                        sku: (string) $product->sku,
                        correlationId: $correlationId,
                        persist: $persist,
                    );
                    // Variants:
                    foreach ($product->variants as $v) {
                        $jobs[] = new RecomputePriceJob(
                            wooProductId: (int) $product->woo_product_id,
                            wooVariationId: (int) $v->woo_variation_id,
                            sku: (string) $v->sku,
                            correlationId: $correlationId,
                            persist: $persist,
                        );
                    }
                }
            });

            $total = count($jobs);
            $this->info("Dispatching {$total} jobs onto sync-bulk queue…");

            if ($total === 0) {
                $this->warn('No products matched scope; nothing to dispatch.');
                return self::SUCCESS;
            }

            $batch = Bus::batch($jobs)
                ->name('pricing:recompute ' . ($persist ? 'live' : 'dry-run'))
                ->onQueue('sync-bulk')
                ->allowFailures()
                ->dispatch();

            $this->info("Batch dispatched — id={$batch->id}. Track progress in Horizon.");
            $this->line("  processed: {$total}");
            $this->line("  mode: {$mode}");
            $this->line("  See Horizon /horizon for real-time progress.");
            return self::SUCCESS;
        }
    }
    ```

    Step 7 — register the command. Phase 2 Plan 03 pattern: commands registered via a service provider's `commands()` method inside `runningInConsole()` guard. Check how `SyncSupplierCommand` is registered (likely `app/Domain/Sync/SyncServiceProvider.php` or `AppServiceProvider::boot()`). Mirror that pattern for `PricingRecomputeCommand`:
    - If a pattern ServiceProvider exists per-domain, create `app/Domain/Pricing/PricingServiceProvider.php` + register in `config/app.php` providers array + `Artisan::starting` alternative is the `commands()` method via `if ($this->app->runningInConsole()) { $this->commands([PricingRecomputeCommand::class]); }`.
    - If Phase 2 centralised commands in AppServiceProvider, add `PricingRecomputeCommand::class` to the same list.
    - IMPORTANT: Check Phase 2 actual registration before deciding. If `SyncSupplierCommand` is auto-discovered (Laravel 12 commands folder) then just placing the command under `app/Domain/Pricing/Console/Commands/` is enough. Phase 2 Plan 03 summary suggests explicit registration via `runningInConsole()` guard — follow whichever is the current pattern.

    Step 8 — run all 3 new test files. All MUST pass.

    Step 9 — manual probe:
    ```
    php artisan pricing:recompute --help
    ```
    Output MUST contain `--live`, `--dry-run`, `--only`, `--brand`, `--category`, `--all` options.
    ```
    php artisan pricing:recompute                       # → "One of --all required" error
    php artisan pricing:recompute --all                 # → dispatches dry-run batch
    php artisan pricing:recompute --live --dry-run      # → "mutually exclusive" error
    ```

    **DO NOT:**
    - Do NOT dispatch jobs on the `default` queue (that's for the event-driven listener — one-SKU-at-a-time). Bulk goes on `sync-bulk` per Phase 1 D-09 + Pitfall 8.
    - Do NOT skip `ShouldBeUnique` — without it, a stuck first batch + a manual re-run can double-emit ProductPriceChanged.
    - Do NOT default to --live. D-12 mandates dry-run by default. Phase 2 D-04 set the precedent; Phase 3 follows.
    - Do NOT emit ProductPriceChanged from the command directly — the job does it (via PriceRecomputer, via the persist flag).
    - Do NOT extend Laravel's base `Command` class — use `BaseCommand` per Phase 1 (correlation_id threading is mandatory).
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Pricing/RecomputePriceJobTest.php tests/Feature/Pricing/PricingRecomputeCommandDryRunTest.php tests/Feature/Pricing/PricingRecomputeCommandLiveTest.php --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `test -f app/Domain/Pricing/Jobs/RecomputePriceJob.php` returns 0
    - `test -f app/Domain/Pricing/Console/Commands/PricingRecomputeCommand.php` returns 0
    - `grep -q "implements ShouldQueue, ShouldBeUnique" app/Domain/Pricing/Jobs/RecomputePriceJob.php`
    - `grep -q "sync-bulk" app/Domain/Pricing/Jobs/RecomputePriceJob.php`
    - `grep -q "public int \$tries = 3" app/Domain/Pricing/Jobs/RecomputePriceJob.php`
    - `grep -q "uniqueFor = 300" app/Domain/Pricing/Jobs/RecomputePriceJob.php`
    - `grep -q "uniqueId" app/Domain/Pricing/Jobs/RecomputePriceJob.php`
    - `grep -q "extends BaseCommand" app/Domain/Pricing/Console/Commands/PricingRecomputeCommand.php`
    - `grep -q "pricing:recompute" app/Domain/Pricing/Console/Commands/PricingRecomputeCommand.php`
    - `grep -q "mutually exclusive" app/Domain/Pricing/Console/Commands/PricingRecomputeCommand.php`
    - `grep -q "perform" app/Domain/Pricing/Console/Commands/PricingRecomputeCommand.php`
    - `php artisan pricing:recompute --help 2>&1 | grep -q "\\-\\-live"` (option documented)
    - `php artisan pricing:recompute --help 2>&1 | grep -q "\\-\\-dry-run"` (option documented)
    - `php artisan pricing:recompute --live --dry-run 2>&1 | grep -qi "mutually exclusive"` (validation works)
    - `php artisan list 2>&1 | grep -q "pricing:recompute"` (command registered)
    - `vendor/bin/pest tests/Feature/Pricing/RecomputePriceJobTest.php --stop-on-failure` exits 0
    - `vendor/bin/pest tests/Feature/Pricing/PricingRecomputeCommandDryRunTest.php --stop-on-failure` exits 0
    - `vendor/bin/pest tests/Feature/Pricing/PricingRecomputeCommandLiveTest.php --stop-on-failure` exits 0
    - Combined test count >= 17 passing (6 job + 7 dry-run + 4 live)
  </acceptance_criteria>
  <done>
    Ops runs `pricing:recompute --all` for a safe dry-run projection; `--live` opts into writes. Jobs dispatch onto sync-bulk queue (isolated from webhook handlers) with ShouldBeUnique protection. Command validates mutually-exclusive flags. BaseCommand threads correlation_id through every dispatched job.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Operator CLI → command → queued batch | Local shell; operator has permission to run any artisan command. Entry trust = operator identity on host. |
| Queued job → PriceRecomputer core | In-process queue worker; same trust as the command. correlation_id carried through. |
| PriceRecomputer → DB writes / ProductPriceChanged | Only when persist=true; Woo push further gated by WOO_WRITE_ENABLED (Phase 1 D-08). |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-03-04-01 (maps to T7 bulk DoS) | D (Denial of Service) | pricing:recompute --all --live on 15k SKUs saturates sync-woo-push | mitigate | Batch dispatches onto sync-bulk queue (Phase 1 D-09 segregation + Pitfall 8). sync-woo-push handles ONLY the downstream Woo PUT triggered by ProductPriceChanged. RecomputePriceJob uses ShouldBeUnique + uniqueFor=300 so two concurrent batches cannot race the same SKU. Covered by Task 2 Tests J2, J3, L3. |
| T-03-04-02 (maps to T3 zero-price leak in bulk) | T (Tampering) | Bulk run over a catalogue where many products have buy_price=0 | mitigate | PriceRecomputer (from Task 1) writes ImportIssue + skips sell_price in BOTH persist=true and persist=false. Bulk run never writes £0 to sell_price. Covered by Task 1 Tests 5, 6. Phase 2 Import Issues page exposes the count for operator triage. |
| T-03-04-03 | T (Tampering) | --live flag typed accidentally on production | mitigate | Dry-run IS the default (D-12). --live is explicit. --live + --dry-run together = command error (Task 2 Test D6). Command output banner says "LIVE" and warns about WOO_WRITE_ENABLED — belt-and-braces human readable signal. |
| T-03-04-04 | R (Repudiation) | Who ran recompute when? | mitigate | BaseCommand stamps correlation_id on Context + passes to every dispatched job; ProductPriceChanged events carry same CID; audit_log rows (if admin-edit-adjacent) get the same CID; integration_events rows from downstream Woo PUT get the same CID. Full chain joinable on CID. Existing Phase 1 Auditor behaviour. |
| T-03-04-05 | I (Information Disclosure) | Command output leaks SKUs / prices to stdout | accept | Output is internal; operator is authenticated to the host. No PII. |
| T-03-04-06 | E (Elevation of Privilege) | A non-admin user runs artisan | accept | CLI access requires SSH to the VPS; that's an operator credential concern, not Phase 3's. Database-backed Filament RBAC is the user-facing gate; command-line is for ops. |
| T-03-04-07 | D (Denial of Service) | RecomputePriceJob gets stuck (infinite loop in PriceRecomputer) | mitigate | Job timeout=120s; tries=3; Horizon supervisor auto-recycles. ThrottledFailedJobNotifier (Phase 1) alerts on permanent failure (5-min dedup). |
| T-03-04-08 | T (Tampering) | Bulk run masks a legitimate pricing issue by overwriting a manual admin tweak | mitigate | ProductOverride is the supported escape hatch (D-08) — resolver checks override first, so admin-pinned margins survive bulk recompute. If admin tweaks products.sell_price directly (bypassing override), bulk recompute WILL overwrite it on next run — documented behaviour per PROJECT.md "Manual overrides in Woo admin" row in Out of Scope. |
</threat_model>

<verification>
- `vendor/bin/pest tests/Feature/Pricing/PriceRecomputerTest.php tests/Feature/Pricing/RecomputePriceJobTest.php tests/Feature/Pricing/PricingRecomputeCommandDryRunTest.php tests/Feature/Pricing/PricingRecomputeCommandLiveTest.php --stop-on-failure` — all Plan 04 tests
- `vendor/bin/pest tests/Feature/Pricing/RecomputePriceListenerTest.php tests/Feature/Pricing/RecomputePriceListenerZeroPriceTest.php --stop-on-failure` — Plan 02 regression (refactor preserved behaviour)
- `php artisan pricing:recompute --help` lists --live, --dry-run, --only, --brand, --category, --all
- `php artisan pricing:recompute --live --dry-run` errors with "mutually exclusive"
- `php artisan pricing:recompute` errors with "One of --all / --only / --brand / --category required"
- `php artisan pricing:recompute --all` (no --live) dispatches a batch and reports "DRY-RUN"
</verification>

<success_criteria>
- PriceRecomputer is the single shared core; listener delegates to it; bulk job delegates to it
- `pricing:recompute` command exists, extends BaseCommand, defaults to dry-run
- `--live` + `--dry-run` together produces a clean error, not a noisy exception
- Jobs dispatch onto `sync-bulk` queue with ShouldBeUnique (uniqueFor=300)
- Dry-run writes nothing to sell_price, emits no ProductPriceChanged, logs no activity_log entries for sync-driven paths
- Zero/null buy_price path writes ImportIssue in BOTH modes (dry-run + live)
- Horizon shows the batch as it progresses
- All 40+ tests pass (25+ carried from Task 1 + 17 from Task 2)
</success_criteria>

<output>
Create `.planning/phases/03-pricing-engine/03-04-SUMMARY.md` covering:
- PriceRecomputer contract + persist flag semantics
- Listener-to-core refactor impact (listener file line count drop, Plan 02 test regression green)
- Job queue choice (sync-bulk) + ShouldBeUnique rationale (Pitfall 8 + D-09)
- Command flag matrix + validation errors
- dry-run default (D-12) explicitly documented for ops handover
- Pointer for Plan 05: add Deptrac Pricing layer ruleset + final phase VERIFICATION
- Any deviation from plan (command registration mechanism chosen)
</output>
