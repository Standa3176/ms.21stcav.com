<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\WooMaintenanceGapsWidget;
use App\Models\User;
use Filament\Pages\Page;

/**
 * Quick task 260707-w2w — Pass 1 of the Woo Maintenance section.
 *
 * A new 'Woo Maintenance' nav group whose Overview page shows the
 * operator an at-a-glance summary of catalogue gaps over products LIVE
 * on Woo: how many are missing images / EAN / stock status / brand /
 * category, plus the total live-product count. Counts come from the
 * cached, driver-portable ProductGapReport (single source of truth).
 *
 * Admin-only — mirrors AutoCreateHealthPage's gate, since the Pass-2
 * drill-down will hang real-money fix actions off these same gaps.
 *
 * Additive — no existing page/command/model behaviour changes. The page
 * and its header widget auto-discover (Filament scans app/Filament/Pages
 * + app/Filament/Widgets).
 */
final class WooMaintenanceOverviewPage extends Page
{
    protected static ?string $slug = 'woo-maintenance';

    protected static ?string $navigationGroup = 'Woo Maintenance';

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Maintenance Overview';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.woo-maintenance-overview';

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->hasRole('admin');
    }

    protected function getHeaderWidgets(): array
    {
        return [WooMaintenanceGapsWidget::class];
    }
}
