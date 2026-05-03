<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CompetitorResource\Pages;

use App\Domain\Competitor\Filament\Resources\CompetitorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompetitors extends ListRecords
{
    protected static string $resource = CompetitorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add Competitor'),
        ];
    }
}
