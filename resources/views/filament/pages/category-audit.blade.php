<x-filament-panels::page>
    {{-- 260607-t6w — Category Audit page footer summary + table. --}}

    @php($summary = $this->getSummary())
    @php($lastRun = $summary['last_run_at'])

    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 mb-4 bg-gray-50 dark:bg-gray-900/50">
        <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
            <div><strong>Total findings:</strong> {{ number_format($summary['total']) }}</div>
            <div><span class="text-red-600 font-semibold">{{ $summary['missing'] }}</span> missing</div>
            <div><span class="text-amber-600 font-semibold">{{ $summary['orphaned'] }}</span> orphaned</div>
            <div><span class="text-amber-600 font-semibold">{{ $summary['uncategorized'] }}</span> uncategorized</div>
            <div><span class="text-sky-600 font-semibold">{{ $summary['suspicious'] }}</span> suspicious</div>
            <div class="ml-auto text-gray-600 dark:text-gray-400">
                @if ($lastRun)
                    Last run: {{ \Carbon\Carbon::parse($lastRun)->diffForHumans() }}
                @else
                    Last run: <em>never (waiting for Fri 22:00 cron or run `php artisan products:audit-categories` manually)</em>
                @endif
                &middot; Next run: {{ $summary['next_run_hint'] }}
            </div>
        </div>
    </div>

    {{-- 260607-v5g — operator tip pointing at the new Woo-REST-backed
         category backfill command. Distinct sky-blue palette so it does
         not blur into the severity-coloured count banner above. --}}
    <div class="rounded-lg border border-sky-200 dark:border-sky-800 p-4 mb-4 bg-sky-50 dark:bg-sky-900/30 text-sm">
        <strong>Bulk fix available:</strong> Most <code>missing_category_id</code> findings can be auto-fixed by running
        <code>php artisan products:backfill-category-from-woo --dry-run</code> first (preview),
        then re-run without <code>--dry-run</code> to apply. Pulls Woo's own category taxonomy back into MS.
        Quick task <code>260607-v5g</code>.
        <br>
        See also <a href="/admin/stock-divergence" class="underline">/admin/stock-divergence</a> (260609-nku) for SKUs where Woo claims stock but no fresh supplier carries any.
    </div>

    {{ $this->table }}
</x-filament-panels::page>
