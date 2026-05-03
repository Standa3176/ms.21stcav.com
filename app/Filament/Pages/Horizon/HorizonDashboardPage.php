<?php

declare(strict_types=1);

namespace App\Filament\Pages\Horizon;

use App\Filament\Pages\Horizon\Concerns\HasHorizonRedisStatus;
use App\Filament\Widgets\Horizon\HorizonOverviewStatsWidget;
use Filament\Pages\Page;

/**
 * Phase 7 Plan 02 — D-03 native Horizon Pages (post-09.1 follow-up #3).
 *
 * Parent of the Horizon nav group in the admin sidebar. Uses Filament 3.3
 * native parent-child nav: this page declares $navigationLabel='Horizon'
 * and the 7 sibling pages set $navigationParentItem='Horizon' so Filament
 * renders a single collapsible "Horizon" entry in the Operations group.
 *
 * URL: /admin/horizon → renders 8 stat tiles via HorizonOverviewStatsWidget
 * mirroring Horizon's own DashboardStatsController:
 *   Jobs Per Minute · Jobs Past Hour · Failed Jobs (7d) · Status
 *   Total Processes · Max Wait Time · Max Runtime · Max Throughput
 *
 * Admin-only (defence in depth — page-level canAccess + role-gated /horizon
 * route gate from HorizonServiceProvider). Pricing_manager / sales / read_only
 * never see the nav entry; direct-URL hits return Filament 403.
 *
 * Redis dependency: see {@see HasHorizonRedisStatus} — without Redis the
 * page renders a warning banner and skips the stat tiles.
 */
class HorizonDashboardPage extends Page
{
    use HasHorizonRedisStatus;

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Horizon';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'horizon';

    protected static ?string $title = 'Horizon Dashboard';

    protected static string $view = 'filament.pages.horizon.dashboard';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        // Skip widget registration when Redis is unreachable — every widget
        // call would throw RedisException and surface as a 500 cascade. The
        // Blade view shows the warning banner from getRedisBannerData() instead.
        if ($this->getRedisBannerData() !== null) {
            return [];
        }

        return [
            HorizonOverviewStatsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 4;
    }
}
