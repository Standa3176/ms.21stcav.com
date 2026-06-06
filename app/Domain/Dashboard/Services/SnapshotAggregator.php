<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Services;

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Dashboard\Models\DashboardSnapshot;
use App\Domain\Sync\Models\ImportIssue;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 Plan 02 — SnapshotAggregator (D-02).
 *
 * Owns the HOW of every home-dashboard metric. Widgets are dumb readers — they
 * call `read($metricKey)` and render whatever they get. This service is the
 * single place that knows:
 *   - what to count
 *   - how to count it
 *   - what shape the payload takes
 *
 * `dashboard:refresh` (DashboardRefreshCommand) calls `computeAll()` every 5
 * minutes and upserts the result into `dashboard_snapshots` via
 * `DashboardSnapshot::upsertByKey`. Widgets polling the home page read those
 * rows by `metric_key` — zero live aggregation on page load (D-02 truth).
 *
 * Metric keys (9 — one per dashboard widget):
 *   Row 1 (freshness):
 *     - last_sync_run
 *     - crm_push_success_rate
 *     - competitor_freshness
 *   Row 2 (actions):
 *     - pending_reviews
 *     - import_issues
 *     - horizon_failed_jobs
 *   Row 3 (system health):
 *     - sync_diffs_parity
 *     - product_catalogue_health
 *     - weekly_report_status
 *
 * Every compute method is public so individual metrics can be refreshed
 * on-demand (e.g. Plan 07-05's divergence-scan only rewrites
 * sync_diffs_parity after its scan finishes).
 *
 * All methods return an array shape documented in the per-method docblock.
 * Widgets rely on the contract — do NOT change keys without updating widgets.
 */
final class SnapshotAggregator
{
    /**
     * Compute every metric in one pass.
     *
     * @return array<string, array<string, mixed>> map of metric_key => payload
     */
    public function computeAll(): array
    {
        return [
            'last_sync_run' => $this->computeLastSyncRun(),
            'crm_push_success_rate' => $this->computeCrmPushSuccessRate(),
            'competitor_freshness' => $this->computeCompetitorFreshness(),
            'pending_reviews' => $this->computePendingReviews(),
            'import_issues' => $this->computeImportIssues(),
            'horizon_failed_jobs' => $this->computeHorizonFailedJobs(),
            'sync_diffs_parity' => $this->computeSyncDiffsParity(),
            'product_catalogue_health' => $this->computeProductCatalogueHealth(),
            'weekly_report_status' => $this->computeWeeklyReportStatus(),
            // Phase 09.1 Plan 01 (D-15) — IntegrationHealthWidget reads this metric_key.
            'integration_health' => $this->computeIntegrationHealth(),
            // Quick task 260606-lhp — HighConfidenceSourceableWidget +
            // SuggestionsQueueHealthWidget read this metric_key.
            'suggestions_triage_health' => $this->computeSuggestionsTriageHealth(),
        ];
    }

    /**
     * Phase 09.1 Plan 01 — Integration health per kind (D-15).
     *
     * Returns a per-kind map of [status, last_test_at] for the
     * IntegrationHealthWidget 5-tile display. Reads the latest IntegrationCredential
     * row per kind. Missing rows return ['status' => 'unknown', 'last_test_at' => null].
     *
     * @return array<string, array{status: string, last_test_at: ?string}>
     */
    public function computeIntegrationHealth(): array
    {
        $rows = Schema::hasTable('integration_credentials')
            ? DB::table('integration_credentials')
                ->select(['kind', 'last_test_status', 'last_test_at'])
                ->get()
                ->keyBy('kind')
            : collect();

        $out = [];
        foreach (\App\Domain\Integrations\Enums\IntegrationCredentialKind::cases() as $kind) {
            $row = $rows->get($kind->value);
            $rawStatus = $row->last_test_status ?? null;
            $out[$kind->value] = [
                'status' => $rawStatus !== null && $rawStatus !== ''
                    ? (string) $rawStatus
                    : 'unknown',
                'last_test_at' => isset($row->last_test_at) && $row->last_test_at !== null
                    ? \Carbon\Carbon::parse($row->last_test_at)->toIso8601String()
                    : null,
            ];
        }

        return $out;
    }

