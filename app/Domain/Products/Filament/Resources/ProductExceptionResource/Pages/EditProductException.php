<?php

declare(strict_types=1);

namespace App\Domain\Products\Filament\Resources\ProductExceptionResource\Pages;

use App\Domain\Products\Filament\Resources\ProductExceptionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductException extends EditRecord
{
    protected static string $resource = ProductExceptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
