<?php

declare(strict_types=1);

namespace App\Domain\Products\Filament\Resources\ProductExceptionResource\Pages;

use App\Domain\Products\Filament\Resources\ProductExceptionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductException extends CreateRecord
{
    protected static string $resource = ProductExceptionResource::class;

    /**
     * Stamp the creating user automatically — operator doesn't pick from a
     * dropdown. Captures audit context for "who pinned this SKU".
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }
}
