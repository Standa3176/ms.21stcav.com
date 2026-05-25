<?php

declare(strict_types=1);

namespace App\Domain\CRM\Filament\Resources;

use App\Domain\CRM\Filament\Resources\CrmStatusMappingResource\Pages;
use App\Domain\CRM\Models\CrmPipelineSetting;
use App\Domain\CRM\Models\CrmStatusMapping;
use App\Domain\CRM\Services\BitrixClient;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

/**
 * Phase 4 Plan 04 — CrmStatusMappingResource (D-06, CRM-07).
 *
 * Admin-editable CRUD over `crm_status_mappings` (seeded with the 7 standard
 * Woo statuses in Plan 04-01). The `bitrix_stage_id` Select is populated live
 * from `crm.deal.fields -> STAGE_ID.items` filtered by the configured pipeline.
 *
 * Save validation: bitrix_stage_id must exist for the current pipeline. If a
 * user changes the pipeline in CrmPipelineSettingsPage, previously-saved
 * stage IDs may become stale — the form flags this on next edit.
 */
class CrmStatusMappingResource extends Resource
{
    protected static ?string $model = CrmStatusMapping::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    // Quick task 260504-ev5 — 8-group nav restructure. CRM status mapping
    // moved to dedicated 'CRM & Bitrix' group at sort 20.
    protected static ?string $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'woo_status';

    protected static ?string $pluralModelLabel = 'CRM Status Mappings';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('woo_status')
                ->label('Woo order status')
                ->required()
                ->maxLength(40)
                ->helperText('The WooCommerce order status slug (e.g. "processing", "on-hold"). Seeded rows cover the standard 7.'),

            Select::make('bitrix_stage_id')
                ->label('Bitrix Deal STAGE_ID')
                ->required()
                ->searchable()
                ->helperText('Pipeline-filtered stage picker. Configure the pipeline first on the CRM Pipeline Settings page.')
                ->options(function (): array {
                    return self::pipelineStageOptions();
                })
                ->rules([
                    function (): \Closure {
                        return function (string $attribute, $value, \Closure $fail): void {
                            if (! is_string($value) || $value === '' || $value === '__not_configured__') {
                                return;
                            }
                            $valid = array_key_exists($value, self::pipelineStageOptions());
                            if (! $valid) {
                                $fail("Stage '{$value}' does not exist in the configured pipeline. Re-select from the dropdown or run Refresh from Bitrix on the Field Mappings page.");
                            }
                        };
                    },
                ]),

            TextInput::make('bitrix_stage_label')
                ->label('Friendly label')
                ->maxLength(40)
                ->nullable()
                ->helperText('Display label for admin reference — seeded with legacy-plugin values; override freely.'),

            Toggle::make('is_terminal')
                ->label('Terminal stage?')
                ->helperText('D-09: terminal→non-terminal transitions are blocked (prevents accidental "un-cancel" pushes).'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('woo_status')
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->searchable()
                    ->label('Woo status'),

                TextColumn::make('bitrix_stage_label')
                    ->placeholder('—')
                    ->label('Stage label'),

                TextColumn::make('bitrix_stage_id')
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->label('STAGE_ID'),

                IconColumn::make('is_terminal')
                    ->boolean()
                    ->label('Terminal?'),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Updated'),
            ])
            ->defaultSort('woo_status')
            ->actions([
                EditAction::make()
                    ->authorize(fn (CrmStatusMapping $record): bool => auth()->user()?->can('update', $record) ?? false),
                DeleteAction::make()
                    ->authorize(fn (CrmStatusMapping $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCrmStatusMappings::route('/'),
            'create' => Pages\CreateCrmStatusMapping::route('/create'),
            'edit' => Pages\EditCrmStatusMapping::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string, string> STAGE_ID => Label options for the Bitrix deal
     *                               stage picker, filtered to the configured pipeline.
     */
    private static function pipelineStageOptions(): array
    {
        $pipeline = CrmPipelineSetting::query()->first();
        // Careful: empty('0') === true, so don't use empty() for ID strings.
        if ($pipeline === null || $pipeline->bitrix_pipeline_id === null || $pipeline->bitrix_pipeline_id === '') {
            return ['__not_configured__' => 'Configure pipeline first at CRM Pipeline Settings'];
        }

        try {
            $fields = app(BitrixClient::class)->dealFieldsGet();
        } catch (Throwable) {
            return ['__error__' => 'Bitrix schema unavailable — click Refresh from Bitrix on Field Mappings'];
        }

        $stageItems = $fields['STAGE_ID']['items'] ?? [];
        if (! is_array($stageItems)) {
            return [];
        }

        $pipelineId = (string) $pipeline->bitrix_pipeline_id;
        $prefix = 'C'.$pipelineId.':';
        $options = [];

        foreach ($stageItems as $stage) {
            if (! is_array($stage)) {
                continue;
            }
            $id = (string) ($stage['ID'] ?? '');
            if ($id === '') {
                continue;
            }
            // Keep stages for this pipeline; the default pipeline uses unprefixed IDs
            // (NEW, PREPAYMENT_INVOICE, etc.) so allow both conventions.
            $isDefaultPipeline = ($pipelineId === '0' || $pipelineId === '');
            $matchesPrefix = str_starts_with($id, $prefix);
            $unprefixed = ! str_contains($id, ':');
            if (! ($matchesPrefix || ($isDefaultPipeline && $unprefixed))) {
                continue;
            }
            $value = (string) ($stage['VALUE'] ?? $id);
            $options[$id] = "{$id} — {$value}";
        }

        if ($options === []) {
            return ['__empty__' => 'No stages found for pipeline '.$pipelineId];
        }

        return $options;
    }
}
