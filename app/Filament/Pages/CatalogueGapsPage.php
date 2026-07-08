<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Services\ProductGapReport;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Quick task 260707-wa9 — Woo Maintenance Pass 2: the Catalogue Gaps drill-down.
 *
 * Pass 1 (260707-w2w) shipped the Maintenance Overview + ProductGapReport
 * (the single source of truth for catalogue gaps over products LIVE on Woo).
 * This page is the drill-down: it lists the actual live-on-Woo products for a
 * chosen gap and hangs one-click fix actions off each row.
 *
 * CRITICAL — the gap list REUSES ProductGapReport::liveBase() + apply() (never
 * re-defines the gap predicates), so the list is EXACTLY the products the
 * Overview counted. The Overview stat cards deep-link in via
 * ?tableFilters[gap][value]=<gap> (Filament SelectFilter form-state).
 *
 * Admin-only — mirrors AutoCreateHealthPage's gate + action shape: each fix
 * action is admin-gated, requiresConfirmation, dispatches the existing artisan
 * command via Artisan::call(['--skus' => ...]) (array-option form — never
 * concatenate into a shell string), and wraps in try/catch with a Notification.
 * The fixable gaps map to: source-images (missing images), backfill EAN
 * (missing ean), resync-to-woo (always). Category gaps have no one-click fix
 * — surfaced for manual triage.
 *
 * Quick task 260708-cey — PASS 2 REWIRE. The Gap filter is the reconciled gaps
 * (ProductGapReport::GAPS) and reuses ProductGapReport::apply(), which gates on
 * woo_reconciled_at and reads the woo_* mirror. The columns surface that
 * reconciled truth: woo_image_count / woo_gtin / woo_category_count /
 * woo_reconciled_at. Stock dropped from the gap set (always set on Woo), so the
 * Hydrate stock action was removed; the remaining fix actions are unchanged.
 *
 * Quick task 260708-fyh — PASS B adds missing_brand: the Gap filter now offers
 * 4 options (GAPS-driven) and a woo_brand_count 'Brand' column (danger when 0)
 * joins the row. Resync re-pushes the product_brand link when a local brand_id
 * exists; products with no local brand_id need a brand assigned first.
 */
