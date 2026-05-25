{{-- "Products to add" — parts on ≥N suppliers not on meetingstore. Filterable.
     $rows (sliced), $total, $minSuppliers, $computedAt. Categories aren't in the
     supplier feed (assigned via AI at draft time), so they're not shown here. --}}
<div class="space-y-3" x-data="{ q: '' }">
    @if ($computedAt === null)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Not scanned yet. Run <code class="font-mono text-xs">php artisan supplier:scan-add-candidates</code> (it runs weekly on its own).
        </p>
    @else
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ number_format($total) }} part(s) stocked by ≥{{ $minSuppliers }} suppliers but not on meetingstore.
            @if ($total > count($rows)) Showing the first {{ number_format(count($rows)) }} (most-stocked) — use <strong>Export</strong> for the full list. @endif
            Scanned {{ \Illuminate\Support\Carbon::parse($computedAt)->diffForHumans() }}.
        </p>

        @if (count($rows) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing to add — every multi-supplier part is already on meetingstore. 🎉</p>
        @else
            <input type="search" x-model="q" placeholder="Filter by brand, part or description…"
                class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200" />

            <div class="max-h-[55vh] overflow-auto rounded-md border border-gray-200 dark:border-gray-700">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">Brand</th>
                            <th class="px-3 py-2">Part (MPN)</th>
                            <th class="px-3 py-2">Description</th>
                            <th class="px-3 py-2 text-right">Suppliers</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($rows as $r)
                            <tr data-search="{{ strtolower(($r['brand'] ?? '').' '.($r['part'] ?? '').' '.($r['title'] ?? '')) }}"
                                x-show="q === '' || $el.dataset.search.includes(q.toLowerCase())">
                                <td class="px-3 py-1.5">{{ $r['brand'] !== '' ? $r['brand'] : '—' }}</td>
                                <td class="px-3 py-1.5 font-mono">{{ $r['part'] }}</td>
                                <td class="px-3 py-1.5">{{ \Illuminate\Support\Str::limit($r['title'], 70) }}</td>
                                <td class="px-3 py-1.5 text-right font-medium text-success-600">{{ $r['suppliers'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-400">Categories aren't in the supplier feed — they're assigned automatically when a part is drafted to a product.</p>
        @endif
    @endif
</div>
