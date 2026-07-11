<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Filament\Resources;

use App\Domain\Integrations\Filament\Resources\GaChannelMetricResource\Pages;
use App\Domain\Integrations\Models\GaChannelMetric;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 15 Plan 15a-02 — GA4 Channels (READ-ONLY Marketing viewer).
 *
 * Read-only window over ga_channel_metrics_daily, populated by the scheduled
 * google:pull-ga4 pull. No create/edit/delete — the table is producer-owned by
 * the command (GaChannelMetricPolicy denies all mutations). Revenue pennies are
 * rendered as £ (money('gbp', divideBy: 100)). Lives in the Marketing nav group.
 *
 * The resource is presentation: it reads a model in the Integrations domain but
 * belongs to the Http/presentation Deptrac layer (the domain Filament subdirs).
 */
class GaChannelMetricResource extends Resource
{
    protected static ?string $model = GaChannelMetric::class;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationGroup = 'Marketing';

    protected static ?string $navigationLabel = 'GA4 Channels';

    protected static ?string $modelLabel = 'GA4 Channel Metric';

    protected static ?string $pluralModelLabel = 'GA4 Channels';

    protected static ?string $slug = 'ga4-channels';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')
                    ->date('Y-m-d')
                    ->sortable()
                    ->label('Date'),
                TextColumn::make('channel_group')
                    ->badge()
                    ->searchable()
                    ->sortable()
                    ->label('Channel'),
                TextColumn::make('source_medium')
                    ->fontFamily('mono')
                    ->searchable()
                    ->limit(40)
                    ->label('Source / Medium'),
                TextColumn::make('campaign')
                    ->searchable()
                    ->limit(40)
                    ->placeholder('—')
                    ->label('Campaign'),
                TextColumn::make('sessions')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('key_events')
                    ->numeric()
                    ->sortable()
                    ->label('Key events'),
                TextColumn::make('transactions')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('purchase_revenue_pennies')
                    ->money('gbp', divideBy: 100)
                    ->sortable()
                    ->label('Revenue'),
                TextColumn::make('pulled_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->placeholder('—')
                    ->label('Pulled at'),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Filter::make('date')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('date', '>=', $d))
                            ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('date', '<=', $d));
                    }),
                SelectFilter::make('channel_group')
                    ->label('Channel')
                    ->options(fn (): array => GaChannelMetric::query()
                        ->distinct()
                        ->orderBy('channel_group')
                        ->pluck('channel_group', 'channel_group')
                        ->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGaChannelMetrics::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        // ga_channel_metrics_daily is producer-owned by google:pull-ga4.
        return false;
    }
}
