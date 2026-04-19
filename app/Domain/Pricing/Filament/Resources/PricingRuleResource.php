<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Filament\Resources;

use App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages;
use App\Domain\Pricing\Models\PricingRule;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Phase 3 Plan 03 — PricingRuleResource (PRCE-02, PRCE-08, PRCE-09).
 *
 * CRUD surface for PricingRule (scope / brand_category / category / brand /
 * default_tier). Admin + pricing_manager edit; sales + read_only view-only.
 *
 * Custom pages (registered via getPages()):
 *   - RuleExplorer    — type a SKU → effective price + full resolution chain (PRCE-08)
 *   - SimulatedImpact — edit a rule → see N SKUs that would change before save (PRCE-09)
 *
 * Gate wiring: PricingRulePolicy is hand-written (see its docblock). Filament's
 * implicit policy checks route through Gate::policy binding in AppServiceProvider.
 * Bulk + row-level actions call ->authorize() explicitly (Phase 1 Warning 9
 * defence-in-depth).
 */
class PricingRuleResource extends Resource
{
    protected static ?string $model = PricingRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Pricing';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $pluralModelLabel = 'Pricing Rules';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('scope')
                ->label('Scope')
                ->required()
                ->reactive()
                ->options([
                    PricingRule::SCOPE_BRAND => 'Brand only',
                    PricingRule::SCOPE_CATEGORY => 'Category only',
                    PricingRule::SCOPE_BRAND_CATEGORY => 'Brand + Category',
                    PricingRule::SCOPE_DEFAULT_TIER => 'Default tier',
                ]),

            // brand_id / category_id are numeric inputs — no brands / categories
            // tables exist in Phase 3 v1 (D-06 deferred). Phase 6 auto-create
            // will populate these when the taxonomy tables land.
            TextInput::make('brand_id')
                ->label('Brand ID')
                ->numeric()
                ->nullable()
                ->visible(fn ($get) => in_array($get('scope'), [
                    PricingRule::SCOPE_BRAND,
                    PricingRule::SCOPE_BRAND_CATEGORY,
                ], true))
                ->helperText('Matches product.brand_id / variant.brand_id (Phase 6 taxonomy).'),

            TextInput::make('category_id')
                ->label('Category ID')
                ->numeric()
                ->nullable()
                ->visible(fn ($get) => in_array($get('scope'), [
                    PricingRule::SCOPE_CATEGORY,
                    PricingRule::SCOPE_BRAND_CATEGORY,
                ], true))
                ->helperText('Matches product.category_id / variant.category_id (Phase 6 taxonomy).'),

            TextInput::make('margin_basis_points')
                ->label('Margin (basis points)')
                ->numeric()
                ->required()
                ->helperText('2200 = 22.00%. Integer pennies math (D-03) — no float % input here.'),

            TextInput::make('priority')
                ->label('Priority')
                ->numeric()
                ->default(100)
                ->required()
                ->helperText('Higher wins on ties within a scope (D-07). Default 100.'),

            Toggle::make('is_default_tier')
                ->label('Default tier fallback?')
                ->reactive()
                ->helperText('When true, brand/category must be NULL and tier_min/tier_max scope which supplier buy_price applies.'),

            TextInput::make('tier_min_pennies')
                ->label('Tier min (pennies)')
                ->numeric()
                ->nullable()
                ->visible(fn ($get) => (bool) $get('is_default_tier'))
                ->helperText('Inclusive lower bound. 0 = from £0.'),

            TextInput::make('tier_max_pennies')
                ->label('Tier max (pennies)')
                ->numeric()
                ->nullable()
                ->visible(fn ($get) => (bool) $get('is_default_tier'))
                ->helperText('Inclusive upper bound. Leave NULL for open-ended top tier.'),

            Toggle::make('active')
                ->label('Active')
                ->default(true)
                ->helperText('Inactive rules are skipped by the resolver — use this to A/B experiments without deleting.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable()->label('#'),

                TextColumn::make('scope')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        PricingRule::SCOPE_BRAND_CATEGORY => 'success',
                        PricingRule::SCOPE_CATEGORY => 'warning',
                        PricingRule::SCOPE_BRAND => 'primary',
                        PricingRule::SCOPE_DEFAULT_TIER => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('brand_id')->label('Brand')->placeholder('—'),
                TextColumn::make('category_id')->label('Category')->placeholder('—'),

                TextColumn::make('margin_basis_points')
                    ->label('Margin')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state / 100, 2).'%')
                    ->sortable(),

                TextColumn::make('priority')->numeric()->sortable(),

                IconColumn::make('is_default_tier')->boolean()->label('Default?'),

                TextColumn::make('tier_min_pennies')
                    ->label('Tier min')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : '£'.number_format($state / 100, 2)),

                TextColumn::make('tier_max_pennies')
                    ->label('Tier max')
                    ->formatStateUsing(fn ($state) => $state === null ? '∞' : '£'.number_format($state / 100, 2)),

                IconColumn::make('active')->boolean()->label('Active'),

                TextColumn::make('updated_at')->dateTime()->sortable()->label('Updated'),
            ])
            ->defaultSort('priority', 'desc')
            ->filters([
                TernaryFilter::make('active')->label('Active only')->default(true),
                SelectFilter::make('scope')->multiple()->options([
                    PricingRule::SCOPE_BRAND => 'Brand',
                    PricingRule::SCOPE_CATEGORY => 'Category',
                    PricingRule::SCOPE_BRAND_CATEGORY => 'Brand + Category',
                    PricingRule::SCOPE_DEFAULT_TIER => 'Default tier',
                ]),
            ])
            ->actions([
                // Warning 9 defence-in-depth: ->authorize() on every action.
                EditAction::make()
                    ->authorize(fn (PricingRule $record): bool => auth()->user()?->can('update', $record) ?? false),
                DeleteAction::make()
                    ->authorize(fn (PricingRule $record): bool => auth()->user()?->can('delete', $record) ?? false),

                // Drill-down to Simulated Impact for this rule.
                \Filament\Tables\Actions\Action::make('simulated_impact')
                    ->label('Simulated Impact')
                    ->icon('heroicon-o-chart-bar')
                    ->authorize(fn (PricingRule $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->url(fn (PricingRule $record): string => static::getUrl('simulated-impact', ['record' => $record])),
            ])
            ->bulkActions([
                DeleteBulkAction::make()
                    ->authorize(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPricingRules::route('/'),
            'create' => Pages\CreatePricingRule::route('/create'),
            'edit' => Pages\EditPricingRule::route('/{record}/edit'),
            'rule-explorer' => Pages\RuleExplorer::route('/rule-explorer'),
            'simulated-impact' => Pages\SimulatedImpact::route('/{record}/simulated-impact'),
        ];
    }
}
