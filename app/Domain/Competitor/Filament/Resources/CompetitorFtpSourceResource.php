<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources;

use App\Domain\Competitor\Filament\Resources\CompetitorFtpSourceResource\Pages;
use App\Domain\Competitor\Ftp\Services\FtpSourceConnector;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorFtpSource;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;

/**
 * Phase 11.1 Plan 01 — CompetitorFtpSourceResource (D-08 admin-only).
 *
 * Admin-managed CRUD for FTP/SFTP/FTPS competitor feed sources. Holds
 * encrypted credentials at rest (D-04 — `'encrypted'` Eloquent cast).
 * Auto-discovered via app/Domain/Competitor/Filament/Resources/ (existing
 * Phase 5 discoverResources path).
 *
 * RBAC enforced by CompetitorFtpSourcePolicy (admin-only on every method
 * including viewAny — pricing_manager / sales / read_only all 403). Every
 * row Action calls `->authorize(...)` to honour the policy at the action
 * level (D-09).
 *
 * Two row Actions (D-09):
 *   - Test connection — connects via FtpSourceConnector::connect, lists
 *     base_path, reports file count + filenames; shows verbatim Flysystem
 *     error on failure for ops debugging.
 *   - Pull now — queues `competitor:ftp-pull --source={id} --live` so the
 *     command runs on the next worker tick without blocking the HTTP request.
 *
 * Form `password_encrypted` / `private_key_encrypted` / `passphrase_encrypted`
 * fields use `dehydrated(filled)` so blank-on-edit means "keep existing" —
 * critical UX so admins don't accidentally wipe credentials when editing
 * other fields.
 */
class CompetitorFtpSourceResource extends Resource
{
    protected static ?string $model = CompetitorFtpSource::class;

    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-down';

    protected static ?string $navigationGroup = 'Competitor Intelligence';

    protected static ?string $navigationLabel = 'FTP Sources';

    protected static ?int $navigationSort = 99;

    protected static ?string $modelLabel = 'FTP Source';

    protected static ?string $pluralModelLabel = 'FTP Sources';

