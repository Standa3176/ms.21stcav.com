<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages;

use App\Domain\Pricing\Filament\Resources\PricingRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPricingRule extends EditRecord
{
    protected static string $resource = PricingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize(fn ($record): bool => auth()->user()?->can('delete', $record) ?? false),

            \Filament\Actions\Action::make('simulated_impact')
                ->label('Simulated Impact')
                ->icon('heroicon-o-chart-bar')
                ->url(fn ($record): string => PricingRuleResource::getUrl('simulated-impact', ['record' => $record])),
        ];
    }
}
