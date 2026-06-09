<x-filament-panels::page>
    {{-- 260609-nku — Stock Divergence page footer summary + table. --}}

    @php($summary = $this->getSummary())
    @php($lastRun = $summary['last_run_at'])

    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 mb-4 bg-gray-50 dark:bg-gray-900/50">
        <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
            <div><strong>Divergent SKUs:</strong> {{ number_format($summary['total']) }}</div>
            <div><span class="text-red-600 font-semibold">{{ number_format($summary['total_phantom_units']) }}</span> total phantom units</div>
            <div class="ml-auto text-gray-600 dark:text-gray-400">
                @if ($lastRun)
                    Last run: {{ \Carbon\Carbon::parse($lastRun)->diffForHumans() }}
                @else
                    Last run: <em>never (waiting for Mon 09:15 cron or run `php artisan products:audit-stock-divergence` manually)</em>
                @endif
                &middot; Next run: {{ $summary['next_run_hint'] }}
            </div>
        </div>
    </div>

    {{-- 260609-nku — operator context: explain what phantom stock means + how
         bulk-resync writes back. Distinct sky-blue palette so it stays separate
         from the count banner. --}}
    <div class="rounded-lg border border-sky-200 dark:border-sky-800 p-4 mb-4 bg-sky-50 dark:bg-sky-900/30 text-sm">
        <strong>Phantom stock</strong> = Woo claims qty &gt; 0 but MS's confirmed-fresh suppliers all report 0.
        <code>Resync selected to Woo</code> bulk-action (cap 100 SKUs) pushes MS's 0 over Woo's phantom number
        via <code>products:resync-to-woo</code>.
        Quick task <code>260609-nku</code>.
        <br>
        See also <a href="/admin/category-audit" class="underline">/admin/category-audit</a> (260607-t6w) for taxonomy issues.
    </div>

    {{ $this->table }}
</x-filament-panels::page>
