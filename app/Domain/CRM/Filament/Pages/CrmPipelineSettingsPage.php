<?php

declare(strict_types=1);

namespace App\Domain\CRM\Filament\Pages;

use App\Domain\CRM\Models\CrmPipelineSetting;
use App\Domain\CRM\Services\BitrixClient;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

/**
 * Phase 4 Plan 04 — CrmPipelineSettingsPage (D-05, D-07, CRM-07).
 *
 * Singleton Filament Page that edits the ONE row in crm_pipeline_settings
 * seeded by the Plan 04-01 migration. Admin-only via canAccess().
 *
 * Form:
 *   - bitrix_pipeline_id (Select from crm.dealcategory.list via BitrixClient::dealFieldsGet()['CATEGORY_ID']['items'])
 *   - landing_stage_id (Select from crm.dealcategory.stage.list for the selected pipeline)
 *   - assigned_user_id (TextInput — freeform; user.search adapter can come later)
 *   - deal_title_template (TextInput validated against allowed placeholders)
 *
 * Not a Filament Resource because it's a singleton (one row only).
 */
class CrmPipelineSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    // Quick task 260504-ev5 — 8-group nav restructure. CRM pipeline settings
    // moved to dedicated 'CRM & Bitrix' group at sort 40.
    protected static ?string $navigationGroup = 'Settings';

    // 260710-pdw — de-collided within Settings (was 40). Now 90.
    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'CRM Pipeline Settings';

    protected static ?string $title = 'CRM Pipeline Settings';

    protected static string $view = 'filament.pages.crm-pipeline-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $row = CrmPipelineSetting::current();
        $this->form->fill([
            'bitrix_pipeline_id' => $row->bitrix_pipeline_id,
            'landing_stage_id' => $row->landing_stage_id,
            'assigned_user_id' => $row->assigned_user_id,
            'deal_title_template' => $row->deal_title_template,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Pipeline + landing stage')
                ->description('Settings consumed by DealPayloadBuilder (Plan 04-03) when constructing the CATEGORY_ID + STAGE_ID for every new Deal.')
                ->schema([
                    Select::make('bitrix_pipeline_id')
                        ->label('Pipeline (CATEGORY_ID)')
                        ->required()
                        ->live()  // refresh landing_stage_id options on change
                        ->options(fn () => self::pipelineOptions())
                        ->helperText('Populated live from crm.dealcategory.list. Run bitrix:bootstrap first if this list is empty.'),

                    Select::make('landing_stage_id')
                        ->label('Landing STAGE_ID')
                        ->required()
                        ->options(function (Get $get): array {
                            $pipeline = (string) ($get('bitrix_pipeline_id') ?? '');

                            return self::stageOptionsForPipeline($pipeline);
                        })
                        ->helperText('Initial stage for every newly-created Deal. Scoped to the pipeline above.'),
                ]),

            Section::make('Defaults')
                ->schema([
                    TextInput::make('assigned_user_id')
                        ->label('Default assigned user ID')
                        ->maxLength(20)
                        ->nullable()
                        ->helperText('Bitrix user ID (numeric). Leave blank to let Bitrix assign per pipeline rules.'),

                    TextInput::make('deal_title_template')
                        ->label('Deal title template')
                        ->required()
                        ->maxLength(255)
                        ->default('Woo Order #{order_number}')
                        ->helperText('Placeholders: {order_number}, {order_id}, {customer_email}, {customer_name}, {order_date}.'),
                ]),
        ])->statePath('data');
    }

    /** Admin-only — Warning 9 defence-in-depth + per-save authorize. */
    public function save(): void
    {
        abort_unless(auth()->user()?->hasRole('admin') ?? false, 403);

        $state = $this->form->getState();

        $row = CrmPipelineSetting::current();
        $row->update([
            'bitrix_pipeline_id' => $state['bitrix_pipeline_id'] ?? null,
            'landing_stage_id' => $state['landing_stage_id'] ?? null,
            'assigned_user_id' => $state['assigned_user_id'] ?? null,
            'deal_title_template' => $state['deal_title_template'] ?? 'Woo Order #{order_number}',
        ]);

        Notification::make()
            ->success()
            ->title('Pipeline settings saved')
            ->body('DealPayloadBuilder will use these values on the next push.')
            ->send();
    }

    /** Admin-only access (Warning 9). */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * @return array<string, string> pipeline_id => label
     */
    private static function pipelineOptions(): array
    {
        try {
            $fields = app(BitrixClient::class)->dealFieldsGet();
        } catch (Throwable) {
            return ['__error__' => 'Bitrix schema unavailable — configure BITRIX_WEBHOOK_URL first'];
        }

        $items = $fields['CATEGORY_ID']['items'] ?? [];
        if (! is_array($items) || $items === []) {
            return ['0' => '0 — Default pipeline'];
        }

        $options = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (string) ($item['ID'] ?? '');
            $value = (string) ($item['VALUE'] ?? $id);
            if ($id !== '') {
                $options[$id] = "{$id} — {$value}";
            }
        }

        return $options !== [] ? $options : ['0' => '0 — Default pipeline'];
    }

    /**
     * @return array<string, string> STAGE_ID => label options filtered to the given pipeline.
     */
    private static function stageOptionsForPipeline(string $pipelineId): array
    {
        if ($pipelineId === '') {
            return ['__pick_pipeline__' => 'Pick a pipeline first'];
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

        $prefix = 'C'.$pipelineId.':';
        $isDefault = ($pipelineId === '0');
        $options = [];

        foreach ($stageItems as $stage) {
            if (! is_array($stage)) {
                continue;
            }
            $id = (string) ($stage['ID'] ?? '');
            if ($id === '') {
                continue;
            }
            $matchesPrefix = str_starts_with($id, $prefix);
            $unprefixed = ! str_contains($id, ':');
            if (! ($matchesPrefix || ($isDefault && $unprefixed))) {
                continue;
            }
            $value = (string) ($stage['VALUE'] ?? $id);
            $options[$id] = "{$id} — {$value}";
        }

        return $options !== [] ? $options : ['__empty__' => 'No stages for pipeline '.$pipelineId];
    }
}
