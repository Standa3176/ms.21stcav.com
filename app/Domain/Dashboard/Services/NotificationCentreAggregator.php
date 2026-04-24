<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Services;

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Suggestions\Models\Suggestion;
use App\Foundation\Integration\Models\IntegrationEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 7 Plan 04 Task 1 — NotificationCentreAggregator (D-10).
 *
 * Single service that aggregates the 4 notification-centre tabs from existing
 * tables. No new `notifications` table is introduced — we compose from Horizon
 * `failed_jobs`, competitor ingest freshness, `suggestions` status=pending, and
 * inbound failed `integration_events`.
 *
 * Each method returns a Collection of array rows keyed so the Filament page's
 * Blade template can render them without knowing the underlying schema.
 *
 * Resilience: every method guards against missing tables via Schema::hasTable
 * so a minimal test DB (or a partial migration) does not blow up — a missing
 * table simply yields an empty collection.
 *
 * Shape contracts (widgets / blade depend on these — do NOT rename keys):
 *   failedJobs()        — ['uuid','connection','queue','failed_at','exception_summary']
 *   staleFeeds()        — ['competitor_id','name','hours_since','last_at','is_stale']
 *   pendingSuggestions — ['kind','count','oldest','newest']
 *   webhookDlq()        — ['id','channel','operation','correlation_id','failed_at','error']
 *
 * All four methods have LIMITs applied where the source table can grow
 * unbounded — failed_jobs + webhookDlq cap at 200 rows to defeat T-07-04-05
 * (poll-time DoS).
 */
final class NotificationCentreAggregator
{
    /**
     * Horizon's `failed_jobs` table, last 7 days window, newest-first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function failedJobs(): Collection
    {
        if (! Schema::hasTable('failed_jobs')) {
            return collect();
        }

        return DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDays(7))
            ->orderByDesc('failed_at')
            ->limit(200)
            ->get()
            ->map(fn ($row) => [
                'uuid' => $row->uuid,
                'connection' => $row->connection,
                'queue' => $row->queue,
                'failed_at' => $row->failed_at,
                'exception_summary' => Str::limit((string) $row->exception, 300),
            ]);
    }

    /**
     * Competitors whose last_ingest_at is older than the stale threshold
     * OR who have never ingested. Active competitors only.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function staleFeeds(): Collection
    {
        $threshold = (int) config('competitor.stale_feed_hours', 48);

        return Competitor::query()
            ->where('status', Competitor::STATUS_ACTIVE)
            ->where('is_active', true)
            ->get()
            ->map(function ($competitor) use ($threshold) {
                $last = $competitor->last_ingest_at;
                $hoursSince = $last !== null ? (int) $last->diffInHours(now()) : null;

                return [
                    'competitor_id' => $competitor->id,
                    'name' => $competitor->name,
                    'hours_since' => $hoursSince,
                    'last_at' => $last?->toIso8601String(),
                    'is_stale' => $hoursSince === null || $hoursSince >= $threshold,
                ];
            })
            ->filter(fn (array $row) => $row['is_stale'])
            ->values();
    }

    /**
     * Pending `suggestions` grouped by `kind`. Ordered by count desc so the
     * operator sees the biggest triage pile first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pendingSuggestions(): Collection
    {
        return Suggestion::query()
            ->where('status', Suggestion::STATUS_PENDING)
            ->selectRaw('kind, COUNT(*) as count, MIN(created_at) as oldest, MAX(created_at) as newest')
            ->groupBy('kind')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'kind' => $row->kind,
                'count' => (int) $row->count,
                'oldest' => $row->oldest,
                'newest' => $row->newest,
            ]);
    }

    /**
     * Inbound failed webhook events (channel=woo|bitrix, status=failed) in
     * the last 7 days. 200-row LIMIT per threat T-07-04-05.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function webhookDlq(): Collection
    {
        return IntegrationEvent::query()
            ->where('direction', 'inbound')
            ->whereIn('channel', ['woo', 'bitrix'])
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn (IntegrationEvent $event) => [
                'id' => $event->id,
                'channel' => $event->channel,
                'operation' => $event->operation,
                'endpoint' => $event->endpoint,
                'correlation_id' => $event->correlation_id,
                'failed_at' => $event->created_at,
                'error' => Str::limit((string) $event->error_message, 200),
            ]);
    }
}
