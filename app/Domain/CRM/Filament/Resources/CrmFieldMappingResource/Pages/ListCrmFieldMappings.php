<?php

declare(strict_types=1);

namespace App\Domain\CRM\Filament\Resources\CrmFieldMappingResource\Pages;

use App\Domain\CRM\Filament\Resources\CrmFieldMappingResource;
use App\Domain\CRM\Models\CrmFieldMapping;
use Filament\Actions\CreateAction;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListCrmFieldMappings extends ListRecords
{
    protected static string $resource = CrmFieldMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('create', CrmFieldMapping::class) ?? false),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'deal' => Tab::make('Deal')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('entity_type', CrmFieldMapping::ENTITY_DEAL)),
            'contact' => Tab::make('Contact')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('entity_type', CrmFieldMapping::ENTITY_CONTACT)),
            'company' => Tab::make('Company')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('entity_type', CrmFieldMapping::ENTITY_COMPANY)),
        ];
    }
}
