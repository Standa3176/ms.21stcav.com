{{-- Competitor-position bucket table, rendered inside a tile modal. Live-filterable
     (client-side Alpine) over the shown rows; $rows (sliced), $total, $cap. --}}
@php
    $money = fn (int $p) => '£' . number_format($p / 100, 2);
@endphp

<div class="space-y-3" x-data="{ q: '' }">
    @if ($total > count($rows))
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Showing the worst {{ number_format(count($rows)) }} of {{ number_format($total) }} by margin — use <strong>Export</strong> for the full list.
        </p>
    @else
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ number_format($total) }} row(s).</p>
    @endif

    @if (count($rows) === 0)
        <p class="text-sm text-gray-500 dark:text-gray-400">No rows in this bucket. 🎉</p>
    @else
        <input type="search" x-model="q" placeholder="Filter by SKU or name…"
            class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200" />

        <div class="max-h-[55vh] overflow-auto rounded-md border border-gray-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="sticky top-0 bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2">SKU</th>
                        <th class="px-3 py-2">Name</th>
                        <th class="px-3 py-2 text-right">Our cost (ex)</th>
                        <th class="px-3 py-2 text-right">Lowest comp (ex)</th>
                        <th class="px-3 py-2 text-right">Margin</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($rows as $r)
                        @php $m = (int) $r['margin_bps']; @endphp
                        <tr data-search="{{ strtolower($r['sku'].' '.$r['name']) }}"
                            x-show="q === '' || $el.dataset.search.includes(q.toLowerCase())">
                            <td class="px-3 py-1.5 font-mono">{{ $r['sku'] }}</td>
                            <td class="px-3 py-1.5">{{ \Illuminate\Support\Str::limit($r['name'], 60) }}</td>
                            <td class="px-3 py-1.5 text-right text-gray-500">
                                <span class="block">{{ $money((int) $r['cost_ex']) }}</span>
                                @if (! empty($r['supplier_name']))
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($r['supplier_name'], 28) }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-1.5 text-right {{ $m <= 0 ? 'text-danger-600 font-medium' : '' }}">
                                <span class="block">{{ $money((int) $r['comp_ex']) }}</span>
                                @if (! empty($r['competitor_name']))
                                    <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($r['competitor_name'], 28) }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-1.5 text-right {{ $m <= 0 ? 'text-danger-600' : ($m < 600 ? 'text-warning-600' : 'text-success-600') }}">
                                {{ number_format($m / 100, 1) }}%
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
