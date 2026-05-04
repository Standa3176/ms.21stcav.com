<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Filament\Resources;

use App\Domain\Alerting\Filament\Resources\AlertRecipientResource\Pages;
use App\Domain\Alerting\Models\AlertRecipient;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin-only CRUD for the failed-job alert distribution list (D-12).
 *
 * Access enforced by AlertRecipientPolicy — every gate requires
 * $user->hasRole('admin'). read_only/sales/pricing_manager all 403.
 */
class AlertRecipientResource extends Resource
{
    protected static ?string $model = AlertRecipient::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    // Quick task 260504-ev5 — 8-group nav restructure. Alert recipients sit
    // in Admin group at sort 20 (after Integration Credentials@10).
    protected static ?string $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Alert Recipients';

    protected static ?string $modelLabel = 'Alert Recipient';

    protected static ?string $pluralModelLabel = 'Alert Recipients';

    /**
     * eager-load pattern forward-compat (Gemini Concern MEDIUM, Pitfall 10):
     *
     * AlertRecipient currently has zero relations rendered in the table, so this
     * override is a no-op pass-through. Established here so future relation
     * columns (e.g., createdByUser.name, recentAlerts) cannot regress to N+1 —
     * developers add `->with([...])` inline when extending the table schema.
     *
     * DO NOT REMOVE even though `parent::getEloquentQuery()` is equivalent today.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            TextInput::make('name')
                ->maxLength(255),
            Toggle::make('is_active')
                ->default(true),
            Toggle::make('receives_sync_reports')
                ->label('Receives Sync Reports')
                ->helperText('D-08: Opt-in to the daily supplier sync CSV report. Default true.')
                ->default(true),
            // Phase 4 Plan 04 — CRM alert opt-in (D-12 from Plan 04-03).
            // Default FALSE (evidence payloads may carry order PII); seeded
            // ops@meetingstore.co.uk row is force-set TRUE by the migration.
            Toggle::make('receives_crm_alerts')
                ->label('Receives CRM alerts')
                ->helperText('Email this recipient when a Bitrix push exhausts retries (crm_push_failed).')
                ->default(false),
            // Phase 5 Plan 04a — competitor alert opt-in (Plan 05-01 column).
            // Covers stale-feed warnings (CompetitorCheckStaleCommand, Plan 05-05)
            // + CSV ingest failure notifications. Default FALSE; seeded fallback
            // row is force-promoted TRUE by the migration + DatabaseSeeder UPDATE.
            Toggle::make('receives_competitor_alerts')
                ->label('Receives Competitor Alerts')
                ->helperText('Email this recipient for competitor stale-feed warnings + CSV ingest failure notifications.')
                ->default(false),
            // Phase 6 Plan 04 — auto-create DLQ alert opt-in (Plan 06-01 column).
            // Covers CreateWooProductJob / ProcessAutoCreateImageJob retry
            // exhaustion notifications. Default FALSE; seeded fallback row
            // (ops@meetingstore.co.uk) is force-promoted TRUE by the
            // Plan 06-01 migration + DatabaseSeeder UPDATE.
            Toggle::make('receives_auto_create_alerts')
                ->label('Receives Auto-Create Alerts')
                ->helperText('Email this recipient when CreateWooProductJob / ProcessAutoCreateImageJob exhausts retries (auto_create_failed).')
                ->default(false),
            // Phase 7 Plan 04 — weekly digest opt-in (Plan 07-01 column).
            // Default TRUE (unlike the 4 incident-alert toggles above) because
            // the weekly digest is an ambient ops summary rather than an
            // incident notification. Plan 07-01 migration force-updates every
            // existing row to TRUE so the seeded fallback auto-subscribes.
            Toggle::make('receives_weekly_digest')
                ->label('Receives Weekly Digest')
                ->helperText('Monday 07:00 Europe/London — Sync / Margin / CRM / Auto-Create / Competitor summary email (DASH-05).')
                ->default(true),
            // Phase 11.1 Plan 01 — Competitor FTP pull failure alert opt-in (D-12).
            // Default FALSE; seeded fallback (ops@meetingstore.co.uk) is force-promoted
            // TRUE by the Plan 11.1-01 migration so Pitfall M outage cannot strand alerts.
            Toggle::make('receives_competitor_ftp_alerts')
                ->label('Receives Competitor FTP Alerts')
                ->helperText('Email this recipient when an FTP source fails 3 consecutive pulls and auto-disables (Phase 11.1 D-12).')
                ->default(false),
            Textarea::make('notes')
                ->rows(3)
                ->maxLength(2000)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->searchable(),
                TextColumn::make('name')->searchable(),
                IconColumn::make('is_active')->boolean(),
                IconColumn::make('receives_sync_reports')
                    ->boolean()
                    ->label('Reports?'),
                IconColumn::make('receives_crm_alerts')
                    ->boolean()
                    ->label('CRM alerts?'),
                // Phase 5 Plan 04a — competitor alert opt-in status at a glance.
                IconColumn::make('receives_competitor_alerts')
                    ->boolean()
                    ->label('Comp alerts?'),
                // Phase 6 Plan 04 — auto-create alert opt-in status at a glance.
                IconColumn::make('receives_auto_create_alerts')
                    ->boolean()
                    ->label('Auto-create alerts?'),
                // Phase 7 Plan 04 — weekly digest opt-in status at a glance.
                IconColumn::make('receives_weekly_digest')
                    ->boolean()
                    ->label('Weekly digest?'),
                // Phase 11.1 Plan 01 — Competitor FTP pull failure opt-in.
                IconColumn::make('receives_competitor_ftp_alerts')
                    ->boolean()
                    ->label('FTP alerts?'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('email');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAlertRecipients::route('/'),
            'create' => Pages\CreateAlertRecipient::route('/create'),
            'edit' => Pages\EditAlertRecipient::route('/{record}/edit'),
        ];
    }
}