    /**
     * Quick task 260606-lhp — decision-grade Suggestions triage payload.
     *
     * Registered in computeAll() under metric_key='suggestions_triage_health'
     * so dashboard:refresh persists it every 5 min. Powers BOTH Home dashboard
     * tiles introduced by this task:
     *   - HighConfidenceSourceableWidget (Tile 1, click-through)
     *   - SuggestionsQueueHealthWidget   (Tile 2, informational)
     *
     * 6-key payload:
     *   high_confidence_count : int   - shared scope (drift-locked vs sidebar badge)
     *   sourceable_count      : int   - pending NPO + EXISTS supplier_sku_cache
     *   raw_pending_count     : int   - pending NPO only
     *   applied_7d            : int   - status=applied + resolved_at>=now()-7d
     *   rejected_7d           : int   - status=rejected + resolved_at>=now()-7d
     *   oldest_pending_days   : ?int  - null when no pending rows
     *
     * Defensive try/catch — minimal test envs may lack supplier_sku_cache;
     * a failed read MUST NOT 500 the dashboard. On any Throwable returns the
     * zero-payload (matching the empty-state shape Test 3 asserts).
     *
     * Performance: one COUNT per of {high-confidence, sourceable, raw-pending}
     * + one selectRaw collapsing applied/rejected + one min(proposed_at) on the
     * pending subset. 5 round-trips per refresh — well inside the "single-snapshot
     * refresh" cadence (5min schedule).
     *
     * @return array<string, mixed>
     */
    public function computeSuggestionsTriageHealth(): array
    {
        $zero = [
            'high_confidence_count' => 0,
            'sourceable_count' => 0,
            'raw_pending_count' => 0,
            'applied_7d' => 0,
            'rejected_7d' => 0,
            'oldest_pending_days' => null,
        ];

        try {
            $hasCache = Schema::hasTable('supplier_sku_cache');

            $highConfidence = $hasCache
                ? Suggestion::query()->highConfidenceSourceable()->count()
                : 0;

            // Sourceable = pending NPO + EXISTS supplier_sku_cache (NO competitor
            // gate). Different predicate from the scope deliberately — Tile 1's
            // description renders both numbers.
            $sourceable = 0;
            if ($hasCache) {
                $skuExpr = DB::connection()->getDriverName() === 'sqlite'
                    ? "json_extract(suggestions.evidence, '$.sku')"
                    : "JSON_UNQUOTE(JSON_EXTRACT(suggestions.evidence, '$.sku'))";

                $sourceable = (int) Suggestion::query()
                    ->where('status', Suggestion::STATUS_PENDING)
                    ->where('kind', 'new_product_opportunity')
                    ->whereRaw("EXISTS (SELECT 1 FROM supplier_sku_cache c WHERE c.sku = LOWER(TRIM({$skuExpr})))")
                    ->count();
            }

            $rawPending = (int) Suggestion::query()
                ->where('status', Suggestion::STATUS_PENDING)
                ->where('kind', 'new_product_opportunity')
                ->count();

            // applied + rejected counts in ONE round-trip via CASE WHEN.
            $resolvedWindow = DB::table('suggestions')
                ->where('kind', 'new_product_opportunity')
                ->whereIn('status', [Suggestion::STATUS_APPLIED, Suggestion::STATUS_REJECTED])
                ->where('resolved_at', '>=', now()->subDays(7))
                ->selectRaw("SUM(CASE WHEN status = 'applied' THEN 1 ELSE 0 END) AS applied_7d, SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_7d")
                ->first();

            $appliedSevenDays = (int) ($resolvedWindow->applied_7d ?? 0);
            $rejectedSevenDays = (int) ($resolvedWindow->rejected_7d ?? 0);

            $oldest = Suggestion::query()
                ->where('kind', 'new_product_opportunity')
                ->where('status', Suggestion::STATUS_PENDING)
                ->min('proposed_at');

            $oldestPendingDays = $oldest === null
                ? null
                : (int) \Carbon\Carbon::parse($oldest)->diffInDays(now());

            return [
                'high_confidence_count' => (int) $highConfidence,
                'sourceable_count' => $sourceable,
                'raw_pending_count' => $rawPending,
                'applied_7d' => $appliedSevenDays,
                'rejected_7d' => $rejectedSevenDays,
                'oldest_pending_days' => $oldestPendingDays,
            ];
        } catch (\Throwable $e) {
            report($e);

            return $zero;
        }
    }

