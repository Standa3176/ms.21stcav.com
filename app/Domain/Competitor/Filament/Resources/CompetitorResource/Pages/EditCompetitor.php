<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CompetitorResource\Pages;

use App\Domain\Competitor\Filament\Resources\CompetitorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompetitor extends EditRecord
{
    protected static string $resource = CompetitorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
