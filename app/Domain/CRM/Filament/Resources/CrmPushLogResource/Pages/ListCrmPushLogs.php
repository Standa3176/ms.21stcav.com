<?php

declare(strict_types=1);

namespace App\Domain\CRM\Filament\Resources\CrmPushLogResource\Pages;

use App\Domain\CRM\Filament\Resources\CrmPushLogResource;
use Filament\Resources\Pages\ListRecords;

class ListCrmPushLogs extends ListRecords
{
    protected static string $resource = CrmPushLogResource::class;

    // No header actions — read-only Resource (CRM-11 acceptance).
    protected function getHeaderActions(): array
    {
        return [];
    }
}
