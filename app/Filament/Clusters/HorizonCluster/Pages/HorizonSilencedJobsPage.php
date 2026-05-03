<?php

declare(strict_types=1);

namespace App\Filament\Clusters\HorizonCluster\Pages;

use App\Filament\Clusters\HorizonCluster;
use Filament\Pages\Page;

/**
 * Phase 7 Plan 02 — D-03 Horizon Cluster sub-page (Silenced Jobs).
 *
 * Iframe-embeds /horizon/silenced-jobs inside Filament chrome via the
 * shared cluster view. Admin-only.
 */
class HorizonSilencedJobsPage extends Page
{
    protected static ?string $cluster = HorizonCluster::class;

    protected static string $view = 'filament.clusters.horizon.embed';

    protected static ?string $slug = 'silenced-jobs';

    protected static ?string $title = 'Silenced Jobs';

    protected static ?string $navigationLabel = 'Silenced Jobs';

    protected static ?string $navigationIcon = 'heroicon-o-speaker-x-mark';

    protected static ?int $navigationSort = 70;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['horizonPath' => '/horizon/silenced-jobs'];
    }
}
