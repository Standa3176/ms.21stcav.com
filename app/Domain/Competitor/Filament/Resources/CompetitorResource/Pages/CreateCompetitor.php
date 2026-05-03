<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CompetitorResource\Pages;

use App\Domain\Competitor\Filament\Resources\CompetitorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCompetitor extends CreateRecord
{
    protected static string $resource = CompetitorResource::class;
}
