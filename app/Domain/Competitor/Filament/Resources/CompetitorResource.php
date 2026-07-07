<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources;

use App\Domain\Competitor\Filament\Resources\CompetitorResource\Pages;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorFtpFeed;
use Carbon\Carbon;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Quick task 260503-uwk — Competitors admin resource.
 *
 * Phase 5 Plan 01 created the Competitor model + watcher-driven auto-create
 * on first CSV sighting. There was no Filament Resource — competitors only
 * existed via seeder or watcher. This Resource closes that gap so ops can:
 *   - Add a competitor before the first CSV arrives (so FTP Feed setup
 *     doesn't require pre-seeding)
 *   - Edit slug/name/status (e.g. promote pending → active after inspection)
 *   - See feed counts and last_ingest_at at a glance
 *
 * Auto-discovered via AdminPanelProvider:111
 * (`->discoverResources(in: app_path('Domain/Competitor/Filament/Resources'))`).
 */
class CompetitorResource extends Resource
{
    protected static ?string $model = Competitor::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    // Quick task 260504-ev5 — 8-group nav restructure. Competitors moves to
    // its own dedicated 'Competitors' group (was lumped under Catalogue).
    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Competitor Feeds';

    protected static ?string $modelLabel = 'Competitor';

    protected static ?string $pluralModelLabel = 'Competitors';

    protected static ?string $slug = 'competitors';

    protected static ?int $navigationSort = 10;

    /**
     * Quick task 260504-ev5 — gray informational total-count badge.
     * Hidden when zero so a clean install doesn't show "0" against a
     * prompt-to-add-competitors empty state.
     */
    public static function getNavigationBadge(): ?string
    {
        // Defensive: badge runs on every sidebar render — failed query (missing table, broken connection) must not 500 the entire admin.
        try {
            $count = Competitor::query()->count();
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    /**
     * 260705-pw3 — PURE colour for the Last Ingest cell. RED when the feed is
     * behind the latest run (null, or > $lagHours older than the newest active
     * ingest); GREEN when it arrived with the latest run; GRAY when there's no
     * reference (no active competitor has ingested yet). Unit-tested.
     */
    public static function freshnessColorFor(?Carbon $lastIngestAt, ?Carbon $latestRun, int $lagHours): string
    {
        if ($latestRun === null) {
            return 'gray';
        }
        if ($lastIngestAt === null) {
            return 'danger';
        }

        return $lastIngestAt->lt($latestRun->copy()->subHours(max(0, $lagHours)))
            ? 'danger'
            : 'success';
    }

    /**
     * 260705-pw3 — Newest last_ingest_at across ACTIVE competitors = "the last
     * feed run". Memoised for the request so the column's ->color() closure
     * doesn't run a MAX() per row.
     */
    protected static ?Carbon $latestActiveIngestAtMemo = null;

    protected static bool $latestActiveIngestAtLoaded = false;

    public static function latestActiveIngestAt(): ?Carbon
    {
        if (! self::$latestActiveIngestAtLoaded) {
            self::$latestActiveIngestAtLoaded = true;
            $max = Competitor::query()
                ->where('status', Competitor::STATUS_ACTIVE)
                ->where('is_active', true)
                ->max('last_ingest_at');
            self::$latestActiveIngestAtMemo = $max !== null ? Carbon::parse($max) : null;
        }

        return self::$latestActiveIngestAtMemo;
    }

    /**
     * 260706-pfy — Newest remote_file_date (the actual feed-FILE creation date,
     * distinct from last_ingest_at = when the app processed it) across ACTIVE
     * competitors' FTP feeds = "the latest feed-file run". Memoised for the
     * request so the Feed file date column's ->color() closure doesn't run a
     * MAX() per row. Ignores inactive competitors so a dormant feed can't raise
     * the reference and make everyone else look behind.
     */
    protected static ?Carbon $latestActiveFeedFileDateMemo = null;

    protected static bool $latestActiveFeedFileDateLoaded = false;

    public static function latestActiveFeedFileDate(): ?Carbon
    {
        if (! self::$latestActiveFeedFileDateLoaded) {
            self::$latestActiveFeedFileDateLoaded = true;
            $max = CompetitorFtpFeed::query()
                ->whereHas('competitor', fn (Builder $q): Builder => $q->active())
                ->max('remote_file_date');
            self::$latestActiveFeedFileDateMemo = $max !== null ? Carbon::parse($max) : null;
        }

        return self::$latestActiveFeedFileDateMemo;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                    // Derive slug from name on create only — preserve existing slug on edit.
                    if (filled($state) && blank($get('slug'))) {
                        $set('slug', Str::slug((string) $state, '_'));
                    }
                }),

            TextInput::make('slug')
                ->required()
                ->maxLength(64)
                ->unique(ignoreRecord: true)
                ->rules(['regex:/^[a-z0-9_-]+$/'])
                ->helperText('Used in CSV filenames — lowercase letters, digits, hyphens, underscores only. Must match the watcher regex.'),

            Select::make('status')
                ->required()
                ->options([
                    Competitor::STATUS_PENDING => 'Pending (awaiting first inspection)',
                    Competitor::STATUS_ACTIVE => 'Active',
                    Competitor::STATUS_INACTIVE => 'Inactive',
                ])
                // Operator-driven creation defaults to Active — the operator
                // has consciously typed the name. Pending is reserved for the
                // watcher's auto-create-from-filename path (CompetitorWatchCommand)
                // where the slug hasn't been visually inspected yet.
                ->default(Competitor::STATUS_ACTIVE),

            Toggle::make('is_active')
                ->default(true)
                ->helperText('Independent of status — toggle off to skip ingest without changing the lifecycle status.'),

            TextInput::make('website_url')
                ->url()
                ->maxLength(512)
                ->helperText('Optional — public website used for human reference.'),

            Textarea::make('map_policy_notes')
                ->rows(3)
                ->maxLength(2048)
                ->helperText('Optional — MAP / pricing-policy notes the team should know when reviewing competitor prices.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name', 'asc')
            // 260706-pfy — load the newest remote_file_date per competitor in one
            // aggregate (no N+1). Exposes $record->ftp_feeds_max_remote_file_date.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withMax('ftpFeeds', 'remote_file_date'))
            ->columns([
                TextColumn::make('id')->label('Id')->sortable()->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('name')->sortable()->searchable(),

                TextColumn::make('slug')
                    ->sortable()
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Competitor::STATUS_ACTIVE => 'success',
                        Competitor::STATUS_PENDING => 'warning',
                        Competitor::STATUS_INACTIVE => 'gray',
                        default => 'gray',
                    }),

                // 260707-fkx — resolve the count directly per row instead of
                // Filament's ->counts() aggregate: the withCount subquery +
                // the ->modifyQueryUsing(withMax('ftpFeeds', ...)) modifier
                // populated ftp_feeds_count on SQLite (tests green) but NOT on
                // MariaDB (prod) → column read null → blank. A direct COUNT is
                // identical on both engines; ~5-row settings table so the
                // per-row query is negligible. Not DB-sortable (computed state),
                // so ->sortable() is dropped.
                TextColumn::make('ftp_feeds_count')
                    ->label('Feeds')
                    ->state(fn (Competitor $record): int => $record->ftpFeeds()->count())
                    ->tooltip('Number of feed files configured for this competitor'),

                TextColumn::make('last_ingest_at')
                    ->label('Last Ingest')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('— never')
                    // 260705-pw3 — RED when behind the latest feed run (pure
                    // rule + memoised newest-active reference; display-only).
                    ->color(fn (Competitor $record): string => self::freshnessColorFor(
                        $record->last_ingest_at,
                        self::latestActiveIngestAt(),
                        (int) config('competitor.last_run_lag_hours', 24),
                    ))
                    ->tooltip(function (Competitor $record): ?string {
                        $latest = self::latestActiveIngestAt();
                        if ($latest === null) {
                            return null;
                        }
                        if ($record->last_ingest_at === null) {
                            return 'Never ingested — not from the last feed run';
                        }
                        $lag = (int) config('competitor.last_run_lag_hours', 24);

                        return $record->last_ingest_at->lt($latest->copy()->subHours(max(0, $lag)))
                            ? 'Behind the latest run ('.$record->last_ingest_at->diffForHumans($latest, ['parts' => 1]).' before the newest feed)'
                            : 'From the latest feed run';
                    }),

                // 260706-pfy — the actual feed-FILE creation date (newest
                // remote_file_date across this competitor's feeds), distinct
                // from Last Ingest (when the app processed it). Same behind-the-
                // latest-run red rule as Last Ingest (reuses freshnessColorFor +
                // a memoised newest-active-feed-file reference); withMax-backed so
                // no N+1. Display-only.
                TextColumn::make('feed_file_date')
                    ->label('Feed file date')
                    ->state(fn (Competitor $record): ?string => $record->ftp_feeds_max_remote_file_date)
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('— none')
                    ->color(fn (Competitor $record): string => self::freshnessColorFor(
                        $record->ftp_feeds_max_remote_file_date !== null ? Carbon::parse($record->ftp_feeds_max_remote_file_date) : null,
                        self::latestActiveFeedFileDate(),
                        (int) config('competitor.last_run_lag_hours', 24),
                    ))
                    ->tooltip(function (Competitor $record): ?string {
                        $latest = self::latestActiveFeedFileDate();
                        $raw = $record->ftp_feeds_max_remote_file_date;
                        if ($latest === null) {
                            return null;
                        }
                        if ($raw === null) {
                            return 'No feed file date — not from the last feed run';
                        }
                        $date = Carbon::parse($raw);
                        $lag = (int) config('competitor.last_run_lag_hours', 24);

                        return $date->lt($latest->copy()->subHours(max(0, $lag)))
                            ? 'Feed file behind the latest run ('.$date->diffForHumans($latest, ['parts' => 1]).' before the newest feed file)'
                            : 'Feed file from the latest run';
                    }),

                ToggleColumn::make('is_active'),
            ])
            ->actions([
                EditAction::make(),
                // Custom-modal delete that surfaces cascade impact BEFORE the click.
                // competitor_prices + competitor_ftp_feeds + competitor_csv_mappings
                // all cascadeOnDelete; competitor_ingest_runs + csv_parse_errors
                // nullOnDelete (history preserved).
                DeleteAction::make()
                    ->modalHeading('Delete competitor and cascading data?')
                    ->modalDescription(function (Competitor $record): string {
                        $prices = $record->prices()->count();
                        $feeds = $record->ftpFeeds()->count();
                        $hasMapping = $record->csvMapping()->exists() ? 1 : 0;

                        return sprintf(
                            "Deleting \"%s\" will PERMANENTLY remove:\n"
                                ."  • %d competitor price rows\n"
                                ."  • %d FTP feed config%s\n"
                                ."  • %d column mapping%s\n\n"
                                .'Run history and parse errors are kept (with competitor_id=null) for forensics. '
                                .'To pause ingestion without losing data, toggle is_active off instead.',
                            $record->name,
                            $prices,
                            $feeds,
                            $feeds === 1 ? '' : 's',
                            $hasMapping,
                            $hasMapping === 1 ? '' : 's',
                        );
                    })
                    ->modalSubmitActionLabel('Yes — delete competitor + all cascading data'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompetitors::route('/'),
            'create' => Pages\CreateCompetitor::route('/create'),
            'edit' => Pages\EditCompetitor::route('/{record}/edit'),
        ];
    }
}
