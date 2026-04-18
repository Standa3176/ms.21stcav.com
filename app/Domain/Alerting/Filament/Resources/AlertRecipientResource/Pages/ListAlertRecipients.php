<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Filament\Resources\AlertRecipientResource\Pages;

use App\Domain\Alerting\Filament\Resources\AlertRecipientResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAlertRecipients extends ListRecords
{
    protected static string $resource = AlertRecipientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
