<?php

declare(strict_types=1);

namespace App\Domain\Sync\Filament\Resources;

use App\Domain\Sync\Filament\Resources\SupplierResource\Pages;
use App\Domain\Sync\Models\Supplier;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Quick task 260626-oqr — Suppliers admin page.
 *
 * Wires the operator control surface for the previously-DEAD
 * suppliers.is_active flag (added 260608-g8x, never read until now). An
 * operator flips a supplier's Active toggle OFF and the next supplier:db-sync
 * run drops its price + stock entirely (SupplierExclusionResolver →
 * buildBestOfferMap, ahead of the stale filter, unconditional).
 *
 * Suppliers are AUTO-DISCOVERED by suppliers:check-stale — there is NO create
 * action. RBAC mirrors ImportIssueResource (D-02): admin + pricing_manager
 * write (toggle + edit form), sales + read_only are view-only. Write surfaces
 * gate via hasAnyRole on BOTH ->disabled/->visible and ->authorize.
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Sync & CRM';

    // After Import Issues (20); suppliers are the related metadata surface.
    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'supplier_id';

    protected static ?string $pluralModelLabel = 'Suppliers';

    /**
     * Count of EXCLUDED (is_active=false) suppliers — 'danger' so a paused
     * supplier is visible at a glance in the sidebar. Defensive try/catch:
     * the badge runs on every render and must never 500 the admin.
     */
    public static function getNavigationBadge(): ?string
    {
        try {
            $count = Supplier::query()->where('is_active', false)->count();
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    private static function canWrite(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    public static function form(Form $form): Form
    {
        $readOnly = fn (): bool => ! self::canWrite();

        return $form->schema([
            // Natural key + denormalised name are auto-discovered — never edited.
            TextInput::make('supplier_id')
                ->label('Supplier ID')
                ->disabled(),
            TextInput::make('name')
                ->placeholder('—')
                ->disabled(),
            Toggle::make('is_active')
                ->label('Active')
                ->helperText('Off = excluded: the sync drops this supplier\'s price + stock.')
                ->disabled($readOnly),
            TextInput::make('stale_after_days')
                ->label('Stale after (days)')
                ->numeric()
                ->minValue(1)
                ->helperText('Blank = default 7 days')
                ->disabled($readOnly),
            Textarea::make('notes')
                ->rows(3)
                ->maxLength(2000)
                ->columnSpanFull()
                ->disabled($readOnly),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('supplier_id')
                    ->label('Supplier ID')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->placeholder('—'),
                ToggleColumn::make('is_active')
                    ->label('Active')
                    // Gate inline writes to admin + pricing_manager; read-only
                    // for sales/read_only (Warning 9 defence-in-depth at POST).
                    ->disabled(fn (): bool => ! self::canWrite()),
                TextColumn::make('freshness')
                    ->label('Freshness')
                    ->badge()
                    ->getStateUsing(fn (Supplier $record): string => app(SupplierFreshnessResolver::class)
                        ->classify((string) $record->supplier_id))
                    ->color(fn (string $state): string => match ($state) {
                        'fresh' => 'success',
                        'amber' => 'warning',
                        'stale' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('last_seen')
                    ->label('Last seen')
                    ->getStateUsing(fn (Supplier $record): ?string => app(SupplierFreshnessResolver::class)
                        ->latestRecordedAtFor((string) $record->supplier_id)?->diffForHumans())
                    ->placeholder('never'),
                TextColumn::make('stale_after_days')
                    ->label('Stale after (days)')
                    ->sortable()
                    ->placeholder('default'),
                TextColumn::make('notes')
                    ->limit(40)
                    ->placeholder('—'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('supplier_id')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Excluded only'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        // Suppliers are auto-discovered by suppliers:check-stale — no UI creation.
        return false;
    }
}
