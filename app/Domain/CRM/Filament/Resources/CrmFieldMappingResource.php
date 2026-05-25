<?php

declare(strict_types=1);

namespace App\Domain\CRM\Filament\Resources;

use App\Domain\CRM\Filament\Resources\CrmFieldMappingResource\Pages;
use App\Domain\CRM\Models\CrmFieldMapping;
use App\Domain\CRM\Services\BitrixSchemaCache;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

/**
 * Phase 4 Plan 04 — CrmFieldMappingResource (CRM-06, CRM-02).
 *
 * Admin-editable CRUD over `crm_field_mappings` with:
 *   - entity_type filter (Deal | Contact | Company | All)
 *   - "Refresh from Bitrix" header action (invalidates BitrixSchemaCache +
 *     re-warms each entity's schema; gated via ->authorize(admin))
 *   - bitrix_field Select populated from BitrixSchemaCache::fieldsFor($entity)
 *     so admins pick from real Bitrix fields, not free-text
 *   - Per-save validation: BitrixSchemaCache::validateMapping() rejects stale
 *     UF_CRM_* names with a per-row error
 *
 * Gate: CrmFieldMappingPolicy (admin-only, hand-written hasRole). Every
 * Filament Action below also chains ->authorize() as Warning 9 defence-in-depth.
 */
class CrmFieldMappingResource extends Resource
{
    protected static ?string $model = CrmFieldMapping::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    // Quick task 260504-ev5 — 8-group nav restructure. CRM field mapping
    // moved to dedicated 'CRM & Bitrix' group at sort 10.
    protected static ?string $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'woo_field';

    protected static ?string $pluralModelLabel = 'CRM Field Mappings';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('entity_type')
                ->label('Entity')
                ->required()
                ->reactive()  // so the bitrix_field options refresh when entity changes
                ->options([
                    CrmFieldMapping::ENTITY_DEAL => 'Deal',
                    CrmFieldMapping::ENTITY_CONTACT => 'Contact',
                    CrmFieldMapping::ENTITY_COMPANY => 'Company',
                ])
                ->helperText('Which Bitrix entity this mapping targets.'),

            TextInput::make('woo_field')
                ->label('Woo source field')
                ->required()
                ->maxLength(100)
                ->helperText('Dot-path into the Woo order/customer payload — e.g. "billing.first_name", "customer_id", "_ms_utm_source", "line_items".'),

