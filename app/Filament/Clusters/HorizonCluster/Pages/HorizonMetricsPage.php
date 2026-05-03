<?php

declare(strict_types=1);

namespace App\Filament\Clusters\HorizonCluster\Pages;

use App\Filament\Clusters\HorizonCluster;
use Filament\Pages\Page;

/**
 * Phase 7 Plan 02 — D-03 Horizon Cluster sub-page (Metrics).
 *
 * Iframe-embeds /horizon/metrics inside Filament chrome via the
 * shared cluster view. Admin-only.
 */
class HorizonMetricsPage extends Page
{
    protected static ?string $cluster = HorizonCluster::class;

    protected static string $view = 'filament.clusters.horizon.embed';

    protected static ?string $slug = 'metrics';

    protected static ?string $title = 'Metrics';

    protected static ?string $navigationLabel = 'Metrics';

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['horizonPath' => '/horizon/metrics'];
    }
}
