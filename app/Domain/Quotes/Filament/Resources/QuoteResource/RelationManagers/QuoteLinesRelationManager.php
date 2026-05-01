<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Filament\Resources\QuoteResource\RelationManagers;

use App\Domain\Products\Models\Product;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteLine;
use App\Domain\Quotes\Services\QuoteLineWriter;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Phase 11 Plan 03 — QuoteLinesRelationManager (D-10 + D-13).
 *
 * Search-and-add SKU picker (PRIMARY input path per D-10) reuses Phase 6
 * ProductResource search infrastructure: Select::searchable() against
 * products.sku|name|brand with up to 25 matches (debounced ≥3 chars by
 * Filament's default search behaviour).
 *
 * Manual SKU TextInput fallback for catalogue-gap cases (D-10 fallback path)
 * — visible only when the picker has no value (rare; primarily for SKUs not
 * yet in `products` table — a Phase 6 auto-create gap).
 *
 * Add action delegates to QuoteLineWriter::add (Plan 11-02 sole creation
 * path) so the immutability + total-recompute observer chain runs through
 * the same code path on every line write — UI parity with service layer.
 *
 * D-13 immutability mirrored at UI layer:
 *   - Add/Edit/Delete actions HIDDEN when parent Quote.status !== draft
 *   - Edit form allows quantity_int ONLY (price + snapshot fields hidden);
 *     observer chain handles line_total recompute + parent total recompute.
 *
 * Pitfall 7 — every action calls ->authorize('update', $this->ownerRecord)
 * server-side; visible() is UI hint only.
 */
class QuoteLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    protected static ?string $recordTitleAttribute = 'sku';

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('sku')
                ->label('Product')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Product::query()
                    ->where(function ($q) use ($search): void {
                        $q->where('sku', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    })
                    ->whereNotNull('sku')
                    ->limit(25)
                    ->get(['sku', 'name'])
                    ->mapWithKeys(fn (Product $p): array => [
                        $p->sku => "{$p->sku} — {$p->name}",
                    ])
                    ->all())
                ->getOptionLabelUsing(fn ($value): ?string => Product::where('sku', $value)
                    ->value('name'))
                ->live()
                ->helperText('Type ≥3 chars (SKU or name) — primary input path per D-10.'),

            TextInput::make('sku_manual')
                ->label('Manual SKU (fallback)')
                ->visible(fn (Get $get): bool => empty($get('sku')))
                ->helperText('Use only if SKU not in catalogue (Phase 6 auto-create gap).')
                ->maxLength(64),

            TextInput::make('quantity_int')
                ->label('Quantity')
                ->numeric()
                ->minValue(1)
                ->maxValue(9999)
                ->default(1)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku')
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('sku')
                    ->fontFamily('mono')
                    ->searchable(),

                TextColumn::make('product_snapshot.name')
                    ->label('Name')
                    ->limit(40),

                TextColumn::make('product_snapshot.brand')
                    ->label('Brand')
                    ->placeholder('—'),

                TextColumn::make('quantity_int')
                    ->label('Qty')
                    ->numeric(),

                TextColumn::make('unit_price_pence_at_quote')
                    ->label('Unit (inc VAT)')
                    ->formatStateUsing(fn ($state): string => '£'.number_format(((int) $state) / 100, 2)),

                TextColumn::make('line_total_pence_at_quote')
                    ->label('Line total (inc VAT)')
                    ->formatStateUsing(fn ($state): string => '£'.number_format(((int) $state) / 100, 2))
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                Action::make('add_line')
                    ->label('Add line')
                    ->icon('heroicon-o-plus')
                    // D-13 mirror: hide when parent Quote.status !== draft.
                    ->visible(fn (): bool => $this->ownerRecord->status === Quote::STATUS_DRAFT)
                    // Pitfall 7 — server-side gate (parent Quote update permission).
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->ownerRecord) ?? false)
                    ->form([
                        Select::make('sku')
                            ->label('Product')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Product::query()
                                ->where(function ($q) use ($search): void {
                                    $q->where('sku', 'like', "%{$search}%")
                                        ->orWhere('name', 'like', "%{$search}%");
                                })
                                ->whereNotNull('sku')
                                ->limit(25)
                                ->get(['sku', 'name'])
                                ->mapWithKeys(fn (Product $p): array => [
                                    $p->sku => "{$p->sku} — {$p->name}",
                                ])
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Product::where('sku', $value)
                                ->value('name'))
                            ->live(),

                        TextInput::make('sku_manual')
                            ->label('Manual SKU (fallback)')
                            ->visible(fn (Get $get): bool => empty($get('sku')))
                            ->maxLength(64),

                        TextInput::make('quantity_int')
                            ->label('Quantity')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(9999)
                            ->default(1)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $sku = ! empty($data['sku']) ? $data['sku'] : ($data['sku_manual'] ?? null);
                        if (empty($sku)) {
                            Notification::make()
                                ->danger()
                                ->title('SKU required')
                                ->body('Pick a product from the search or enter a manual SKU.')
                                ->send();

                            return;
                        }
                        try {
                            app(QuoteLineWriter::class)->add(
                                $this->ownerRecord,
                                $sku,
                                (int) $data['quantity_int'],
                            );
                            Notification::make()
                                ->success()
                                ->title('Line added')
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('Could not add line')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->actions([
                EditAction::make()
                    // D-13 mirror — hide edit row action when parent != draft.
                    ->visible(fn (QuoteLine $record): bool => $record->quote->status === Quote::STATUS_DRAFT)
                    ->authorize(fn (QuoteLine $record): bool => auth()->user()?->can('update', $record->quote) ?? false)
                    ->form([
                        // Quantity-only edit. Price + snapshot are observer-locked
                        // (Plan 11-02 QuoteLineImmutabilityObserver throws on
                        // unit_price_pence_at_quote / product_snapshot dirty).
                        TextInput::make('quantity_int')
                            ->label('Quantity')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(9999)
                            ->required(),
                    ]),

                DeleteAction::make()
                    ->visible(fn (QuoteLine $record): bool => $record->quote->status === Quote::STATUS_DRAFT)
                    ->authorize(fn (QuoteLine $record): bool => auth()->user()?->can('update', $record->quote) ?? false),
            ]);
    }
}