    /**
     * Convenience read helper for widgets — returns the raw JSON payload or
     * null if no snapshot has been computed yet.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $metricKey): ?array
    {
        $snapshot = DashboardSnapshot::where('metric_key', $metricKey)->first();

        return $snapshot?->metric_value_json;
    }

    /**
     * Refresh every metric_key via upsertByKey. Plan 07-02 DashboardRefreshCommand
     * delegates here; keeping it on the service so ad-hoc callers (tests,
     * tinker) get the same pipeline.
     *
     * @return int Number of metrics upserted.
     */
    public function refreshAll(): int
    {
        $computed = $this->computeAll();
        foreach ($computed as $metricKey => $payload) {
            DashboardSnapshot::upsertByKey($metricKey, $payload);
        }

        return count($computed);
    }

    /**
     * Last completed SyncRun — freshness + outcome tiles.
     *
     * Shape:
     *   run_id            ?int       Latest run id, null if never run
     *   duration_seconds  ?int       Elapsed time for the run
     *   updated_count     int        Products mutated by the run
     *   failed_count      int        Per-SKU failures
     *   age_traffic_light string     'green' <26h, 'amber' <50h, 'red' otherwise
     *   last_completed_at ?string    ISO-8601 or null
     *
     * @return array<string, mixed>
     */
    public function computeLastSyncRun(): array
    {
        $run = SyncRun::where('status', SyncRun::STATUS_COMPLETED)
            ->latest('completed_at')
            ->first();

        if ($run === null) {
            return [
                'run_id' => null,
                'duration_seconds' => null,
                'updated_count' => 0,
                'failed_count' => 0,
                'age_traffic_light' => 'red',
                'last_completed_at' => null,
            ];
        }

        $completedAt = $run->completed_at;
        $startedAt = $run->started_at;
        $ageHours = $completedAt !== null ? $completedAt->diffInHours(now()) : PHP_INT_MAX;

        return [
            'run_id' => $run->id,
            'duration_seconds' => $completedAt !== null && $startedAt !== null
                ? (int) $startedAt->diffInSeconds($completedAt)
                : null,
            'updated_count' => (int) ($run->updated_count ?? 0),
            'failed_count' => (int) ($run->failed_count ?? 0),
            'age_traffic_light' => $ageHours < 26 ? 'green' : ($ageHours < 50 ? 'amber' : 'red'),
            'last_completed_at' => $completedAt?->toIso8601String(),
        ];
    }

