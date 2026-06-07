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

    {{ $this->table }}
</x-filament-panels::page>
