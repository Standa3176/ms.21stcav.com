<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Filament\Resources\IntegrationCredentialResource\Pages;

use App\Domain\Integrations\Filament\Resources\IntegrationCredentialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIntegrationCredentials extends ListRecords
{
    protected static string $resource = IntegrationCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
