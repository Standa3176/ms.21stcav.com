<?php

declare(strict_types=1);

namespace App\Domain\Sync\Filament\Resources;

use App\Domain\Sync\Filament\Resources\ProductSupplierBlockResource\Pages;
use App\Domain\Sync\Models\ProductSupplierBlock;
use App\Domain\Sync\Models\Supplier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Quick task 261027-duz — Supplier offer blocks admin page.
 *
 * Operator surface for product_supplier_blocks: block ONE supplier's offer for
 * ONE match key (price AND stock) in supplier:db-sync and
 * products:hydrate-stock-from-offers. Same effect as `supplier:block-offer`.
 *
 * Single ManageRecords page (modal create, row delete) — no edit page; to
 * change a reason, delete + re-create (or re-run the CLI, which upserts).
 * RBAC mirrors SupplierResource: admin + pricing_manager write; everyone who
 * can see Suppliers can view.
 */
class ProductSupplierBlockResource extends Resource
{
    protected static ?string $model = ProductSupplierBlock::class;

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $navigationGroup = 'Sync & CRM';

    // Right after Suppliers (25).
    protected static ?int $navigationSort = 26;

    protected static ?string $navigationLabel = 'Supplier offer blocks';

    protected static ?string $modelLabel = 'supplier offer block';

    protected static ?string $pluralModelLabel = 'Supplier offer blocks';

    private static function canWrite(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canWrite();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return self::canWrite();
    }

    public static function canDeleteAny(): bool
    {
        return self::canWrite();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('sku')
                ->label('SKU / match key')
                ->helperText('Supplier match key — usually the product SKU (an alternative supplier SKU alias can be blocked too).')
                ->required()
                ->maxLength(191)
                // Friendly duplicate check (the DB unique psb_sku_supplier_unique
                // would otherwise surface as a 500). Normalised like the model.
                ->rule(fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                    $exists = ProductSupplierBlock::query()
                        ->where('sku', strtolower(trim((string) $value)))
                        ->where('supplier_id', trim((string) $get('supplier_id')))
                        ->exists();
                    if ($exists) {
                        $fail('This supplier is already blocked for this SKU.');
                    }
                }),
            Select::make('supplier_id')
                ->label('Supplier')
                ->required()
                ->searchable()
                ->options(fn (): array => Supplier::query()
                    ->orderBy('name')
                    ->get(['supplier_id', 'name'])
                    ->mapWithKeys(fn (Supplier $s): array => [
                        (string) $s->supplier_id => ($s->name ?: $s->supplier_id).' ('.$s->supplier_id.')',
                    ])
                    ->all()),
            Textarea::make('reason')
                ->required()
                ->rows(3)
                ->maxLength(2000)
                ->helperText('Why this supplier\'s offer is wrong for this SKU (e.g. "Midwich 1301222 is an unrelated £7.20 item").')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU / key')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->limit(40)
                    ->placeholder('—'),
                TextColumn::make('supplier_id')
                    ->label('Supplier')
                    ->formatStateUsing(function (?string $state): string {
                        $name = $state === null ? null : Supplier::query()->where('supplier_id', $state)->value('name');

                        return $name ? "{$name} ({$state})" : (string) $state;
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reason')
                    ->limit(60)
                    ->tooltip(fn (ProductSupplierBlock $record): string => (string) $record->reason),
                TextColumn::make('createdBy.name')
                    ->label('Created by')
                    ->placeholder('CLI'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageProductSupplierBlocks::route('/'),
        ];
    }
}
