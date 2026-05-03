<?php

declare(strict_types=1);

namespace App\Filament\Pages\Horizon;

use App\Filament\Pages\Horizon\Concerns\HasHorizonRedisStatus;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Contracts\JobRepository;

/**
 * Phase 7 Plan 02 — D-03 native Horizon Pages (post-09.1 follow-up #3).
 *
 * /admin/horizon/failed-jobs — lists failed jobs via
 * JobRepository::getFailed(). Mirrors Horizon's own FailedJobsController
 * decode shape: payload as object, exception as UTF-8-converted string,
 * context decoded if present.
 *
 * Per-row Livewire actions:
 *   - retry($id)  — calls `php artisan horizon:retry {id}` (Horizon's
 *     own retry path; respects supervisor logic).
 *   - delete($id) — calls JobRepository::deleteFailed($id), purging the
 *     row from Redis.
 *
 * Both actions admin-gated at the method level (defence in depth on top
 * of the page-level canAccess + sidebar-hidden gate).
 */
class HorizonFailedJobsPage extends Page
{
    use HasHorizonRedisStatus;

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Failed Jobs';

    protected static ?string $navigationParentItem = 'Horizon';

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-circle';

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'horizon/failed-jobs';

    protected static ?string $title = 'Failed Jobs';

    protected static string $view = 'filament.pages.horizon.failed-jobs';

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

        return collect(app(JobRepository::class)->getFailed(-1))
            ->map(function (object $job): object {
                $job->payload = json_decode((string) $job->payload);
                $job->exception = mb_convert_encoding((string) ($job->exception ?? ''), 'UTF-8');
                $job->context = isset($job->context) && $job->context !== null
                    ? json_decode((string) $job->context)
                    : null;

                return $job;
            })
            ->values();
    }

    public function getTotal(): int
    {
        if ($this->getRedisBannerData() !== null) {
            return 0;
        }

        return (int) app(JobRepository::class)->countFailed();
    }

    /**
     * Re-queue a failed job via Horizon's own artisan command (mirrors
     * NotificationCentrePage::retryFailedJob() pattern).
     */
    public function retry(string $id): void
    {
        abort_unless((bool) auth()->user()?->hasRole('admin'), 403);

        Artisan::call('horizon:retry', ['id' => $id]);

        Notification::make()
            ->title('Job queued for retry')
            ->success()
            ->send();
    }

    /**
     * Permanently delete a failed job from Redis.
     */
    public function deleteFailed(string $id): void
    {
        abort_unless((bool) auth()->user()?->hasRole('admin'), 403);

        app(JobRepository::class)->deleteFailed($id);

        Notification::make()
            ->title('Failed job deleted')
            ->success()
            ->send();
    }
}
