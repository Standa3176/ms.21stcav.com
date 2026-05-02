<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CompetitorFtpCredentialResource\Pages;

use App\Domain\Competitor\Filament\Resources\CompetitorFtpCredentialResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompetitorFtpCredential extends EditRecord
{
    protected static string $resource = CompetitorFtpCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
