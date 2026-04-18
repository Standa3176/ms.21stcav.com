<?php

declare(strict_types=1);

namespace App\Domain\Sync\Filament\Resources\SyncRunResource\Pages;

use App\Domain\Sync\Filament\Resources\SyncRunResource;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewSyncRun extends ViewRecord
{
    protected static string $resource = SyncRunResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Run Details')
                ->columns(3)
                ->schema([
                    TextEntry::make('id')->label('Run ID'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('dry_run')->label('Dry-run')->badge(),
                    TextEntry::make('started_at')->dateTime(),
                    TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('correlation_id')->fontFamily('mono')->copyable(),
                ]),
            Section::make('Counts')
                ->columns(6)
                ->schema([
                    TextEntry::make('total_skus'),
                    TextEntry::make('updated_count')->label('Updated'),
                    TextEntry::make('skipped_count')->label('Skipped'),
                    TextEntry::make('failed_count')->label('Failed'),
                    TextEntry::make('missing_count')->label('Missing'),
                    TextEntry::make('unknown_sku_count')->label('Unknown SKUs'),
                ]),
            Section::make('Abort Details')
                ->columns(2)
                ->visible(fn ($record) => in_array($record->status, ['aborted', 'failed'], true))
                ->schema([
                    TextEntry::make('abort_reason')->placeholder('—'),
                    TextEntry::make('abort_message')->placeholder('—'),
                ]),
            Section::make('Cursor')
                ->columns(2)
                ->schema([
                    TextEntry::make('cursor_page')->label('Last page'),
                    TextEntry::make('cursor_sku')->label('Last SKU')->placeholder('—'),
                ]),
        ]);
    }
}
