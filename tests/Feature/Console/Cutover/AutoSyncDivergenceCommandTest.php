<?php

declare(strict_types=1);

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Console\Input\InputOption;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260611-rl4 — cutover:auto-sync
|--------------------------------------------------------------------------
|
| 8 Pest cases A-H cover the auto-sync chain semantics:
|   A — Happy path (full chain)
|   B — --skip-scan
|   C — --dry-run
|   D — Phase 1 scan fails
|   E — Phase 2 push fails (re-scan skipped per planner correction)
|   F — --field=stock_quantity only
|   G — --max-products=10
|   H — Parity regression alarm
|
| Strategy:
| Replace the registered `cutover:divergence-scan` and
| `products:push-divergence-to-woo` commands with anonymous Command
| subclasses whose handle() (a) records invocations, (b) returns a
| canned exit code, and (c) optionally swaps the
| `dashboard_snapshots.sync_diffs_parity` row mid-run (to simulate the
| second scan computing a different parity_percent). Symfony's
| Application::add() replaces existing commands by name, so the
| auto-sync command's Artisan::call() routes hit the stubs.
*/

/**
 * Shared state for the recorder stubs.
 *
 * @var array{
 *   scan_calls:int,
 *   push_calls:array<int, array<string, mixed>>,
 *   scan_exits:array<int, int>,
 *   push_exits:array<int, int>,
 *   parity_swap_on_call:?array{call_index:int, parity:int},
 * }
 */
function recorderState(): array
{
    return [
        'scan_calls' => 0,
        'push_calls' => [],
        'scan_exits' => [],
        'push_exits' => [],
        'parity_swap_on_call' => null,
    ];
}

/**
 * Seed the sync_diffs_parity snapshot row that DivergenceScanner would write.
 */
function seedParitySnapshot(int $parityPercent): void
{
    DashboardSnapshot::upsertByKey('sync_diffs_parity', [
        'parity_percent' => $parityPercent,
        'diverged_rows' => 0,
        'total_products' => 100,
        'threshold_percent' => 99,
        'traffic_light' => $parityPercent >= 99 ? 'green' : 'red',
        'window_days' => 7,
        'source' => 'cutover:divergence-scan',
        'correlation_id' => 'test-cid',
    ]);
}

/**
 * Register the two stub commands that replace cutover:divergence-scan +
 * products:push-divergence-to-woo for THIS test. Returns a state-bag the
 * test can read back to make assertions.
 *
 * @param  array<int, int>  $scanExits  ordered exit codes for successive scan calls
 * @param  array<int, int>  $pushExits  ordered exit codes for successive push calls
 * @param  ?array{call_index:int, parity:int}  $paritySwap  swap the snapshot before
 *         returning from the Nth scan call (1-indexed) so phase 3 reads a different
 *         parity than phase 1
 * @return object  state holder with public scanCalls/pushCalls/pushArgs counters
 */
