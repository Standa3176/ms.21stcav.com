<?php

declare(strict_types=1);

namespace App\Domain\Products\Filament\Resources;

use App\Domain\Products\Filament\Resources\ProductResource\Pages;
use App\Domain\Products\Filament\Resources\ProductResource\RelationManagers\VariantsRelationManager;
use App\Domain\Products\Models\Product;
use Filament\Forms\Components\IconEntry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Phase 2 Plan 02-04 — D-01 expansion Product Resource.
 *
 * Read-access for all 4 roles (policy-gated via ProductPolicy::viewAny).
 * Pricing_manager + admin edit price/cost fields; identity fields
 * (woo_product_id, sku, type) are disabled because Woo is the canonical source.
 *
 * Variations drill-down lives in VariantsRelationManager and is only visible
 * on ViewProduct/EditProduct when the parent is `type=variable`.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $pluralModelLabel = 'Products';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('woo_product_id')->disabled()->label('Woo product id'),
            TextInput::make('sku')->disabled(),
            TextInput::make('name')->disabled(),
            Select::make('type')
                ->disabled()
                ->options([
                    'simple' => 'Simple',
                    'variable' => 'Variable',
                    'grouped' => 'Grouped',
                    'external' => 'External',
                ]),
            Select::make('status')
                ->options([
                    'publish' => 'Publish',
                    'pending' => 'Pending',
                    'draft' => 'Draft',
                    'private' => 'Private',
                ]),
            TextInput::make('stock_status'),
            TextInput::make('buy_price')->numeric()->step('0.0001'),
            TextInput::make('sell_price')->numeric()->step('0.0001'),
            TextInput::make('cost_price')->numeric()->step('0.0001'),
            Toggle::make('is_custom_ms')->label('Custom-MS')->disabled(),
            Toggle::make('exclude_from_auto_update')->label('Exclude from auto-update'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('woo_product_id')->label('Woo id')->sortable(),
                TextColumn::make('sku')->searchable()->sortable()->placeholder('—'),
                TextColumn::make('name')->searchable()->limit(40),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'simple' => 'gray',
                        'variable' => 'primary',
                        'grouped' => 'warning',
                        'external' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('status')->badge(),
                TextColumn::make('stock_status')->badge()->placeholder('—'),
                TextColumn::make('buy_price')->money('GBP')->sortable(),
                TextColumn::make('sell_price')->money('GBP')->sortable(),
                IconColumn::make('is_custom_ms')->boolean()->label('Custom-MS'),
                IconColumn::make('exclude_from_auto_update')->boolean()->label('Excluded'),
                TextColumn::make('last_synced_at')->dateTime()->sortable()->placeholder('never'),
            ])
            ->defaultSort('woo_product_id', 'desc')
            ->filters([
                SelectFilter::make('type')->multiple()->options([
                    'simple' => 'Simple',
                    'variable' => 'Variable',
                    'grouped' => 'Grouped',
                    'external' => 'External',
                ]),
                SelectFilter::make('status')->multiple()->options([
                    'publish' => 'Publish',
                    'pending' => 'Pending',
                    'draft' => 'Draft',
                    'private' => 'Private',
                ]),
                TernaryFilter::make('is_custom_ms')->label('Custom-MS'),
                TernaryFilter::make('exclude_from_auto_update')->label('Excluded from auto-update'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            VariantsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'view' => Pages\ViewProduct::route('/{record}'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        // Products are synced from Woo; no UI creation in Phase 2 (Phase 6 auto-create).
        return false;
    }
}
