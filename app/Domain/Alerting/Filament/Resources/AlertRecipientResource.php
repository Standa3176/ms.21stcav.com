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

    protected static ?string $navigationGroup = 'Settings';

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
