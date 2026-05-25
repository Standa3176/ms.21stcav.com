<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Filament\Resources;

use App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateSkipRuleResource\Pages;
use App\Domain\ProductAutoCreate\Models\AutoCreateRejection;
use App\Domain\ProductAutoCreate\Models\AutoCreateSkipRule;
use App\Domain\ProductAutoCreate\Rules\ValidPregPattern;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Phase 6 Plan 04 — Auto-Create Skip Rule Resource (D-04, T-06-04-01).
 *
 * Admin-editable CRUD over auto_create_skip_rules. Admin only for mutations;
 * pricing_manager can view (read-only insight into vendor-exclusion policy).
 *
 * Key form validations:
 *   - sku_pattern scope: `value` must pass ValidPregPattern custom Rule
 *     (compiles cleanly + survives ReDoS budget). Belt-and-braces with the
 *     AutoCreateSkipRule model's 256-char cap + @preg_match.
 *   - price_range scope: `value` must match `<N` / `>N` / `N-M` regex
 *     (inclusive bounds on ranges — same shape AutoCreateSkipRule parses).
 *   - brand / category scopes: free text, required.
 *
 * Navigation: under 'Product Operations' group so it clusters with the
 * Review Resource + Settings Page.
 *
 * RBAC: AutoCreateSkipRulePolicy (hand-written, Phase 6 Plan 01).
 *   - viewAny/view: admin + pricing_manager
 *   - create/update/delete: admin only
 *   - sales + read_only: denied entirely.
 */
class AutoCreateSkipRuleResource extends Resource
{
    protected static ?string $model = AutoCreateSkipRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    // Quick task 260504-ev5 — 8-group nav restructure. Skip rules are part
    // of the auto-create flow — moved to 'WooCommerce' group at sort 40.
    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Auto-Create Skip Rules';

    protected static ?string $modelLabel = 'Skip Rule';

    protected static ?string $pluralModelLabel = 'Skip Rules';

    protected static ?string $slug = 'auto-create-skip-rules';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('scope')
                ->required()
                ->reactive()
                ->options([
                    AutoCreateSkipRule::SCOPE_BRAND => 'Brand (exact match, case-insensitive)',
                    AutoCreateSkipRule::SCOPE_CATEGORY => 'Category (exact match, case-insensitive)',
                    AutoCreateSkipRule::SCOPE_SKU_PATTERN => 'SKU pattern (regex)',
                    AutoCreateSkipRule::SCOPE_PRICE_RANGE => 'Price range (e.g. <25 / >500 / 25-100)',
                ])
                ->helperText('Chooses which field the `value` below is matched against.'),

            TextInput::make('value')
                ->required()
                ->maxLength(256)
                ->helperText(fn (Get $get) => match ($get('scope')) {
                    AutoCreateSkipRule::SCOPE_SKU_PATTERN => 'Regex fragment (no delimiters). Must compile + survive a 50ms ReDoS budget.',
                    AutoCreateSkipRule::SCOPE_PRICE_RANGE => 'Accepted shapes: <N, >N, or N-M (GBP). Inclusive on both ends of ranges.',
                    AutoCreateSkipRule::SCOPE_BRAND => 'Brand name as seen in the supplier feed (case-insensitive + trim-tolerant).',
                    AutoCreateSkipRule::SCOPE_CATEGORY => 'Category name as seen in the supplier feed (case-insensitive + trim-tolerant).',
                    default => 'Value matched per the scope above.',
                })
                ->rules(fn (Get $get): array => match ($get('scope')) {
                    AutoCreateSkipRule::SCOPE_SKU_PATTERN => [new ValidPregPattern],
                    AutoCreateSkipRule::SCOPE_PRICE_RANGE => [
                        'regex:/^[<>]\d+(\.\d+)?$|^\d+(\.\d+)?-\d+(\.\d+)?$/',
                    ],
                    default => [],
                }),

            Select::make('reason')
                ->required()
                ->options([
                    AutoCreateRejection::REASON_NOT_A_REAL_PRODUCT => 'Not a real product',
                    AutoCreateRejection::REASON_DUPLICATE_OF_EXISTING => 'Duplicate of existing',
                    AutoCreateRejection::REASON_DISCONTINUED_BY_SUPPLIER => 'Discontinued by supplier',
                    AutoCreateRejection::REASON_SPARE_PART_OR_ACCESSORY => 'Spare part or accessory',
                    AutoCreateRejection::REASON_POOR_QUALITY_DATA => 'Poor quality data',
                    AutoCreateRejection::REASON_MISCLASSIFIED_BRAND_OR_CATEGORY => 'Misclassified brand or category',
                    AutoCreateRejection::REASON_BELOW_VIABILITY_THRESHOLD => 'Below viability threshold',
                    AutoCreateRejection::REASON_OTHER => 'Other',
                ])
                ->helperText('Mirrors AutoCreateRejection reasons for analytics continuity.'),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Inactive rules are kept for history but not evaluated at event time.'),

            Hidden::make('created_by_user_id')
                ->default(fn () => auth()->id()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scope')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AutoCreateSkipRule::SCOPE_BRAND => 'primary',
                        AutoCreateSkipRule::SCOPE_CATEGORY => 'info',
                        AutoCreateSkipRule::SCOPE_SKU_PATTERN => 'warning',
                        AutoCreateSkipRule::SCOPE_PRICE_RANGE => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('value')
                    ->searchable()
                    ->fontFamily('mono')
                    ->limit(60),
                TextColumn::make('reason')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active?'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('scope')->options([
                    AutoCreateSkipRule::SCOPE_BRAND => 'Brand',
                    AutoCreateSkipRule::SCOPE_CATEGORY => 'Category',
                    AutoCreateSkipRule::SCOPE_SKU_PATTERN => 'SKU pattern',
                    AutoCreateSkipRule::SCOPE_PRICE_RANGE => 'Price range',
                ]),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->actions([
                EditAction::make()
                    ->authorize(fn ($record) => auth()->user()?->can('update', $record) ?? false),
                DeleteAction::make()
                    ->authorize(fn ($record) => auth()->user()?->can('delete', $record) ?? false),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAutoCreateSkipRules::route('/'),
            'create' => Pages\CreateAutoCreateSkipRule::route('/create'),
            'edit' => Pages\EditAutoCreateSkipRule::route('/{record}/edit'),
        ];
    }
}
