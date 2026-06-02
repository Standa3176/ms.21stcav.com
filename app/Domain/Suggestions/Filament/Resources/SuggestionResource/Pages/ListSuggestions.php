<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages;

use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
use App\Domain\Suggestions\Models\Suggestion;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stage 1 tabs (minimal — match the working CrmFieldMapping pattern):
 *   - Tab closures only touch the Builder argument (no $this capture)
 *   - No badge() calls — eager-evaluated badge queries were the suspected
 *     cause of the 2026-06-02 ListSuggestions 500 that forced commit 95eebe4
 *   - whereRaw with MySQL CAST AS UNSIGNED for the JSON comparison
 *     (proven working in prod elsewhere; avoids any Eloquent JSON syntax
 *     interaction with the parent getEloquentQuery's eager load)
 *
 * Stage 2 will add badge counts once Stage 1 is verified rendering live.
 * Stage 3 will add the "Auto-create all in this tab" header action.
 */
class ListSuggestions extends ListRecords
{
    protected static string $resource = SuggestionResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            '3-plus' => Tab::make('3+ competitors')
                ->icon('heroicon-o-fire')
                ->modifyQueryUsing(static fn (Builder $q): Builder => $q
                    ->where('kind', 'new_product_opportunity')
                    ->where('status', Suggestion::STATUS_PENDING)
                    ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.supporting_competitors')) AS UNSIGNED) >= 3")),

            '2-competitors' => Tab::make('2 competitors')
                ->icon('heroicon-o-arrow-trending-up')
                ->modifyQueryUsing(static fn (Builder $q): Builder => $q
                    ->where('kind', 'new_product_opportunity')
                    ->where('status', Suggestion::STATUS_PENDING)
                    ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.supporting_competitors')) AS UNSIGNED) = 2")),

            '1-competitor' => Tab::make('1 competitor')
                ->icon('heroicon-o-flag')
                ->modifyQueryUsing(static fn (Builder $q): Builder => $q
                    ->where('kind', 'new_product_opportunity')
                    ->where('status', Suggestion::STATUS_PENDING)
                    ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.supporting_competitors')) AS UNSIGNED) = 1")),
        ];
    }
}
