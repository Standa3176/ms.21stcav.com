<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Filament\Resources\QuoteResource\Pages;

use App\Domain\Quotes\Filament\Resources\QuoteResource;
use App\Domain\Quotes\Models\Quote;
use Filament\Resources\Pages\CreateRecord;

/**
 * Phase 11 Plan 03 — CreateQuote page.
 *
 * mutateFormDataBeforeCreate persists customer_group_name_at_quote (D-02 +
 * Pitfall 6). Also defaults status=draft because the form's Select::status
 * is disabled (transitions go through dedicated row actions, never the form).
 */
class CreateQuote extends CreateRecord
{
    protected static string $resource = QuoteResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = QuoteResource::denormaliseCustomerGroupName($data);
        $data['status'] = $data['status'] ?? Quote::STATUS_DRAFT;

        return $data;
    }
}
