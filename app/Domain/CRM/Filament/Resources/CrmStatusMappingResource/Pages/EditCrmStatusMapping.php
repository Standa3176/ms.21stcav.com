<?php

declare(strict_types=1);

namespace App\Domain\CRM\Filament\Resources\CrmStatusMappingResource\Pages;

use App\Domain\CRM\Filament\Resources\CrmStatusMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCrmStatusMapping extends EditRecord
{
    protected static string $resource = CrmStatusMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize(fn ($record): bool => auth()->user()?->can('delete', $record) ?? false),
        ];
    }
}
