<?php

declare(strict_types=1);

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 02 Task 1 — DashboardRefreshCommand
|--------------------------------------------------------------------------
|
| Covers:
|   - `dashboard:refresh` writes 9 rows (one per metric_key) to dashboard_snapshots
|   - Upsert semantics — second invocation keeps row count at 9
|   - Command registered in the artisan registry (AppServiceProvider wiring)
|   - snapshots:prune deletes rows older than retention cut-off
|   - snapshots:prune --days=0 is a no-op safety guard
*/

it('writes exactly fifteen dashboard_snapshots rows on first run', function (): void {
    expect(DashboardSnapshot::count())->toBe(0);

    Artisan::call('dashboard:refresh');

    // 9 (Phase 7) + 1 (Phase 09.1 integration_health) + 1 (260606-lhp suggestions_triage_health)
    // + 4 quick-task widgets (ad_candidates_health, category_audit_health, supplier_freshness,
    // stock_divergence) = 15.
    expect(DashboardSnapshot::count())->toBe(15);

    // Every expected metric_key is present.
    $keys = DashboardSnapshot::query()->pluck('metric_key')->sort()->values()->toArray();
    expect($keys)->toBe([
        'ad_candidates_health',
        'category_audit_health',
        'competitor_freshness',
        'crm_push_success_rate',
        'horizon_failed_jobs',
        'import_issues',
        'integration_health',
        'last_sync_run',
        'pending_reviews',
        'product_catalogue_health',
        'stock_divergence',
        'suggestions_triage_health',
        'supplier_freshness',
        'sync_diffs_parity',
        'weekly_report_status',
    ]);
});

it('upserts rather than inserts on a second run (idempotent)', function (): void {
    Artisan::call('dashboard:refresh');
    Artisan::call('dashboard:refresh');

    expect(DashboardSnapshot::count())->toBe(15);
});

it('registers dashboard:refresh and snapshots:prune in the artisan registry', function (): void {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('dashboard:refresh');
    expect($commands)->toContain('snapshots:prune');
});

it('prunes dashboard_snapshots older than the retention window', function (): void {
    // 40-day-old row — should be pruned at the default 30-day retention.
    DashboardSnapshot::create([
        'metric_key' => 'old_metric',
        'metric_value_json' => ['value' => 1],
        'computed_at' => now()->subDays(40),
    ]);

    // 10-day-old row — should survive.
    DashboardSnapshot::create([
        'metric_key' => 'recent_metric',
        'metric_value_json' => ['value' => 2],
        'computed_at' => now()->subDays(10),
    ]);

    Artisan::call('snapshots:prune');

    expect(DashboardSnapshot::where('metric_key', 'old_metric')->exists())->toBeFalse();
    expect(DashboardSnapshot::where('metric_key', 'recent_metric')->exists())->toBeTrue();
});

it('treats --days=0 as a no-op safety guard', function (): void {
    DashboardSnapshot::create([
        'metric_key' => 'guarded_metric',
        'metric_value_json' => ['value' => 1],
        'computed_at' => now()->subYears(10),
    ]);

    Artisan::call('snapshots:prune', ['--days' => 0]);

    // Nothing deleted despite the row being 10 years old.
    expect(DashboardSnapshot::where('metric_key', 'guarded_metric')->exists())->toBeTrue();
});
