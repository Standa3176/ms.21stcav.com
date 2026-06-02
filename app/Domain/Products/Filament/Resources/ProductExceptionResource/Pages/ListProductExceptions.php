<?php

declare(strict_types=1);

namespace App\Domain\Products\Filament\Resources\ProductExceptionResource\Pages;

use App\Domain\Products\Filament\Resources\ProductExceptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductExceptions extends ListRecords
{
    protected static string $resource = ProductExceptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
