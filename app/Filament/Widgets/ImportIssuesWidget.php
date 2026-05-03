<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Phase 7 Plan 02 — Row 2 actions tile: import + ingest issues.
 *
 * Reads `import_issues` metric_key. Three numeric tiles covering the main
 * failure modes across Phase 5 CSV pipeline + Phase 6 auto-create:
 *   - Unresolved CSV parse errors (csv_parse_errors where resolved_at IS NULL)
 *   - Quarantined CSVs (competitor_ingest_runs where status='failed')
 *   - Low-completeness auto-create drafts (Phase 6 — completeness_score<50)
 */
final class ImportIssuesWidget extends StatsOverviewWidget
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
        $snapshot = DashboardSnapshot::where('metric_key', 'import_issues')->first();

        if ($snapshot === null || $snapshot->computed_at === null) {
            return [
                Stat::make('Import issues', '—')
                    ->description('No data yet')
                    ->descriptionIcon('heroicon-m-arrow-path')
                    ->color('gray'),
            ];
        }

        $payload = (array) $snapshot->metric_value_json;
        $parseErrors = (int) ($payload['unresolved_csv_parse_errors'] ?? 0);
        $quarantined = (int) ($payload['quarantined_csvs'] ?? 0);
        $lowComp = (int) ($payload['low_completeness_drafts'] ?? 0);

        // Threshold logic: 0 success, 1–10 warning, >10 danger.
        $bucketColor = fn (int $count) => $count === 0
            ? 'success'
            : ($count <= 10 ? 'warning' : 'danger');

        $stale = $snapshot->isStale();
        $ring = $stale ? ['class' => 'ring-2 ring-amber-400'] : [];

        return [
            Stat::make('CSV parse errors', (string) $parseErrors)
                ->description('Unresolved ingest failures')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($bucketColor($parseErrors))
                ->extraAttributes($ring),
            Stat::make('Quarantined CSVs', (string) $quarantined)
                ->description('Failed ingest runs')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($bucketColor($quarantined)),
            Stat::make('Low-completeness drafts', (string) $lowComp)
                ->description('Score <50 — auto-create')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($bucketColor($lowComp)),
        ];
    }
}
