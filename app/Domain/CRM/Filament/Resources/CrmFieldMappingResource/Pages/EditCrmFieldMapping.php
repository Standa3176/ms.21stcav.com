<?php

declare(strict_types=1);

namespace App\Domain\CRM\Filament\Resources\CrmFieldMappingResource\Pages;

use App\Domain\CRM\Filament\Resources\CrmFieldMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCrmFieldMapping extends EditRecord
{
    protected static string $resource = CrmFieldMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize(fn ($record): bool => auth()->user()?->can('delete', $record) ?? false),
        ];
    }
}
