<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Filament\Resources\AlertRecipientResource\Pages;

use App\Domain\Alerting\Filament\Resources\AlertRecipientResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAlertRecipient extends EditRecord
{
    protected static string $resource = AlertRecipientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
