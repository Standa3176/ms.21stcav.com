<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Filament\Resources\ProductOverrideResource\Pages;

use App\Domain\Pricing\Filament\Resources\ProductOverrideResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductOverride extends EditRecord
{
    protected static string $resource = ProductOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize(fn ($record): bool => auth()->user()?->can('delete', $record) ?? false),
        ];
    }
}
