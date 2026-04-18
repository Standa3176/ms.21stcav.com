<?php

declare(strict_types=1);

namespace App\Domain\Products\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 2 Plan 02-04 — ProductVariant drill-down (D-01).
 *
 * Only rendered when the parent Product is type=variable. Read-only for
 * sales/read_only; pricing_manager + admin can edit price fields via the
 * parent policy chain (ProductVariantPolicy delegates to role check).
 */
class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Variants';

    protected static ?string $recordTitleAttribute = 'sku';

    /**
     * D-01: only show variants tab when parent is a variable product.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === 'variable';
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('sku')->disabled(),
            TextInput::make('buy_price')->numeric()->step('0.0001'),
            TextInput::make('sell_price')->numeric()->step('0.0001'),
            TextInput::make('stock_quantity')->numeric(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->searchable(),
                TextColumn::make('woo_variation_id')->label('Woo variation id'),
                TextColumn::make('buy_price')->money('GBP')->sortable(),
                TextColumn::make('sell_price')->money('GBP')->sortable(),
                TextColumn::make('stock_quantity')->label('Stock'),
                TextColumn::make('status')->badge(),
                TextColumn::make('last_synced_at')->dateTime()->placeholder('never'),
            ])
            ->defaultSort('sku', 'asc');
    }
}
