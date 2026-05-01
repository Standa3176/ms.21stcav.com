<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Filament\Resources\QuoteResource\Pages;

use App\Domain\Quotes\Filament\Resources\QuoteResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Phase 11 Plan 03 — EditQuote page.
 *
 * mutateFormDataBeforeSave keeps customer_group_name_at_quote in sync if the
 * customer_group_id changes (rare in a draft, but still consistent — D-02).
 *
 * DeleteAction is policy-gated; QuotePolicy::delete denies sales/read_only.
 */
class EditQuote extends EditRecord
{
    protected static string $resource = QuoteResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return QuoteResource::denormaliseCustomerGroupName($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->authorize(fn ($record): bool => auth()->user()?->can('view', $record) ?? false),
            DeleteAction::make()
                ->authorize(fn ($record): bool => auth()->user()?->can('delete', $record) ?? false),
        ];
    }
}
