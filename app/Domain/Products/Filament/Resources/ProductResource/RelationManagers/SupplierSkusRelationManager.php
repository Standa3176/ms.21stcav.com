<?php

declare(strict_types=1);

namespace App\Domain\Products\Filament\Resources\ProductResource\RelationManagers;

use App\Domain\Products\Models\ProductSupplierSku;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Quick task 260823-clp — the operator's "alternative SKU" field.
 *
 * Restores what the legacy Stock Updater plugin had and this app lost: a place
 * to say "supplier B calls this same part something else". Recording it here
 * stops the add-candidate scan proposing that code as a new part and stops the
 * auto-create pipeline publishing a duplicate of a product already on Woo.
 *
 * Deliberately does NOT affect price or stock: an alternative code changes what
 * the app RECOGNISES, not which supplier offer feeds buy_price. Sourcing one
 * product from several suppliers is separate, deliberately-deferred work (see
 * the 2026-08-09 TODO, step 5).
 *
 * Write access mirrors the pricing-sensitive resources — admin and
 * pricing_manager only; everyone else reads.
 */
class SupplierSkusRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierSkus';

    protected static ?string $title = 'Alternative SKUs';

    protected static ?string $recordTitleAttribute = 'supplier_sku';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('supplier_sku')
                ->label('Supplier SKU')
                ->required()
                ->maxLength(100)
                ->helperText('The code this supplier uses for this same physical part. Case and spacing are normalised automatically.'),
            TextInput::make('supplier_id')
                ->label('Supplier id')
                ->numeric()
                ->helperText('Optional. Leave blank if the code applies whoever quotes it.'),
            Select::make('source')
                ->options([
                    ProductSupplierSku::SOURCE_MANUAL => 'Manual (operator)',
                    ProductSupplierSku::SOURCE_DERIVED_MPN => 'Derived — matching part number',
                    ProductSupplierSku::SOURCE_DERIVED_EAN => 'Derived — matching EAN',
                ])
                ->default(ProductSupplierSku::SOURCE_MANUAL)
                ->required(),
            TextInput::make('notes')->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('supplier_sku')->label('Supplier SKU')->searchable()->copyable(),
                TextColumn::make('supplier_id')->label('Supplier')->placeholder('any'),
                TextColumn::make('source')->badge()->color(fn (string $state): string => $state === ProductSupplierSku::SOURCE_MANUAL ? 'success' : 'gray'),
                TextColumn::make('confidence')->suffix('%')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')->limit(40)->placeholder('—'),
                TextColumn::make('created_at')->dateTime('d/m/Y')->label('Added'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No alternative SKUs')
            ->emptyStateDescription('Add one when another supplier lists this same part under a different code — it stops the app creating a duplicate product for it.')
            ->headerActions([
                \Filament\Tables\Actions\CreateAction::make()
                    ->visible(fn (): bool => static::canWrite()),
            ])
            ->actions([
                \Filament\Tables\Actions\EditAction::make()->visible(fn (): bool => static::canWrite()),
                \Filament\Tables\Actions\DeleteAction::make()->visible(fn (): bool => static::canWrite()),
            ]);
    }

    private static function canWrite(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }
}
