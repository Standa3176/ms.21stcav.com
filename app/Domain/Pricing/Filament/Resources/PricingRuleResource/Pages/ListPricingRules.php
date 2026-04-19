<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages;

use App\Domain\Pricing\Filament\Resources\PricingRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPricingRules extends ListRecords
{
    protected static string $resource = PricingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('create', \App\Domain\Pricing\Models\PricingRule::class) ?? false),

            \Filament\Actions\Action::make('rule_explorer')
                ->label('Rule Explorer')
                ->icon('heroicon-o-magnifying-glass')
                ->url(PricingRuleResource::getUrl('rule-explorer')),
        ];
    }
}
