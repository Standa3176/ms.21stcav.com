<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Filament\Widgets;

use App\Domain\Integrations\Models\GaChannelMetric;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Phase 15 Plan 15b-02 Task 2 — Marketing overview stats (READ-ONLY).
 *
 * PURE PRESENTATION over ga_channel_metrics_daily (15a-02). Four tiles over the
 * last 30 days: Sessions, Transactions, Revenue (£ from pennies), Top channel by
 * revenue. No writes, no Google calls, no data pull.
 *
 * Zero-safe / empty-state: with ZERO GaChannelMetric rows every tile renders a
 * friendly value (0 / £0.00 / —) — never an error, no divide-by-zero (pennies
 * are divided by 100 for DISPLAY only).
 *
 * Driver-portable: SUM + GROUP BY + whereBetween('date', …) only (no MySQL-only
 * date/JSON functions) so SQLite tests and MariaDB prod agree.
 */
final class MarketingOverviewStats extends StatsOverviewWidget
{
    /** Trailing-window length in days (inclusive of today). */
    private const WINDOW_DAYS = 30;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        // Mirror the GA4 Channels viewer — any authed workspace user may read.
        return auth()->user()?->can('viewAny', GaChannelMetric::class) ?? false;
    }

    protected function getStats(): array
    {
        [$from, $today] = $this->window();

        $base = GaChannelMetric::query()->whereBetween('date', [$from, $today]);

        $sessions = (int) (clone $base)->sum('sessions');
        $transactions = (int) (clone $base)->sum('transactions');
        $revenuePennies = (int) (clone $base)->sum('purchase_revenue_pennies');

        $topChannel = (clone $base)
            ->select('channel_group')
            ->selectRaw('SUM(purchase_revenue_pennies) as rev')
            ->groupBy('channel_group')
            ->orderByDesc('rev')
            ->first();

        $topChannelLabel = $topChannel?->channel_group ?: '—';

        return [
            Stat::make('Sessions', number_format($sessions))
                ->description('Last '.self::WINDOW_DAYS.' days')
                ->descriptionIcon('heroicon-m-users')
                ->color('gray'),
            Stat::make('Transactions', number_format($transactions))
                ->description('Last '.self::WINDOW_DAYS.' days')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color($transactions > 0 ? 'success' : 'gray'),
            Stat::make('Revenue', $this->money($revenuePennies))
                ->description('Last '.self::WINDOW_DAYS.' days')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($revenuePennies > 0 ? 'success' : 'gray'),
            Stat::make('Top channel by revenue', $topChannelLabel)
                ->description('Last '.self::WINDOW_DAYS.' days')
                ->descriptionIcon('heroicon-m-trophy')
                ->color('primary'),
        ];
    }

    /**
     * @return array{0: string, 1: string} [from, today] as Y-m-d date strings.
     */
    private function window(): array
    {
        $today = Carbon::today()->toDateString();
        $from = Carbon::today()->subDays(self::WINDOW_DAYS - 1)->toDateString();

        return [$from, $today];
    }

    /** Pennies → £ for DISPLAY only (money stored as integer pennies). */
    private function money(int $pennies): string
    {
        return '£'.number_format($pennies / 100, 2);
    }
}
