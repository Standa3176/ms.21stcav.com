<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CompetitorPriceResource\Pages;

use App\Domain\Competitor\Filament\Resources\CompetitorPriceResource;
use Filament\Resources\Pages\ListRecords;

class ListCompetitorPrices extends ListRecords
{
    protected static string $resource = CompetitorPriceResource::class;
}