    /**
     * Rolling 24h CRM push success rate — reads `integration_events` where
     * channel='bitrix' (Phase 4 Plan 03 shape — there is no dedicated
     * crm_push_logs table; the CrmPushLogResource binds to IntegrationEvent).
     *
     * Shape:
     *   success_count        int
     *   retry_count          int  (count of attempt>1 rows — Bitrix D-11 retry signal)
     *   failed_count         int
     *   total                int
     *   success_rate_percent ?int (null when total=0)
     *
     * @return array<string, mixed>
     */
    public function computeCrmPushSuccessRate(): array
    {
        $row = DB::table('integration_events')
            ->where('channel', 'bitrix')
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw("
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_count,
                SUM(CASE WHEN status = 'retrying' THEN 1 ELSE 0 END) AS retry_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                COUNT(*) AS total
            ")
            ->first();

        $total = (int) ($row->total ?? 0);
        $success = (int) ($row->success_count ?? 0);

        return [
            'success_count' => $success,
            'retry_count' => (int) ($row->retry_count ?? 0),
            'failed_count' => (int) ($row->failed_count ?? 0),
            'total' => $total,
            'success_rate_percent' => $total === 0 ? null : (int) round($success / $total * 100),
        ];
    }

    /**
     * Traffic-light buckets for each active Competitor's ingest freshness.
     * Mirrors Phase 5 `StaleFeedTrafficLight` widget thresholds — threshold is
     * sourced from `config('competitor.stale_feed_hours', 48)` so the CompetitorCheckStaleCommand
     * and the home dashboard can't drift.
     *
     * Shape:
     *   fresh           int                 active competitors with ingest < threshold
     *   stale           int                 active competitors with ingest >= threshold
     *   missing         int                 active competitors with no ingest recorded
     *   per_competitor  array<int,array>    per-row {id,name,hours_since,status}
     *
     * @return array<string, mixed>
     */
    public function computeCompetitorFreshness(): array
    {
        $threshold = (int) config('competitor.stale_feed_hours', 48);

        $active = Competitor::query()
            ->where('status', Competitor::STATUS_ACTIVE)
            ->where('is_active', true)
            ->get();

        $fresh = 0;
        $stale = 0;
        $missing = 0;
        $perCompetitor = [];

        foreach ($active as $competitor) {
            $lastIngest = $competitor->last_ingest_at;
            $hours = $lastIngest !== null ? (int) $lastIngest->diffInHours(now()) : null;
            $status = $hours === null ? 'missing' : ($hours < $threshold ? 'fresh' : 'stale');

            match ($status) {
                'fresh' => $fresh++,
                'stale' => $stale++,
                default => $missing++,
            };

            $perCompetitor[] = [
                'id' => $competitor->id,
                'name' => $competitor->name,
                'hours_since' => $hours,
                'status' => $status,
            ];
        }

        return [
            'fresh' => $fresh,
            'stale' => $stale,
            'missing' => $missing,
            'threshold_hours' => $threshold,
            'per_competitor' => $perCompetitor,
        ];
    }

    /**
     * Triage backlog — what ops should look at first.
     *
     * Shape:
     *   auto_create_drafts                   int
     *   margin_change_suggestions            int
     *   new_product_opportunity_suggestions  int
     *   total_pending_suggestions            int
     *
     * @return array<string, mixed>
     */
    public function computePendingReviews(): array
    {
        return [
            'auto_create_drafts' => Product::query()
                ->where('auto_create_status', 'draft')
                ->count(),
            'margin_change_suggestions' => Suggestion::query()
                ->where('status', Suggestion::STATUS_PENDING)
                ->where('kind', 'margin_change')
                ->count(),
            'new_product_opportunity_suggestions' => Suggestion::query()
                ->where('status', Suggestion::STATUS_PENDING)
                ->where('kind', 'new_product_opportunity')
                ->count(),
            'total_pending_suggestions' => Suggestion::query()
                ->where('status', Suggestion::STATUS_PENDING)
                ->count(),
        ];
    }

    /**
     * Import + ingest issues across Sync + Competitor layers.
     *
     * Tables may not exist in every test environment; Schema::hasTable gates
     * each read to keep the aggregator resilient — missing table means the
     * count is 0, not a crash.
     *
     * Shape:
     *   unresolved_csv_parse_errors  int   Phase 5 csv_parse_errors
     *   quarantined_csvs             int   Phase 5 competitor_ingest_runs where status='failed'
     *   low_completeness_drafts      int   Phase 6 Product where completeness_score<50 + draft
     *   unresolved_import_issues     int   Phase 2 ImportIssue where resolved_at IS NULL
     *
     * @return array<string, mixed>
     */
    public function computeImportIssues(): array
    {
        $csvParseErrors = Schema::hasTable('csv_parse_errors')
            ? (int) DB::table('csv_parse_errors')->whereNull('resolved_at')->count()
            : 0;

        // Phase 5 Plan 01 competitor_ingest_runs uses 'failed' not 'quarantined'.
        // Quarantine files live on disk; the DB-side signal is a failed ingest run.
        $quarantinedCsvs = Schema::hasTable('competitor_ingest_runs')
            ? (int) DB::table('competitor_ingest_runs')->where('status', 'failed')->count()
            : 0;

        return [
            'unresolved_csv_parse_errors' => $csvParseErrors,
            'quarantined_csvs' => $quarantinedCsvs,
            'low_completeness_drafts' => Product::query()
                ->where('completeness_score', '<', 50)
                ->where('auto_create_status', 'draft')
                ->count(),
            'unresolved_import_issues' => ImportIssue::query()->unresolved()->count(),
        ];
    }

    /**
     * Horizon failed_jobs counts — 5-min + 24h rolling windows.
     * Table ships with Laravel Horizon / Queue and may be absent in minimal
     * test databases; Schema::hasTable guards the reads.
     *
     * Shape:
     *   last_5_min    int
     *   last_24_hours int
     *
     * @return array<string, mixed>
     */
    public function computeHorizonFailedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['last_5_min' => 0, 'last_24_hours' => 0];
        }

