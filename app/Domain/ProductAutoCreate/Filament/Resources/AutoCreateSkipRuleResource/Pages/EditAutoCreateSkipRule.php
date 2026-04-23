<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateSkipRuleResource\Pages;

use App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateSkipRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAutoCreateSkipRule extends EditRecord
{
    protected static string $resource = AutoCreateSkipRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize(fn () => auth()->user()?->hasRole('admin') ?? false),
        ];
    }
}
