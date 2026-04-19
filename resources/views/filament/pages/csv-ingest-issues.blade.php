<x-filament-panels::page>
    {{--
        Phase 5 Plan 04b — CSV Ingest Issues page (COMP-05).

        4-tab shell over csv_parse_errors. Livewire-driven $activeTab property
        on the Page class drives which query the table widget reports.
    --}}

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($this->getTabs() as $key => $label)
            <button
                type="button"
                wire:click="setActiveTab('{{ $key }}')"
                @class([
                    'rounded-md px-3 py-1.5 text-sm font-medium transition',
                    'bg-amber-500 text-white shadow-sm' => $this->activeTab === $key,
                    'bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700' => $this->activeTab !== $key,
                ])
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-300">
        @switch($this->activeTab)
            @case('quarantine')
                Quarantined files — column-auto-detection returned ambiguous results. Click <strong>Resolve mapping</strong> to pick the SKU + price columns and re-queue the CSV.
                @break
            @case('orphans')
                Orphaned SKUs — CSV rows for SKUs we don't currently sell. These create <strong>new_product_opportunity</strong> suggestions in the Suggestions inbox.
                @break
            @case('encoding')
                Encoding failures — files that couldn't be decoded after BOM / Windows-1252 / UTF-8 fallback. Inspect the raw file and re-upload in UTF-8.
                @break
            @case('values')
                Value-parse failures — unparseable prices, invalid SKU formats, or invalid filenames. Usually a data-quality issue upstream (n8n scraping / supplier CSV export).
                @break
        @endswitch
    </div>

    <div class="mt-4">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
