<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Dashboard\Services\SnapshotAggregator;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 02 Task 1 — SnapshotAggregator per-metric compute methods
|--------------------------------------------------------------------------
|
| Covers per-metric payload shape for each of the 9 widget data sources so a
| future schema change (e.g. new status value on SyncRun or new Competitor
| column) fails loud in CI instead of silently drifting the dashboard.
*/

it('computes last_sync_run from the latest completed SyncRun', function (): void {
    SyncRun::create([
        'started_at' => now()->subMinutes(30),
        'completed_at' => now()->subMinutes(10),
        'status' => SyncRun::STATUS_COMPLETED,
        'dry_run' => false,
        'updated_count' => 42,
        'failed_count' => 3,
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
    ]);

    $payload = app(SnapshotAggregator::class)->computeLastSyncRun();

    expect($payload)->toHaveKeys([
        'run_id', 'duration_seconds', 'updated_count', 'failed_count',
        'age_traffic_light', 'last_completed_at',
    ]);
    expect($payload['updated_count'])->toBe(42);
    expect($payload['failed_count'])->toBe(3);
    expect($payload['duration_seconds'])->toBeGreaterThan(0);
    expect($payload['age_traffic_light'])->toBe('green'); // <26h
    expect($payload['last_completed_at'])->not->toBeNull();
});

it('returns empty-state last_sync_run payload when no run exists', function (): void {
    $payload = app(SnapshotAggregator::class)->computeLastSyncRun();

    expect($payload['run_id'])->toBeNull();
    expect($payload['age_traffic_light'])->toBe('red');
    expect($payload['last_completed_at'])->toBeNull();
});

it('computes crm_push_success_rate across status buckets in last 24h', function (): void {
    // 7 success + 2 retrying + 1 failed = 10 total → 70% success rate.
    foreach (['success', 'success', 'success', 'success', 'success', 'success', 'success'] as $s) {
        insertIntegrationEvent($s);
    }
    insertIntegrationEvent('retrying');
    insertIntegrationEvent('retrying');
    insertIntegrationEvent('failed');

    // Noise — channel='woo' should be ignored.
    insertIntegrationEvent('success', channel: 'woo');

    // Noise — older than 24h should be ignored.
    insertIntegrationEvent('failed', at: now()->subDays(2));

    $payload = app(SnapshotAggregator::class)->computeCrmPushSuccessRate();

    expect($payload['success_count'])->toBe(7);
    expect($payload['retry_count'])->toBe(2);
    expect($payload['failed_count'])->toBe(1);
    expect($payload['total'])->toBe(10);
    expect($payload['success_rate_percent'])->toBe(70);
});

it('null-returns success_rate_percent when there is no push data', function (): void {
    $payload = app(SnapshotAggregator::class)->computeCrmPushSuccessRate();

    expect($payload['total'])->toBe(0);
    expect($payload['success_rate_percent'])->toBeNull();
});

it('computes competitor_freshness buckets across fresh, stale, missing', function (): void {
    Competitor::create([
        'slug' => 'fresh-one',
        'name' => 'Fresh One',
        'status' => Competitor::STATUS_ACTIVE,
        'is_active' => true,
        'last_ingest_at' => now()->subHours(1),
    ]);
    Competitor::create([
        'slug' => 'fresh-two',
        'name' => 'Fresh Two',
        'status' => Competitor::STATUS_ACTIVE,
        'is_active' => true,
        'last_ingest_at' => now()->subHours(20),
    ]);
    Competitor::create([
        'slug' => 'stale',
        'name' => 'Stale',
        'status' => Competitor::STATUS_ACTIVE,
        'is_active' => true,
        'last_ingest_at' => now()->subHours(72),
    ]);
    Competitor::create([
        'slug' => 'missing',
        'name' => 'Missing',
        'status' => Competitor::STATUS_ACTIVE,
        'is_active' => true,
        'last_ingest_at' => null,
    ]);
    // Inactive competitor — should NOT be counted in any bucket.
    Competitor::create([
        'slug' => 'inactive',
        'name' => 'Inactive',
        'status' => Competitor::STATUS_INACTIVE,
        'is_active' => false,
        'last_ingest_at' => now(),
    ]);

    $payload = app(SnapshotAggregator::class)->computeCompetitorFreshness();

    expect($payload['fresh'])->toBe(2);
    expect($payload['stale'])->toBe(1);
    expect($payload['missing'])->toBe(1);
    expect($payload['per_competitor'])->toHaveCount(4); // only active rows
});

