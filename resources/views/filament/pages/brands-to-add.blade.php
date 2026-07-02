<x-filament-panels::page>
    {{-- 260702-hg1 — Brands to Add page: lists brands on pending suggestions
         that are NOT yet on Woo, each with a one-click Create-on-Woo. --}}

    @php($canWrite = auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false)

    <div class="rounded-lg border border-sky-200 dark:border-sky-800 p-4 mb-4 bg-sky-50 dark:bg-sky-900/30 text-sm">
        <strong>How this works:</strong> Piece 1 (<code>php artisan products:refresh-brands-to-add</code>)
        walks pending <em>new product opportunity</em> suggestions and lists the manufacturer brands that
        are <strong>not yet on Woo</strong>. Click <strong>Create on Woo</strong> to add the brand term to
        <code>products/brands</code> — the SKUs it unlocks then become creatable (re-run
        <code>products:draft-from-suggestions</code> or the Suggestions bulk action). Publish handles the
        storefront <code>product_brand</code> link — this page only touches the WC-native taxonomy.
        <br>
        <span class="text-gray-600 dark:text-gray-400">
            @if ($generatedAt)
                Last refreshed: {{ \Carbon\Carbon::parse($generatedAt)->diffForHumans() }}
                ({{ \Carbon\Carbon::parse($generatedAt)->toDayDateTimeString() }})
            @else
                <em>Never refreshed — run <code>php artisan products:refresh-brands-to-add</code> (or use the
                Refresh list button above) to populate.</em>
            @endif
        </span>
    </div>

    @if (empty($brands))
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-8 text-center bg-gray-50 dark:bg-gray-900/50">
            <p class="text-gray-600 dark:text-gray-400">
                @if ($generatedAt)
                    No brands to add — every sourceable pending SKU already resolves to a Woo brand.
                @else
                    No data yet. Run <code>php artisan products:refresh-brands-to-add</code> to build the list.
                @endif
            </p>
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-semibold">Brand (to add)</th>
                        <th class="px-4 py-2 font-semibold">Products it would unlock</th>
                        <th class="px-4 py-2 font-semibold">Sample SKUs</th>
                        <th class="px-4 py-2 font-semibold text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($brands as $row)
                        <tr wire:key="brand-{{ $loop->index }}-{{ $row['brand'] }}">
                            <td class="px-4 py-2 font-medium">{{ $row['brand'] }}</td>
                            <td class="px-4 py-2">
                                <span class="inline-flex items-center rounded-full bg-primary-100 dark:bg-primary-900/40 px-2 py-0.5 text-primary-700 dark:text-primary-300 font-semibold">
                                    {{ number_format($row['count']) }}
                                </span>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs text-gray-600 dark:text-gray-400">
                                {{ collect($row['skus'])->take(5)->implode(', ') }}
                                @if (count($row['skus']) > 5)
                                    <span class="text-gray-400">…(+{{ count($row['skus']) - 5 }})</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">
                                @if ($canWrite)
                                    <x-filament::button
                                        size="sm"
                                        icon="heroicon-o-plus-circle"
                                        wire:click="createBrand(@js($row['brand']))"
                                        wire:loading.attr="disabled"
                                    >
                                        Create on Woo
                                    </x-filament::button>
                                @else
                                    <span class="text-gray-400 text-xs">View only</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
