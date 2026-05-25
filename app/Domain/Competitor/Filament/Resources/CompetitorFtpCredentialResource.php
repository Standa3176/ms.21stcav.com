<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources;

use App\Domain\Competitor\Filament\Resources\CompetitorFtpCredentialResource\Pages;
use App\Domain\Competitor\Ftp\Services\FtpSourceConnector;
use App\Domain\Competitor\Models\CompetitorFtpCredential;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Throwable;

/**
 * Phase 11.2 Plan 01 — CompetitorFtpCredentialResource (D-09 admin-only).
 *
 * Admin-only Resource. Most ops users never visit this. Holds encrypted
 * FTP credentials at rest (D-03 — `'encrypted'` Eloquent cast). Each row
 * is a shared FTP folder pointing at one supplier-aggregated location;
 * many CompetitorFtpFeed rows point at it via credential_id.
 *
 * "Test connection" Action (D-09) — connects via FtpSourceConnector::connect,
 * lists files in base_path, reports count without downloading; updates
 * last_test_at + last_test_status + last_test_error so ops can see history.
 *
 * Form `password_encrypted` / `private_key_encrypted` / `passphrase_encrypted`
 * fields use `dehydrated(filled)` so blank-on-edit means "keep existing" —
 * critical UX so admins don't accidentally wipe credentials.
 *
 * RBAC enforced by CompetitorFtpCredentialPolicy (admin-only on every method).
 * Every Action calls `->authorize(...)` to honour the policy at the action layer.
 *
 * Quick task 260503-rul moved from Catalogue → Admin: FTP credentials are secrets,
 * sit alongside other integration credentials in the Admin nav group. CompetitorFtpFeedResource
 * remains in Catalogue (operational config edited by ops, not secrets).
 */
class CompetitorFtpCredentialResource extends Resource
{
    protected static ?string $model = CompetitorFtpCredential::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'FTP Credentials';

    protected static ?string $modelLabel = 'FTP Credential';

    protected static ?string $pluralModelLabel = 'FTP Credentials';

    protected static ?string $slug = 'competitor-ftp-credentials';

    protected static ?int $navigationSort = 50;

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->helperText('Friendly identifier — must be unique (e.g. "supplier_aggregate", "weekly_distributor").'),

            Select::make('protocol')
                ->required()
                ->options([
                    CompetitorFtpCredential::PROTOCOL_FTP => 'FTP',
                    CompetitorFtpCredential::PROTOCOL_SFTP => 'SFTP (SSH)',
                    CompetitorFtpCredential::PROTOCOL_FTPS => 'FTPS (FTP over TLS)',
                ])
                ->default(CompetitorFtpCredential::PROTOCOL_SFTP)
                ->live()
                ->afterStateUpdated(function ($state, callable $set): void {
                    $set('port', match ($state) {
                        CompetitorFtpCredential::PROTOCOL_FTP => 21,
                        CompetitorFtpCredential::PROTOCOL_SFTP => 22,
                        CompetitorFtpCredential::PROTOCOL_FTPS => 990,
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
                ->helperText('Optional — for SFTP key auth. Leave blank to keep existing key.'),

            TextInput::make('passphrase_encrypted')
                ->label('Private Key Passphrase')
                ->password()
                ->revealable()
                ->maxLength(512)
                ->dehydrated(fn ($state): bool => filled($state))
                ->helperText('Passphrase for an encrypted private key. Leave blank to keep existing.'),

            TextInput::make('base_path')
                ->required()
                ->default('/feeds')
                ->maxLength(512),

            Toggle::make('verify_ssl')
                ->default(true)
                ->helperText('SECURITY: leave ON. Disable only for self-signed certs after ops review (logged as Warning).'),

            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->sortable()->searchable(),

            TextColumn::make('protocol')->badge(),

            TextColumn::make('host')
                ->formatStateUsing(fn ($record) => "{$record->host}:{$record->port}")
                ->searchable(),

            TextColumn::make('last_test_status')
                ->label('Last Test')
                ->badge()
                ->color(fn ($state): string => match ($state) {
                    CompetitorFtpCredential::TEST_STATUS_OK => 'success',
                    CompetitorFtpCredential::TEST_STATUS_FAILED => 'danger',
                    default => 'gray',
                })
                ->placeholder('— never tested'),

            TextColumn::make('last_test_at')
                ->label('Tested At')
                ->dateTime('Y-m-d H:i')
                ->placeholder('—'),

            ToggleColumn::make('is_active'),
        ])
            ->actions([
                EditAction::make()
                    ->authorize(fn ($record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('test_connection')
                    ->label('Test connection')
                    ->icon('heroicon-o-bolt')
                    ->authorize(fn ($record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(function (CompetitorFtpCredential $record): void {
                        try {
                            $disk = app(FtpSourceConnector::class)->connect($record);
                            $count = 0;
                            $names = [];
                            foreach ($disk->listContents('', deep: false) as $item) {
                                if ($item->isFile()) {
                                    $count++;
                                    if (count($names) < 10) {
                                        $names[] = basename($item->path());
                                    }
                                }
                            }

                            $record->update([
                                'last_test_at' => now(),
                                'last_test_status' => CompetitorFtpCredential::TEST_STATUS_OK,
                                'last_test_error' => null,
                            ]);

                            Notification::make()
                                ->title("Connected — {$count} file(s) in base_path")
                                ->body($count > 0 ? 'First files: '.implode(', ', $names) : null)
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            $record->update([
                                'last_test_at' => now(),
                                'last_test_status' => CompetitorFtpCredential::TEST_STATUS_FAILED,
                                'last_test_error' => $e->getMessage(),
                            ]);

                            Notification::make()
                                ->title('Connection failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                DeleteAction::make()
                    ->authorize(fn ($record): bool => auth()->user()?->can('delete', $record) ?? false),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompetitorFtpCredentials::route('/'),
            'create' => Pages\CreateCompetitorFtpCredential::route('/create'),
            'edit' => Pages\EditCompetitorFtpCredential::route('/{record}/edit'),
        ];
    }
}
