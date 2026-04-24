<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Phase 7 Plan 02 — Row 3 system-health tile: CUT-01 parity percentage.
 *
 * Reads `sync_diffs_parity` metric_key. THE central cutover tile — Plan 07-05's
 * `cutover:divergence-scan` writes the underlying sync_diffs rows (provider=
 * 'divergence-scan'); SnapshotAggregator converts that rowset into a parity %
 * against the threshold from config/cutover.php.
 *
 * Traffic light:
 *   green  — parity >= threshold (default 99%)
 *   red    — parity < threshold
 *   amber  — unknown (no products scanned yet)
 *
 * Ops MUST see 7 consecutive green days before flipping WOO_WRITE_ENABLED=true
 * per the Phase 7 cutover runbook (D-19).
 */
final class SyncDiffsParityWidget extends StatsOverviewWidget
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
        $snapshot = DashboardSnapshot::where('metric_key', 'sync_diffs_parity')->first();

        if ($snapshot === null) {
            return [
                Stat::make('Shadow-mode parity', '—')
                    ->description('Awaiting first divergence-scan')
                    ->color('gray'),
            ];
        }

        $payload = (array) $snapshot->metric_value_json;
        $parity = $payload['parity_percent'] ?? null;
        $threshold = (int) ($payload['threshold_percent'] ?? 99);
        $window = (int) ($payload['window_days'] ?? 7);
        $diverged = (int) ($payload['diverged_rows'] ?? 0);

        $color = match ($payload['traffic_light'] ?? 'amber') {
            'green' => 'success',
            'red' => 'danger',
            default => 'warning',
        };

        $value = $parity === null ? '—' : ($parity . '%');

        $stale = $snapshot->isStale();
        $ring = $stale ? ['class' => 'ring-2 ring-amber-400'] : [];

        return [
            Stat::make('Shadow-mode parity', $value)
                ->description(sprintf(
                    'Threshold: %d%% · %d-day window · %d diverged rows',
                    $threshold,
                    $window,
                    $diverged,
                ))
                ->color($color)
                ->extraAttributes($ring),
        ];
    }
}
