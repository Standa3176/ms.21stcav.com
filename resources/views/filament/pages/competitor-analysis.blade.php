<x-filament-panels::page>
    {{--
        Phase 5 Plan 04b — Competitor Analysis page (COMP-10).

        The three widgets (StaleFeedTrafficLight header, SkuPriceTrendChart +
        BiggestMarginDeltasTable footer) are rendered by the panel via
        getHeaderWidgets() / getFooterWidgets(). This Blade adds a help banner
        + the optional per-SKU query-string picker so ops can drill into a
        specific SKU's competitor trend.
    --}}

    <div class="space-y-4">
        <div class="rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-300">
            <div class="font-semibold">How to read this dashboard</div>
            <p class="mt-1">
                Traffic-light tile shows freshness of every <strong>active</strong> competitor feed (threshold: {{ (int) config('competitor.stale_feed_hours', 48) }}h).
                The trend chart plots each competitor's ex-VAT price for the selected SKU against our current sell price (dashed green).
                The deltas table lists the 50 (competitor, SKU) pairs with the biggest absolute price difference; products without a recomputed sell price are omitted until Phase 3 recompute runs.
            </p>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Tip: append <code class="font-mono">?sku=YOUR-SKU</code> to the URL to pin the trend chart to a specific product.
            </p>
        </div>
    </div>
</x-filament-panels::page>
