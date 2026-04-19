<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Filament\Resources\ProductOverrideResource\Pages;

use App\Domain\Pricing\Filament\Resources\ProductOverrideResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductOverrides extends ListRecords
{
    protected static string $resource = ProductOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('create', \App\Domain\Pricing\Models\ProductOverride::class) ?? false),
        ];
    }
}
