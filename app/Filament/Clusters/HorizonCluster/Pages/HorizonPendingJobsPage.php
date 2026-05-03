<?php

declare(strict_types=1);

namespace App\Filament\Clusters\HorizonCluster\Pages;

use App\Filament\Clusters\HorizonCluster;
use Filament\Pages\Page;

/**
 * Phase 7 Plan 02 — D-03 Horizon Cluster sub-page (Pending Jobs).
 *
 * Iframe-embeds /horizon/pending-jobs inside Filament chrome via the
 * shared cluster view. Admin-only.
 */
class HorizonPendingJobsPage extends Page
{
    protected static ?string $cluster = HorizonCluster::class;

    protected static string $view = 'filament.clusters.horizon.embed';

    protected static ?string $slug = 'pending-jobs';

    protected static ?string $title = 'Pending Jobs';

    protected static ?string $navigationLabel = 'Pending Jobs';

    protected static ?string $navigationIcon = 'heroicon-o-pause-circle';

    protected static ?int $navigationSort = 50;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['horizonPath' => '/horizon/pending-jobs'];
    }
}
