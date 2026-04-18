<?php

declare(strict_types=1);

namespace App\Domain\Sync\Filament\Resources\SyncRunResource\RelationManagers;

use App\Domain\Sync\Models\SyncRunItem;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Phase 2 Plan 02-04 — per-SKU action log for a run (11 D-10 columns mirrored
 * in the CSV report).
 *
 * Read-only: writes come from SyncChunkJob (updated/skipped/failed) and
 * MarkMissingSkusJob (missing). Filter by action lets ops isolate failures.
 */
class SyncRunItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Run Items';

    protected static ?string $recordTitleAttribute = 'sku';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->searchable(),
                TextColumn::make('woo_product_id')->label('Woo product id'),
                TextColumn::make('woo_variation_id')->label('Variation id')->placeholder('—'),
                TextColumn::make('action')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        SyncRunItem::ACTION_UPDATED => 'success',
                        SyncRunItem::ACTION_SKIPPED => 'gray',
                        SyncRunItem::ACTION_FAILED => 'danger',
                        SyncRunItem::ACTION_MISSING => 'warning',
                        SyncRunItem::ACTION_UNKNOWN_SKU => 'primary',
                        default => 'gray',
                    }),
                TextColumn::make('reason')->placeholder('—')->limit(30),
                TextColumn::make('old_price')->label('Old £'),
                TextColumn::make('new_price')->label('New £'),
                TextColumn::make('old_stock')->label('Old stock'),
                TextColumn::make('new_stock')->label('New stock'),
                TextColumn::make('error_message')->label('Error')->placeholder('—')->limit(50),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('id', 'asc')
            ->filters([
                SelectFilter::make('action')->multiple()->options([
                    SyncRunItem::ACTION_UPDATED => 'Updated',
                    SyncRunItem::ACTION_SKIPPED => 'Skipped',
                    SyncRunItem::ACTION_FAILED => 'Failed',
                    SyncRunItem::ACTION_MISSING => 'Missing',
                    SyncRunItem::ACTION_UNKNOWN_SKU => 'Unknown SKU',
                ]),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
