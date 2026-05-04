<?php

declare(strict_types=1);

namespace App\Domain\Products\Filament\Resources;

use App\Domain\ProductAutoCreate\Services\FieldPinManager;
use App\Domain\Products\Filament\Resources\ProductResource\Pages;
use App\Domain\Products\Filament\Resources\ProductResource\RelationManagers\VariantsRelationManager;
use App\Domain\Products\Models\Product;
use App\Filament\Actions\QueueCsvExportAction;
use App\Filament\Actions\SavedFilterAction;
use App\Filament\Concerns\HasExportableTable;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

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
    use HasExportableTable;

    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    // Quick task 260504-ev5 — 8-group nav restructure. ProductResource is the
    // canonical first item under Catalogue.
    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $pluralModelLabel = 'Products';

    /**
     * Quick task 260504-ev5 — gray informational total-count badge with
     * thousands separator (Woo catalogue is ~5k SKUs per v2 STACK assumption).
     * Hidden when zero (clean install) so the sidebar stays uncluttered.
     */
    public static function getNavigationBadge(): ?string
    {
        // Defensive: badge runs on every sidebar render — failed query (missing table, broken connection) must not 500 the entire admin.
        try {
            $count = Product::query()->count();
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? number_format($count) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('product_tabs')
                ->tabs([
                    // ── Main tab: core Woo-sourced fields + pricing (Phase 2 shape) ───
                    Tabs\Tab::make('Details')
                        ->icon('heroicon-o-cube')
                        ->schema([
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
                        ]),

                    // ── Field Pins tab (Phase 6 Plan 04 — AUTO-10, AUTO-11) ─────────
                    // 8 per-field pin booleans on the product's ProductOverride row.
                    // Pinned fields SURVIVE the next supplier sync — changes made in
                    // Woo admin won't be overwritten. Applied via ProductOverrideGuard
                    // (Plan 06-03) from the ApplyPinsDuringSync listener (Plan 06-05).
                    //
                    // Visibility: admin + pricing_manager (ProductOverridePolicy).
                    // Save hook: dehydrateStateUsing captures the toggles + upserts
                    // the ProductOverride row in the afterSave() hook via the
                    // EditProduct custom save path below.
                    //
                    // LogsActivity on ProductOverride (Plan 06-01 D-12) captures
                    // the audit trail via the model's LogOptions + $fillable coverage.
                    Tabs\Tab::make('Field Pins')
                        ->icon('heroicon-o-lock-closed')
                        ->visible(fn (?Product $record) => $record !== null
                            && (auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false))
                        ->schema([
                            Section::make('Pin fields against supplier sync')
                                ->description('Pinned fields survive the next supplier sync — changes made here or in Woo admin will not be overwritten. Audit trail via activity_log.')
                                ->schema([
                                    Grid::make(2)->schema([
                                        Toggle::make('override_pins.pin_title')->label('Pin title'),
                                        Toggle::make('override_pins.pin_short_description')->label('Pin short description'),
                                        Toggle::make('override_pins.pin_long_description')->label('Pin long description'),
                                        Toggle::make('override_pins.pin_meta_description')->label('Pin meta description'),
                                        Toggle::make('override_pins.pin_image')->label('Pin image'),
                                        Toggle::make('override_pins.pin_slug')->label('Pin slug'),
                                        Toggle::make('override_pins.pin_brand')->label('Pin brand'),
                                        Toggle::make('override_pins.pin_category')->label('Pin category'),
                                    ]),
                                ])
                                ->afterStateHydrated(function ($component, ?Product $record): void {
                                    if ($record === null) {
                                        return;
                                    }
                                    // FieldPinManager lives in ProductAutoCreate; it owns the
                                    // pin_* concept and isolates ProductOverride access.
                                    $component->state([
                                        'override_pins' => app(FieldPinManager::class)->loadPinsFor($record),
                                    ]);
                                }),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * Called by EditProduct after the main Product save. Delegates to the
     * FieldPinManager service in ProductAutoCreate which owns the pin concept
     * and handles authorisation + upsert + audit trail (LogsActivity).
     */
    public static function saveFieldPins(Product $product, array $pins): bool
    {
        return app(FieldPinManager::class)->savePins($product, $pins);
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
            ])
            // Phase 7 Plan 03 — DASH-04 saved-filter header action (per-user).
            ->headerActions([
                SavedFilterAction::buildActionGroup(static::getSlug()),
            ])
            // Phase 7 Plan 03 — DASH-04 CSV export bulk actions.
            // HasExportableTable streams <10k inline; QueueCsvExportAction
            // dispatches QueuedCsvExportJob for 10k-100k; >100k hard-fails.
            ->bulkActions([
                static::getExportBulkAction(),
                QueueCsvExportAction::make(static::class),
            ]);
    }

    // ── Phase 7 Plan 03 — DASH-03 global search (D-04) ─────────────────────

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['sku', 'name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Product $record */
        return ($record->sku ?? '(no sku)').' · '.($record->name ?? '(no name)');
    }

    /** @return array<string, string|int|null> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Product $record */
        return [
            'Status' => $record->status ?? '—',
            'Stock' => $record->stock_status ?? '—',
            'Type' => $record->type ?? '—',
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return static::getUrl('edit', ['record' => $record]);
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
