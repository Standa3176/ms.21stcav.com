<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateSkipRuleResource\Pages;

use App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateSkipRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAutoCreateSkipRule extends CreateRecord
{
    protected static string $resource = AutoCreateSkipRuleResource::class;
}
