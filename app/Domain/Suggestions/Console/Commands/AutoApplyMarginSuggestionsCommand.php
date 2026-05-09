<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
use App\Domain\Suggestions\Models\Suggestion;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Auto-apply pending margin_change Suggestions whose proposed delta meets the
 * configurable threshold — replaces the legacy WP plugin's setPer() ≥ 8%
 * auto-apply behaviour.
 *
 * Threshold lives in config/pricing.php (auto_apply_threshold_bps; default
 * 800 = 8.00 percentage points). Suggestions are eligible when:
 *   - kind = 'margin_change'
 *   - status = 'pending'
 *   - auto_apply_eligible = true        (set by MarginAnalyser)
 *   - |new_margin_bps - old_margin_bps| >= threshold_bps
 *
 * Each eligible row is dispatched as ApplySuggestionJob — same path as a
 * human-clicked Approve, so MarginChangeApplier + the audit trail stay
 * unchanged. Idempotent via Suggestion.STATUS_APPLIED guard.
 *
 * Schedule: routes/console.php registers this Mon-Fri at 07:30 Europe/London,
 * after the 07:00 supplier:db-sync so freshly-recomputed margins are in scope.
 */
final class AutoApplyMarginSuggestionsCommand extends BaseCommand
{
    protected $signature = 'suggestions:auto-apply
        {--dry-run : Report what would dispatch without enqueuing jobs}
        {--limit=0 : Stop after N dispatches (0 = no limit)}';

    protected $description = 'Auto-apply margin_change Suggestions whose delta meets pricing.auto_apply_threshold_bps.';

    protected function perform(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $threshold = (int) config('pricing.auto_apply_threshold_bps', 800);

        $this->info(sprintf(
            'suggestions:auto-apply — %s threshold=%d bps (%s%%)',
            $dryRun ? 'DRY-RUN' : 'LIVE',
            $threshold,
            number_format($threshold / 100, 2),
        ));

        $eligible = 0;
        $belowThreshold = 0;
        $dispatched = 0;
        $missingPayload = 0;

        Suggestion::query()
            ->where('kind', 'margin_change')
            ->where('status', Suggestion::STATUS_PENDING)
            ->where('auto_apply_eligible', true)
            ->orderBy('proposed_at')
            ->cursor()
            ->each(function (Suggestion $suggestion) use (
                $threshold, $dryRun, $limit,
                &$eligible, &$belowThreshold, &$dispatched, &$missingPayload
            ): bool {
                $eligible++;
                $payload = (array) $suggestion->payload;
                $oldBps = $payload['old_margin_basis_points'] ?? null;
                $newBps = $payload['new_margin_basis_points'] ?? null;

                if ($oldBps === null || $newBps === null) {
                    $missingPayload++;

                    return true;
                }

                $delta = abs((int) $newBps - (int) $oldBps);
                if ($delta < $threshold) {
                    $belowThreshold++;

                    return true;
                }

                if (! $dryRun) {
                    ApplySuggestionJob::dispatch((string) $suggestion->id);
                }
                $dispatched++;

                return ! ($limit > 0 && $dispatched >= $limit);
            });

        $this->info(str_repeat('-', 60));
        $this->info(sprintf(
            'eligible=%d below_threshold=%d missing_payload=%d %s=%d',
            $eligible,
            $belowThreshold,
            $missingPayload,
            $dryRun ? 'would_dispatch' : 'dispatched',
            $dispatched,
        ));

        return SymfonyCommand::SUCCESS;
    }
}
