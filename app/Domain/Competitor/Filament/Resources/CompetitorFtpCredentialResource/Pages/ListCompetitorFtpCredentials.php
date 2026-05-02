<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CompetitorFtpCredentialResource\Pages;

use App\Domain\Competitor\Filament\Resources\CompetitorFtpCredentialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompetitorFtpCredentials extends ListRecords
{
    protected static string $resource = CompetitorFtpCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
