<?php

declare(strict_types=1);

namespace App\Domain\Sync\Filament\Resources\SupplierResource\Pages;

use App\Domain\Sync\Filament\Resources\SupplierResource;
use Filament\Resources\Pages\ListRecords;

class ListSuppliers extends ListRecords
{
    protected static string $resource = SupplierResource::class;
}
