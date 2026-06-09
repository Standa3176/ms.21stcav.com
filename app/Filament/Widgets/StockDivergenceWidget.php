<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Quick task 260609-nku — Home dashboard tile: Stock Divergence.
 *
 * Surfaces the latest weekly audit snapshot's divergent SKU count + total
 * phantom units with click-through to /admin/stock-divergence for triage.
 *
 * Reads from the `stock_divergence` snapshot key populated by
 * SnapshotAggregator::computeStockDivergence during dashboard:refresh
 * (5-min cadence) — widget render path NEVER hits stock_divergence_findings
 * directly, so the dashboard stays a single indexed lookup per tile.
 *
 * Color logic mirrors CategoryAuditWidget:
 *   - success (green): count = 0 — no phantom stock detected
 *   - danger (red):    count > 0 — leaks exist; ops should bulk-resync
 *   - warning (amber): total_phantom_units > 0 stat (always render warning
 *                      when there's any phantom inventory, even if low SKU
 *                      count, because each phantom unit is a real backorder
 *                      risk).
 *
 * RBAC: admin + pricing_manager only. Sales / read_only see silent absence
 * (the dashboard layout adapts; no 403). Mirrors CategoryAuditWidget exactly.
 */
final class StockDivergenceWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $snapshot = DashboardSnapshot::where('metric_key', 'stock_divergence')->first();
        $payload = is_array($snapshot?->metric_value_json) ? $snapshot->metric_value_json : [];

        $count = (int) ($payload['count'] ?? 0);
        $totalPhantom = (int) ($payload['total_phantom_units'] ?? 0);
        $lastRunAt = isset($payload['last_run_at']) && $payload['last_run_at'] !== null
            ? \Carbon\Carbon::parse((string) $payload['last_run_at'])
            : null;

        return [
            Stat::make('Divergent SKUs', number_format($count))
                ->description('Phantom stock detected')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($count > 0 ? 'danger' : 'success')
                ->url('/admin/stock-divergence'),

            Stat::make('Total phantom units', number_format($totalPhantom))
                ->description('Woo overcount across all SKUs')
                ->color($totalPhantom > 0 ? 'warning' : 'success'),

            Stat::make('Last audited', $lastRunAt !== null ? $lastRunAt->diffForHumans() : 'never')
                ->description('Next run: Mon 09:15 London'),
        ];
    }
}
