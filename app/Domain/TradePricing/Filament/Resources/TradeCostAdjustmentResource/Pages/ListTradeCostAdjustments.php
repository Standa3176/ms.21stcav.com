<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Filament\Resources\TradeCostAdjustmentResource\Pages;

use App\Domain\TradePricing\Filament\Resources\TradeCostAdjustmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListTradeCostAdjustments extends ListRecords
{
    protected static string $resource = TradeCostAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
