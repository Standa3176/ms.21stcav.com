<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Phase 09.1 Plan 01 — IntegrationHealthWidget (D-15).
 *
 * 5 traffic-light tiles (one per integration kind) on the home dashboard.
 * Reads from dashboard_snapshots metric_key='integration_health' — populated
 * by SnapshotAggregator::computeIntegrationHealth() during dashboard:refresh.
 * Zero live aggregation at page render.
 *
 * RBAC: admin only. The credentials state is operational config and a broader
 * audience would invite confusion (sales doesn't care that the Anthropic API
 * key is set — they care that quotes work).
 */
final class IntegrationHealthWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $snapshot = DashboardSnapshot::where('metric_key', 'integration_health')->first();
        $payload = is_array($snapshot?->metric_value_json) ? $snapshot->metric_value_json : [];

        $stats = [];
        foreach (IntegrationCredentialKind::cases() as $kind) {
            $entry = $payload[$kind->value] ?? ['status' => 'unknown', 'last_test_at' => null];
            $status = (string) ($entry['status'] ?? 'unknown');

            $color = match ($status) {
                'ok' => 'success',
                'failed' => 'danger',
                default => 'gray',
            };

            $description = ($entry['last_test_at'] ?? null)
                ? 'Tested ' . Carbon::parse($entry['last_test_at'])->diffForHumans()
                : 'Never tested';

            $stats[] = Stat::make($kind->label(), ucfirst($status))
                ->description($description)
                ->color($color);
        }

        return $stats;
    }
}
