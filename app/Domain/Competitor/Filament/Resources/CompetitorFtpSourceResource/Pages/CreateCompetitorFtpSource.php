<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CompetitorFtpSourceResource\Pages;

use App\Domain\Competitor\Filament\Resources\CompetitorFtpSourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCompetitorFtpSource extends CreateRecord
{
    protected static string $resource = CompetitorFtpSourceResource::class;
}
