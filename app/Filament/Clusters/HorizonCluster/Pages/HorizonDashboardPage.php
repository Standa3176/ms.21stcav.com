<?php

declare(strict_types=1);

namespace App\Filament\Clusters\HorizonCluster\Pages;

use App\Filament\Clusters\HorizonCluster;
use Filament\Pages\Page;

/**
 * Phase 7 Plan 02 — D-03 Horizon Cluster sub-page (Dashboard).
 *
 * Default child of {@see HorizonCluster} — lowest navigationSort (10) so
 * clicking the cluster parent in the sidebar lands here. Iframe-embeds
 * /horizon/dashboard inside Filament chrome via the shared cluster view.
 */
class HorizonDashboardPage extends Page
{
    protected static ?string $cluster = HorizonCluster::class;

    protected static string $view = 'filament.clusters.horizon.embed';

    protected static ?string $slug = 'dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['horizonPath' => '/horizon/dashboard'];
    }
}
