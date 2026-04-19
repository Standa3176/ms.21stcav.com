<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Competitor\Models\CsvParseError;

/**
 * Phase 5 Plan 05 Task 1 — COMP-07 permanent regression guard.
 *
 * competitor_prices rows are the immutable history — the entire Phase 5 value
 * proposition. ANY future prune/retention command that touches this table MUST
 * update this test + submit COMP-07 revision for a new product decision.
 *
 * Mechanism: seed rows with `recorded_at` 5 years ago → run every discoverable
 * retention command in sequence → assert competitor_prices row count unchanged.
 *
 * competitor_ingest_runs rows are similarly kept forever (RESEARCH.md Open Q4
 * parity with sync_runs). csv_parse_errors rows are ALLOWED to prune under
 * Phase 1 D-05 integration_events 90d convention, but competitor:csv-prune
 * itself MUST NOT delete them.
 */
it('competitor_prices rows with recorded_at=5yrs ago survive ALL prune commands', function (): void {
    $competitor = Competitor::factory()->create(['slug' => 'prune-guard-co']);
    $run = CompetitorIngestRun::factory()->for($competitor)->create([
        'started_at' => now()->subYears(5),
    ]);

    // Force the factory's created_at/updated_at + recorded_at back 5 years to make
    // a retention-forgiving prune (e.g. --days=1) would-otherwise nuke them.
    // Distinct recorded_at values per row to respect (competitor_id, sku, recorded_at) unique.
    foreach ([5, 5 * 12, 5 * 365] as $i => $daysOffset) {
        CompetitorPrice::factory()->create([
            'competitor_id' => $competitor->id,
            'sku' => 'ANCIENT-001',
            'recorded_at' => now()->subYears(5)->subDays($i),
            'ingest_run_id' => $run->id,
        ]);
    }

    // Manually force created_at back too (LogsActivity sets created_at=now()).
    CompetitorPrice::query()->where('competitor_id', $competitor->id)->update([
        'created_at' => now()->subYears(5),
        'updated_at' => now()->subYears(5),
    ]);

    expect(CompetitorPrice::count())->toBe(3);
    expect(CompetitorIngestRun::count())->toBeGreaterThanOrEqual(1);

    // Run every retention command available. Use `--days=1` to force aggressive pruning
    // on anything they'd legitimately target; if a command accidentally targets
    // competitor_prices, rows will be GONE at --days=1.
    $commands = [
        'competitor:csv-prune --days=1',
        'activitylog:prune --days=1',
        'integration-events:prune --days=1',
        'sync-errors:prune --days=1',
    ];

    foreach ($commands as $signature) {
        $command = explode(' ', $signature)[0];
        if (! array_key_exists($command, \Illuminate\Support\Facades\Artisan::all())) {
            continue; // skip commands not installed in this environment
        }
        $this->artisan($signature);
    }

    // COMP-07 mandate: every competitor_prices row survives.
    expect(CompetitorPrice::count())->toBe(3, 'COMP-07 violation — a retention command pruned competitor_prices');
    expect(CompetitorIngestRun::where('id', $run->id)->exists())->toBeTrue(
        'competitor_ingest_runs retained (RESEARCH Open Q4)'
    );
});

it('no Command class writes a DELETE or TRUNCATE targeting competitor_prices', function (): void {
    // Static-scan guardrail: grep all Command .php files under app/ for any SQL / Eloquent
    // statement that would remove rows from the competitor_prices table. This prevents
    // a future contributor from adding a stealth prune that this suite doesn't catch.
    $commandDirs = [
        app_path('Console/Commands'),
        app_path('Domain'),
    ];

    $offenders = [];
    foreach ($commandDirs as $base) {
        if (! is_dir($base)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (! str_ends_with($path, 'Command.php')) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }
            // Three classic delete shapes to guard against.
            $redFlags = [
                "CompetitorPrice::query()",
                "CompetitorPrice::where",
                "DB::table('competitor_prices')",
                'DB::table("competitor_prices")',
                'truncate(\'competitor_prices\')',
                'truncate("competitor_prices")',
            ];
            foreach ($redFlags as $needle) {
                if (str_contains($contents, $needle) &&
                    (str_contains($contents, '->delete()') || str_contains($contents, 'truncate'))) {
                    $offenders[] = sprintf('%s contains pattern `%s` + delete/truncate', $path, $needle);
                }
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'COMP-07 VIOLATION — a Command file contains a delete/truncate against competitor_prices: '
            .implode('; ', $offenders)
    );
});
