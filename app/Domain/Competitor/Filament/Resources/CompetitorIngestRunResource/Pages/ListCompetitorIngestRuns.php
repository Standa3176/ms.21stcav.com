<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CompetitorIngestRunResource\Pages;

use App\Domain\Competitor\Filament\Resources\CompetitorIngestRunResource;
use Filament\Resources\Pages\ListRecords;

class ListCompetitorIngestRuns extends ListRecords
{
    protected static string $resource = CompetitorIngestRunResource::class;
}
