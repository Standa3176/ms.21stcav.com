<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Filament\Pages;

use App\Domain\ProductAutoCreate\Models\AutoCreateSetting;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Phase 6 Plan 04 — AutoCreateSettingsPage (AUTO-07, D-09).
 *
 * Singleton Filament Page (ONE page — NOT one-per-record). Admin-only.
 *
 * Form:
 *   - mode (Radio: draft | immediate_publish)  — AUTO-07 default=draft
 *   - cta (TextInput, ≤120 chars)
 *   - optimize_images (Toggle, disabled on Windows with helper text)
 *   - completeness_threshold (Number input, 0-100, default 85)
 *
 * Persists to auto_create_settings singleton row via AutoCreateSetting model.
 * Save fires spatie/activitylog via the model's LogsActivity trait → audit
 * row captures the before/after diff.
 *
 * RBAC: AutoCreateSettingsPolicy (admin-only, hand-written).
 *   - canAccess() returns false for non-admin → Page + route both 403
 *   - save() additionally abort_unless(user->can('update', AutoCreateSetting::class))
 *     as Warning 9 defence-in-depth.
 */
class AutoCreateSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    // Quick task 260504-ev5 — 8-group nav restructure. Auto-Create config
    // belongs with the WooCommerce group (alongside SyncRuns + ImportIssues +
    // Skip Rules) for the auto-create-product workflow surface.
    protected static ?string $navigationGroup = 'Settings';

    // 260710-pdw — de-collided within Settings (was 30, colliding with PricingRule). Now 60.
    protected static ?int $navigationSort = 60;

    protected static ?string $navigationLabel = 'Auto-Create Settings';

    protected static ?string $title = 'Auto-Create Settings';

    protected static ?string $slug = 'auto-create-settings';

    protected static string $view = 'filament.pages.auto-create-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $row = AutoCreateSetting::current();
        $this->form->fill([
            'mode' => $row->mode,
            'cta' => $row->cta,
            'optimize_images' => (bool) $row->optimize_images,
            'completeness_threshold' => (int) $row->completeness_threshold,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Publish mode')
                ->description('AUTO-07: draft-first is the v1 default. Immediate publish is an advanced opt-in requiring an ops runbook entry and close monitoring.')
                ->schema([
                    Radio::make('mode')
                        ->required()
                        ->options([
                            'draft' => 'Draft (v1 default) — every auto-created product lands in the review inbox for human approval.',
                            'immediate_publish' => 'Immediate publish (advanced) — products above the completeness threshold publish without review.',
                        ]),
                ]),

            Section::make('Content defaults')
                ->schema([
                    TextInput::make('cta')
                        ->label('Meta description CTA')
                        ->required()
                        ->maxLength(120)
                        ->helperText('Trailer line appended to meta_description (e.g. "Shop now at meetingstore.co.uk"). Keep ≤120 chars so the full meta stays ≤160.'),

                    Toggle::make('optimize_images')
                        ->label('Optimise images (mozjpeg / pngquant / webp)')
                        ->helperText(PHP_OS_FAMILY === 'Windows'
                            ? 'Disabled on Windows dev — binaries absent. Enable on Linux VPS for ~30% smaller WebP output.'
                            : 'Runs spatie/image-optimizer after intervention/image conversion. Gracefully degrades if binaries missing.')
                        ->disabled(PHP_OS_FAMILY === 'Windows'),

                    TextInput::make('completeness_threshold')
                        ->label('Completeness publish threshold')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(85)
                        ->helperText('D-09: products below this score require an override reason to publish. Bulk approve silently skips rows below threshold.'),
                ]),
        ])->statePath('data');
    }

    /**
     * Save handler — admin-only via canAccess() + per-save abort_unless
     * (Warning 9 defence-in-depth on top of route-level access control).
     */
    public function save(): void
    {
        abort_unless(
            auth()->user()?->can('update', AutoCreateSetting::class) ?? false,
            403,
        );

        $state = $this->form->getState();

        $row = AutoCreateSetting::current();
        $row->update([
            'mode' => $state['mode'] ?? 'draft',
            'cta' => $state['cta'] ?? 'Shop now at meetingstore.co.uk',
            'optimize_images' => (bool) ($state['optimize_images'] ?? false),
            'completeness_threshold' => (int) ($state['completeness_threshold'] ?? 85),
        ]);

        // Supplemental activity log — LogsActivity trait already captured
        // the diff; this entry records the admin causer + "settings saved"
        // semantic event so operations dashboards can filter on it.
        activity()
            ->performedOn($row)
            ->causedBy(auth()->user())
            ->withProperties($state)
            ->log('auto_create.settings.updated');

        Notification::make()
            ->success()
            ->title('Auto-create settings saved')
            ->body('Mode + CTA + thresholds updated; changes apply to the next CreateWooProductJob run.')
            ->send();
    }

    /** Admin-only access gate (Warning 9). */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('update', AutoCreateSetting::class) ?? false;
    }
}
