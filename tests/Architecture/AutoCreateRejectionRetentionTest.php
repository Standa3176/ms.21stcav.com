<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Models\AutoCreateRejection;
use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Architecture: Phase 6 Plan 06 — auto_create_rejections indefinite retention
|--------------------------------------------------------------------------
|
| Plan 06-06 Task 1. Permanent regression guard for CONTEXT D-06:
|
|   Rejection history is retention-indefinite, surviving even the Phase 1 D-04
|   365-day audit_log cap. Rejection rows feed future auto-skip-rule
|   suggestion engines (CONTEXT D-06): "Ops rejected 12 Brand X products with
|   reason `spare_part_or_accessory` in the last 90d — suggest adding an
|   auto-skip rule." Removing old rows destroys the analytic signal.
|
| Mirrors Phase 5 Plan 05-05's CompetitorPricesNeverPrunedTest shape:
|
|   1. DYNAMIC: seed a 5-year-old AutoCreateRejection row → run EVERY
|      discoverable prune command in Artisan::all() with signatures matching
|      `*prune*` → assert the rejection row survives. Resilient to future
|      prune commands being added.
|
|   2. STATIC-SCAN: grep every Command.php file under app/Console/Commands
|      and app/Domain (recursively) for any DELETE/TRUNCATE pattern
|      targeting the `auto_create_rejections` table. Catches a future
|      contributor adding a stealth prune that the dynamic test wouldn't
|      know to discover.
|
| ANY future phase that introduces an auto_create_rejections prune MUST
| either (a) update this test with proof D-06 is preserved under the new
| constraint or (b) raise a REQUIREMENTS.md revision for a new product
| decision. This is the permanent boundary.
*/

it('auto_create_rejections rows with created_at=5yrs ago survive ALL prune commands', function (): void {
    // AutoCreateRejection is append-only ($timestamps = false) — only created_at
    // exists. Factory seeds via Product::factory() so the FK resolves. Force the
    // created_at back 5 years via a follow-up UPDATE because the model doesn't
    // touch timestamps on insert (column default is DB `useCurrent`, not
    // Eloquent-driven).
    $rejection = AutoCreateRejection::factory()
        ->for(Product::factory(), 'product')
        ->create([
            'reason' => 'spare_part_or_accessory',
            'notes' => 'Plan 06-06 retention regression seed',
        ]);

    AutoCreateRejection::query()
        ->where('id', $rejection->id)
        ->update(['created_at' => now()->subYears(5)]);

    expect(AutoCreateRejection::count())->toBe(1);

    // Dynamically discover every registered prune command — resilient to new
    // prunes added in later phases. Phase 5 Plan 05-05 CompetitorPricesNeverPruned
    // precedent: use `--days=1` where supported so any command that happens to
    // target our table would nuke the row at the most aggressive retention.
    $pruneCommands = collect(Artisan::all())
        ->keys()
        ->filter(fn (string $name): bool => str_contains(strtolower($name), 'prune'))
        ->values();

    expect($pruneCommands)->not->toBeEmpty(
        'Test pre-condition violated: no prune commands discovered via Artisan::all(). '.
        'At minimum Phase 1 + Phase 5 should register activitylog:prune / '.
        'integration-events:prune / sync-errors:prune / competitor:csv-prune.'
    );

    foreach ($pruneCommands as $signature) {
        // Some prunes accept --days (Phase 1/5 pattern), some do not (Phase 1
        // sync-diffs:prune). Try the aggressive --days=1 first; on signature
        // rejection fall back to a bare invocation.
        try {
            Artisan::call($signature, ['--days' => 1]);
        } catch (\InvalidArgumentException) {
            Artisan::call($signature);
        }
    }

    // D-06 mandate: the rejection row survives every prune.
    expect(AutoCreateRejection::find($rejection->id))->not->toBeNull(
        'D-06 VIOLATION — a retention command pruned auto_create_rejections. '.
        'Rejection history is documented as indefinite-retention per Phase 6 '.
        'CONTEXT D-06 (feeds future auto-skip-rule suggestion analytics).'
    );
});

it('no Command class writes a DELETE or TRUNCATE targeting auto_create_rejections', function (): void {
    // Static-scan guardrail: grep every *Command.php file under app/Console
    // and app/Domain for any SQL / Eloquent statement that would remove rows
    // from the auto_create_rejections table. This prevents a future contributor
    // from adding a stealth prune that the dynamic test wouldn't catch.
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
            // Classic delete shapes to guard against — Eloquent + DB facade +
            // raw truncate. Pair the needle with a ->delete()/truncate flag to
            // reduce false positives on READ-only references (unlikely, but
            // belt-and-braces with the Phase 5 COMP-07 test pattern).
            $redFlags = [
                'AutoCreateRejection::query()',
                'AutoCreateRejection::where',
                "DB::table('auto_create_rejections')",
                'DB::table("auto_create_rejections")',
                "truncate('auto_create_rejections')",
                'truncate("auto_create_rejections")',
            ];
            foreach ($redFlags as $needle) {
                if (
                    str_contains($contents, $needle) &&
                    (str_contains($contents, '->delete()') || str_contains($contents, 'truncate'))
                ) {
                    $offenders[] = sprintf('%s contains pattern `%s` + delete/truncate', $path, $needle);
                }
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'D-06 VIOLATION — a Command file contains a delete/truncate against auto_create_rejections: '
            .implode('; ', $offenders)
    );
});
