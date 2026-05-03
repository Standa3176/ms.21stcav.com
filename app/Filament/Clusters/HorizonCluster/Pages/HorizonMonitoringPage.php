<?php

declare(strict_types=1);

namespace App\Filament\Clusters\HorizonCluster\Pages;

use App\Filament\Clusters\HorizonCluster;
use Filament\Pages\Page;

/**
 * Phase 7 Plan 02 — D-03 Horizon Cluster sub-page (Monitoring).
 *
 * Iframe-embeds /horizon/monitoring inside Filament chrome via the
 * shared cluster view. Admin-only.
 */
class HorizonMonitoringPage extends Page
{
    protected static ?string $cluster = HorizonCluster::class;

    protected static string $view = 'filament.clusters.horizon.embed';

    protected static ?string $slug = 'monitoring';

    protected static ?string $title = 'Monitoring';

    protected static ?string $navigationLabel = 'Monitoring';

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['horizonPath' => '/horizon/monitoring'];
    }
}
