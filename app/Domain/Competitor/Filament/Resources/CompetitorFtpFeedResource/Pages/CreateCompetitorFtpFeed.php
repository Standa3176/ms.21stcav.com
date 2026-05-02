<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CompetitorFtpFeedResource\Pages;

use App\Domain\Competitor\Filament\Resources\CompetitorFtpFeedResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCompetitorFtpFeed extends CreateRecord
{
    protected static string $resource = CompetitorFtpFeedResource::class;
}
