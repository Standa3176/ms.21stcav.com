<?php

declare(strict_types=1);

namespace App\Domain\Products\Filament\Resources;

use App\Domain\Products\Filament\Resources\ProductExceptionResource\Pages;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductException;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Operator-managed SKU allowlist preventing the supplier-sync demotion
 * (FlagProductsMissingBuyPriceCommand, Mon-Fri 07:15 BST) from flipping
 * publish → pending. See ProductException model + migration docblocks.
 *
 * Access (per ProductExceptionPolicy):
 *   - admin + pricing_manager: full CRUD
 *   - sales + read_only:       view-only
 *   - admin only:              delete
 */
class ProductExceptionResource extends Resource
{
    protected static ?string $model = ProductException::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Product Exceptions';

    protected static ?string $modelLabel = 'Product Exception';

    protected static ?string $pluralModelLabel = 'Product Exceptions';

    /**
     * Eager-load the creator relation so the table column doesn't N+1.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('creator');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            // Searchable SKU picker — same pattern as ProductOverrideResource
            // but storing the sku string (not a foreign key) so the
            // FlagProductsMissingBuyPriceCommand hashmap lookup stays
            // O(1) and the audit log column matches what shows up on
            // Woo + integration_events.
            //
            // Search hits both sku AND product name (operator may know
            // the part by name, not the cryptic SKU). Display shows
            // "SKU — Name" so the operator can confirm they picked the
            // right row. Free-text input deliberately removed — too
            // easy to typo or whitespace-paste a SKU that does not
            // exist locally.
            Select::make('sku')
                ->label('SKU')
                ->helperText('Search by SKU or product name. Picks from the products table — prevents typos.')
                ->required()
                ->unique(ignoreRecord: true, table: 'product_exceptions', column: 'sku')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Product::query()
                    ->where(function ($q) use ($search) {
                        $q->where('sku', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    })
                    ->orderBy('sku')
                    ->limit(50)
                    ->get(['sku', 'name'])
                    ->mapWithKeys(fn (Product $p) => [
                        $p->sku => $p->sku.' — '.Str::limit((string) $p->name, 70),
                    ])
                    ->all())
                ->getOptionLabelUsing(function (?string $value): string {
                    if ($value === null || $value === '') {
                        return '';
                    }
                    $p = Product::query()->where('sku', $value)->first(['sku', 'name']);

                    return $p === null
                        ? $value.' — (not found in local products)'
                        : $p->sku.' — '.Str::limit((string) $p->name, 70);
                }),
            TextInput::make('reason')
                ->label('Reason')
                ->helperText('Short label — shows in the table.')
                ->placeholder('e.g. "Custom build" or "Direct vendor (non-integrated)"')
                ->maxLength(255),
            Toggle::make('is_paused')
                ->label('Paused')
                ->helperText('When ON, this exception is IGNORED by the sync (SKU follows normal demotion rules). Use to temporarily disable without deleting.')
                ->default(false),
            Textarea::make('notes')
                ->rows(4)
                ->maxLength(2000)
                ->helperText('Operator context — why is this exception here, when can it be retired, who to ask.')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->searchable()
                    ->limit(40)
                    ->wrap(),
                IconColumn::make('is_paused')
                    ->label('Paused?')
                    ->boolean()
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->trueIcon('heroicon-o-pause-circle')
                    ->falseIcon('heroicon-o-check-circle'),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->placeholder('(system)')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                TernaryFilter::make('is_paused')
                    ->label('Paused')
                    ->placeholder('All')
                    ->trueLabel('Paused only')
                    ->falseLabel('Active only'),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('pause')
                        ->label('Pause')
                        ->icon('heroicon-o-pause-circle')
                        ->color('warning')
                        ->action(fn (Collection $records) => $records->each->update(['is_paused' => true]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('unpause')
                        ->label('Unpause')
                        ->icon('heroicon-o-play-circle')
                        ->color('success')
                        ->action(fn (Collection $records) => $records->each->update(['is_paused' => false]))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductExceptions::route('/'),
            'create' => Pages\CreateProductException::route('/create'),
            'edit' => Pages\EditProductException::route('/{record}/edit'),
        ];
    }
}
