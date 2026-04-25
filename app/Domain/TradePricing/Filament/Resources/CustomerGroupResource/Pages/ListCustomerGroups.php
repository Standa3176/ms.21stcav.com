<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Filament\Resources\CustomerGroupResource\Pages;

use App\Domain\TradePricing\Filament\Resources\CustomerGroupResource;
use App\Domain\TradePricing\Models\CustomerGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Phase 9 Plan 05 — CustomerGroupResource list page (TRDE-04 D-10).
 *
 * Mirrors ListPricingRules: ->authorize() on the Create header action so a
 * sales user reaching the index doesn't see a Create button (defence in depth
 * on top of CustomerGroupPolicy::create()).
 */
class ListCustomerGroups extends ListRecords
{
    protected static string $resource = CustomerGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('create', CustomerGroup::class) ?? false),
        ];
    }
}
