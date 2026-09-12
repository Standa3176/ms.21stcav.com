<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Filament\Resources\TradeCostAdjustmentResource\Pages;

use App\Domain\TradePricing\Filament\Resources\TradeCostAdjustmentResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateTradeCostAdjustment extends CreateRecord
{
    protected static string $resource = TradeCostAdjustmentResource::class;

    /**
     * The form's scope/value radios are UI-only, so the unused column of each
     * pair has to be nulled explicitly — otherwise switching a row from a
     * percentage to a fixed cost leaves BOTH set and the resolver silently
     * prefers the price list.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return TradeCostAdjustmentResource::normalise($data);
    }
}
