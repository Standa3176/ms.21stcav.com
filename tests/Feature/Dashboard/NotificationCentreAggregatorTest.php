<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Dashboard\Services\NotificationCentreAggregator;
use App\Domain\Suggestions\Models\Suggestion;
use App\Foundation\Integration\Models\IntegrationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 04 Task 1 — NotificationCentreAggregator
|--------------------------------------------------------------------------
|
| Covers 4 public aggregator methods feeding the 4 notification-centre tabs:
|   - failedJobs (Horizon failed_jobs, last 7 days window)
|   - staleFeeds (Competitor ingest freshness — stale + missing buckets)
|   - pendingSuggestions (grouped by kind with count + oldest timestamp)
|   - webhookDlq (inbound failed integration_events in last 7 days)
*/

it('returns failed jobs from the last 7 days only', function (): void {
    DB::table('failed_jobs')->insert([
        [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'TestJobA']),
            'exception' => 'RuntimeException: A',
            'failed_at' => now()->subDays(2),
        ],
        [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue' => 'sync-bulk',
            'payload' => json_encode(['displayName' => 'TestJobB']),
            'exception' => 'RuntimeException: B',
            'failed_at' => now()->subDays(6),
        ],
        [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'TestJobC']),
            'exception' => 'RuntimeException: C',
            'failed_at' => now()->subDays(10),
        ],
    ]);

    $aggregator = new NotificationCentreAggregator();
    $rows = $aggregator->failedJobs();

    expect($rows)->toHaveCount(2);
    $queues = $rows->pluck('queue')->all();
    expect($queues)->toContain('default');
    expect($queues)->toContain('sync-bulk');
});

it('returns stale feeds for competitors older than the threshold or missing', function (): void {
    config()->set('competitor.stale_feed_hours', 48);

    Competitor::factory()->create([
        'name' => 'Fresh Co',
        'status' => Competitor::STATUS_ACTIVE,
        'is_active' => true,
        'last_ingest_at' => now()->subHours(12),
    ]);
    Competitor::factory()->create([
        'name' => 'Stale Co',
        'status' => Competitor::STATUS_ACTIVE,
        'is_active' => true,
        'last_ingest_at' => now()->subHours(50),
    ]);
    Competitor::factory()->create([
        'name' => 'Missing Co',
        'status' => Competitor::STATUS_ACTIVE,
        'is_active' => true,
        'last_ingest_at' => null,
    ]);

    $aggregator = new NotificationCentreAggregator();
    $rows = $aggregator->staleFeeds();

    expect($rows)->toHaveCount(2);
    $names = $rows->pluck('name')->all();
    expect($names)->toContain('Stale Co');
    expect($names)->toContain('Missing Co');
});

it('groups pending suggestions by kind with counts', function (): void {
    foreach (range(1, 3) as $i) {
        Suggestion::create([
            'kind' => 'margin_change',
            'status' => Suggestion::STATUS_PENDING,
            'correlation_id' => 'agg-margin-'.$i,
            'payload' => ['x' => $i],
            'proposed_at' => now(),
        ]);
    }
    foreach (range(1, 2) as $i) {
        Suggestion::create([
            'kind' => 'new_product_opportunity',
            'status' => Suggestion::STATUS_PENDING,
            'correlation_id' => 'agg-npo-'.$i,
            'payload' => ['y' => $i],
            'proposed_at' => now(),
        ]);
    }
    // Non-pending row that MUST be excluded.
    Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_APPROVED,
        'correlation_id' => 'agg-excluded',
        'payload' => ['z' => 1],
        'proposed_at' => now(),
    ]);

    $aggregator = new NotificationCentreAggregator();
    $rows = $aggregator->pendingSuggestions();

    expect($rows)->toHaveCount(2);
    $byKind = $rows->keyBy('kind');
    expect((int) $byKind['margin_change']['count'])->toBe(3);
    expect((int) $byKind['new_product_opportunity']['count'])->toBe(2);
});

it('returns failed inbound woo webhook events within 7 days', function (): void {
    // In-window failed inbound (woo).
    IntegrationEvent::create([
        'channel' => 'woo',
        'direction' => 'inbound',
        'operation' => 'webhook.order',
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'endpoint' => '/webhook/woo/order',
        'method' => 'POST',
        'status' => 'failed',
        'created_at' => now()->subDays(2),
    ]);
    IntegrationEvent::create([
        'channel' => 'woo',
        'direction' => 'inbound',
        'operation' => 'webhook.customer',
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'endpoint' => '/webhook/woo/customer',
        'method' => 'POST',
        'status' => 'failed',
        'created_at' => now()->subDays(1),
    ]);
    // Outside 7-day window — EXCLUDED.
    IntegrationEvent::create([
        'channel' => 'woo',
        'direction' => 'inbound',
        'operation' => 'webhook.old',
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'endpoint' => '/webhook/woo/old',
        'method' => 'POST',
        'status' => 'failed',
        'created_at' => now()->subDays(9),
    ]);
    // Success — EXCLUDED.
    IntegrationEvent::create([
        'channel' => 'woo',
        'direction' => 'inbound',
        'operation' => 'webhook.ok',
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'endpoint' => '/webhook/woo/ok',
        'method' => 'POST',
        'status' => 'success',
        'created_at' => now()->subDays(1),
    ]);

    $aggregator = new NotificationCentreAggregator();
    $rows = $aggregator->webhookDlq();

    expect($rows)->toHaveCount(2);
});
