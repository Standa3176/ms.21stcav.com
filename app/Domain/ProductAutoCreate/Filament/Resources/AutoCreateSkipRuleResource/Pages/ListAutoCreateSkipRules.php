<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateSkipRuleResource\Pages;

use App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateSkipRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAutoCreateSkipRules extends ListRecords
{
    protected static string $resource = AutoCreateSkipRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->authorize(fn () => auth()->user()?->hasRole('admin') ?? false),
        ];
    }
}
