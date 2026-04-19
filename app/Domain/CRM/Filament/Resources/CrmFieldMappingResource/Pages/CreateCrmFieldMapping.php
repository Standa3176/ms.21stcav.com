<?php

declare(strict_types=1);

namespace App\Domain\CRM\Filament\Resources\CrmFieldMappingResource\Pages;

use App\Domain\CRM\Filament\Resources\CrmFieldMappingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCrmFieldMapping extends CreateRecord
{
    protected static string $resource = CrmFieldMappingResource::class;
}
