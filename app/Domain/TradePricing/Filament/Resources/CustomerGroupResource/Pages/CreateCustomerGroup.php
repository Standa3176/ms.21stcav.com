<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Filament\Resources\CustomerGroupResource\Pages;

use App\Domain\TradePricing\Filament\Resources\CustomerGroupResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Phase 9 Plan 05 — CustomerGroupResource create page (TRDE-04 D-10).
 *
 * Standard CreateRecord — admin + pricing_manager only via
 * CustomerGroupPolicy::create() (gated through CreateAction on the
 * ListCustomerGroups header + Filament's implicit policy check on
 * page mount).
 */
class CreateCustomerGroup extends CreateRecord
{
    protected static string $resource = CustomerGroupResource::class;
}
