<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Phase 7 Plan 02 — Row 3 system-health tile: weekly digest status.
 *
 * Reads `weekly_report_status` metric_key. Plan 07-04 populates this on every
 * digest send; SnapshotAggregator falls back to an empty-state payload with the
 * next-Monday ETA when no digest has shipped yet.
 *
 * 2 tiles — Last sent ("3 days ago" / "Not yet sent") + Next run ("Monday 07:00").
 */
final class WeeklyReportStatusWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 1;

    public function __construct()
    {
        static::$pollingInterval = (int) config('dashboard.widget_poll_seconds', 60) . 's';
    }

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', DashboardSnapshot::class) ?? false;
    }

    protected function getStats(): array
    {
        $snapshot = DashboardSnapshot::where('metric_key', 'weekly_report_status')->first();

        if ($snapshot === null) {
            return [
                Stat::make('Weekly digest', '—')
                    ->description('Awaiting first dashboard:refresh')
                    ->color('gray'),
            ];
        }

        $payload = (array) $snapshot->metric_value_json;
        $lastSent = $payload['last_sent_at'] ?? null;
        $nextRun = $payload['next_run_iso'] ?? null;
        $recipients = (int) ($payload['recipient_count'] ?? 0);

        $lastSentLabel = $lastSent ? Carbon::parse($lastSent)->diffForHumans() : 'Not yet sent';
        $nextRunLabel = $nextRun ? Carbon::parse($nextRun)->format('D H:i') : '—';

        $stale = $snapshot->isStale();
        $ring = $stale ? ['class' => 'ring-2 ring-amber-400'] : [];

        return [
            Stat::make('Last sent', $lastSentLabel)
                ->description(sprintf('%d recipients', $recipients))
                ->color($lastSent ? 'success' : 'gray')
                ->extraAttributes($ring),
            Stat::make('Next run', $nextRunLabel)
                ->description('Monday 07:00 Europe/London')
                ->color('gray'),
        ];
    }
}
