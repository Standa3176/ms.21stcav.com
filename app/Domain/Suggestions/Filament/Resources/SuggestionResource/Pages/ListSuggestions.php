<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages;

use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
use App\Domain\Suggestions\Models\Suggestion;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tabs for new-product-opportunity competitor-count bucketing.
 *
 * The parent SuggestionResource::getEloquentQuery() uses a complex chain
 * (->with(["resolvedByUser"]) + ->when(! request->filled("tableFilters.kind.value"),
 * ->where(kind, "!=", "agent_guardrail_blocked"))) that broke the page
 * (HTTP 500: "newQueryWithoutRelationships() on null at Builder.php:355")
 * when combined with Filament 3 Tab modifyQueryUsing.
 *
 * Fix: override getTableQuery() at the PAGE level to bypass the parent's
 * chain entirely. We build a fresh, simple Suggestion::query() with
 * just the resolvedByUser eager-load (still required for the table's
 * "Resolved by" column) and let the tab modifyQueryUsing apply its
 * own kind+status+competitor-count filters on top. The agent_guardrail_blocked
 * default-hide is applied here directly (no ->when() request inspection),
 * matching what the parent's complex chain was trying to express.
 */
class ListSuggestions extends ListRecords
{
    protected static string $resource = SuggestionResource::class;

    /**
     * Override the table's base query — bypass the parent Resource's
     * complex getEloquentQuery chain (see class docblock for why).
     */
    protected function getTableQuery(): ?Builder
    {
        return Suggestion::query()
            ->with(['resolvedByUser'])
            ->where('kind', '!=', 'agent_guardrail_blocked');
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            '3-plus' => Tab::make('3+ competitors')
                ->icon('heroicon-o-fire')
                ->modifyQueryUsing(fn (Builder $q): Builder => $q
                    ->where('kind', 'new_product_opportunity')
                    ->where('status', Suggestion::STATUS_PENDING)
                    ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.supporting_competitors')) AS UNSIGNED) >= 3")),

            '2-competitors' => Tab::make('2 competitors')
                ->icon('heroicon-o-arrow-trending-up')
                ->modifyQueryUsing(fn (Builder $q): Builder => $q
                    ->where('kind', 'new_product_opportunity')
                    ->where('status', Suggestion::STATUS_PENDING)
                    ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.supporting_competitors')) AS UNSIGNED) = 2")),

            '1-competitor' => Tab::make('1 competitor')
                ->icon('heroicon-o-flag')
                ->modifyQueryUsing(fn (Builder $q): Builder => $q
                    ->where('kind', 'new_product_opportunity')
                    ->where('status', Suggestion::STATUS_PENDING)
                    ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.supporting_competitors')) AS UNSIGNED) = 1")),
        ];
    }
}
