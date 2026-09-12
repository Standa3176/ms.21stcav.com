<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Filament\Resources;

use App\Domain\Products\Support\BrandOptions;
use App\Domain\TradePricing\Filament\Resources\TradeCostAdjustmentResource\Pages;
use App\Domain\TradePricing\Models\TradeCostAdjustment;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Quick task 260912-f8x — manage trade-only cost arrangements.
 *
 * The daily supplier feed does not carry the cost actually paid: it quotes
 * SILVER-level Yealink while the business buys at platinum (~11% better,
 * measured over 70 matched SKUs on 2026-09-10), and a two-month Logitech deal
 * is absent from it entirely.
 *
 * Rows here are read ONLY by the trade storefront pricer. `products.buy_price`
 * keeps the feed value untouched, so retail pricing, health-check and the
 * divergence scan all carry on reading one unchanged column — and the better
 * buying is spent on trade customers rather than given away at retail, which
 * is what the operator asked for.
 *
 * A whole manufacturer price list belongs in `trade:import-costs`, not this
 * screen — 70 rows typed by hand is how mistakes get made. This is for adding
 * one-off arrangements, and for seeing and expiring what the importer loaded.
 *
 * $navigationSort = 115 sits beside CustomerGroupResource (110) in Settings;
 * PricingRuleResource is 70. Distinctness is the I-01 convention.
 */
final class TradeCostAdjustmentResource extends Resource
{
    protected static ?string $model = TradeCostAdjustment::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 115;

    protected static ?string $navigationLabel = 'Trade cost arrangements';

    protected static ?string $modelLabel = 'trade cost arrangement';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Radio::make('scope_kind')
                ->label('Applies to')
                ->options([
                    'brand' => 'A whole brand',
                    'sku' => 'One product (SKU)',
                ])
                ->default(fn ($record) => $record?->sku !== null ? 'sku' : 'brand')
                ->dehydrated(false)      // UI only — the columns below are what persist.
                ->live()
                ->inline()
                ->helperText('A SKU arrangement overrides the brand one for that product, even when it is the smaller discount.'),

            Select::make('brand_id')
                ->label('Brand')
                ->options(fn (): array => BrandOptions::all())
                ->searchable()
                ->nullable()
                ->visible(fn ($get): bool => $get('scope_kind') !== 'sku')
                ->helperText('Brands come from Woo. If the list is empty Woo is unreachable — the numeric id still works.'),

            TextInput::make('sku')
                ->label('SKU')
                ->maxLength(128)
                ->nullable()
                ->visible(fn ($get): bool => $get('scope_kind') === 'sku')
                ->helperText('Must match products.sku exactly (case is ignored).'),

            Radio::make('value_kind')
                ->label('Expressed as')
                ->options([
                    'pct' => 'Percentage off the fed cost',
                    'absolute' => 'A fixed cost we actually pay',
                ])
                ->default(fn ($record) => $record?->absolute_cost !== null ? 'absolute' : 'pct')
                ->dehydrated(false)
                ->live()
                ->inline()
                ->helperText('Use a fixed cost for a published price list — a percentage drifts every time the feed moves.'),

            TextInput::make('adjustment_pct')
                ->label('Discount off feed cost')
                ->numeric()
                ->suffix('%')
                ->minValue(0.001)
                ->maxValue(99.999)
                ->nullable()
                ->visible(fn ($get): bool => $get('value_kind') !== 'absolute')
                ->helperText('e.g. 11.2 means we pay 11.2% less than the feed quotes.'),

            TextInput::make('absolute_cost')
                ->label('Our cost, ex VAT')
                ->numeric()
                ->prefix('£')
                ->minValue(0.0001)
                ->nullable()
                ->visible(fn ($get): bool => $get('value_kind') === 'absolute')
                ->helperText('Ex VAT, matching products.buy_price. Ignored if it is higher than the fed cost.'),

            DatePicker::make('valid_from')
                ->label('From')
                ->nullable()
                ->helperText('Leave blank for "already in force".'),

            DatePicker::make('valid_until')
                ->label('Until')
                ->nullable()
                ->helperText('Leave blank for an open-ended arrangement. A dated deal lapses on its own — that is the point.'),

            TextInput::make('reason')
                ->label('Why')
                ->maxLength(255)
                ->nullable()
                ->helperText('For whoever reads this in six months. e.g. "Yealink platinum tier, August list".'),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('No trade cost arrangements yet')
            ->emptyStateDescription('Without one, trade prices are calculated from the supplier feed cost — which for some brands is not what we actually pay. Load a manufacturer price list with trade:import-costs, or add a single arrangement here.')
            ->columns([
                TextColumn::make('scope')
                    ->label('Applies to')
                    ->state(fn (TradeCostAdjustment $r): string => $r->sku !== null
                        ? 'SKU '.$r->sku
                        : (BrandOptions::name($r->brand_id) ?? 'everything (no scope)'))
                    ->description(fn (TradeCostAdjustment $r): ?string => $r->sku !== null ? null : 'brand')
                    ->searchable(query: fn ($query, string $search) => $query->where('sku', 'like', "%{$search}%")),

                TextColumn::make('value')
                    ->label('Cost')
                    ->state(fn (TradeCostAdjustment $r): string => $r->absolute_cost !== null
                        ? '£'.number_format((float) $r->absolute_cost, 2).' fixed'
                        : number_format((float) $r->adjustment_pct, 2).'% off feed'),

                TextColumn::make('valid_from')->label('From')->date('d M Y')->placeholder('—'),
                TextColumn::make('valid_until')->label('Until')->date('d M Y')->placeholder('open'),

                // "Active" is the operator's switch; "in force" is what the
                // pricer actually sees today. They differ whenever a dated deal
                // has lapsed, and that difference is the thing worth showing.
                IconColumn::make('in_force')
                    ->label('In force today')
                    ->state(fn (TradeCostAdjustment $r): bool => TradeCostAdjustment::query()
                        ->whereKey($r->getKey())->inForce()->exists())
                    ->boolean(),

                TextColumn::make('reason')->label('Why')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('updated_at')->dateTime('d M Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    /**
     * Exactly one column of each pair survives a save.
     *
     * The scope and value radios are `dehydrated(false)` UI affordances, so
     * without this an operator switching a row from "percentage" to "fixed
     * cost" would leave BOTH columns populated — and
     * TradeCostAdjustmentResolver prefers absolute_cost, so the stale
     * percentage would sit there looking authoritative and doing nothing.
     * Same for brand vs sku.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalise(array $data): array
    {
        if (($data['scope_kind'] ?? null) === 'sku') {
            $data['brand_id'] = null;
        } elseif (array_key_exists('scope_kind', $data)) {
            $data['sku'] = null;
        }

        if (($data['value_kind'] ?? null) === 'absolute') {
            $data['adjustment_pct'] = null;
        } elseif (array_key_exists('value_kind', $data)) {
            $data['absolute_cost'] = null;
        }

        unset($data['scope_kind'], $data['value_kind']);

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTradeCostAdjustments::route('/'),
            'create' => Pages\CreateTradeCostAdjustment::route('/create'),
            'edit' => Pages\EditTradeCostAdjustment::route('/{record}/edit'),
        ];
    }
}
