<?php

declare(strict_types=1);

namespace App\Domain\Sync\Filament\Resources\ProductSupplierBlockResource\Pages;

use App\Domain\Sync\Filament\Resources\ProductSupplierBlockResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

/**
 * Quick task 261027-duz — single-page CRUD for supplier offer blocks.
 */
class ManageProductSupplierBlocks extends ManageRecords
{
    protected static string $resource = ProductSupplierBlockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['created_by_user_id'] = auth()->id();

                    return $data;
                }),
        ];
    }
}
