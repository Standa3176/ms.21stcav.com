<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Filament\Resources\QuoteResource\Pages;

use App\Domain\Quotes\Filament\Resources\QuoteResource;
use App\Domain\Quotes\Models\Quote;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Phase 11 Plan 03 — ListQuotes page.
 *
 * Mirrors ListCustomerGroups: ->authorize() on the Create header action so
 * a read_only / unauthenticated user reaching the index doesn't see a Create
 * button (defence in depth on top of QuotePolicy::create()).
 */
class ListQuotes extends ListRecords
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('create', Quote::class) ?? false),
        ];
    }
}
