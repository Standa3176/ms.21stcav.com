<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Placeholder view shipped in Task 1 for page routing; Task 2 populates full UI. --}}
        {{ $this->form }}

        <div class="flex items-center gap-3">
            <x-filament::button wire:click="lookup" icon="heroicon-o-magnifying-glass">
                Look up
            </x-filament::button>
        </div>

        @if ($lastError)
            <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-800 dark:bg-red-900/30 dark:border-red-800 dark:text-red-200">
                {{ $lastError }}
            </div>
        @endif

        @if ($resolution)
            <div class="rounded-md border border-gray-200 p-4 text-sm dark:border-gray-700">
                <div class="font-semibold mb-2">SKU: {{ $resolution['sku'] }}</div>
                <div>Effective retail price: £{{ number_format($resolution['sell_pennies'] / 100, 2) }}</div>
                <div>Margin: {{ number_format($resolution['margin_basis_points'] / 100, 2) }}%</div>
                <div>Source: {{ $resolution['source'] }}</div>
                <div>Chain: {{ implode(' → ', $resolution['chain']) }}</div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
