<x-filament-panels::page>
    {{-- Marketing dashboard — PURE PRESENTATION over ga_channel_metrics_daily
         (15a-02) + ad_optimisation Suggestions (15b-01). The three header widgets
         (overview stats, revenue trend, latest advice) render above this content
         via getHeaderWidgets(). --}}

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Channel &amp; campaign performance from Google Analytics 4 (last 30 days) and the latest
        advice from the ad-optimisation agent. Read-only — use “Review with Claude” to run an
        on-demand analysis, and approve/reject advice in the Suggestions inbox.
    </p>

    @unless ($hasMetrics)
        {{-- Hard empty-state requirement — friendly callout when GA4 isn't connected yet
             (zero ga_channel_metrics_daily rows). Never an error. --}}
        <x-filament::section icon="heroicon-o-signal-slash">
            <x-slot name="heading">No Google Analytics 4 data yet</x-slot>
            <x-slot name="description">Connect Google Analytics 4 to populate this dashboard.</x-slot>

            <p class="text-sm text-gray-600 dark:text-gray-300">
                Add your GA4 credentials in
                <span class="font-medium">Integration Credentials</span>. Once the scheduled
                <code>google:pull-ga4</code> pull has run, sessions, revenue and channel intel
                appear here automatically. Until then the tiles above show zeros — that’s expected,
                not an error.
            </p>
        </x-filament::section>
    @endunless
</x-filament-panels::page>
