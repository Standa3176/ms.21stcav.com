<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources;

use App\Domain\Competitor\Filament\Resources\CompetitorResource\Pages;
use App\Domain\Competitor\Models\Competitor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
 * Quick task 260503-uwk — Competitors admin resource.
 *
 * Phase 5 Plan 01 created the Competitor model + watcher-driven auto-create
 * on first CSV sighting. There was no Filament Resource — competitors only
 * existed via seeder or watcher. This Resource closes that gap so ops can:
 *   - Add a competitor before the first CSV arrives (so FTP Feed setup
 *     doesn't require pre-seeding)
 *   - Edit slug/name/status (e.g. promote pending → active after inspection)
 *   - See feed counts and last_ingest_at at a glance
 *
 * Auto-discovered via AdminPanelProvider:111
 * (`->discoverResources(in: app_path('Domain/Competitor/Filament/Resources'))`).
 */
class CompetitorResource extends Resource
{
    protected static ?string $model = Competitor::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?string $navigationLabel = 'Competitors';

    protected static ?string $modelLabel = 'Competitor';

    protected static ?string $pluralModelLabel = 'Competitors';

    protected static ?string $slug = 'competitors';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                    // Derive slug from name on create only — preserve existing slug on edit.
                    if (filled($state) && blank($get('slug'))) {
                        $set('slug', Str::slug((string) $state, '_'));
                    }
                }),

            TextInput::make('slug')
                ->required()
                ->maxLength(64)
                ->unique(ignoreRecord: true)
                ->rules(['regex:/^[a-z0-9_-]+$/'])
                ->helperText('Used in CSV filenames — lowercase letters, digits, hyphens, underscores only. Must match the watcher regex.'),

            Select::make('status')
                ->required()
                ->options([
                    Competitor::STATUS_PENDING => 'Pending (awaiting first inspection)',
                    Competitor::STATUS_ACTIVE => 'Active',
                    Competitor::STATUS_INACTIVE => 'Inactive',
                ])
                ->default(Competitor::STATUS_PENDING),

            Toggle::make('is_active')
                ->default(true)
                ->helperText('Independent of status — toggle off to skip ingest without changing the lifecycle status.'),

            TextInput::make('website_url')
                ->url()
                ->maxLength(512)
                ->helperText('Optional — public website used for human reference.'),

            Textarea::make('map_policy_notes')
                ->rows(3)
                ->maxLength(2048)
                ->helperText('Optional — MAP / pricing-policy notes the team should know when reviewing competitor prices.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name', 'asc')
            ->columns([
                TextColumn::make('id')->label('Id')->sortable()->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('name')->sortable()->searchable(),

                TextColumn::make('slug')
                    ->sortable()
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Competitor::STATUS_ACTIVE => 'success',
                        Competitor::STATUS_PENDING => 'warning',
                        Competitor::STATUS_INACTIVE => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('feeds_count')
                    ->label('Feeds')
                    ->counts('ftpFeeds')
                    ->sortable(),

                TextColumn::make('last_ingest_at')
                    ->label('Last Ingest')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('— never'),

                ToggleColumn::make('is_active'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompetitors::route('/'),
            'create' => Pages\CreateCompetitor::route('/create'),
            'edit' => Pages\EditCompetitor::route('/{record}/edit'),
        ];
    }
}
