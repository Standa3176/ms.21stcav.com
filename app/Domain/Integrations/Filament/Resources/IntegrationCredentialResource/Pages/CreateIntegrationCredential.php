<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Filament\Resources\IntegrationCredentialResource\Pages;

use App\Domain\Integrations\Filament\Resources\IntegrationCredentialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIntegrationCredential extends CreateRecord
{
    protected static string $resource = IntegrationCredentialResource::class;
}
