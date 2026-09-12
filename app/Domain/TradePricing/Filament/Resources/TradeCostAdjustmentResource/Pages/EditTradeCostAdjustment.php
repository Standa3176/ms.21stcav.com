<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Filament\Resources\TradeCostAdjustmentResource\Pages;

use App\Domain\TradePricing\Filament\Resources\TradeCostAdjustmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditTradeCostAdjustment extends EditRecord
{
    protected static string $resource = TradeCostAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /** See CreateTradeCostAdjustment — the unused column of each pair must be nulled. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return TradeCostAdjustmentResource::normalise($data);
    }
}
