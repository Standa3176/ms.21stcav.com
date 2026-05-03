<?php

declare(strict_types=1);

namespace App\Filament\Clusters\HorizonCluster\Pages;

use App\Filament\Clusters\HorizonCluster;
use Filament\Pages\Page;

/**
 * Phase 7 Plan 02 — D-03 Horizon Cluster sub-page (Completed Jobs).
 *
 * Iframe-embeds /horizon/completed-jobs inside Filament chrome via the
 * shared cluster view. Admin-only.
 */
class HorizonCompletedJobsPage extends Page
{
    protected static ?string $cluster = HorizonCluster::class;

    protected static string $view = 'filament.clusters.horizon.embed';

    protected static ?string $slug = 'completed-jobs';

    protected static ?string $title = 'Completed Jobs';

    protected static ?string $navigationLabel = 'Completed Jobs';

    protected static ?string $navigationIcon = 'heroicon-o-check-circle';

    protected static ?int $navigationSort = 60;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['horizonPath' => '/horizon/completed-jobs'];
    }
}
