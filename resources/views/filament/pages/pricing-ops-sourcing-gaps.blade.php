{{-- "Sourcing gaps" — parts a competitor lists that NO supplier carries and we
     don't sell (likely obsolete, or we need a new supplier). Filterable.
     $rows (sliced), $total, $maxAgeDays, $computedAt. These deliberately don't
     count in the cost tiles and aren't in "Products to add" (nothing to source). --}}
@php
    $money = fn (int $p) => '£' . number_format($p / 100, 2);
@endphp

<div class="space-y-3" x-data="{ q: '' }">
    @if ($computedAt === null)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Not scanned yet. Run <code class="font-mono text-xs">php artisan pricing:scan-sourcing-gaps</code> (it runs weekly on its own).
        </p>
    @else
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ number_format($total) }} part(s) a competitor lists (≤{{ $maxAgeDays }} days) that no supplier carries and we don't sell.
            @if ($total > count($rows)) Showing the first {{ number_format(count($rows)) }} (most-tracked) — use <strong>Export</strong> for the full list. @endif
            Scanned {{ \Illuminate\Support\Carbon::parse($computedAt)->diffForHumans() }}.
        </p>

        @if (count($rows) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">No sourcing gaps — every competitor-listed part is either on meetingstore or sourceable. 🎉</p>
        @else
            <input type="search" x-model="q" placeholder="Filter by part, MPN or competitor…"
                class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200" />

            <div class="max-h-[55vh] overflow-auto rounded-md border border-gray-200 dark:border-gray-700">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">Part</th>
                            <th class="px-3 py-2">MPN</th>
                            <th class="px-3 py-2 text-right">Competitors</th>
                            <th class="px-3 py-2 text-right">Lowest comp (ex)</th>
                            <th class="px-3 py-2">Competitor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($rows as $r)
                            <tr data-search="{{ strtolower(($r['part'] ?? '').' '.($r['mpn'] ?? '').' '.($r['competitor_name'] ?? '')) }}"
                                x-show="q === '' || $el.dataset.search.includes(q.toLowerCase())">
                                <td class="px-3 py-1.5 font-mono">{{ $r['part'] }}</td>
                                <td class="px-3 py-1.5 font-mono text-gray-500">{{ $r['mpn'] ?? '—' }}</td>
                                <td class="px-3 py-1.5 text-right font-medium">{{ $r['competitors'] }}</td>
                                <td class="px-3 py-1.5 text-right">{{ $money((int) ($r['comp_ex'] ?? 0)) }}</td>
                                <td class="px-3 py-1.5 text-gray-500">{{ $r['competitor_name'] ? \Illuminate\Support\Str::limit($r['competitor_name'], 28) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-400">These don't count in the cost tiles and aren't in "Products to add" — there's nothing to source. Investigate for obsolescence or find a supplier.</p>
        @endif
    @endif
</div>