function registerChainStubs(
    array $scanExits = [0, 0],
    array $pushExits = [0],
    ?array $paritySwap = null,
): object {
    $state = new class
    {
        public int $scanCalls = 0;

        public int $pushCalls = 0;

        /** @var array<int, array<string, mixed>> */
        public array $pushArgs = [];

        /** @var array<int, int> */
        public array $scanExits = [];

        /** @var array<int, int> */
        public array $pushExits = [];

        public ?array $paritySwap = null;
    };
    $state->scanExits = $scanExits;
    $state->pushExits = $pushExits;
    $state->paritySwap = $paritySwap;

    $artisan = app(\Illuminate\Contracts\Console\Kernel::class);
    // Booting the kernel materialises the Symfony Application + registered
    // commands; required before we can swap entries by name.
    $artisan->call('list', ['--raw' => true]);

    // Pierce Kernel::getArtisan() visibility via Reflection — the protected
    // method is the only documented way to reach the Symfony Console
    // Application instance, and Application::add() is what we need to
    // OVERRIDE the AppServiceProvider-registered commands by name.
    $getArtisan = new ReflectionMethod($artisan, 'getArtisan');
    $getArtisan->setAccessible(true);
    /** @var \Symfony\Component\Console\Application $consoleApp */
    $consoleApp = $getArtisan->invoke($artisan);

    $scanStub = new class($state) extends Command
    {
        protected $name = 'cutover:divergence-scan';

        protected $description = 'STUB — cutover:divergence-scan (auto-sync test)';

        public function __construct(public object $state)
        {
            parent::__construct();
            $this->getDefinition()->addOption(new InputOption('live', null, InputOption::VALUE_NONE));
        }

        public function handle(): int
        {
            $this->state->scanCalls++;
            $callIndex = $this->state->scanCalls;
            $swap = $this->state->paritySwap;
            if ($swap !== null && $swap['call_index'] === $callIndex) {
                seedParitySnapshot($swap['parity']);
            }

            return $this->state->scanExits[$callIndex - 1] ?? 0;
        }
    };

    $pushStub = new class($state) extends Command
    {
        protected $name = 'products:push-divergence-to-woo';

        protected $description = 'STUB — products:push-divergence-to-woo (auto-sync test)';

        public function __construct(public object $state)
        {
            parent::__construct();
            // Mirror the real signature so option parsing matches.
            $this->getDefinition()->addOption(new InputOption('field', null, InputOption::VALUE_REQUIRED));
            $this->getDefinition()->addOption(new InputOption('limit', null, InputOption::VALUE_REQUIRED));
            $this->getDefinition()->addOption(new InputOption('chunk', null, InputOption::VALUE_REQUIRED));
            $this->getDefinition()->addOption(new InputOption('dry-run', null, InputOption::VALUE_NONE));
            $this->getDefinition()->addOption(new InputOption('correlation-id', null, InputOption::VALUE_REQUIRED));
            $this->getDefinition()->addOption(new InputOption('no-confirm', null, InputOption::VALUE_NONE));
        }

        public function handle(): int
        {
            $this->state->pushCalls++;
            $callIndex = $this->state->pushCalls;
            $this->state->pushArgs[] = [
                'field' => $this->option('field'),
                'limit' => $this->option('limit'),
                'dry-run' => (bool) $this->option('dry-run'),
                'no-confirm' => (bool) $this->option('no-confirm'),
            ];

            return $this->state->pushExits[$callIndex - 1] ?? 0;
        }
    };

    // Inject Laravel container so the closure-bound stubs can resolve.
    $scanStub->setLaravel(app());
    $pushStub->setLaravel(app());

    // Symfony's Console\Application::add() replaces by name — overrides the
    // class-registered entries from AppServiceProvider.
    $consoleApp->add($scanStub);
    $consoleApp->add($pushStub);

    return $state;
}

it('Case A: happy path — scan, push, rescan run; parity 80→95; exit 0; no regression event', function (): void {
    seedParitySnapshot(80);
    $state = registerChainStubs(
        scanExits: [0, 0],
        pushExits: [0],
        paritySwap: ['call_index' => 2, 'parity' => 95],
    );

    $exit = Artisan::call('cutover:auto-sync');

    expect($exit)->toBe(0);
    expect($state->scanCalls)->toBe(2);
    expect($state->pushCalls)->toBe(1);

    $completed = Activity::query()
        ->where('log_name', 'system')
        ->where('description', 'cutover.auto_sync_completed')
        ->first();
    expect($completed)->not->toBeNull();
    expect($completed->properties['parity_before'] ?? null)->toBe(80);
    expect($completed->properties['parity_after'] ?? null)->toBe(95);

    $regression = Activity::query()
        ->where('log_name', 'system')
        ->where('description', 'cutover.auto_sync_parity_regression')
        ->first();
    expect($regression)->toBeNull();
});

it('Case B: --skip-scan reuses latest sync_diffs; only push + rescan invoked', function (): void {
    seedParitySnapshot(90);
    $state = registerChainStubs(
        scanExits: [0], // only ONE scan call expected (the rescan)
        pushExits: [0],
    );

    $exit = Artisan::call('cutover:auto-sync', ['--skip-scan' => true]);

    expect($exit)->toBe(0);
    expect($state->scanCalls)->toBe(1); // rescan only
    expect($state->pushCalls)->toBe(1);
});

