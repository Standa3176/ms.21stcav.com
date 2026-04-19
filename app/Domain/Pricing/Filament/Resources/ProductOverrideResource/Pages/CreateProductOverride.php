<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Filament\Resources\ProductOverrideResource\Pages;

use App\Domain\Pricing\Filament\Resources\ProductOverrideResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductOverride extends CreateRecord
{
    protected static string $resource = ProductOverrideResource::class;
}
