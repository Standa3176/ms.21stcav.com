<?php

declare(strict_types=1);

namespace App\Domain\Agents\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Agents\Jobs\RunAdOptimisationJob;
use App\Domain\Integrations\Models\GaChannelMetric;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 15 Plan 15b-01 — scheduled dispatcher for the advice-only
 * AdOptimisationAgent (mirrors RunSeoAgentBatchCommand's BaseCommand + perform
 * shape, minus the per-product eligibility loop — this is a single analysis run).
 *
 * SAFE NO-OP: pre-flight counts ga_channel_metrics_daily rows within the
 * lookback window (config agents.ad_optimisation.data_lookback_days, default
 * 14). If ZERO, the command logs `ad_optimisation.noop_no_data` and exits 0
 * WITHOUT dispatching — no LLM call, no spend. This makes the command safe to
 * schedule NOW, before real GA4 data flows.
 *
 * When data exists, it generates a correlation id and dispatches ONE
 * RunAdOptimisationJob on the `agents` queue. --dry-run reports eligibility
 * without dispatching.
 *
 * SKIP-IF-PENDING (260713-add): after the no-data guard, if a PENDING
 * ad_optimisation Suggestion already exists (unactioned advice), the command
 * logs `ad_optimisation.skip_pending_exists` and exits 0 WITHOUT dispatching —
 * no Claude spend, no near-duplicate row. Only pending blocks; approved/rejected
 * do not. Once the operator actions the pending one, the next scheduled run
 * produces fresh advice. This guards the SCHEDULED path only — the manual
 * "Review with Claude" dashboard action is deliberately unguarded (explicit
 * operator intent always dispatches).
 */
final class RunAdOptimisationCommand extends BaseCommand
{
    protected $signature = 'agents:run-ad-optimisation
                            {--dry-run : Report eligibility without dispatching}';

    protected $description = 'Phase 15 — advice-only AdOptimisationAgent run (GA4 + margin/competitor/stock → ad_optimisation Suggestions)';

    protected function perform(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $lookbackDays = (int) config('agents.ad_optimisation.data_lookback_days', 14);

        $recentRows = GaChannelMetric::query()
            ->where('date', '>=', Carbon::today()->subDays($lookbackDays)->toDateString())
            ->count();

        // ── SAFE NO-OP — no recent GA4 data means no analysis to run ────────
        if ($recentRows === 0) {
            Log::info('ad_optimisation.noop_no_data', [
                'lookback_days' => $lookbackDays,
                'reason' => 'no ga_channel_metrics_daily rows in the lookback window',
            ]);
            $this->info("No recent GA4 data ({$lookbackDays}d lookback) — nothing to analyse. Exiting without dispatch (no LLM spend).");

            return self::SUCCESS;
        }

        // ── SKIP-IF-PENDING — don't pile up near-identical advice ───────────
        // Only PENDING ad_optimisation Suggestions block; approved/rejected do
        // not. Driver-portable (no JSON expression — plain kind/status columns).
        $pendingExists = Suggestion::query()
            ->where('kind', 'ad_optimisation')
            ->where('status', Suggestion::STATUS_PENDING)
            ->exists();

        if ($pendingExists) {
            Log::info('ad_optimisation.skip_pending_exists', [
                'reason' => 'a pending ad_optimisation Suggestion is already awaiting operator action',
            ]);
            $this->info('A pending ad_optimisation suggestion already exists — skipping (no dispatch, no LLM spend). Action the pending advice to unblock the next run.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Dry-run: {$recentRows} GA4 row(s) in the last {$lookbackDays}d — a live run would dispatch one AdOptimisationAgent analysis. No dispatch.");

            return self::SUCCESS;
        }

        $correlationId = (string) Str::uuid();
        RunAdOptimisationJob::dispatch($correlationId);

        $this->info("Dispatched AdOptimisationAgent run on `agents` queue ({$recentRows} GA4 row(s) in the last {$lookbackDays}d; correlation {$correlationId}).");

        return self::SUCCESS;
    }
}
