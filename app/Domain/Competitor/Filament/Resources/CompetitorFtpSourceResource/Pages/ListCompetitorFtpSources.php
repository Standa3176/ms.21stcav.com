<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CompetitorFtpSourceResource\Pages;

use App\Domain\Competitor\Filament\Resources\CompetitorFtpSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompetitorFtpSources extends ListRecords
{
    protected static string $resource = CompetitorFtpSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
