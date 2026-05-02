<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CompetitorFtpFeedResource\Pages;

use App\Domain\Competitor\Filament\Resources\CompetitorFtpFeedResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompetitorFtpFeeds extends ListRecords
{
    protected static string $resource = CompetitorFtpFeedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add New Feed'),
        ];
    }
}
