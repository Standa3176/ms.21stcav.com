<x-filament-panels::page>
    <div class="space-y-6">
        {{--
            Rule Explorer — PRCE-08
            Type a SKU → shows effective retail price + full resolution chain
            (override / brand_category / category / brand / default_tier).

            Read-only: no events dispatched, no DB writes. See
            App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages\RuleExplorer.
        --}}

        {{ $this->form }}

        <div class="flex items-center gap-3">
            <x-filament::button
                wire:click="lookup"
                icon="heroicon-o-magnifying-glass"
            >
                Look up effective price
            </x-filament::button>
        </div>

        @if ($lastError)
            <div class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/30 dark:text-red-200"
                data-testid="rule-explorer-error">
                {{ $lastError }}
            </div>
        @endif

        @if ($resolution)
            <div class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900"
                data-testid="rule-explorer-resolution">

                {{-- Header card: SKU + effective retail price --}}
                <div class="border-b border-gray-200 p-6 dark:border-gray-700">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">SKU</div>
                            <div class="font-mono text-lg font-semibold text-gray-900 dark:text-gray-100">
                                {{ $resolution['sku'] }}
                            </div>
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Product #{{ $resolution['product_id'] }}
                                @if ($resolution['variant_id'])
                                    / Variant #{{ $resolution['variant_id'] }}
                                @endif
                            </div>
                        </div>

                        <div class="text-right">
                            <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Effective retail price</div>
                            <div class="text-3xl font-bold text-emerald-600 dark:text-emerald-400">
                                £{{ number_format($resolution['sell_pennies'] / 100, 2) }}
                            </div>
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                from £{{ number_format($resolution['buy_pennies'] / 100, 2) }} buy price
                                · {{ number_format($resolution['margin_basis_points'] / 100, 2) }}% margin
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Resolution chain badges --}}
                <div class="p-6">
                    <div class="mb-3 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Resolution chain
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($resolution['chain'] as $layer)
                            @php
                                $isMatched = ($layer === $resolution['source']);
                                $colourClasses = match (true) {
                                    $isMatched && $layer === 'override'       => 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900/40 dark:text-purple-200 dark:border-purple-700',
                                    $isMatched && $layer === 'brand_category' => 'bg-emerald-100 text-emerald-800 border-emerald-300 dark:bg-emerald-900/40 dark:text-emerald-200 dark:border-emerald-700',
                                    $isMatched && $layer === 'category'       => 'bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-900/40 dark:text-amber-200 dark:border-amber-700',
                                    $isMatched && $layer === 'brand'          => 'bg-blue-100 text-blue-800 border-blue-300 dark:bg-blue-900/40 dark:text-blue-200 dark:border-blue-700',
                                    $isMatched && $layer === 'default_tier'   => 'bg-gray-200 text-gray-900 border-gray-400 dark:bg-gray-700 dark:text-gray-100 dark:border-gray-500',
                                    default                                   => 'bg-gray-50 text-gray-500 border-gray-200 dark:bg-gray-800 dark:text-gray-500 dark:border-gray-700',
                                };
                            @endphp
                            <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium {{ $colourClasses }}"
                                @if ($isMatched) data-testid="matched-source" @endif
                            >
                                @if ($isMatched)
                                    <svg class="mr-1 h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/>
                                    </svg>
                                @endif
                                {{ $layer }}
                            </span>
                            @if (! $loop->last)
                                <span class="text-gray-400 dark:text-gray-600">→</span>
                            @endif
                        @endforeach
                    </div>

                    <div class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                        Winning layer: <span class="font-semibold">{{ $resolution['source'] }}</span>
                        @if ($resolution['source'] === 'override' && $resolution['override_id'])
                            — <a class="text-primary-600 hover:underline dark:text-primary-400"
                                href="{{ \App\Domain\Pricing\Filament\Resources\ProductOverrideResource::getUrl('edit', ['record' => $resolution['override_id']]) }}">
                                override #{{ $resolution['override_id'] }}
                            </a>
                        @elseif ($resolution['matched_rule_id'])
                            — <a class="text-primary-600 hover:underline dark:text-primary-400"
                                href="{{ \App\Domain\Pricing\Filament\Resources\PricingRuleResource::getUrl('edit', ['record' => $resolution['matched_rule_id']]) }}">
                                rule #{{ $resolution['matched_rule_id'] }}
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
