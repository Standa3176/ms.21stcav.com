<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Phase 7 Plan 02 — Row 1 freshness tile: 24h CRM push success rate.
 *
 * Reads `crm_push_success_rate` metric_key. Bitrix D-11 retry semantics
 * (Phase 4 Plan 03) mean the retry bucket is informational — most retries
 * succeed within 3 attempts. Failed bucket is the alarming one.
 */
final class CrmPushSuccessRateWidget extends StatsOverviewWidget
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
        $snapshot = DashboardSnapshot::where('metric_key', 'crm_push_success_rate')->first();

        if ($snapshot === null || $snapshot->computed_at === null) {
            return [
                Stat::make('CRM push success', '—')
                    ->description('No data yet')
                    ->descriptionIcon('heroicon-m-arrow-path')
                    ->color('gray'),
            ];
        }

        $payload = (array) $snapshot->metric_value_json;
        $rate = $payload['success_rate_percent'] ?? null;
        $total = (int) ($payload['total'] ?? 0);

        if ($total === 0 || $rate === null) {
            return [
                Stat::make('CRM push success', '—')
                    ->description('No CRM pushes in last 24h')
                    ->descriptionIcon('heroicon-m-paper-airplane')
                    ->color('gray'),
            ];
        }

        // Threshold logic: ≥99 success, 95–99 warning, <95 danger.
        $rateColor = $rate >= 99 ? 'success' : ($rate >= 95 ? 'warning' : 'danger');
        $retryCount = (int) ($payload['retry_count'] ?? 0);
        $failedCount = (int) ($payload['failed_count'] ?? 0);

        $stale = $snapshot->isStale();
        $ring = $stale ? ['class' => 'ring-2 ring-amber-400'] : [];

        return [
            Stat::make('CRM success', $rate . '%')
                ->description($total . ' pushes in 24h')
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color($rateColor)
                ->extraAttributes($ring),
            Stat::make('Retries', (string) $retryCount)
                ->description('Transient Bitrix failures')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color($retryCount > 0 ? 'warning' : 'gray'),
            Stat::make('Failed', (string) $failedCount)
                ->description('Human-review in suggestions inbox')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failedCount > 0 ? 'danger' : 'gray'),
        ];
    }
}
