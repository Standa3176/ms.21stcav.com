<?php

declare(strict_types=1);

use App\Domain\Cutover\Services\CutoverChecklistReporter;
use App\Domain\Dashboard\Models\DashboardSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 05 Task 3 — cutover:checklist (D-21)
|--------------------------------------------------------------------------
|
| Behaviour tests CL1..CL10:
|   CL1 — lists all cutover gates (≥13 rows)
|   CL2 — exit code 1 when any item is PENDING/FAIL
|   CL3 — exit code 0 when all items PASS
|   CL4 — --update-status sub-command writes state + subsequent run reflects
|   CL5 — parity gate reads dashboard_snapshots.sync_diffs_parity
|   CL6 — D-20 Gate 1 supplier-probe detects synthesised marker
|   CL7 — D-20 Gate 2 woo-sandbox defaults PENDING (MANUAL)
|   CL8 — D-20 Gate 3 feature-suite reads dashboard_snapshots.feature_suite_last_run
|   CL9 — output is markdown-formatted
|   CL10 — includes every D-19 runbook step
*/

beforeEach(function (): void {
    // Redirect checklist state + research + backup paths to per-test scratch
    // so tests don't pollute storage/app.
    $this->tmp = sys_get_temp_dir().'/cutover-checklist-test-'.uniqid();
    mkdir($this->tmp, 0o755, true);
    mkdir($this->tmp.'/cutover', 0o755, true);
    mkdir($this->tmp.'/research', 0o755, true);

    // Override the reporter's state file path via a subclass binding.
    $tmp = $this->tmp;
    app()->bind(CutoverChecklistReporter::class, function () use ($tmp): object {
        return new class($tmp) extends CutoverChecklistReporter
        {
            public function __construct(string $tmp)
            {
                parent::__construct();
                $this->stateFile = $tmp.'/cutover/checklist-state.json';
            }

            protected function checkSupplierProbe(): string
            {
                // Override to read from the per-test scratch path instead of
                // storage/app/research/supplier-probe.json.
                global $GLOBALS__cutover_checklist_tmp;
                $tmp = $this->stateFile
                    ? dirname(dirname($this->stateFile)).'/research'
                    : storage_path('app/research');
                $path = $tmp.'/supplier-probe.json';
                if (! file_exists($path)) {
                    return self::STATUS_PENDING;
                }
                $c = (string) file_get_contents($path);
                if (str_contains($c, '"__synthesized": true') || str_contains($c, '"__synthesized":true')) {
                    return self::STATUS_PENDING;
                }

                return self::STATUS_PASS;
            }
        };
    });

    config(['cutover.backup_path' => $this->tmp.'/backups']);
});

afterEach(function (): void {
    if (isset($this->tmp) && is_dir($this->tmp)) {
        // Recursive cleanup.
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($this->tmp);
    }
});

it('registers cutover:checklist in the artisan registry', function (): void {
    expect(array_keys(Artisan::all()))->toContain('cutover:checklist');
});

it('lists all cutover gates (≥13 rows) in markdown format (CL1 + CL9)', function (): void {
    $exit = Artisan::call('cutover:checklist');
    $output = Artisan::output();

    // Markdown header + separator row + ≥13 data rows = ≥15 lines with |
    $pipeRows = array_filter(explode("\n", $output), fn (string $l): bool => str_starts_with(trim($l), '|'));
    expect(count($pipeRows))->toBeGreaterThanOrEqual(15);

    // Markdown header verbatim
    expect($output)->toContain('| Item | Status | Action |');
});

it('exits 1 when any item is PENDING (baseline) — CL2', function (): void {
    $exit = Artisan::call('cutover:checklist');

    expect($exit)->toBe(1);
});

it('exits 0 when all items PASS via --update-status (CL3)', function (): void {
    $ids = [
        'supplier-probe', 'woo-sandbox', 'feature-suite',
        'woo-db-snapshot', 'divergence-scan', 'parity-threshold',
        'populate-overrides', 'drill-rollback-staging', 'legacy-plugins-disabled',
        'flag-flip', 'obsolete-statuses-pushed',
        'monitoring-7-days', 'weekly-digest-landed', 'handover-docs',
        'bitrix_quote_type_id_verified',
    ];
    foreach ($ids as $id) {
        Artisan::call('cutover:checklist', ['--update-status' => $id.':pass']);
    }

    $exit = Artisan::call('cutover:checklist');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('ALL CHECKS PASSED');
});

