<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Filament\Resources;

use App\Domain\Pricing\Filament\Resources\ProductOverrideResource\Pages;
use App\Domain\Pricing\Models\ProductOverride;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Phase 3 Plan 03 — ProductOverrideResource (D-08, D-09).
 *
 * Per-product margin override. One row per product (DB UNIQUE + form-level
 * unique validation to surface the collision before the constraint fires).
 * Variations inherit the parent override (D-09).
 *
 * Admin + pricing_manager edit; sales + read_only view-only. Warning 9
 * defence-in-depth on every action.
 */
class ProductOverrideResource extends Resource
{
    protected static ?string $model = ProductOverride::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    // Phase 9 Plan 02 — Brand recolor + nav restructure (4 groups). Per-product
    // pricing overrides slot under Catalogue alongside PricingRuleResource.
    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $pluralModelLabel = 'Product Overrides';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('product_id')
                ->label('Product')
                ->relationship('product', 'sku')
                ->searchable()
                ->preload(false)
                ->required()
                // D-08 uniqueness enforced at form layer (DB UNIQUE is the final guard).
                ->unique(ignoreRecord: true)
                ->helperText('One override per product. Variations inherit the parent override (D-09).'),

            TextInput::make('margin_basis_points')
                ->label('Override margin (basis points)')
                ->numeric()
                ->required()
                ->helperText('2200 = 22.00%. This margin beats every rule in the resolver (D-08).'),

            Textarea::make('reason')
                ->label('Reason')
                ->rows(3)
                ->maxLength(2000)
                ->helperText('Audit trail — capture why the override exists so a future pricing manager can evaluate removing it.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable()->label('#'),
                TextColumn::make('product.sku')->label('SKU')->searchable()->sortable()->placeholder('—'),
                TextColumn::make('margin_basis_points')
                    ->label('Margin')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state / 100, 2).'%')
                    ->sortable(),
                TextColumn::make('reason')->limit(60)->placeholder('—'),
                TextColumn::make('created_by_user_id')->label('Created by')->placeholder('—'),
                TextColumn::make('updated_at')->dateTime()->sortable()->label('Updated'),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                EditAction::make()
                    ->authorize(fn (ProductOverride $record): bool => auth()->user()?->can('update', $record) ?? false),
                DeleteAction::make()
                    ->authorize(fn (ProductOverride $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->bulkActions([
                DeleteBulkAction::make()
                    ->authorize(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductOverrides::route('/'),
            'create' => Pages\CreateProductOverride::route('/create'),
            'edit' => Pages\EditProductOverride::route('/{record}/edit'),
        ];
    }
}
