<?php

declare(strict_types=1);

namespace App\Domain\CRM\Filament\Resources\CrmStatusMappingResource\Pages;

use App\Domain\CRM\Filament\Resources\CrmStatusMappingResource;
use App\Domain\CRM\Models\CrmStatusMapping;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCrmStatusMappings extends ListRecords
{
    protected static string $resource = CrmStatusMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add custom Woo status')
                ->authorize(fn (): bool => auth()->user()?->can('create', CrmStatusMapping::class) ?? false),
        ];
    }
}