it('computes pending_reviews counts from products + suggestions', function (): void {
    // 3 draft-status products.
    Product::factory()->count(3)->create(['auto_create_status' => 'draft']);
    // 2 non-draft noise rows.
    Product::factory()->count(2)->create(['auto_create_status' => 'approved']);

    // 2 pending margin-change, 1 new-product-opportunity, 1 applied (not pending).
    Suggestion::create([
        'kind' => 'margin_change', 'status' => 'pending', 'correlation_id' => 'c-1',
        'proposed_by_type' => 'system', 'proposed_by_id' => null,
        'proposed_at' => now(),
    ]);
    Suggestion::create([
        'kind' => 'margin_change', 'status' => 'pending', 'correlation_id' => 'c-2',
        'proposed_by_type' => 'system', 'proposed_by_id' => null,
        'proposed_at' => now(),
    ]);
    Suggestion::create([
        'kind' => 'new_product_opportunity', 'status' => 'pending', 'correlation_id' => 'c-3',
        'proposed_by_type' => 'system', 'proposed_by_id' => null,
        'proposed_at' => now(),
    ]);
    Suggestion::create([
        'kind' => 'margin_change', 'status' => 'applied', 'correlation_id' => 'c-4',
        'proposed_by_type' => 'system', 'proposed_by_id' => null,
        'proposed_at' => now(),
    ]);

    $payload = app(SnapshotAggregator::class)->computePendingReviews();

    expect($payload['auto_create_drafts'])->toBe(3);
    expect($payload['margin_change_suggestions'])->toBe(2);
    expect($payload['new_product_opportunity_suggestions'])->toBe(1);
    expect($payload['total_pending_suggestions'])->toBe(3); // 2 margin + 1 opportunity
});

it('computes sync_diffs_parity as 100% when no divergence rows exist', function (): void {
    Product::factory()->count(10)->create();

    $payload = app(SnapshotAggregator::class)->computeSyncDiffsParity();

    expect($payload['total_products'])->toBe(10);
    expect($payload['diverged_rows'])->toBe(0);
    expect($payload['parity_percent'])->toBe(100);
    expect($payload['traffic_light'])->toBe('green');
});

it('computes sync_diffs_parity as amber traffic-light when no products exist yet', function (): void {
    $payload = app(SnapshotAggregator::class)->computeSyncDiffsParity();

    expect($payload['total_products'])->toBe(0);
    expect($payload['parity_percent'])->toBeNull();
    expect($payload['traffic_light'])->toBe('amber');
});

it('computes horizon_failed_jobs counts across windows', function (): void {
    // Only run the assertion if the failed_jobs table exists in the test DB.
    if (! \Illuminate\Support\Facades\Schema::hasTable('failed_jobs')) {
        $payload = app(SnapshotAggregator::class)->computeHorizonFailedJobs();
        expect($payload)->toBe(['last_5_min' => 0, 'last_24_hours' => 0]);
        return;
    }

    DB::table('failed_jobs')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['job' => 'TestJob']),
        'exception' => 'RuntimeException: boom',
        'failed_at' => now()->subMinutes(2), // within 5 min
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['job' => 'TestJob']),
        'exception' => 'RuntimeException: boom',
        'failed_at' => now()->subHours(12), // within 24h only
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['job' => 'TestJob']),
        'exception' => 'RuntimeException: boom',
        'failed_at' => now()->subDays(2), // outside window — noise
    ]);

    $payload = app(SnapshotAggregator::class)->computeHorizonFailedJobs();

    expect($payload['last_5_min'])->toBe(1);
    expect($payload['last_24_hours'])->toBe(2);
});

it('exposes a read() helper that returns null when snapshot is absent', function (): void {
    expect(app(SnapshotAggregator::class)->read('never_written'))->toBeNull();
});

it('refreshAll returns the count of metrics upserted', function (): void {
    $count = app(SnapshotAggregator::class)->refreshAll();

    // 9 (Phase 7) + 1 (Phase 09.1 integration_health) + 1 (260606-lhp suggestions_triage_health) = 11.
    expect($count)->toBe(11);
});

// ─── Helpers ────────────────────────────────────────────────────────────

function insertIntegrationEvent(
    string $status,
    string $channel = 'bitrix',
    ?\DateTimeInterface $at = null,
): void {
    DB::table('integration_events')->insert([
        'channel' => $channel,
        'direction' => 'outbound',
        'operation' => 'crm.deal.add',
        'subject_type' => null,
        'subject_id' => null,
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'endpoint' => 'https://example.bitrix.ru/rest/crm.deal.add',
        'method' => 'POST',
        'request_body' => json_encode([]),
        'request_headers' => json_encode([]),
        'response_body' => json_encode(['result' => 1]),
        'http_status' => $status === 'success' ? 200 : 500,
        'latency_ms' => 50,
        'attempt' => 1,
        'status' => $status,
        'error_message' => null,
        'created_at' => $at ?? now(),
    ]);
}
