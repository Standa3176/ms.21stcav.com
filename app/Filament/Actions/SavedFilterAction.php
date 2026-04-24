<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Domain\Dashboard\Models\UserSavedFilter;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;

/**
 * Phase 7 Plan 03 — per-user saved table filter actions (D-07).
 *
 * One ActionGroup combining three actions ('save', 'apply', 'delete')
 * rendered as a single header-action button on every Resource table.
 *
 * Plan 07-01 shipped the UserSavedFilter table + policy + factory. This
 * action wires them into the Filament UI. Cross-user sharing is deferred
 * (v1 scope: per-user private) — every query scopes by auth()->id().
 *
 * Threat mitigation:
 *   - T-07-03-03 (cross-user apply): applyAction + deleteAction both
 *     defence-in-depth abort_unless() on the policy binding. Select options
 *     are pre-scoped by user_id so a clean client can't discover other users'
 *     filter IDs either — the abort_unless() catches crafted PUT bodies.
 *   - T-07-03-02 (payload tampering): applyAction only writes to
 *     \$livewire->tableFilters — Filament's own filter resolver validates
 *     the payload against the Resource's declared filter schema before
 *     applying, so unknown keys are dropped.
 *
 * The resource_slug parameter is threaded through each factory helper so a
 * Resource can pass `static::getSlug()` and the select options + writes
 * all scope automatically.
 */
final class SavedFilterAction
{
    public static function buildActionGroup(string $resourceSlug): ActionGroup
    {
        return ActionGroup::make([
            static::saveAction($resourceSlug),
            static::applyAction($resourceSlug),
            static::deleteAction($resourceSlug),
        ])
            ->label('Saved filters')
            ->icon('heroicon-o-bookmark')
            ->button();
    }

    public static function saveAction(string $resourceSlug): Action
    {
        return Action::make('save-filter')
            ->label('Save current filter')
            ->icon('heroicon-o-bookmark-square')
            ->color('primary')
            ->authorize(fn (): bool => auth()->user()?->can('create', UserSavedFilter::class) ?? false)
            ->form([
                Forms\Components\TextInput::make('filter_name')
                    ->label('Filter name')
                    ->required()
                    ->maxLength(128),
            ])
            ->action(function (array $data, $livewire) use ($resourceSlug): void {
                /** @var array<string, mixed> $payload */
                $payload = (array) ($livewire->tableFilters ?? []);

                UserSavedFilter::updateOrCreate(
                    [
                        'user_id' => auth()->id(),
                        'resource_slug' => $resourceSlug,
                        'filter_name' => $data['filter_name'],
                    ],
                    [
                        'filter_payload_json' => $payload,
                    ],
                );

                Notification::make()
                    ->success()
                    ->title('Filter saved')
                    ->body("Saved as '{$data['filter_name']}' for this resource.")
                    ->send();
            });
    }

    public static function applyAction(string $resourceSlug): Action
    {
        return Action::make('apply-filter')
            ->label('Apply saved filter')
            ->icon('heroicon-o-bookmark')
            ->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('viewAny', UserSavedFilter::class) ?? false)
            ->form([
                Forms\Components\Select::make('saved_filter_id')
                    ->label('Saved filter')
                    ->required()
                    ->options(fn (): array => UserSavedFilter::query()
                        ->where('user_id', auth()->id())
                        ->where('resource_slug', $resourceSlug)
                        ->orderBy('filter_name')
                        ->pluck('filter_name', 'id')
                        ->toArray()),
            ])
            ->action(function (array $data, $livewire): void {
                /** @var UserSavedFilter $filter */
                $filter = UserSavedFilter::findOrFail($data['saved_filter_id']);

                // Defence-in-depth — policy ownership check on top of the
                // select's user-scoped option list. Crafted POST with another
                // user's ID still 403s here.
                abort_unless(auth()->user()?->can('view', $filter) ?? false, 403);

                $livewire->tableFilters = $filter->filter_payload_json ?? [];

                Notification::make()
                    ->success()
                    ->title('Filter applied')
                    ->body("Applied '{$filter->filter_name}'.")
                    ->send();
            });
    }

    public static function deleteAction(string $resourceSlug): Action
    {
        return Action::make('delete-filter')
            ->label('Delete saved filter')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->form([
                Forms\Components\Select::make('saved_filter_id')
                    ->label('Saved filter')
                    ->required()
                    ->options(fn (): array => UserSavedFilter::query()
                        ->where('user_id', auth()->id())
                        ->where('resource_slug', $resourceSlug)
                        ->orderBy('filter_name')
                        ->pluck('filter_name', 'id')
                        ->toArray()),
            ])
            ->action(function (array $data): void {
                /** @var UserSavedFilter $filter */
                $filter = UserSavedFilter::findOrFail($data['saved_filter_id']);

                // UserSavedFilterPolicy::delete allows owner OR admin.
                abort_unless(auth()->user()?->can('delete', $filter) ?? false, 403);

                $name = $filter->filter_name;
                $filter->delete();

                Notification::make()
                    ->success()
                    ->title('Filter deleted')
                    ->body("Removed '{$name}'.")
                    ->send();
            });
    }
}
