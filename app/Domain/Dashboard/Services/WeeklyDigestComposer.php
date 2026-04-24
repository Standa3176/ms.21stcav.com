<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Services;

use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\ProductAutoCreate\Models\AutoCreateRejection;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Sync\Models\SyncRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 Plan 04 Task 2 — WeeklyDigestComposer (D-08).
 *
 * Assembles the 5-section payload consumed by WeeklyDigestMail + the HTML
 * and text Blade views. Each section pulls from its owning domain's models
 * plus a light DB::table read for tables that don't have a dedicated model
 * (sync_errors, csv_parse_errors, competitor_prices aggregates).
 *
 * Shape contract (Blade + Mailable depend on these keys — do NOT rename):
 *   window_start / window_end       ISO-8601 timestamps
 *   sync        — runs_completed, average_duration_seconds, updated_skus,
 *                 failed_skus, top_5_failing_skus
 *   margin      — created_count, approved_count, largest_delta_bps
 *   crm         — deals_pushed, retries, failed_to_suggestions
 *   auto_create — drafts_created, approved_count, rejected_count,
 *                 rejections_by_reason
 *   competitor  — ingested_runs, parse_errors, top_3_movers
 *
 * Resilience: Schema::hasTable guards every cross-domain table read so a
 * partial-migration environment yields zero / empty arrays rather than a
 * crash — matches SnapshotAggregator precedent.
 */
final class WeeklyDigestComposer
{
    /**
     * Build the 5-section payload. Window defaults to last 7 days ending now.
     *
     * @return array<string, mixed>
     */
    public function compose(?Carbon $windowStart = null): array
    {
        $start = $windowStart ?? now()->subWeek();

        return [
            'window_start' => $start->toIso8601String(),
            'window_end' => now()->toIso8601String(),
            'sync' => $this->syncSection($start),
            'margin' => $this->marginSection($start),
            'crm' => $this->crmSection($start),
            'auto_create' => $this->autoCreateSection($start),
            'competitor' => $this->competitorSection($start),
        ];
    }

    /**
     * Supplier Sync section — run counts + avg duration + top failing SKUs.
     *
     * @return array<string, mixed>
     */
    protected function syncSection(Carbon $start): array
    {
        $runs = SyncRun::query()
            ->where('started_at', '>=', $start)
            ->get();

        $completed = $runs->where('status', SyncRun::STATUS_COMPLETED);

        $avgDuration = $completed
            ->filter(fn (SyncRun $r) => $r->completed_at !== null && $r->started_at !== null)
            ->avg(fn (SyncRun $r) => $r->started_at->diffInSeconds($r->completed_at));

        $updated = (int) $completed->sum('updated_count');
        $failed = (int) $completed->sum('failed_count');

        $topFailingSkus = [];
        if (Schema::hasTable('sync_errors')) {
            $topFailingSkus = DB::table('sync_errors')
                ->where('created_at', '>=', $start)
                ->selectRaw('sku, COUNT(*) as c')
                ->groupBy('sku')
                ->orderByDesc('c')
                ->limit(5)
                ->get()
                ->toArray();
        }

        return [
            'runs_completed' => $completed->count(),
            'average_duration_seconds' => $avgDuration !== null ? (int) round($avgDuration) : null,
            'updated_skus' => $updated,
            'failed_skus' => $failed,
            'top_5_failing_skus' => $topFailingSkus,
        ];
    }

    /**
     * Margin Analysis section — margin_change suggestion metrics.
     *
     * @return array<string, mixed>
     */
    protected function marginSection(Carbon $start): array
    {
        $created = Suggestion::query()
            ->where('kind', 'margin_change')
            ->where('created_at', '>=', $start)
            ->count();

        $approved = Suggestion::query()
            ->where('kind', 'margin_change')
            ->where('status', Suggestion::STATUS_APPROVED)
            ->where('updated_at', '>=', $start)
            ->count();

        $largest = Suggestion::query()
            ->where('kind', 'margin_change')
            ->where('created_at', '>=', $start)
            ->get()
            ->map(fn (Suggestion $s) => (int) abs((int) ($s->evidence['margin_delta_bps'] ?? 0)))
            ->max();

        return [
            'created_count' => $created,
            'approved_count' => $approved,
            'largest_delta_bps' => (int) ($largest ?? 0),
        ];
    }

    /**
     * CRM Pushes section — integration_events channel=bitrix (Phase 4 Plan 03
     * shape — no dedicated crm_push_logs table; IntegrationEvent is authoritative).
     *
     * @return array<string, mixed>
     */
    protected function crmSection(Carbon $start): array
    {
        $counts = ['success_count' => 0, 'retry_count' => 0, 'failed_count' => 0];

        if (Schema::hasTable('integration_events')) {
            $row = DB::table('integration_events')
                ->where('channel', 'bitrix')
                ->where('created_at', '>=', $start)
                ->selectRaw("
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_count,
                    SUM(CASE WHEN status = 'retrying' THEN 1 ELSE 0 END) AS retry_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
                ")
                ->first();

            $counts = [
                'success_count' => (int) ($row->success_count ?? 0),
                'retry_count' => (int) ($row->retry_count ?? 0),
                'failed_count' => (int) ($row->failed_count ?? 0),
            ];
        }

        $dlqCount = Suggestion::query()
            ->where('kind', 'crm_push_failed')
            ->where('created_at', '>=', $start)
            ->count();

        return [
            'deals_pushed' => $counts['success_count'],
            'retries' => $counts['retry_count'],
            'failed_to_suggestions' => $dlqCount,
        ];
    }

    /**
     * Product Auto-Create section — draft + approved + rejection counts.
     *
     * @return array<string, mixed>
     */
    protected function autoCreateSection(Carbon $start): array
    {
        $drafts = Product::query()
            ->where('auto_create_status', 'draft')
            ->where('created_at', '>=', $start)
            ->count();

        $approved = Product::query()
            ->where('auto_create_status', 'approved')
            ->where('updated_at', '>=', $start)
            ->count();

        $rejectionsByReason = AutoCreateRejection::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('reason, COUNT(*) as c')
            ->groupBy('reason')
            ->pluck('c', 'reason')
            ->map(fn ($c) => (int) $c)
            ->toArray();

        return [
            'drafts_created' => $drafts,
            'approved_count' => $approved,
            'rejected_count' => (int) array_sum($rejectionsByReason),
            'rejections_by_reason' => $rejectionsByReason,
        ];
    }

    /**
     * Competitor Analysis section — ingest + parse counts + top movers.
     *
     * @return array<string, mixed>
     */
    protected function competitorSection(Carbon $start): array
    {
        $ingested = CompetitorIngestRun::query()
            ->where('created_at', '>=', $start)
            ->where('status', CompetitorIngestRun::STATUS_COMPLETED)
            ->count();

        $parseErrors = 0;
        if (Schema::hasTable('csv_parse_errors')) {
            $parseErrors = (int) DB::table('csv_parse_errors')
                ->where('created_at', '>=', $start)
                ->count();
        }

        $topMovers = [];
        if (Schema::hasTable('competitor_prices')) {
            $topMovers = DB::table('competitor_prices')
                ->where('recorded_at', '>=', $start)
                ->selectRaw('sku, MAX(price_pennies_ex_vat) - MIN(price_pennies_ex_vat) as spread')
                ->groupBy('sku')
                ->orderByDesc('spread')
                ->limit(3)
                ->get()
                ->toArray();
        }

        return [
            'ingested_runs' => $ingested,
            'parse_errors' => $parseErrors,
            'top_3_movers' => $topMovers,
        ];
    }
}
