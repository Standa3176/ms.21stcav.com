<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Filament\Resources;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Enums\IntegrationTestStatus;
use App\Domain\Integrations\Filament\Actions\TestIntegrationAction;
use App\Domain\Integrations\Filament\Resources\IntegrationCredentialResource\Pages;
use App\Domain\Integrations\Models\IntegrationCredential;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Phase 09.1 Plan 01 — IntegrationCredentialResource (D-05 + D-09 + D-12 + D-13).
 *
 * Single Filament Resource managing the 5 integration kinds via a polymorphic
 * payload_encrypted column. Form is kind-aware via live() — selecting a kind
 * rebuilds the secondary fields based on IntegrationCredentialKind::requiredFields().
 *
 * Admin-only Resource. pricing_manager / sales / read_only all 403 (D-12).
 * Every Action calls ->authorize() to honour the policy at the action layer (D-13).
 *
 * Test connection per-row dispatches per-kind via TestIntegrationAction (D-11)
 * and writes back last_test_at + last_test_status + last_test_error +
 * last_test_latency_ms so ops can see history.
 *
 * Form `payload_encrypted.{field}` dot-notation produces a nested array on
 * save; the model's `'encrypted:array'` cast handles JSON-serialisation +
 * AES-256 encryption transparently. Edit-form fields use dehydrated(filled)
 * so blank-on-edit means "keep existing" (critical UX so admins don't
 * accidentally wipe credentials).
 */
class IntegrationCredentialResource extends Resource
{
    protected static ?string $model = IntegrationCredential::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    // Quick task 260504-ev5 — 8-group nav restructure. Integration creds
    // (Anthropic/OpenAI/Woo/Bitrix/Langfuse) stay in Admin group at sort 10.
    protected static ?string $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Integration Credentials';

    protected static ?string $modelLabel = 'Integration Credential';

    protected static ?string $pluralModelLabel = 'Integration Credentials';

    protected static ?string $slug = 'integration-credentials';

    protected static ?int $navigationSort = 10;

    /**
     * Quick task 260504-ev5 — warning badge when any credential row has been
     * deactivated (is_active=false). Inactive integration creds typically
     * mean an outage in progress — surface that in the sidebar so admins
     * can fix the connection before downstream agents burn budget against
     * a misconfigured upstream.
     */
    public static function getNavigationBadge(): ?string
    {
        // Defensive: badge runs on every sidebar render — failed query (missing table, broken connection) must not 500 the entire admin.
        try {
            $count = IntegrationCredential::query()->where('is_active', false)->count();
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('kind')
                ->required()
                ->disabledOn('edit') // kind cannot change after create — UNIQUE constraint would otherwise crash
                ->options(collect(IntegrationCredentialKind::cases())
                    ->mapWithKeys(fn (IntegrationCredentialKind $k) => [$k->value => $k->label()])
                    ->all())
                ->live()
                ->afterStateUpdated(function ($state, callable $set): void {
                    if ($state) {
                        $kind = IntegrationCredentialKind::from($state);
                        $set('name', $kind->label());
                    }
                }),

            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->helperText('Display label, e.g. "Production Supplier API"'),

            // D-05 — kind-aware sub-form. live() on the kind Select above triggers
            // a re-render; this Group's schema callback inspects $get('kind') and
            // emits the right fields per IntegrationCredentialKind::requiredFields().
            Group::make()->schema(function (callable $get): array {
                $kindValue = $get('kind');
                if (! $kindValue) {
                    return [];
                }

                $kind = is_string($kindValue) ? IntegrationCredentialKind::tryFrom($kindValue) : $kindValue;
                if (! $kind instanceof IntegrationCredentialKind) {
                    return [];
                }

                $fields = [];
                foreach ($kind->requiredFields() as $field) {
                    $isUrlField = str_contains($field, 'url') || $field === 'host';

                    $input = TextInput::make("payload_encrypted.{$field}")
                        ->label(Str::headline($field))
                        ->maxLength(2048)
                        ->dehydrated(fn ($state): bool => filled($state))
                        ->helperText('Encrypted at rest. Leave blank on edit to keep the existing value.');

                    if ($isUrlField) {
                        $input = $input->url();
                    } else {
                        $input = $input->password()->revealable();
                    }

                    $fields[] = $input;
                }

                return $fields;
            })->columnSpanFull(),

            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof IntegrationCredentialKind
                        ? $state->label()
                        : (string) $state)
                    ->color(fn ($state) => $state instanceof IntegrationCredentialKind
                        ? $state->color()
                        : 'gray')
                    ->sortable(),
                TextColumn::make('name')->sortable()->searchable(),
                ToggleColumn::make('is_active'),
                TextColumn::make('last_test_status')
                    ->label('Last Test')
                    ->badge()
                    ->color(fn ($state) => match ($state instanceof IntegrationTestStatus ? $state->value : $state) {
                        'ok' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('— never tested'),
                TextColumn::make('last_test_at')
                    ->label('Tested At')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—'),
                TextColumn::make('last_test_latency_ms')
                    ->label('Latency')
                    ->suffix(' ms')
                    ->placeholder('—'),
            ])
            ->defaultSort('kind', 'asc')
            ->actions([
                EditAction::make()
                    ->authorize(fn ($record): bool => auth()->user()?->can('update', $record) ?? false),
                TestIntegrationAction::make(),
                DeleteAction::make()
                    ->authorize(fn ($record): bool => auth()->user()?->can('delete', $record) ?? false),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIntegrationCredentials::route('/'),
            'create' => Pages\CreateIntegrationCredential::route('/create'),
            'edit' => Pages\EditIntegrationCredential::route('/{record}/edit'),
        ];
    }
}
