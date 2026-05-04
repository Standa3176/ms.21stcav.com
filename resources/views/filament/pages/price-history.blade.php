<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Search Product</x-slot>
            <x-slot name="description">Type SKU, name, or description fragment — searches all 5,633 products.</x-slot>
            {{ $this->form }}
        </x-filament::section>

        @if (! is_null($product ?? null))
            <x-filament::section>
                <x-slot name="heading">{{ $product->sku }} — {{ $product->name }}</x-slot>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <div class="text-sm text-gray-500">Sell price</div>
                        <div class="text-lg font-bold">£{{ number_format((float) $product->sell_price, 2) }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Buy price</div>
                        <div class="text-lg font-bold">£{{ number_format((float) $product->buy_price, 2) }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Stock</div>
                        <div class="text-lg font-bold">{{ $product->stock_quantity ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">History days</div>
                        <div class="text-lg font-bold">{{ $snapshots->count() }}</div>
                    </div>
                </div>
            </x-filament::section>

            @if ($snapshots->count() > 0)
                <x-filament::section>
                    <x-slot name="heading">Price + Stock Trend (last {{ $snapshots->count() }} days)</x-slot>
                    <div wire:ignore>
                        <canvas id="trend-chart-{{ $product->id }}" height="80"></canvas>
                    </div>
                    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
                    <script>
                        (function () {
                            const ctx = document.getElementById('trend-chart-{{ $product->id }}');
                            if (! ctx || typeof Chart === 'undefined') return;
                            // Destroy any previous chart instance bound to this canvas
                            // (Livewire re-renders may leave a dangling instance).
                            if (window._meetingStoreTrendChart) {
                                try { window._meetingStoreTrendChart.destroy(); } catch (e) {}
                            }
                            window._meetingStoreTrendChart = new Chart(ctx, {
                                type: 'line',
                                data: {
                                    labels: @json($snapshots->reverse()->values()->map(fn ($s) => $s->recorded_at->format('M d'))),
                                    datasets: [
                                        {
                                            label: 'Sell £',
                                            data: @json($snapshots->reverse()->values()->pluck('sell_price')),
                                            borderColor: 'rgb(34,197,94)',
                                            tension: 0.1,
                                        },
                                        {
                                            label: 'Buy £',
                                            data: @json($snapshots->reverse()->values()->pluck('buy_price')),
                                            borderColor: 'rgb(245,158,11)',
                                            tension: 0.1,
                                        },
                                        {
                                            label: 'Stock',
                                            data: @json($snapshots->reverse()->values()->pluck('stock_quantity')),
                                            borderColor: 'rgb(59,130,246)',
                                            yAxisID: 'y1',
                                            tension: 0.1,
                                        },
                                    ],
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    interaction: { mode: 'index', intersect: false },
                                    scales: {
                                        y:  { type: 'linear', position: 'left',  title: { display: true, text: '£' } },
                                        y1: { type: 'linear', position: 'right', title: { display: true, text: 'Stock' }, grid: { drawOnChartArea: false } },
                                    },
                                },
                            });
                        })();
                    </script>
                </x-filament::section>
            @endif

            @if ($offerSnapshotsToday->count() > 0)
                <x-filament::section>
                    <x-slot name="heading">All Suppliers Today (cheapest first — {{ $offerSnapshotsToday->count() }} offers)</x-slot>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left border-b">
                                <th class="py-2 pr-4">Supplier</th>
                                <th class="py-2 pr-4">Price</th>
                                <th class="py-2 pr-4">Stock</th>
                                <th class="py-2 pr-4">RRP</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($offerSnapshotsToday as $o)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4">{{ $o->supplier_name ?: '—' }}</td>
                                    <td class="py-2 pr-4">£{{ number_format((float) $o->price, 2) }}</td>
                                    <td class="py-2 pr-4">{{ $o->stock ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ $o->rrp ? '£'.number_format((float) $o->rrp, 2) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-filament::section>
            @endif

            @if ($cheapestPerDay->count() > 0)
                <x-filament::section>
                    <x-slot name="heading">Cheapest Supplier per Day (last 30 days)</x-slot>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left border-b">
                                <th class="py-2 pr-4">Date</th>
                                <th class="py-2 pr-4">Supplier</th>
                                <th class="py-2 pr-4">Price</th>
                                <th class="py-2 pr-4">Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cheapestPerDay as $r)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4">{{ $r->recorded_at->format('Y-m-d') }}</td>
                                    <td class="py-2 pr-4">{{ $r->supplier_name ?: '—' }}</td>
                                    <td class="py-2 pr-4">£{{ number_format((float) $r->price, 2) }}</td>
                                    <td class="py-2 pr-4">{{ $r->stock ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-filament::section>
            @endif
        @else
            <x-filament::section>
                <p class="text-gray-500">Pick a product above to see its price + stock history.</p>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
