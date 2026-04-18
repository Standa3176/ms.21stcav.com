<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Filament\Resources\AlertRecipientResource\Pages;

use App\Domain\Alerting\Filament\Resources\AlertRecipientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAlertRecipient extends CreateRecord
{
    protected static string $resource = AlertRecipientResource::class;
}
