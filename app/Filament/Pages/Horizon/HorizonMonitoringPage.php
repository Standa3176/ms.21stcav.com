<?php

declare(strict_types=1);

namespace App\Filament\Pages\Horizon;

use App\Filament\Pages\Horizon\Concerns\HasHorizonRedisStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\TagRepository;

/**
 * Phase 7 Plan 02 — D-03 native Horizon Pages (post-09.1 follow-up #3).
 *
 * /admin/horizon/monitoring — lists tags currently being monitored by
 * Horizon (via TagRepository::monitoring()). Mirrors Horizon's own
 * MonitoringController index() shape: each row is `{tag, count}` where
 * count = #pending + #failed for the tag.
 *
 * Header action "Monitor new tag" prompts for a tag string and calls
 * TagRepository::monitor($tag); per-row "Stop" link in the Blade view fires
 * a Livewire `stopMonitoring($tag)` method which calls the same repository.
 *
 * No Filament Table — TagRepository has no Eloquent backing and Filament 3.3
 * Tables expect either an Eloquent builder or a paginator. The Blade view
 * renders a plain HTML table populated from {@see getMonitoredTags()},
 * matching the data-fetch shape Horizon's own MonitoringController uses.
 *
 * Admin-only (mirrors parent Dashboard page).
 */
class HorizonMonitoringPage extends Page
{
    use HasHorizonRedisStatus;

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Monitoring';

    protected static ?string $navigationParentItem = 'Horizon';

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'horizon/monitoring';

    protected static ?string $title = 'Monitoring';

    protected static string $view = 'filament.pages.horizon.monitoring';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * Header action — prompt for a tag and call TagRepository::monitor($tag).
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('monitor_tag')
                ->label('Monitor new tag')
                ->icon('heroicon-o-plus')
                ->disabled(fn (): bool => $this->getRedisBannerData() !== null)
                ->form([
                    TextInput::make('tag')
                        ->label('Tag')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Horizon will track every job dispatched with this tag.'),
                ])
                ->action(function (array $data): void {
                    app(TagRepository::class)->monitor((string) $data['tag']);

                    Notification::make()
                        ->title("Now monitoring tag '{$data['tag']}'")
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Livewire action — stop monitoring the given tag (per-row "Stop" button).
     */
    public function stopMonitoring(string $tag): void
    {
        abort_unless((bool) auth()->user()?->hasRole('admin'), 403);

        app(TagRepository::class)->stopMonitoring($tag);

        Notification::make()
            ->title("Stopped monitoring '{$tag}'")
            ->success()
            ->send();
    }

    /**
     * Source-of-truth data fetch for the Blade view. Returns an empty
     * collection when Redis is unreachable so the view renders nothing
     * below the warning banner.
     *
     * @return Collection<int, array{tag: string, count: int}>
     */
    public function getMonitoredTags(): Collection
    {
        if ($this->getRedisBannerData() !== null) {
            return collect();
        }

        /** @var TagRepository $tags */
        $tags = app(TagRepository::class);

        return collect($tags->monitoring())
            ->map(fn (string $tag): array => [
                'tag' => $tag,
                'count' => $tags->count($tag) + $tags->count('failed:'.$tag),
            ])
            ->sortBy('tag')
            ->values();
    }
}
