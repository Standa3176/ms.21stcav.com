<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Filament\Resources;

use App\Domain\TradePricing\Filament\Resources\CustomerGroupResource\Pages;
use App\Domain\TradePricing\Models\CustomerGroup;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Phase 9 Plan 05 — CustomerGroupResource (TRDE-04 D-10).
 *
 * Filament CRUD surface for the customer_groups lookup table. Admin +
 * pricing_manager can CRUD; sales is view-only (gated by CustomerGroupPolicy);
 * read_only is locked out entirely.
 *
 * I-01 invariant — $navigationSort = 11 (PricingRuleResource is at 10). The
 * value is asserted distinct from PricingRuleResource::$navigationSort by
 * CustomerGroupResourceTest::it('CustomerGroupResource navigationSort does
 * not collide with PricingRuleResource'). Bumping either Resource's value
 * fails CI.
 *
 * Pitfall 7 — defaultSort('display_order') gives ops a predictable dropdown
 * ordering across the app (PricingRuleResource Select + future customer
 * profile screens both reach for this Resource's data shape).
 *
 * FK ON DELETE RESTRICT (Plan 09-01 schema): deleting a group with active
 * pricing_rules raises QueryException. Filament catches and renders the
 * actionable error to the operator. Assertion locked by CustomerGroupResourceTest::
 * it('deleting a CustomerGroup with active pricing_rules raises a Filament-handled error').
 */
class CustomerGroupResource extends Resource
{
    protected static ?string $model = CustomerGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Settings';

    // I-01 — distinct from PricingRuleResource::$navigationSort (now 70).
    // Reflection-based test in CustomerGroupResourceNavigationSortTest fails CI on collision.
    // 260710-pdw — de-collided within Settings (was 25). Now 110.
    protected static ?int $navigationSort = 110;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Customer Group';

    protected static ?string $pluralModelLabel = 'Customer Groups';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('slug')
                ->required()
                ->unique(ignoreRecord: true)
                ->alphaDash()
                ->maxLength(64)
                ->helperText('lowercase-with-hyphens — used in role_to_group_map config and URL slugs'),

            TextInput::make('name')
                ->required()
                ->maxLength(128)
                ->helperText('Display name shown in PricingRule Select + customer profile.'),

            TextInput::make('display_order')
                ->numeric()
                ->default(100)
                ->helperText('Lower = higher in dropdowns. Seed values: trade=10, reseller=20, education=30, nhs=40.'),

            Toggle::make('is_active')
                ->default(true)
                ->helperText('Inactive groups are skipped by RoleToGroupMapper — use this to retire a group without deleting (preserves audit trail).'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_order')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('slug')
                    ->fontFamily('mono')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('name')
                    ->searchable(),

                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->since()
                    ->label('Updated'),
            ])
            ->defaultSort('display_order')   // Pitfall 7 — predictable ordering.
            ->actions([
                // Warning 9 defence-in-depth: ->authorize() on every action.
                EditAction::make()
                    ->authorize(fn (CustomerGroup $record): bool => auth()->user()?->can('update', $record) ?? false),
                DeleteAction::make()
                    ->authorize(fn (CustomerGroup $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->bulkActions([
                DeleteBulkAction::make()
                    ->authorize(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomerGroups::route('/'),
            'create' => Pages\CreateCustomerGroup::route('/create'),
            'edit' => Pages\EditCustomerGroup::route('/{record}/edit'),
        ];
    }
}
