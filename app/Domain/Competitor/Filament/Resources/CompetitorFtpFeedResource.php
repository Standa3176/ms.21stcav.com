<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources;

use App\Domain\Competitor\Filament\Resources\CompetitorFtpFeedResource\Pages;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorFtpFeed;
use App\Filament\Concerns\HasExportableTable;
use Carbon\CarbonInterface;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Phase 11.2 Plan 01 — CompetitorFtpFeedResource (D-08 + D-10 + D-11).
 *
 * Multi-feed FTP admin Resource matching the operator screenshot:
 *   Id / Supplier / Remote File Date / Remote Filename / Local Filename /
 *   Last Updated / Status / Actions
 *
 * Default sort: id ASC (matches screenshot's `1, 10, 12, 13, 16, 18, 27...`).
 *
 * Stale-feed signal (D-10): remote_file_date older than
 * config('competitor.ftp.stale_days', 30) renders red text.
 *
 * RBAC (D-11): admin write + pricing_manager view-only. Sales + read_only 403.
 * `->authorize()` on every Action enforces the policy at the action level.
 *
 * Refresh now (D-08): queues `competitor:ftp-pull --feed={id} --live` onto the
 * `competitor-csv` queue (Phase 5 precedent).
 */
class CompetitorFtpFeedResource extends Resource
{
    use HasExportableTable;

    protected static ?string $model = CompetitorFtpFeed::class;

    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-down';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'FTP Feeds';

    protected static ?string $modelLabel = 'FTP Feed';

    protected static ?string $pluralModelLabel = 'FTP Feeds';

    protected static ?string $slug = 'competitor-ftp-feeds';

    protected static ?int $navigationSort = 60;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['competitor', 'credential']);
    }

    /**
     * Quick task 260504-ev5 — danger badge when any feed last_pull_status='failed'.
     * Surfaces silent FTP outages in the sidebar so ops doesn't wait for the
     * 3-strike consecutive_failures auto-disable email to learn a feed broke.
     */
    public static function getNavigationBadge(): ?string
    {
        // Defensive: badge runs on every sidebar render — failed query (missing table, broken connection) must not 500 the entire admin.
        try {
            $count = CompetitorFtpFeed::query()
                ->where('last_pull_status', CompetitorFtpFeed::STATUS_FAILED)
                ->count();
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            // Quick task 260503-uwk — competitor select is now inline-creatable
            // (createOptionForm) so ops can add a new competitor without leaving
            // the FTP Feed form. Existing competitors come from the dropdown;
            // new ones get a status=pending row plus an auto-derived slug.
            //
            // afterStateUpdated derives local_filename client-side as soon as
            // a competitor is selected — required so the Hidden::make('local_filename')
            // field below has a value AT VALIDATION TIME (before save).
            // mutateFormDataBeforeCreate runs AFTER validation, so it cannot
            // fill required fields.
            Select::make('competitor_id')
                ->label('Supplier')
                ->relationship('competitor', 'name')
                ->required()
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(function ($state, callable $set): void {
                    if ($state === null || $state === '') {
                        return;
                    }
                    $competitor = Competitor::find($state);
                    if ($competitor === null) {
                        return;
                    }
                    // Watcher regex: <slug>_YYYY-MM-DD.csv. Date is fixed because
                    // freshness comes from file mtime, not the date string.
                    $set('local_filename', sprintf('%s_2026-01-01.csv', $competitor->slug));
                })
                ->createOptionForm([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('slug')
                        ->maxLength(64)
                        ->rules(['regex:/^[a-z0-9_-]+$/'])
                        ->helperText('Optional — auto-derived from name if blank. Lowercase letters, digits, hyphens, underscores only.'),
                ])
                ->createOptionUsing(function (array $data): int {
                    $slug = filled($data['slug'] ?? null)
                        ? (string) $data['slug']
                        : Str::slug((string) $data['name'], '_');

                    // Inline-create from the FTP-feed form is operator-driven →
                    // default to Active. Pending stays reserved for the watcher's
                    // auto-create path where the slug hasn't been inspected.
                    return Competitor::create([
                        'name' => $data['name'],
                        'slug' => $slug,
                        'status' => Competitor::STATUS_ACTIVE,
                        'is_active' => true,
                    ])->getKey();
                }),

            TextInput::make('remote_filename')
                ->required()
                ->maxLength(512)
                ->helperText('Exact filename on the remote FTP server (e.g. nuvias-hub-feed.csv, PRICE.ZIP, Enhanced-GB.tsv)'),

            // Quick task 260503-uwk — local_filename is now Hidden + auto-derived
            // server-side from the competitor's slug. Operators no longer hand-craft
            // the implementation-detail filename pattern. The fixed date 2026-01-01
            // is intentional: the watcher's regex requires <slug>_YYYY-MM-DD.csv but
            // only uses the slug part for competitor identity — freshness comes from
            // the file's mtime, not the date string in its name.
            // Edit form: keeps the existing value (changing local_filename would
            // orphan files in storage/app/competitors/incoming/).
            // Belt-and-braces regex matches the watcher's pattern in case anyone
            // ever programmatically POSTs an arbitrary value to this hidden field.
            Hidden::make('local_filename')
                ->required()
                ->dehydrated()
                ->rules(['regex:/^[a-z0-9_-]+_\d{4}-\d{2}-\d{2}\.csv$/']),

            Select::make('format')
                ->required()
                ->options([
                    CompetitorFtpFeed::FORMAT_CSV => 'CSV',
                    CompetitorFtpFeed::FORMAT_TSV => 'TSV (tab-separated)',
                    CompetitorFtpFeed::FORMAT_ZIP => 'ZIP (extract first *.csv)',
                    CompetitorFtpFeed::FORMAT_TXT => 'TXT (sniff delimiter)',
                ])
                ->default(CompetitorFtpFeed::FORMAT_CSV),

            Select::make('credential_id')
                ->label('FTP Credential')
                ->relationship('credential', 'name')
                ->required()
                ->searchable()
                ->preload(),

            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'asc') // D-08 — matches screenshot ordering
            ->columns([
                TextColumn::make('id')->label('Id')->sortable(),

                TextColumn::make('competitor.name')
                    ->label('Supplier')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('remote_file_date')
                    ->label('Remote File Date')
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->placeholder('—')
                    ->color(fn ($state): ?string => self::staleColor($state)), // D-10

                TextColumn::make('remote_filename')
                    ->label('Remote Filename')
                    ->limit(40)
                    ->tooltip(fn ($record): ?string => $record?->remote_filename),

                TextColumn::make('local_filename')->label('Local Filename')->searchable(),

                TextColumn::make('last_pulled_at')
                    ->label('Last Updated')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->tooltip('When the local file was last fetched (not the Eloquent updated_at column)'),

                // Quick UX iteration — at-a-glance pull health: success / no_change /
                // failed badge + a red Failures badge when a feed is silently retrying.
                TextColumn::make('last_pull_status')
                    ->label('Pull')
                    ->badge()
                    ->placeholder('— never run')
                    ->color(fn (?string $state): string => match ($state) {
                        CompetitorFtpFeed::STATUS_SUCCESS => 'success',
                        CompetitorFtpFeed::STATUS_NO_CHANGE => 'gray',
                        CompetitorFtpFeed::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn ($record): ?string => $record?->last_pull_error),

                TextColumn::make('consecutive_failures')
                    ->label('Failures')
                    ->badge()
                    ->color('danger')
                    ->visible(fn ($record): bool => ($record?->consecutive_failures ?? 0) > 0),

                ToggleColumn::make('is_active')->label('Status'),
            ])
            ->filters([
                TernaryFilter::make('is_active'),
            ])
            ->actions([
                EditAction::make()
                    ->authorize(fn ($record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('refresh_now')
                    ->label('Refresh now')
                    ->icon('heroicon-o-arrow-path')
                    ->authorize(fn ($record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(function (CompetitorFtpFeed $record): void {
                        // D-08 — queue onto competitor-csv (Phase 5 precedent).
                        Artisan::queue('competitor:ftp-pull', [
                            '--feed' => $record->id,
                            '--live' => true,
                        ])->onQueue('competitor-csv');

                        Notification::make()
                            ->title('Refresh queued for feed #'.$record->id)
                            ->body('competitor:ftp-pull --feed='.$record->id.' --live')
                            ->success()
                            ->send();
                    }),

                DeleteAction::make()
                    ->authorize(fn ($record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => auth()->user()?->can('deleteAny', CompetitorFtpFeed::class) ?? false),
                    self::getExportBulkAction(), // Phase 7-03 trait — Download CSV
                ]),
            ]);
    }

    /**
     * D-10 — red text when remote_file_date is older than configured stale_days.
     */
    protected static function staleColor(mixed $state): ?string
    {
        if (! $state instanceof Carbon && ! $state instanceof CarbonInterface) {
            return null;
        }
        $threshold = (int) config('competitor.ftp.stale_days', 30);

        return $state->lt(now()->subDays($threshold)) ? 'danger' : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompetitorFtpFeeds::route('/'),
            'create' => Pages\CreateCompetitorFtpFeed::route('/create'),
            'edit' => Pages\EditCompetitorFtpFeed::route('/{record}/edit'),
        ];
    }
}
