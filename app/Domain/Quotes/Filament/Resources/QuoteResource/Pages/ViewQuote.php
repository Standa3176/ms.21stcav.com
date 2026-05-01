<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Filament\Resources\QuoteResource\Pages;

use App\Domain\Quotes\Filament\Resources\QuoteResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Phase 11 Plan 03 — ViewQuote page (read-only detail view).
 *
 * EditAction is policy-gated. The 4 state-machine actions are exposed via
 * the table row actions on the index page (and via the QuoteLinesRelationManager
 * inline). Future Plan 11-04 may move them onto the View page header for
 * better workflow visibility post-PDF-render.
 */
class ViewQuote extends ViewRecord
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->authorize(fn ($record): bool => auth()->user()?->can('update', $record) ?? false),
        ];
    }
}