    /**
     * Pitfall 10 forward-compat — explicit pass-through eager-load query.
     * Future relation columns add `->with([...])` here without an N+1 regression.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['competitor']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('competitor_id')
                ->label('Competitor')
                ->relationship('competitor', 'name')
                ->required()
                ->searchable()
                ->preload(),

            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->helperText('Friendly identifier — must be unique per competitor (e.g. "weekly_csv", "daily_pricing").'),

            Select::make('protocol')
                ->options([
                    CompetitorFtpSource::PROTOCOL_FTP => 'FTP',
                    CompetitorFtpSource::PROTOCOL_SFTP => 'SFTP (SSH)',
                    CompetitorFtpSource::PROTOCOL_FTPS => 'FTPS (FTP over TLS)',
                ])
                ->required()
                ->default(CompetitorFtpSource::PROTOCOL_SFTP)
                ->live()
                ->afterStateUpdated(function ($state, callable $set): void {
                    $set('port', match ($state) {
                        CompetitorFtpSource::PROTOCOL_FTP => 21,
                        CompetitorFtpSource::PROTOCOL_SFTP => 22,
                        CompetitorFtpSource::PROTOCOL_FTPS => 990,
                        default => 22,
                    });
                }),

            TextInput::make('host')->required()->maxLength(255),

            TextInput::make('port')
                ->numeric()
                ->required()
                ->minValue(1)
                ->maxValue(65535)
                ->default(22),

            TextInput::make('username')->required()->maxLength(255),

            TextInput::make('password_encrypted')
                ->label('Password')
                ->password()
                ->revealable()
                ->maxLength(1024)
                ->dehydrated(fn ($state): bool => filled($state))
                ->helperText('AES-256 encrypted at rest. Leave blank on edit to keep the existing password.'),

            Textarea::make('private_key_encrypted')
                ->label('SFTP Private Key (PEM)')
                ->rows(6)
                ->dehydrated(fn ($state): bool => filled($state))
                ->helperText('Optional — for SFTP key auth. Leave blank for password auth or to keep existing key.'),

            TextInput::make('passphrase_encrypted')
                ->label('Private Key Passphrase')
                ->password()
                ->revealable()
                ->maxLength(512)
                ->dehydrated(fn ($state): bool => filled($state))
                ->helperText('Passphrase for an encrypted private key. Leave blank if key is unencrypted or to keep existing.'),

            TextInput::make('base_path')
                ->required()
                ->default('/')
                ->maxLength(512),

            TextInput::make('filename_pattern')
                ->required()
                ->maxLength(512)
                ->default('/^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$/')
                ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                    if (@preg_match($value, '') === false) {
                        $fail('Invalid PHP regex.');
                    }
                })
                ->helperText('PHP regex matched against remote filenames. Default = Phase 5 watcher regex.'),

            TextInput::make('cron_expression')
                ->required()
                ->maxLength(64)
                ->default('*/15 * * * *')
                ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                    if (preg_match('/^\S+\s+\S+\s+\S+\s+\S+\s+\S+$/', $value) !== 1) {
                        $fail('Must be 5 space-separated cron fields.');
                    }
                })
                ->helperText('Standard 5-field cron. v1 ships a single global */15 schedule; per-source schedules deferred.'),

            Toggle::make('verify_ssl')
                ->default(true)
                ->helperText('SECURITY: leave ON. Disable only for self-signed certs after ops review (logged as Warning).'),

            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('last_pull_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        CompetitorFtpSource::STATUS_SUCCESS => 'success',
                        CompetitorFtpSource::STATUS_PARTIAL => 'warning',
                        CompetitorFtpSource::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('— never pulled'),

                TextColumn::make('competitor.name')->label('Competitor')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('protocol')->badge(),
                TextColumn::make('host')->searchable(),
                TextColumn::make('last_pulled_at')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('last_pull_files_fetched')->label('Files')->numeric(),
                TextColumn::make('consecutive_failures')
                    ->label('Fails')
                    ->badge()
                    ->color(fn ($state): string => $state >= 2 ? 'danger' : ($state >= 1 ? 'warning' : 'gray')),
                IconColumn::make('is_active')->boolean(),
            ])
            ->defaultSort('last_pulled_at', 'desc')
            ->actions([
                Action::make('test_connection')
                    ->label('Test connection')
                    ->icon('heroicon-o-signal')
                    ->authorize(fn (CompetitorFtpSource $source): bool => auth()->user()?->can('update', $source) ?? false)
                    ->action(function (CompetitorFtpSource $source): void {
                        try {
                            $fs = app(FtpSourceConnector::class)->connect($source);
                            $count = 0;
                            $names = [];
                            foreach ($fs->listContents('', deep: false) as $item) {
                                if ($item->isFile()) {
                                    $count++;
                                    if (count($names) < 10) {
                                        $names[] = basename($item->path());
                                    }
                                }
                            }
                            Notification::make()
                                ->title("Connected — {$count} file(s) in base_path")
                                ->body($count > 0 ? 'First files: '.implode(', ', $names) : null)
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Connection failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                Action::make('pull_now')
                    ->label('Pull now')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->authorize(fn (CompetitorFtpSource $source): bool => auth()->user()?->can('update', $source) ?? false)
                    ->requiresConfirmation()
                    ->modalDescription('Queue a one-shot competitor:ftp-pull --live --source for this source. Runs on the next worker tick.')
                    ->action(function (CompetitorFtpSource $source): void {
                        Artisan::queue('competitor:ftp-pull', [
                            '--source' => $source->id,
                            '--live' => true,
                        ]);
                        Notification::make()
                            ->title('Queued for next worker tick')
                            ->body("competitor:ftp-pull --source={$source->id} --live")
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompetitorFtpSources::route('/'),
            'create' => Pages\CreateCompetitorFtpSource::route('/create'),
            'edit' => Pages\EditCompetitorFtpSource::route('/{record}/edit'),
        ];
    }
}
