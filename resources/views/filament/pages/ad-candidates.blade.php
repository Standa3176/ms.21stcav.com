<x-filament-panels::page>
    {{-- 260607-pys — Ad Candidates page filter strip + footer summary. --}}

    @php($summary = $this->getSummary())
    @php($brandOptions = $this->getBrandOptions())

    <style>
        /* Kill the Tailwind Forms background-image chevron on multi-select
           — it scales weirdly across the select width on multiple lines. */
        select.ad-candidates-brand-picker {
            background-image: none !important;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            padding-right: 0.75rem !important;
        }
    </style>

    <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <h2 class="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-200">Filter golden ad targets</h2>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
            {{-- Brand multi-select (wider — spans 3 cols of 6 so it doesn't squash).
                 Alpine x-model="brandQuery" filters visible <option>s client-side as
                 operator types — no Livewire round-trip until they actually pick a brand. --}}
            <div class="md:col-span-3" x-data="{ brandQuery: '' }">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Brands</label>
                <input
                    type="search"
                    x-model="brandQuery"
                    placeholder="Type to filter brands…"
                    class="mt-1 mb-1 block w-full rounded border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                />
                <select
                    wire:model.live="filterBrandIds"
                    multiple
                    class="ad-candidates-brand-picker mt-1 block w-full rounded border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    size="8"
                >
                    @foreach ($brandOptions as $id => $name)
                        <option
                            value="{{ $id }}"
                            x-show="brandQuery === '' || '{{ strtolower(addslashes($name)) }}'.includes(brandQuery.toLowerCase())"
                        >{{ $name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Type above to narrow. Cmd/Ctrl-click to multi-select. Empty = all brands.
                </p>
            </div>

            {{-- Right column stack: margin + toggles --}}
            <div class="md:col-span-3 space-y-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Min margin (£)</label>
                    <input
                        type="number"
                        min="0"
                        step="1"
                        wire:model.live.debounce.500ms="filterMinMarginPounds"
                        class="mt-1 block w-full rounded border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Default £199.</p>
                </div>

                <label class="flex items-center space-x-2 text-sm text-gray-700 dark:text-gray-200">
                    <input
                        type="checkbox"
                        wire:model.live="filterStockRequired"
                        class="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                    />
                    <span>Supplier in stock (last 7d)</span>
                </label>

                <label class="flex items-center space-x-2 text-sm text-gray-700 dark:text-gray-200">
                    <input
                        type="checkbox"
                        wire:model.live="filterBeatRequired"
                        class="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                    />
                    <span>We must beat lowest competitor</span>
                </label>
            </div>
        </div>
    </div>

    {{-- Footer summary banner --}}
    <div class="rounded-lg border border-primary-200 bg-primary-50 p-4 text-sm dark:border-primary-900/40 dark:bg-primary-900/10">
        <span class="font-semibold">{{ number_format($summary['count']) }}</span>
        candidate SKU(s) match current filters
        &middot;
        Total margin potential
        <span class="font-semibold">£{{ number_format($summary['total_margin_pence'] / 100, 2) }}</span>
        &middot;
        Avg margin
        <span class="font-semibold">£{{ number_format($summary['avg_margin_pence'] / 100, 2) }}</span>
        per SKU
    </div>

    {{ $this->table }}
</x-filament-panels::page>
