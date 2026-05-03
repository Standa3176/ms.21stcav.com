<?php

declare(strict_types=1);

namespace App\Filament\Pages\Horizon;

use App\Filament\Pages\Horizon\Concerns\HasHorizonRedisStatus;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;

/**
 * Phase 7 Plan 02 — D-03 native Horizon Pages (post-09.1 follow-up #3).
 *
 * /admin/horizon/completed-jobs — lists completed jobs via
 * JobRepository::getCompleted(). Mirrors Horizon's own
 * CompletedJobsController decode shape.
 *
 * Admin-only.
 */
class HorizonCompletedJobsPage extends Page
{
    use HasHorizonRedisStatus;

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Completed Jobs';

    protected static ?string $navigationParentItem = 'Horizon';

    protected static ?string $navigationIcon = 'heroicon-o-check-circle';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'horizon/completed-jobs';

    protected static ?string $title = 'Completed Jobs';

    protected static string $view = 'filament.pages.horizon.completed-jobs';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return Collection<int, object>
     */
    public function getJobs(): Collection
    {
        if ($this->getRedisBannerData() !== null) {
            return collect();
        }

        return collect(app(JobRepository::class)->getCompleted(-1))
            ->map(function (object $job): object {
                $job->payload = json_decode((string) $job->payload);

                return $job;
            })
            ->values();
    }

    public function getTotal(): int
    {
        if ($this->getRedisBannerData() !== null) {
            return 0;
        }

        return (int) app(JobRepository::class)->countCompleted();
    }
}
