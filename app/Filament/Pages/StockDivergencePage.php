<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Products\Models\StockDivergenceFinding;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Quick task 260609-nku — Stock Divergence page.
 *
 * /admin/stock-divergence — operator-facing UI surfacing the latest
 * AuditStockDivergenceCommand snapshot. The page lists "phantom stock"
 * SKUs (MS=0 + every fresh supplier=0 + Woo>0) sorted by phantom_units
 * DESC so the ecom manager triages the largest leaks first on Monday
 * morning after the Mon 09:15 London cron run.
 *
 * Per-row + bulk 'Resync to Woo' actions invoke the existing
 * products:resync-to-woo command which pushes MS's 0 over Woo's phantom
 * number via a split-PUT (mirrors the 260607-t6w admin pattern). Bulk
 * cap = 100 SKUs/click to protect Woo REST throughput.
 *
 * RBAC: admin + pricing_manager (page-level), matching CategoryAuditPage.
 *
 * navigationSort=17 — sits after CategoryAuditPage (15) so the two audit
 * pages cluster in the Catalogue navigation group.
 */
final class StockDivergencePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'stock-divergence';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Stock Divergence';

    /**
     * Catalogue group sort order:
     *   ProductResource=10, CategoryAuditPage=15, [this]=17, QuoteResource=20.
     */
    protected static ?int $navigationSort = 17;

    protected static string $view = 'filament.pages.stock-divergence';

    protected static ?string $title = 'Stock Divergence';

    /** Max SKUs per bulk-resync click — protects Woo REST throughput. */
    public const BULK_RESYNC_CAP = 100;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => StockDivergenceFinding::query())
            ->defaultSort('phantom_units', 'desc')
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('sku')
                    ->fontFamily('mono')
                    ->copyable()
                    ->sortable()
                    ->searchable(),

                TextColumn::make('name')
                    ->limit(50)
                    ->tooltip(fn (StockDivergenceFinding $r): ?string => $r->name)
                    ->searchable(),

                TextColumn::make('ms_stock_quantity')
                    ->label('MS qty')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('woo_stock_quantity')
                    ->label('Woo qty')
                    ->badge()
                    ->color('warning')
                    ->sortable(),

                TextColumn::make('phantom_units')
                    ->label('Phantom diff')
                    ->badge()
                    ->color('danger')
                    ->sortable(),

                TextColumn::make('woo_last_modified')
                    ->label('Woo modified')
                    ->dateTime()
                    ->since(),

                TextColumn::make('ms_last_synced_at')
                    ->label('MS synced')
                    ->dateTime()
                    ->since(),

                TextColumn::make('audited_at')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->filters([
                Filter::make('phantom_min')
                    ->form([
                        Forms\Components\TextInput::make('phantom_min')
                            ->numeric()
                            ->label('Min phantom units'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        $min = $data['phantom_min'] ?? null;
                        if ($min === null || $min === '') {
                            return $q;
                        }

                        return $q->where('phantom_units', '>=', (int) $min);
                    }),
            ])
            ->actions([
                Action::make('view_on_storefront')
                    ->label('View on Woo')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (StockDivergenceFinding $r): string => rtrim((string) config('services.woo.storefront_url', config('services.woo.url', '')), '/').'/?p='.$r->woo_product_id)
                    ->openUrlInNewTab(),

                Action::make('resync')
                    ->label('Resync to Woo')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription(fn (StockDivergenceFinding $r): string => "Push MS stock=0 over Woo's phantom {$r->woo_stock_quantity}?")
                    ->action(function (StockDivergenceFinding $record): void {
                        try {
                            Log::info('StockDivergencePage: resync invoked', [
                                'sku' => $record->sku,
                                'actor_id' => auth()->id(),
                            ]);
                            Artisan::call('products:resync-to-woo', ['--skus' => $record->sku]);

                            Notification::make()
                                ->success()
                                ->title('Resync queued')
                                ->body("SKU {$record->sku} pushed to Woo")
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('Resync failed')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                BulkAction::make('resync_selected')
                    ->label('Resync selected to Woo')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription(fn (EloquentCollection $records): string => 'Resync '.$records->count().' SKUs ('.$records->sum('phantom_units').' phantom units total) to Woo?')
                    ->action(function (EloquentCollection $records): void {
                        if ($records->count() > self::BULK_RESYNC_CAP) {
                            Notification::make()
                                ->danger()
                                ->title('Too many selected')
                                ->body('Cap is '.self::BULK_RESYNC_CAP.' SKUs per bulk operation. Narrow your selection.')
                                ->send();

                            return;
                        }

                        $skus = $records->pluck('sku')->filter()->unique()->values()->implode(',');
                        try {
                            Artisan::call('products:resync-to-woo', ['--skus' => $skus]);

                            Notification::make()
                                ->success()
                                ->title('Bulk resync queued')
                                ->body($records->count().' SKUs pushed to Woo')
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('Bulk resync failed')
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    /**
     * Footer summary banner data — count + total phantom units + last-run +
     * next-run hint. Mirror CategoryAuditPage::getSummary() shape so the blade
     * template can render the same banner pattern.
     *
     * @return array{
     *     total:int,
     *     total_phantom_units:int,
     *     last_run_at:?string,
     *     next_run_hint:string
     * }
     */
    public function getSummary(): array
    {
        $base = StockDivergenceFinding::query();
        $lastRunAt = $base->clone()->max('audited_at');

        return [
            'total' => (int) $base->clone()->count(),
            'total_phantom_units' => (int) $base->clone()->sum('phantom_units'),
            'last_run_at' => $lastRunAt !== null
                ? \Carbon\Carbon::parse($lastRunAt)->toIso8601String()
                : null,
            'next_run_hint' => 'Mon 09:15 London',
        ];
    }
}
