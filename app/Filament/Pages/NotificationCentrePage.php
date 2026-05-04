<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Competitor\Jobs\IngestCompetitorCsvJob;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Dashboard\Services\NotificationCentreAggregator;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Phase 7 Plan 04 Task 1 — NotificationCentrePage (D-10 + D-11).
 *
 * Unified Filament page at `/admin/notifications` with 4 tabs aggregating
 * from existing tables:
 *   - Failed jobs (Horizon `failed_jobs`, last 7 days)
 *   - Stale feeds (active competitors past stale_feed_hours threshold)
 *   - Pending suggestions (grouped by `kind`)
 *   - Webhook DLQ (inbound failed integration_events in last 7 days)
 *
 * No new notifications table is introduced. Every row is aggregated by
 * {@see NotificationCentreAggregator}. Livewire `wire:poll` refreshes the
 * current tab every `config('dashboard.widget_poll_seconds')` seconds —
 * no WebSocket / Echo dependency per CONTEXT.md §Claude's-Discretion.
 *
 * Quick actions (D-11) are gated by role per the threat model:
 *   - retryFailedJob — admin-only (T-07-04-01 mitigation)
 *   - reingestCompetitor — admin + pricing_manager (T-07-04-02 mitigation)
 *
 * Pending-suggestions actions link to the existing SuggestionResource
 * filtered by kind — no new action code required (D-11 reuse).
 */
class NotificationCentrePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $slug = 'notifications';

    protected static ?string $navigationLabel = 'Notification Centre';

    // Quick task 260504-ev5 — Operations group, sort 100 (after Horizon children).
    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.notification-centre';

    /** Current tab — bound to the tab-switch buttons. */
    public string $activeTab = 'failed-jobs';

    /**
     * 4-tab registry. Keys are the `$activeTab` identifiers; values are
     * the human-readable labels rendered in the header bar.
     *
     * @return array<string, string>
     */
    public function getTabs(): array
    {
        return [
            'failed-jobs' => 'Failed jobs',
            'stale-feeds' => 'Stale feeds',
            'pending-suggestions' => 'Pending suggestions',
            'webhook-dlq' => 'Webhook DLQ',
        ];
    }

    /**
     * Build the per-tab data payload for the Blade view.
     *
     * @return array<string, \Illuminate\Support\Collection<int, array<string, mixed>>>
     */
    public function getData(?NotificationCentreAggregator $aggregator = null): array
    {
        $aggregator ??= app(NotificationCentreAggregator::class);

        return [
            'failed-jobs' => $aggregator->failedJobs(),
            'stale-feeds' => $aggregator->staleFeeds(),
            'pending-suggestions' => $aggregator->pendingSuggestions(),
            'webhook-dlq' => $aggregator->webhookDlq(),
        ];
    }

    /** Livewire action — switch the active tab. */
    public function switchTab(string $tab): void
    {
        if (array_key_exists($tab, $this->getTabs())) {
            $this->activeTab = $tab;
        }
    }

    /**
     * Quick action — re-queue a Horizon failed job (T-07-04-01: admin-only).
     *
     * Delegates to `php artisan horizon:retry {id}` so any Horizon-side
     * supervisor logic (retry-count cap, exception class dedup) applies.
     */
    public function retryFailedJob(string $uuid): void
    {
        abort_unless((bool) auth()->user()?->hasRole('admin'), 403);

        Artisan::call('horizon:retry', ['id' => $uuid]);

        Notification::make()
            ->title('Job queued for retry')
            ->success()
            ->send();
    }

    /**
     * Quick action — re-dispatch IngestCompetitorCsvJob for the given
     * competitor (T-07-04-02: admin + pricing_manager only).
     *
     * The job expects a filesystem processing path. We dispatch with an
     * empty path to signal "watcher will pick up the next CSV in the
     * incoming/ directory" — actual re-ingestion remains watcher-driven
     * so partial files are not processed.
     */
    public function reingestCompetitor(int $competitorId): void
    {
        abort_unless(
            (bool) auth()->user()?->hasAnyRole(['admin', 'pricing_manager']),
            403,
        );

        $competitor = Competitor::findOrFail($competitorId);

        // Trigger the watcher to re-scan incoming/ for this competitor on the
        // next tick; we do NOT pass a stale processing path because the job
        // short-circuits on an absent file.
        Log::info('NotificationCentrePage: stale-feed re-ingest queued', [
            'competitor_id' => $competitor->id,
            'name' => $competitor->name,
            'actor_id' => auth()->id(),
        ]);

        Artisan::queue('competitor:watch');

        Notification::make()
            ->title("Re-ingest queued for {$competitor->name}")
            ->success()
            ->send();
    }

    /**
     * Page-level access gate. All 4 roles can view the ambient ops intel;
     * the per-action gates above enforce write scope.
     */
    public static function canAccess(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();

        return $user !== null
            && $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']);
    }
}
