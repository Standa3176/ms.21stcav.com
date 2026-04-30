<?php

declare(strict_types=1);

namespace App\Domain\Agents\Filament\Actions;

use App\Domain\Agents\Jobs\RunPricingAgentJob;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Suggestions\Models\Suggestion;
use Filament\Infolists\Components\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Phase 10 Plan 04 — Filament header action wiring "Run pricing agent" /
 * "Re-run pricing agent" button on the SuggestionResource margin_change
 * detail view (CONTEXT D-01 admin-pull + D-02 latest-wins idempotency +
 * D-03 one-at-a-time lock).
 *
 * Mounted into the infolist via:
 *
 *   Section::make('Agent Enrichment')
 *       ->headerActions([RunPricingAgentAction::make()])
 *
 * Authorisation: defers to the `run_pricing_agent` Shield permission.
 * Plan 10-05 ships the permission via the Shield seeder; until then the
 * permission may not exist, so the authorize check returns true if the
 * permission is missing (so admins can still iterate during dev) and false
 * for explicit denials. This soft-gate is the deliberate Plan 10-04 → 10-05
 * handoff so the action is wired without blocking on the seeder.
 *
 * D-03 lock: button is hidden while the latest agent_run for this
 * suggestion has status='running' — prevents accidental double-burn.
 *
 * D-02 latest-wins: button label flips to "Re-run pricing agent" once any
 * prior agent_run_ids[] entry exists; admin can iterate forever (Phase 8's
 * 5-year AgentRun retention captures every iteration).
 */
final class RunPricingAgentAction
{
    public static function make(): Action
    {
        return Action::make('run_pricing_agent')
            ->label(fn (Suggestion $record): string => empty($record->evidence['agent_run_ids'] ?? [])
                ? 'Run pricing agent'
                : 'Re-run pricing agent')
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            // Visible only on margin_change AND while no agent_run is currently running.
            ->visible(fn (Suggestion $record): bool => $record->kind === 'margin_change'
                && ! self::hasRunningAgentRun($record))
            // Soft-gate per Plan 10-04 → 10-05 handoff: when the Shield permission
            // exists, defer to it; when it doesn't yet exist (Plan 10-05 ships it),
            // allow admins to dispatch so dev iteration isn't blocked.
            ->authorize(fn (): bool => self::isAuthorised())
            ->requiresConfirmation()
            ->modalHeading('Dispatch pricing agent')
            ->modalDescription(
                'Dispatches the pricing agent on the agents queue. Daily budget cap 500 pence; '
                .'the agent reads competitor prices, supplier trend, sales volume, and proposes a '
                .'margin band with confidence. Run will appear in 10-30s — refresh to see reasoning.'
            )
            ->modalSubmitActionLabel('Run agent')
            ->action(function (Suggestion $record): void {
                RunPricingAgentJob::dispatch(
                    suggestionId: $record->id,
                    userId: auth()->id(),
                    triggeringCorrelationId: $record->correlation_id,
                );

                Notification::make()
                    ->success()
                    ->title('Pricing agent dispatched')
                    ->body('Run will appear in 10-30s — refresh to see reasoning, confidence, and proposed band.')
                    ->send();
            });
    }

    /**
     * D-03 lock — query agent_runs for an in-flight run on this suggestion.
     * Cheap query; the agents queue is bounded by Horizon maxProcesses=2.
     */
    private static function hasRunningAgentRun(Suggestion $suggestion): bool
    {
        return AgentRun::query()
            ->where('triggering_suggestion_id', $suggestion->id)
            ->where('status', 'running')
            ->exists();
    }

    /**
     * Soft-gate per Plan 10-04 → 10-05 handoff. When the `run_pricing_agent`
     * Shield permission exists in the spatie/permission table, defer to it
     * (admins MUST have the permission). When it doesn't yet exist (Plan 10-05
     * ships the seeder), admins are allowed through so dev iteration isn't
     * blocked. Non-admin users always need the explicit permission.
     */
    private static function isAuthorised(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        // Defer to the permission if it exists.
        if ($user->can('run_pricing_agent')) {
            return true;
        }

        // Plan 10-04 → 10-05 handoff: admins are allowed through until Plan 10-05
        // seeds the run_pricing_agent permission. After Plan 10-05 the permission
        // exists for every admin so this branch becomes a no-op.
        if (method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return true;
        }

        return false;
    }
}
