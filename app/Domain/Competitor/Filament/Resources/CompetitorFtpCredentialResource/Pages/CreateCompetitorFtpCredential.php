<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CompetitorFtpCredentialResource\Pages;

use App\Domain\Competitor\Filament\Resources\CompetitorFtpCredentialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCompetitorFtpCredential extends CreateRecord
{
    protected static string $resource = CompetitorFtpCredentialResource::class;
}
