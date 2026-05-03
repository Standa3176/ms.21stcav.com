<?php

declare(strict_types=1);

namespace App\Filament\Clusters\HorizonCluster\Pages;

use App\Filament\Clusters\HorizonCluster;
use Filament\Pages\Page;

/**
 * Phase 7 Plan 02 — D-03 Horizon Cluster sub-page (Failed Jobs).
 *
 * Iframe-embeds /horizon/failed-jobs inside Filament chrome via the
 * shared cluster view. Admin-only.
 */
class HorizonFailedJobsPage extends Page
{
    protected static ?string $cluster = HorizonCluster::class;

    protected static string $view = 'filament.clusters.horizon.embed';

    protected static ?string $slug = 'failed-jobs';

    protected static ?string $title = 'Failed Jobs';

    protected static ?string $navigationLabel = 'Failed Jobs';

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-circle';

    protected static ?int $navigationSort = 80;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['horizonPath' => '/horizon/failed-jobs'];
    }
}
