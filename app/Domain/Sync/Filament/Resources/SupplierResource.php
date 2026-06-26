<?php

declare(strict_types=1);

namespace App\Domain\Sync\Filament\Resources;

use App\Domain\Sync\Filament\Resources\SupplierResource\Pages;
use App\Domain\Sync\Models\Supplier;
use Carbon\Carbon;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Quick task 260626-oqr — Suppliers admin page.
 *
 * Wires the operator control surface for the previously-DEAD
 * suppliers.is_active flag (added 260608-g8x, never read until now). An
 * operator flips a supplier's Active toggle OFF and the next supplier:db-sync
 * run drops its price + stock entirely (SupplierExclusionResolver →
 * buildBestOfferMap, ahead of the stale filter, unconditional).
 *
 * Suppliers are AUTO-DISCOVERED by suppliers:check-stale — there is NO create
 * action. RBAC mirrors ImportIssueResource (D-02): admin + pricing_manager
 * write (toggle + edit form), sales + read_only are view-only. Write surfaces
 * gate via hasAnyRole on BOTH ->disabled/->visible and ->authorize.
 *
 * Quick task 260626-phz — the table carries a 'Feed date' badge column coloured
 * by WORKING-DAY age (weekends excluded): RED > 5 working days, AMBER 4–5,
 * GREEN ≤ 3, GRAY when never. It replaced the old relative 'Last seen' column.
 *
 * Quick task 260626-q2b — the 'Feed date' column now reads the REAL supplier
 * file date (suppliers.feed_remote_date, mirrored from the remote feeds.remote_date
 * by suppliers:sync-feed-dates) instead of the recorded_at MS-pull date, which was
 * always today() and so showed every supplier as fresh. The old recorded_at-based
 * 'Freshness' badge is replaced by a truthful 'Status' (Feed off / No data / Stale
 * / Fresh) derived from feed_remote_date + feed_status, and an 'Upstream pull'
 * column (feed_cron_run) shows when the upstream puller last ran.
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Sync & CRM';

    // After Import Issues (20); suppliers are the related metadata surface.
    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'supplier_id';

    protected static ?string $pluralModelLabel = 'Suppliers';

    /**
     * Count of EXCLUDED (is_active=false) suppliers — 'danger' so a paused
     * supplier is visible at a glance in the sidebar. Defensive try/catch:
     * the badge runs on every render and must never 500 the admin.
     */
    public static function getNavigationBadge(): ?string
    {
        try {
            $count = Supplier::query()->where('is_active', false)->count();
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    private static function canWrite(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    /**
     * Quick task 260626-phz — pure working-day age of a feed date.
     *
     * Weekdays between the feed date and now, weekends excluded. abs()+round()
     * for Carbon v2/v3 sign robustness; start-of-day both ends so a partial-day
     * time component never shifts the count. NULL feed date → NULL age.
     * Public + static so the boundary logic is unit-tested without a panel.
     */
    public static function workingDaysSince(?Carbon $date): ?int
    {
        if ($date === null) {
            return null;
        }

        return (int) round(abs(
            $date->copy()->startOfDay()->diffInWeekdays(now()->startOfDay())
        ));
    }

    /**
     * Maps working-day age to a Filament badge colour on the 5-working-day
     * contract: > 5 danger, 4–5 warning, ≤ 3 success, NULL (no data) gray.
     */
    public static function feedAgeColor(?int $workingDays): string
    {
        return match (true) {
            $workingDays === null => 'gray',
            $workingDays > 5 => 'danger',
            $workingDays >= 4 => 'warning',
            default => 'success',
        };
    }

    /**
     * Working-day age in words for the feed-date cell tooltip.
     */
    public static function feedAgeTooltip(?int $workingDays): ?string
    {
        if ($workingDays === null) {
            return 'No feed data recorded yet';
        }
        if ($workingDays === 0) {
            return 'Today';
        }

        return $workingDays.' working day'.($workingDays === 1 ? '' : 's').' ago';
    }

    public static function form(Form $form): Form
    {
        $readOnly = fn (): bool => ! self::canWrite();

        return $form->schema([
            // Natural key + denormalised name are auto-discovered — never edited.
            TextInput::make('supplier_id')
                ->label('Supplier ID')
                ->disabled(),
            TextInput::make('name')
                ->placeholder('—')
                ->disabled(),
            Toggle::make('is_active')
                ->label('Active')
                ->helperText('Off = excluded: the sync drops this supplier\'s price + stock.')
                ->disabled($readOnly),
            TextInput::make('stale_after_days')
                ->label('Stale after (days)')
                ->numeric()
                ->minValue(1)
                ->helperText('Blank = default 7 days')
                ->disabled($readOnly),
            Textarea::make('notes')
                ->rows(3)
                ->maxLength(2000)
                ->columnSpanFull()
                ->disabled($readOnly),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('supplier_id')
                    ->label('Supplier ID')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->placeholder('—'),
                ToggleColumn::make('is_active')
                    ->label('Active')
                    // Gate inline writes to admin + pricing_manager; read-only
                    // for sales/read_only (Warning 9 defence-in-depth at POST).
                    ->disabled(fn (): bool => ! self::canWrite()),
                // 260626-q2b — truthful status derived from the REAL feed date
                // (feed_remote_date) + upstream feed_status. Replaces the old
                // recorded_at-based 'Freshness' badge, which was a no-op (always
                // 'fresh' because recorded_at = the MS pull date stamped today on
                // every sync). 'Feed off' when the supplier feed is disabled
                // upstream (feeds.status=0); 'No data' when we have no real date;
                // 'Stale' (red) when older than 5 working days; else 'Fresh'.
                TextColumn::make('feed_status_label')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(function (Supplier $record): string {
                        if ($record->feed_status === 0) {
                            return 'Feed off';
                        }
                        if ($record->feed_remote_date === null) {
                            return 'No data';
                        }

                        return self::workingDaysSince($record->feed_remote_date) > 5 ? 'Stale' : 'Fresh';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Fresh' => 'success',
                        'Stale' => 'danger',
                        'Feed off' => 'danger',
                        default => 'gray',
                    }),
                // 260626-q2b — the REAL supplier file date (feeds.remote_date,
                // mirrored locally by suppliers:sync-feed-dates), badge-coloured
                // by working-day age: RED > 5 working days, AMBER 4–5, GREEN ≤ 3,
                // GRAY when never. Replaces the recorded_at-based column, which
                // showed the MS pull date (always today) instead of the true date.
                TextColumn::make('feed_remote_date')
                    ->label('Feed date')
                    ->badge()
                    ->sortable()
                    ->placeholder('never')
                    ->formatStateUsing(fn ($state): ?string => $state?->format('D j M Y'))
                    ->color(fn (Supplier $record): string => self::feedAgeColor(self::workingDaysSince($record->feed_remote_date)))
                    ->tooltip(fn (Supplier $record): ?string => self::feedAgeTooltip(self::workingDaysSince($record->feed_remote_date))),
                // 260626-q2b — when the upstream supplier-feed puller last ran
                // (feeds.cron_run). Distinct from the supplier's own file date:
                // a stale Nuvias file with a recent cron_run means the puller is
                // running but the supplier stopped uploading.
                TextColumn::make('feed_cron_run')
                    ->label('Upstream pull')
                    ->dateTime('D j M Y H:i')
                    ->sortable()
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('stale_after_days')
                    ->label('Stale after (days)')
                    ->sortable()
                    ->placeholder('default'),
                TextColumn::make('notes')
                    ->limit(40)
                    ->placeholder('—'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('supplier_id')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Excluded only'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        // Suppliers are auto-discovered by suppliers:check-stale — no UI creation.
        return false;
    }
}