it('Case C: --dry-run runs scan live, push dry-run, rescan SKIPPED', function (): void {
    seedParitySnapshot(85);
    $state = registerChainStubs(
        scanExits: [0],
        pushExits: [0],
    );

    $exit = Artisan::call('cutover:auto-sync', ['--dry-run' => true]);

    expect($exit)->toBe(0);
    expect($state->scanCalls)->toBe(1); // phase 1 only — phase 3 SKIPPED
    expect($state->pushCalls)->toBe(1);
    expect($state->pushArgs[0]['dry-run'])->toBe(true);

    // Parity-regression check is SKIPPED in dry-run even if hypothetical drop.
    $regression = Activity::query()
        ->where('log_name', 'system')
        ->where('description', 'cutover.auto_sync_parity_regression')
        ->first();
    expect($regression)->toBeNull();
});

it('Case D: phase 1 scan fails — push + rescan SKIPPED, audit log with phase=scan, exit 1', function (): void {
    seedParitySnapshot(70);
    $state = registerChainStubs(
        scanExits: [1], // first scan returns 1
        pushExits: [0],
    );

    $exit = Artisan::call('cutover:auto-sync');

    expect($exit)->toBe(1);
    expect($state->scanCalls)->toBe(1); // only the failing scan
    expect($state->pushCalls)->toBe(0);

    $failed = Activity::query()
        ->where('log_name', 'system')
        ->where('description', 'cutover.auto_sync_failed')
        ->first();
    expect($failed)->not->toBeNull();
    expect($failed->properties['phase'] ?? null)->toBe('scan');
    expect($failed->properties['scan_exit'] ?? null)->toBe(1);
});

it('Case E: phase 2 push fails — rescan SKIPPED (planner fail-fast), audit log phase=push, exit 1', function (): void {
    seedParitySnapshot(70);
    $state = registerChainStubs(
        scanExits: [0, 0],
        pushExits: [1], // push returns 1
    );

    $exit = Artisan::call('cutover:auto-sync');

    expect($exit)->toBe(1);
    expect($state->scanCalls)->toBe(1); // phase 1 ran, phase 3 SKIPPED
    expect($state->pushCalls)->toBe(1);

    $failed = Activity::query()
        ->where('log_name', 'system')
        ->where('description', 'cutover.auto_sync_failed')
        ->first();
    expect($failed)->not->toBeNull();
    expect($failed->properties['phase'] ?? null)->toBe('push');
    expect($failed->properties['parity_before'] ?? null)->toBe(70);
    expect($failed->properties['push_exit'] ?? null)->toBe(1);
});

it('Case F: --field=stock_quantity routes single-field csv to push command', function (): void {
    seedParitySnapshot(80);
    $state = registerChainStubs(
        scanExits: [0, 0],
        pushExits: [0],
        paritySwap: ['call_index' => 2, 'parity' => 95],
    );

    $exit = Artisan::call('cutover:auto-sync', ['--field' => 'stock_quantity']);

    expect($exit)->toBe(0);
    expect($state->pushCalls)->toBe(1);
    expect($state->pushArgs[0]['field'])->toBe('stock_quantity');
});

it('Case G: --max-products=10 propagates to push command --limit', function (): void {
    seedParitySnapshot(80);
    $state = registerChainStubs(
        scanExits: [0, 0],
        pushExits: [0],
        paritySwap: ['call_index' => 2, 'parity' => 95],
    );

    $exit = Artisan::call('cutover:auto-sync', ['--max-products' => 10]);

    expect($exit)->toBe(0);
    expect($state->pushCalls)->toBe(1);
    expect((int) $state->pushArgs[0]['limit'])->toBe(10);
});

it('Case H: parity regression — parity_after < parity_before → audit log + exit 1', function (): void {
    seedParitySnapshot(95); // parity_before
    $state = registerChainStubs(
        scanExits: [0, 0],
        pushExits: [0],
        paritySwap: ['call_index' => 2, 'parity' => 80], // parity_after DROPS
    );

    $exit = Artisan::call('cutover:auto-sync');

    expect($exit)->toBe(1); // alarm exit code
    expect($state->scanCalls)->toBe(2);
    expect($state->pushCalls)->toBe(1);

    $regression = Activity::query()
        ->where('log_name', 'system')
        ->where('description', 'cutover.auto_sync_parity_regression')
        ->first();
    expect($regression)->not->toBeNull();
    expect($regression->properties['parity_before'] ?? null)->toBe(95);
    expect($regression->properties['parity_after'] ?? null)->toBe(80);
    expect($regression->properties['delta'] ?? null)->toBe(-15);
});
