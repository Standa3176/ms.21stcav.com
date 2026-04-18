<?php

declare(strict_types=1);

namespace App\Domain\Products\Filament\Resources\ProductResource\Pages;

use App\Domain\Products\Filament\Resources\ProductResource;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;
}
