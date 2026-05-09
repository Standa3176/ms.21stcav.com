<?php

declare(strict_types=1);

namespace App\Filament\Pages\Horizon;

use App\Filament\Pages\Horizon\Concerns\HasHorizonRedisStatus;
use App\Filament\Widgets\Horizon\JobsThroughputChart;
use App\Filament\Widgets\Horizon\RuntimePerJobChart;
use Filament\Pages\Page;

/**
 * Phase 7 Plan 02 — D-03 native Horizon Pages (post-09.1 follow-up #3).
 *
 * /admin/horizon/metrics — two header widgets (charts):
 *   - JobsThroughputChart — line chart, jobs processed per minute (24h window)
 *   - RuntimePerJobChart  — bar chart, average runtime per job class
 *
 * Both widgets read from Laravel\Horizon\Contracts\MetricsRepository
 * (measuredJobs() + snapshotsForJob()) — same data source as Horizon's own
 * /horizon/metrics page.
 *
 * Admin-only.
 */
class HorizonMetricsPage extends Page
{
    use HasHorizonRedisStatus;

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Metrics';

    protected static ?string $navigationParentItem = 'Horizon';

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'horizon/metrics';

    protected static ?string $title = 'Metrics';

    protected static string $view = 'filament.pages.horizon.metrics';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        if ($this->getRedisBannerData() !== null) {
            return [];
        }

        return [
            JobsThroughputChart::class,
            RuntimePerJobChart::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
