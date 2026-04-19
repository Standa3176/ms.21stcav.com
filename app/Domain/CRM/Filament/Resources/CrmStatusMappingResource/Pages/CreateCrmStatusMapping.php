<?php

declare(strict_types=1);

namespace App\Domain\CRM\Filament\Resources\CrmStatusMappingResource\Pages;

use App\Domain\CRM\Filament\Resources\CrmStatusMappingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCrmStatusMapping extends CreateRecord
{
    protected static string $resource = CrmStatusMappingResource::class;
}
