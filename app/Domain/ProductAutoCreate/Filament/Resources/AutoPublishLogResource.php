<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Filament\Resources;

use App\Domain\ProductAutoCreate\Filament\Resources\AutoPublishLogResource\Pages;
use App\Domain\ProductAutoCreate\Models\AutoPublishLogEntry;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quick task 260711-aps Task 3 — Auto-Publish Log (READ-ONLY viewer).
 *
 * A read-only window over auto_publish_log — "what was pushed straight to live
 * Woo, and when" by the twice-weekly scheduled auto-publish. The table is
 * producer-owned by products:draft-from-suggestions --auto-approve; NO create/
 * edit/delete. competitor_count surfaces the 2-vs-3 split; woo_product_id links
 * to the live Woo product (wp-admin edit).
 *
 * Lives in the 'Woo Maintenance' nav group (the auto-create/Woo surface).
 * Admin-only — mirrors WooMaintenanceOverviewPage / AutoCreateHealthPage gates.
 *
 * Presentation layer: the resource reads a model in the ProductAutoCreate domain
 * but belongs to the Http/presentation Deptrac layer (domain Filament subdirs).
 */
class AutoPublishLogResource extends Resource
{
    protected static ?string $model = AutoPublishLogEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationGroup = 'Woo Maintenance';

    protected static ?string $navigationLabel = 'Auto-Publish Log';

    protected static ?string $modelLabel = 'Auto-Publish Log Entry';

    protected static ?string $pluralModelLabel = 'Auto-Publish Log';

    protected static ?string $slug = 'auto-publish-log';

    protected static ?int $navigationSort = 40;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('published_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->label('Published at'),
                TextColumn::make('sku')
                    ->searchable()
                    ->fontFamily('mono')
                    ->label('SKU'),
                TextColumn::make('competitor_count')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->tooltip('Number of competitors tracking this SKU at publish time (2 or 3 under the schedule)')
                    ->label('Competitors'),
                TextColumn::make('woo_product_id')
                    ->url(
                        fn (AutoPublishLogEntry $record): ?string => $record->woo_product_id !== null
                            ? rtrim((string) config('services.woo.storefront_url', 'https://meetingstore.co.uk'), '/')
                                ."/wp-admin/post.php?post={$record->woo_product_id}&action=edit"
                            : null,
                        shouldOpenInNewTab: true,
                    )
                    ->color('primary')
                    ->placeholder('—')
                    ->label('Woo product'),
                TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Source'),
            ])
            ->defaultSort('published_at', 'desc')
            ->filters([
                SelectFilter::make('competitor_count')
                    ->label('Competitors')
                    ->options([
                        2 => '2 competitors',
                        3 => '3 competitors',
                    ]),
                Filter::make('published_at')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('published_at', '>=', $d))
                            ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('published_at', '<=', $d));
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAutoPublishLog::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->hasRole('admin');
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        // auto_publish_log is producer-owned by the scheduled auto-publish command.
        return false;
    }
}
