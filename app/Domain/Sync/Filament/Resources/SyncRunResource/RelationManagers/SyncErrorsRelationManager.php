<?php

declare(strict_types=1);

namespace App\Domain\Sync\Filament\Resources\SyncRunResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Phase 2 Plan 02-04 — read-only drill-down of SyncError rows for a run.
 *
 * Produces the per-SKU error log operators consult when a sync aborts.
 * Read-only: no create/edit/delete — append-only upstream.
 */
class SyncErrorsRelationManager extends RelationManager
{
    protected static string $relationship = 'errors';

    protected static ?string $title = 'Errors';

    protected static ?string $recordTitleAttribute = 'sku';

    public function form(Form $form): Form
    {
        // Read-only RelationManager — form is never used but is required by Filament.
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->searchable(),
                TextColumn::make('woo_product_id')->label('Woo product id'),
                TextColumn::make('woo_variation_id')->label('Woo variation id')->placeholder('—'),
                TextColumn::make('error_class')->label('Error')->limit(40),
                TextColumn::make('error_message')->limit(80),
                TextColumn::make('correlation_id')
                    ->fontFamily('mono')
                    ->limit(12)
                    ->copyable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
