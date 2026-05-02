<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CompetitorFtpSourceResource\Pages;

use App\Domain\Competitor\Filament\Resources\CompetitorFtpSourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompetitorFtpSource extends EditRecord
{
    protected static string $resource = CompetitorFtpSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
