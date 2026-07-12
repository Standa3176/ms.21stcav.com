<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Filament\Widgets;

use App\Domain\Integrations\Models\GaChannelMetric;
use App\Domain\Integrations\Support\MarketingDateRange;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Phase 15 Plan 15b-02 Task 2 — Marketing overview stats (READ-ONLY).
 *
 * PURE PRESENTATION over ga_channel_metrics_daily (15a-02). Four tiles —
 * Sessions, Transactions, Revenue (£ from pennies), Top channel by revenue —
 * over the window chosen in the page's date-range filter (260712-mdr; default
 * 90d). No writes, no Google calls, no data pull.
 *
 * Zero-safe / empty-state: with ZERO GaChannelMetric rows every tile renders a
 * friendly value (0 / £0.00 / —) — never an error, no divide-by-zero (pennies
 * are divided by 100 for DISPLAY only).
 *
 * Driver-portable: SUM + GROUP BY + whereBetween('date', …) only (no MySQL-only
 * date/JSON functions) so SQLite tests and MariaDB prod agree. The window is
 * resolved through the shared MarketingDateRange resolver so this widget and the
 * revenue-trend chart always agree on the same [from, to].
 */
final class MarketingOverviewStats extends StatsOverviewWidget
{
    // 260712-mdr — receive the page's `filters` state (range/from/to).
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        // Mirror the GA4 Channels viewer — any authed workspace user may read.
        return auth()->user()?->can('viewAny', GaChannelMetric::class) ?? false;
    }

    protected function getStats(): array
    {
        $range = $this->resolveRange();
        $label = $range->label;

        $base = GaChannelMetric::query()->whereBetween('date', [$range->from, $range->to]);

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
                ->description($label)
                ->descriptionIcon('heroicon-m-users')
                ->color('gray'),
            Stat::make('Transactions', number_format($transactions))
                ->description($label)
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color($transactions > 0 ? 'success' : 'gray'),
            Stat::make('Revenue', $this->money($revenuePennies))
                ->description($label)
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($revenuePennies > 0 ? 'success' : 'gray'),
            Stat::make('Top channel by revenue', $topChannelLabel)
                ->description($label)
                ->descriptionIcon('heroicon-m-trophy')
                ->color('primary'),
        ];
    }

    /** Resolve the page-filter state to a concrete window (shared resolver). */
    private function resolveRange(): MarketingDateRange
    {
        return MarketingDateRange::resolve(
            $this->filters['range'] ?? null,
            $this->filters['from'] ?? null,
            $this->filters['to'] ?? null,
        );
    }

    /** Pennies → £ for DISPLAY only (money stored as integer pennies). */
    private function money(int $pennies): string
    {
        return '£'.number_format($pennies / 100, 2);
    }
}