        return [
            'last_5_min' => (int) DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subMinutes(5))
                ->count(),
            'last_24_hours' => (int) DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count(),
        ];
    }

    /**
     * CUT-01 parity tile — divergence-scan rowset.
     *
     * sync_diffs schema (Phase 1 Plan 04 + Phase 4 Plan 01 `provider` column):
     *   - provider            VARCHAR(20) — 'divergence-scan' for this metric
     *   - created_at          timestamp  — when the diff was recorded
     *   - payload             JSON       — contains sku + field context
     *
     * There is no product_id column on sync_diffs (diff writes are by SKU
     * inside the JSON payload); parity is computed as a ratio of distinct
     * diverged rows / total scanned rows inside the window.
     *
     * Window + threshold come from config/cutover.php (D-12) so ops can
     * widen/tighten without a redeploy.
     *
     * Shape:
     *   parity_percent    ?int     (null when no products scanned)
     *   diverged_rows     int
     *   total_products    int
     *   threshold_percent int
     *   traffic_light     string   'green' if parity>=threshold, 'red' if below, 'amber' if unknown
     *   window_days       int
     *
     * @return array<string, mixed>
     */
    public function computeSyncDiffsParity(): array
    {
        $window = (int) config('cutover.parity_window_days', 7);
        $threshold = (int) config('cutover.parity_threshold_percent', 99);

        $total = Product::query()->count();
        $diverged = 0;

        if (Schema::hasTable('sync_diffs')) {
            $diverged = (int) DB::table('sync_diffs')
                ->where('provider', 'divergence-scan')
                ->where('created_at', '>=', now()->subDays($window))
                ->count();
        }

        $parity = $total === 0
            ? null
            : (int) round(100 - min(100, ($diverged / $total * 100)));

        return [
            'parity_percent' => $parity,
            'diverged_rows' => $diverged,
            'total_products' => $total,
            'threshold_percent' => $threshold,
            'traffic_light' => $parity === null
                ? 'amber'
                : ($parity >= $threshold ? 'green' : 'red'),
            'window_days' => $window,
        ];
    }

    /**
     * Product catalogue health — per-status counts on the products table.
     *
     * Shape:
     *   published int
     *   draft     int
     *   pending   int
     *   total     int
     *
     * @return array<string, mixed>
     */
    public function computeProductCatalogueHealth(): array
    {
        $byStatus = DB::table('products')
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->toArray();

        $byStatus = array_map('intval', $byStatus);

        return [
            'published' => $byStatus['publish'] ?? 0,
            'draft' => $byStatus['draft'] ?? 0,
            'pending' => $byStatus['pending'] ?? 0,
            'total' => array_sum($byStatus),
        ];
    }

    /**
     * Weekly digest status — Plan 07-04 populates this on every send.
     *
     * First-run fallback (no prior digest): return empty state with the next
     * Monday 07:00 ETA so the widget has content to render. Do NOT overwrite
     * the value Plan 07-04 wrote — preserve the real last_sent_at + recipient
     * count when available.
     *
     * Shape:
     *   last_sent_at    ?string   ISO-8601 or null
     *   recipient_count int
     *   next_run_iso    string    ISO-8601 of next Monday 07:00 Europe/London
     *
     * @return array<string, mixed>
     */
    public function computeWeeklyReportStatus(): array
    {
        $existing = $this->read('weekly_report_status');

        if (is_array($existing) && array_key_exists('last_sent_at', $existing)) {
            // Plan 07-04 owns the real data — just refresh next_run_iso so the
            // ETA ticks forward as each Monday passes.
            $existing['next_run_iso'] = $this->nextMondayEightSeven()->toIso8601String();

            return $existing;
        }

        return [
            'last_sent_at' => null,
            'recipient_count' => 0,
            'next_run_iso' => $this->nextMondayEightSeven()->toIso8601String(),
        ];
    }

    private function nextMondayEightSeven(): \Carbon\CarbonImmutable
    {
        // 07:00 Europe/London — matches Plan 07-04 D-08 schedule.
        return \Carbon\CarbonImmutable::now('Europe/London')
            ->next(\Carbon\CarbonImmutable::MONDAY)
            ->setTime(7, 0);
    }
}