it('--update-status sub-command persists state + subsequent run reflects PASS (CL4)', function (): void {
    Artisan::call('cutover:checklist', ['--update-status' => 'supplier-probe:pass']);

    $output = Artisan::output();
    expect($output)->toContain('Updated supplier-probe to pass');

    // Subsequent run: supplier-probe should now read PASS.
    $reporter = app(CutoverChecklistReporter::class);
    $gates = collect($reporter->gates())->keyBy('id');
    expect($gates['supplier-probe']['status'])->toBe(CutoverChecklistReporter::STATUS_PASS);
});

it('parity-threshold gate reads dashboard_snapshots.sync_diffs_parity (CL5)', function (): void {
    DashboardSnapshot::create([
        'metric_key' => 'sync_diffs_parity',
        'metric_value_json' => ['parity_percent' => 99, 'diverged_rows' => 1, 'total_products' => 100],
        'computed_at' => now(),
    ]);

    $gates = collect(app(CutoverChecklistReporter::class)->gates())->keyBy('id');
    expect($gates['parity-threshold']['status'])->toBe(CutoverChecklistReporter::STATUS_PASS);

    // Now downgrade to 95 (below threshold) — expect FAIL
    DashboardSnapshot::where('metric_key', 'sync_diffs_parity')->update([
        'metric_value_json' => ['parity_percent' => 95],
        'computed_at' => now(),
    ]);

    $gates = collect(app(CutoverChecklistReporter::class)->gates())->keyBy('id');
    expect($gates['parity-threshold']['status'])->toBe(CutoverChecklistReporter::STATUS_FAIL);
});

it('feature-suite gate reads dashboard_snapshots.feature_suite_last_run (CL8)', function (): void {
    // Missing snapshot → PENDING
    $gates = collect(app(CutoverChecklistReporter::class)->gates())->keyBy('id');
    expect($gates['feature-suite']['status'])->toBe(CutoverChecklistReporter::STATUS_PENDING);

    // status=pass → PASS
    DashboardSnapshot::create([
        'metric_key' => 'feature_suite_last_run',
        'metric_value_json' => ['status' => 'pass', 'timestamp' => now()->toIso8601String()],
        'computed_at' => now(),
    ]);
    $gates = collect(app(CutoverChecklistReporter::class)->gates())->keyBy('id');
    expect($gates['feature-suite']['status'])->toBe(CutoverChecklistReporter::STATUS_PASS);

    // status=fail → FAIL
    DashboardSnapshot::where('metric_key', 'feature_suite_last_run')->update([
        'metric_value_json' => ['status' => 'fail'],
        'computed_at' => now(),
    ]);
    $gates = collect(app(CutoverChecklistReporter::class)->gates())->keyBy('id');
    expect($gates['feature-suite']['status'])->toBe(CutoverChecklistReporter::STATUS_FAIL);
});

it('woo-sandbox gate defaults PENDING requiring manual tick-off (CL7)', function (): void {
    $gates = collect(app(CutoverChecklistReporter::class)->gates())->keyBy('id');
    expect($gates['woo-sandbox']['status'])->toBe(CutoverChecklistReporter::STATUS_PENDING);

    // After --update-status=pass
    Artisan::call('cutover:checklist', ['--update-status' => 'woo-sandbox:pass']);
    $gates = collect(app(CutoverChecklistReporter::class)->gates())->keyBy('id');
    expect($gates['woo-sandbox']['status'])->toBe(CutoverChecklistReporter::STATUS_PASS);
});

it('includes every D-19 runbook step (CL10)', function (): void {
    Artisan::call('cutover:checklist');
    $output = Artisan::output();

    // Each action line mentions the relevant artisan command.
    foreach ([
        'cutover:snapshot-woo-db',
        'cutover:divergence-scan',
        'cutover:populate-overrides',
        'cutover:drill-rollback',
        'cutover:disable-legacy-plugins',
        'WOO_WRITE_ENABLED',
    ] as $needle) {
        expect($output)->toContain($needle);
    }
});

it('rejects malformed --update-status values', function (): void {
    $exit = Artisan::call('cutover:checklist', ['--update-status' => 'bogus-no-colon']);
    expect($exit)->toBe(1);

    $exit = Artisan::call('cutover:checklist', ['--update-status' => 'supplier-probe:invalid']);
    expect($exit)->toBe(1);
});