            Select::make('bitrix_field')
                ->label('Bitrix target field')
                ->required()
                ->searchable()
                ->helperText('Populated live from BitrixSchemaCache. If the field list is stale, use Refresh from Bitrix above.')
                ->options(function (Get $get): array {
                    $entity = $get('entity_type');
                    if (! is_string($entity) || $entity === '') {
                        return [];
                    }
                    try {
                        $fields = app(BitrixSchemaCache::class)->fieldsFor($entity);
                    } catch (Throwable) {
                        // Surface a visible option instead of silently returning empty.
                        return ['__error__' => 'Bitrix schema unavailable — click Refresh from Bitrix'];
                    }

                    $options = [];
                    foreach ($fields as $name => $def) {
                        if (! is_string($name) || $name === '') {
                            continue;
                        }
                        $label = $name;
                        if (is_array($def)) {
                            $descriptor = (string) ($def['title'] ?? $def['formLabel'] ?? $def['USER_TYPE_ID'] ?? '');
                            if ($descriptor !== '') {
                                $label = "{$name} — {$descriptor}";
                            }
                        }
                        $options[$name] = $label;
                    }

                    return $options;
                })
                ->rules([
                    // Per-save stale-mapping detector — rejects bitrix_field values
                    // that no longer exist in the live Bitrix schema.
                    fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                        if (! is_string($value) || $value === '' || $value === '__error__') {
                            return;
                        }
                        $entity = $get('entity_type');
                        if (! is_string($entity) || $entity === '') {
                            return;
                        }
                        try {
                            $valid = app(BitrixSchemaCache::class)->validateMapping($entity, $value);
                        } catch (Throwable) {
                            $fail("Bitrix schema could not be validated. Click 'Refresh from Bitrix' and try again.");

                            return;
                        }
                        if (! $valid) {
                            $fail("Field '{$value}' not found in current Bitrix {$entity} schema. Click Refresh from Bitrix if this field was recently added.");
                        }
                    },
                ]),

            Toggle::make('is_custom')
                ->label('Is custom Bitrix field (UF_CRM_*)?')
                ->default(fn (Get $get) => str_starts_with((string) $get('bitrix_field'), 'UF_CRM_'))
                ->helperText('Auto-inferred from UF_CRM_ prefix; toggle to override.'),

            Select::make('transformer')
                ->label('Transformer')
                ->nullable()
                ->options([
                    'none' => 'None (raw value)',
                    'uppercase' => 'Uppercase',
                    'phone_e164' => 'Phone → E.164',
                    'join_line_items' => 'Line items → string summary',
                ])
                ->default('none')
                ->helperText('Applied in PayloadTransformer at push time. Leave null to pass the raw Woo value through.'),

            TextInput::make('sort_order')
                ->label('Sort order')
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->maxValue(999)
                ->helperText('Render/apply order — lower first. Default 0.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('entity_type')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        CrmFieldMapping::ENTITY_DEAL => 'success',
                        CrmFieldMapping::ENTITY_CONTACT => 'primary',
                        CrmFieldMapping::ENTITY_COMPANY => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('woo_field')
                    ->searchable()
                    ->sortable()
                    ->label('Woo field'),

                TextColumn::make('bitrix_field')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->label('Bitrix field'),

                IconColumn::make('is_custom')
                    ->boolean()
                    ->label('Custom?'),

                TextColumn::make('transformer')
                    ->badge()
                    ->placeholder('—')
                    ->color('gray'),

                TextColumn::make('sort_order')
                    ->numeric()
                    ->sortable()
                    ->label('Order'),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Updated'),
            ])
            ->defaultSort('entity_type')
            ->filters([
                SelectFilter::make('entity_type')
                    ->multiple()
                    ->options([
                        CrmFieldMapping::ENTITY_DEAL => 'Deal',
                        CrmFieldMapping::ENTITY_CONTACT => 'Contact',
                        CrmFieldMapping::ENTITY_COMPANY => 'Company',
                    ]),
            ])
            ->headerActions([
                // Phase 1 Warning 9: ->authorize() is the hard gate. ->visible() alone
                // can be bypassed by crafted POSTs. Both layers, always.
                Action::make('refresh_bitrix_schema')
                    ->label('Refresh from Bitrix')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (): bool => auth()->user()?->hasRole('admin') ?? false)
                    ->authorize(fn (): bool => auth()->user()?->hasRole('admin') ?? false)
                    ->requiresConfirmation()
                    ->modalHeading('Refresh Bitrix field schema cache?')
                    ->modalDescription('Invalidates the 24h cache + re-fetches deal/contact/company field lists from the live Bitrix tenant. Uses 3 API calls.')
                    ->action(function (): void {
                        $cache = app(BitrixSchemaCache::class);
                        $cache->invalidate();
                        $counts = [];
                        try {
                            foreach (BitrixSchemaCache::ENTITIES as $entity) {
                                $fields = $cache->fieldsFor($entity);
                                $counts[$entity] = is_countable($fields) ? count($fields) : 0;
                            }
                        } catch (Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('Bitrix schema refresh FAILED')
                                ->body($e->getMessage())
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Bitrix schema refreshed')
                            ->body(sprintf(
                                'Deal: %d fields · Contact: %d fields · Company: %d fields.',
                                $counts['deal'] ?? 0,
                                $counts['contact'] ?? 0,
                                $counts['company'] ?? 0,
                            ))
                            ->send();
                    }),
            ])
            ->actions([
                EditAction::make()
                    ->authorize(fn (CrmFieldMapping $record): bool => auth()->user()?->can('update', $record) ?? false),
                DeleteAction::make()
                    ->authorize(fn (CrmFieldMapping $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCrmFieldMappings::route('/'),
            'create' => Pages\CreateCrmFieldMapping::route('/create'),
            'edit' => Pages\EditCrmFieldMapping::route('/{record}/edit'),
        ];
    }
}
