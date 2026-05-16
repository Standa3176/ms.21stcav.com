<?php

declare(strict_types=1);

namespace App\Domain\Agents\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Agents\Jobs\RunSeoAgentJob;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Phase 12 Plan 05 (SEOAGT-05) — nightly SEO agent batch.
 *
 * Iterates Phase 6 AutoCreate drafts where:
 *   - auto_create_status='pending_review'
 *   - completeness_score < 85
 *   - no PENDING or APPLIED seo_content_patch Suggestion on this product yet
 *
 * Worst-first ordering (lowest completeness_score first) so the night's
 * 20-job budget biases toward the drafts that benefit most from agent
 * enrichment.
 *
 * P12-E (batch budget race) — the monthly budget is re-checked BEFORE
 * each dispatch, not just once at batch start. Without this, dispatching
 * all 20 jobs could overshoot the £200 monthly ceiling by ~100p when the
 * budget was already near the cap at batch start (each run costs ~5p).
 *
 * Cache key shape mirrors {@see BudgetGuard::monthlyKey()} verbatim:
 *   `agents.monthly.{Y-m}` in Europe/London (matches BudgetGuard convention
 *   so any other agent's spend on the same day reduces this batch's headroom).
 *
 * Dry-run mode (--dry-run) lists eligible products with their score
 * WITHOUT enqueueing any jobs — operator pre-flight check before live
 * dispatch.
 *
 * Schedule: routes/console.php registers this at 04:30 Europe/London,
 * gated by AGENT_SEO_BATCH_SCHEDULE_ENABLED env flag (default true,
 * operator emergency disable per O-2).
 */
final class RunSeoAgentBatchCommand extends BaseCommand
{
    protected $signature = 'agents:run-seo-batch
                            {--limit=20 : Max products to process this run}
                            {--dry-run : Show eligible products without dispatching}';

    protected $description = 'Phase 12 SEOAGT-05 — nightly SEO agent batch over Phase 6 AutoCreate drafts';

    protected function perform(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        // ── 1. Pre-flight monthly budget check (P12-E layer 1) ─────────────
        $monthlyCap = (int) config('agents.monthly_ceiling_pence', 20000);
        $monthlySpent = $this->currentMonthlySpentPence();
        if ($monthlySpent >= $monthlyCap) {
            $this->warn("Monthly budget already exceeded ({$monthlySpent}/{$monthlyCap}p) — batch aborted");

            return self::SUCCESS;
        }

        // ── 2. Eligibility query (worst-first, capped by --limit) ──────────
        $eligible = self::eligibleProductsQuery()->limit($limit)->get();

        if ($eligible->isEmpty()) {
            $this->info('No eligible AutoCreate drafts; nothing to do.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Found %d eligible draft(s); %s mode',
            $eligible->count(),
            $dryRun ? 'dry-run' : 'live dispatch',
        ));

        $batchCorrelationId = (string) Str::uuid();
        $dispatched = 0;

        foreach ($eligible as $product) {
            if ($dryRun) {
                $this->line(sprintf('  [DRY] %s (score=%d)', $product->sku ?? '(no sku)', (int) $product->completeness_score));

                continue;
            }

            // ── P12-E layer 2 — between-dispatch budget recheck ───────────
            // Other agents may be spending concurrently; re-read the cache
            // BEFORE each dispatch and bail early if the ceiling is crossed
            // mid-batch.
            $monthlySpent = $this->currentMonthlySpentPence();
            if ($monthlySpent >= $monthlyCap) {
                $this->warn(sprintf(
                    'Monthly budget exceeded mid-batch (%d/%dp) — stopping at %d/%d',
                    $monthlySpent,
                    $monthlyCap,
                    $dispatched,
                    $eligible->count(),
                ));

                break;
            }

            RunSeoAgentJob::dispatch($product->id, $batchCorrelationId);
            $dispatched++;
        }

        if (! $dryRun) {
            $this->info("Dispatched {$dispatched} SeoAgent runs on `agents` queue.");
        }

        return self::SUCCESS;
    }

    /**
     * Eligibility query — exposed as a public static method so Plan 12-05
     * Pest tests can exercise it in isolation without invoking the full
     * command. Returns a Builder so callers can layer ->limit / ->select /
     * ->pluck as needed.
     *
     * SQLite + MySQL compatible — uses a whereNotIn subquery over a
     * JSON_EXTRACT-equivalent rather than relying on a Product.suggestions()
     * relation (the join target is Suggestion.payload->product_id, a JSON
     * field, with no foreign key column).
     *
     * @return Builder<Product>
     */
    public static function eligibleProductsQuery(): Builder
    {
        // Subquery: ids of Products that have at least one pending OR applied
        // seo_content_patch Suggestion. Cross-DB JSON access: SQLite supports
        // json_extract(payload, '$.product_id'); MySQL 8 supports the same
        // function. Eloquent's `whereJsonContains` would also work but emits
        // a CAST that SQLite handles differently — explicit json_extract is
        // safer for both engines.
        $blockedProductIds = Suggestion::query()
            ->where('kind', 'seo_content_patch')
            ->whereIn('status', [Suggestion::STATUS_PENDING, Suggestion::STATUS_APPLIED])
            ->whereNotNull('payload')
            ->get(['payload'])
            ->map(fn (Suggestion $s) => (int) (is_array($s->payload) ? ($s->payload['product_id'] ?? 0) : 0))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        return Product::query()
            ->where('auto_create_status', 'pending_review')
            ->where('completeness_score', '<', 85)
            ->when(
                $blockedProductIds !== [],
                fn (Builder $q) => $q->whereNotIn('id', $blockedProductIds),
            )
            ->orderBy('completeness_score');
    }

    /**
     * Reads the current month's agent spend from Cache.
     *
     * Cache key mirrors BudgetGuard::monthlyKey() exactly — `agents.monthly.{Y-m}`
     * in Europe/London — so concurrent spend by other agent kinds (pricing,
     * chatbot, etc.) is visible to the SEO batch's race-protection logic.
     */
    private function currentMonthlySpentPence(): int
    {
        $tz = (string) config('agents.day_boundary_timezone', 'Europe/London');
        $month = Carbon::now($tz)->format('Y-m');

        return (int) Cache::get('agents.monthly.' . $month, 0);
    }
}