final class CatalogueGapsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'catalogue-gaps';

    protected static ?string $navigationGroup = 'Woo Maintenance';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Catalogue Gaps';

    /** After Maintenance Overview (10) in the Woo Maintenance group. */
    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.catalogue-gaps';

    /**
     * Admin-only — the fix actions are real-money operations (Claude spend on
     * source-images; Woo writes on resync). Mirrors WooMaintenanceOverviewPage
     * + AutoCreateHealthPage.
     */
    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->hasRole('admin');
    }

    public function table(Table $table): Table
    {
        $report = app(ProductGapReport::class);

        return $table
            ->query(fn (): Builder => $report->liveBase())
            ->defaultSort('name', 'asc')
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('sku')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->placeholder('—'),

                TextColumn::make('name')
                    ->limit(50)
                    ->tooltip(fn (Product $record): ?string => $record->name),

                // Reconciled truth (woo_* mirror), not the local columns.
                TextColumn::make('woo_image_count')
                    ->label('Images')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?int $state): string => $state === null
                        ? 'gray'
                        : ($state === 0 ? 'danger' : 'success')),

                TextColumn::make('woo_gtin')
                    ->label('EAN')
                    ->placeholder('— none')
                    ->badge()
                    ->color(fn (?string $state): string => ($state === null || $state === '') ? 'danger' : 'gray'),

                TextColumn::make('woo_category_count')
                    ->label('Categories')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?int $state): string => $state === null
                        ? 'gray'
                        : ($state === 0 ? 'danger' : 'success')),

                // Quick task 260708-fyh — Pass B: the reconciled product_brand
                // term count (danger when 0 → the storefront Brand link is empty).
                TextColumn::make('woo_brand_count')
                    ->label('Brand')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?int $state): string => $state === null
                        ? 'gray'
                        : ($state === 0 ? 'danger' : 'success')),

                TextColumn::make('woo_reconciled_at')
                    ->label('Reconciled')
                    ->dateTime()
                    ->placeholder('never')
                    ->toggleable(),

                TextColumn::make('woo_product_id')
                    ->label('Woo ID')
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('gap')
                    ->label('Gap')
                    ->options(ProductGapReport::GAPS)
                    ->default('missing_images')
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? null)
                        ? $report->apply($query, (string) $data['value'])
                        : $query),
            ])
            ->actions([
                $this->fixAction(
                    'source_images',
                    'Source images',
                    'heroicon-o-photo',
                    'primary',
                    'products:source-images',
                    'Runs products:source-images --skus=<sku>. Icecat + supplier feed + web search + Claude vision (~10p). Use only when the gallery is empty.',
                    fn (Product $record): bool => empty((array) $record->gallery_image_urls),
                ),
                $this->fixAction(
                    'backfill_ean',
                    'Backfill EAN',
                    'heroicon-o-bars-3-bottom-left',
                    'primary',
                    'products:backfill-merchant-feed',
                    'Runs products:backfill-merchant-feed --skus=<sku>. Backfills EAN/brand/category from supplier_db (+ EAN provider fallback). May cost per lookup.',
                    fn (Product $record): bool => $record->ean === null || trim((string) $record->ean) === '',
                ),
                $this->fixAction(
                    'resync',
                    'Resync to Woo',
                    'heroicon-o-arrow-path',
                    'warning',
                    'products:resync-to-woo',
                    'Runs products:resync-to-woo --skus=<sku>. Re-pushes tags + regular_price + attributes to the existing Woo product. Safe to repeat.',
                    fn (Product $record): bool => true,
                ),
            ])
            ->bulkActions([
                $this->bulkFixAction('source_images_bulk', 'Source images', 'heroicon-o-photo', 'products:source-images'),
                $this->bulkFixAction('backfill_ean_bulk', 'Backfill EAN', 'heroicon-o-bars-3-bottom-left', 'products:backfill-merchant-feed'),
                $this->bulkFixAction('resync_bulk', 'Resync to Woo', 'heroicon-o-arrow-path', 'products:resync-to-woo'),
            ]);
    }

    /**
     * A per-row fix action mirroring AutoCreateHealthPage: admin-gated,
     * requiresConfirmation, Artisan::call with the array-option --skus form,
     * try/catch → success/failure Notification.
     *
     * @param  callable(Product): bool  $visible
     */
    private function fixAction(
        string $name,
        string $label,
        string $icon,
        string $color,
        string $command,
        string $description,
        callable $visible,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->modalHeading(fn (Product $record): string => "{$label}: {$record->sku}")
            ->modalDescription($description)
            ->visible(fn (Product $record): bool => (bool) auth()->user()?->hasRole('admin') && $visible($record))
            ->authorize(fn (): bool => (bool) auth()->user()?->hasRole('admin'))
            ->action(function (Product $record) use ($command, $label): void {
                try {
                    Log::info('CatalogueGapsPage: fix action invoked', [
                        'command' => $command,
                        'sku' => $record->sku,
                        'actor_id' => auth()->id(),
                    ]);
                    // Array-option form — never concatenate user input into a
                    // shell-style string.
                    Artisan::call($command, ['--skus' => $record->sku]);

                    Notification::make()
                        ->success()
                        ->title("{$label} dispatched for {$record->sku}")
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title("{$label} failed")
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    /**
     * A bulk fix action: gather the selected SKUs into a CSV --skus option and
     * dispatch the same command once. Admin-gated, requiresConfirmation, same
     * try/catch → Notification shape.
     */
    private function bulkFixAction(string $name, string $label, string $icon, string $command): BulkAction
    {
        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->requiresConfirmation()
            ->modalDescription("Runs {$command} --skus=<selected SKUs> once for the selected products.")
            ->visible(fn (): bool => (bool) auth()->user()?->hasRole('admin'))
            ->authorize(fn (): bool => (bool) auth()->user()?->hasRole('admin'))
            ->action(function (Collection $records) use ($command, $label): void {
                $csv = $records
                    ->pluck('sku')
                    ->filter(fn ($sku): bool => $sku !== null && $sku !== '')
                    ->implode(',');

                if ($csv === '') {
                    Notification::make()
                        ->warning()
                        ->title('No SKUs in the selection')
                        ->send();

                    return;
                }

                try {
                    Log::info('CatalogueGapsPage: bulk fix action invoked', [
                        'command' => $command,
                        'skus' => $csv,
                        'actor_id' => auth()->id(),
                    ]);
                    Artisan::call($command, ['--skus' => $csv]);

                    Notification::make()
                        ->success()
                        ->title("{$label} dispatched for ".$records->count().' product(s)')
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title("{$label} failed")
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }
}
