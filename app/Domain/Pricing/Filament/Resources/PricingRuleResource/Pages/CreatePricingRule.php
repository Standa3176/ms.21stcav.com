<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages;

use App\Domain\Pricing\Filament\Resources\PricingRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePricingRule extends CreateRecord
{
    protected static string $resource = PricingRuleResource::class;
}
