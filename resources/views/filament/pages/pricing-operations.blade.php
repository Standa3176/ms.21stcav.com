<x-filament-panels::page>
    {{-- Pricing Operations — built 2026-05-25 (operator request). Four panels:
         recent price changes / new SKUs / competitor-at-floor / competitor-below-cost.
         Panels 3-4 come from CompetitorPositionScanner (cached; "Recompute" busts it). --}}

    @php
        $floorPct = number_format(($scan['floor_bps'] ?? 600) / 100, 1);
        $money = fn (?int $pennies) => '£' . number_format(((int) $pennies) / 100, 2);
        $gbp = fn ($decimal) => '£' . number_format((float) $decimal, 2);
        $pct = fn (int $bps) => number_format($bps / 100, 1) . '%';
    @endphp

    {{-- ── Summary strip ──────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">Matched (cost + competitor)</div>
            <div class="text-2xl font-semibold">{{ number_format($scan['matched_count'] ?? 0) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">Winnable (undercut OK)</div>
            <div class="text-2xl font-semibold text-success-600">{{ number_format($scan['winnable_count'] ?? 0) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">At/below floor ({{ $floorPct }}%)</div>
            <div class="text-2xl font-semibold text-warning-600">{{ number_format($scan['at_floor_count'] ?? 0) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">Below our cost</div>
            <div class="text-2xl font-semibold text-danger-600">{{ number_format($scan['below_cost_count'] ?? 0) }}</div>
        </div>
    </div>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
        Competitor positions computed
        {{ \Illuminate\Support\Carbon::parse($scan['computed_at'])->diffForHumans() }}
        (competitor prices ≤ {{ $scan['max_age_days'] ?? 30 }} days old, all ex-VAT). Use “Recompute positions” to refresh.
    </p>

    {{-- ── Panel 1 — Recent price changes ────────────────────────────── --}}
    <x-filament::section icon="heroicon-o-arrows-up-down" class="mt-6">
        <x-slot name="heading">Recent sell-price changes</x-slot>
        <x-slot name="description">Day-over-day moves from the daily price snapshots (most recent first).</x-slot>

        @if (count($recentChanges) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">No sell-price changes recorded in the snapshot window yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr><th class="py-1 pr-4">SKU</th><th class="py-1 pr-4">Old</th><th class="py-1 pr-4">New</th><th class="py-1 pr-4">Change</th><th class="py-1">Date</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($recentChanges as $r)
                            @php $delta = (float) $r->new_price - (float) $r->old_price; @endphp
                            <tr>
                                <td class="py-1 pr-4 font-mono">{{ $r->sku }}</td>
                                <td class="py-1 pr-4 text-gray-500">{{ $gbp($r->old_price) }}</td>
                                <td class="py-1 pr-4 font-medium">{{ $gbp($r->new_price) }}</td>
                                <td class="py-1 pr-4 {{ $delta < 0 ? 'text-success-600' : 'text-danger-600' }}">
                                    {{ $delta < 0 ? '▼' : '▲' }} {{ $gbp(abs($delta)) }}
                                </td>
                                <td class="py-1 text-gray-500">{{ \Illuminate\Support\Carbon::parse($r->recorded_at)->toDateString() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- ── Panel 2 — New SKUs awaiting review ────────────────────────── --}}
    <x-filament::section icon="heroicon-o-sparkles" class="mt-6">
        <x-slot name="heading">New SKUs awaiting review</x-slot>
        <x-slot name="description">Auto-drafted products (incl. the weekly competitor-only drafts) pending manual publish.</x-slot>

        @if ($newSkus->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing awaiting review.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr><th class="py-1 pr-4">SKU</th><th class="py-1 pr-4">Name</th><th class="py-1 pr-4">Status</th><th class="py-1 pr-4">Sell</th><th class="py-1">Added</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($newSkus as $p)
                            <tr>
                                <td class="py-1 pr-4 font-mono">{{ $p->sku }}</td>
                                <td class="py-1 pr-4">{{ \Illuminate\Support\Str::limit($p->name, 50) }}</td>
                                <td class="py-1 pr-4 text-xs">{{ str_replace('_', ' ', $p->auto_create_status) }}</td>
                                <td class="py-1 pr-4">{{ $p->sell_price !== null ? $gbp($p->sell_price) : '—' }}</td>
                                <td class="py-1 text-gray-500">{{ optional($p->created_at)->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">
                <a href="/admin/auto-create-reviews" class="text-sm text-primary-600 hover:underline">Open the review inbox →</a>
            </div>
        @endif
    </x-filament::section>

    {{-- ── Panel 3 — Competitor at/below our floor ───────────────────── --}}
    <x-filament::section icon="heroicon-o-exclamation-triangle" class="mt-6">
        <x-slot name="heading">Competitor at/below our {{ $floorPct }}% floor ({{ number_format($scan['at_floor_count'] ?? 0) }})</x-slot>
        <x-slot name="description">Competitor is above our cost but so close that undercutting would breach the floor — we hold at the floor price. Worst margins first.</x-slot>

        @php $atFloor = $scan['at_floor'] ?? []; @endphp
        @if (count($atFloor) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">No products currently held at the floor. 🎯</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr><th class="py-1 pr-4">SKU</th><th class="py-1 pr-4">Name</th><th class="py-1 pr-4">Our cost (ex)</th><th class="py-1 pr-4">Lowest comp (ex)</th><th class="py-1">Margin</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($atFloor as $r)
                            <tr>
                                <td class="py-1 pr-4 font-mono">{{ $r['sku'] }}</td>
                                <td class="py-1 pr-4">{{ \Illuminate\Support\Str::limit($r['name'], 45) }}</td>
                                <td class="py-1 pr-4 text-gray-500">{{ $money($r['cost_ex']) }}</td>
                                <td class="py-1 pr-4">{{ $money($r['comp_ex']) }}</td>
                                <td class="py-1 text-warning-600">{{ $pct($r['margin_bps']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (($scan['at_floor_count'] ?? 0) > count($atFloor))
                <p class="mt-2 text-xs text-gray-500">Showing the worst {{ count($atFloor) }} of {{ number_format($scan['at_floor_count']) }}.</p>
            @endif
        @endif
    </x-filament::section>

    {{-- ── Panel 4 — Competitor below our cost ───────────────────────── --}}
    <x-filament::section icon="heroicon-o-no-symbol" class="mt-6">
        <x-slot name="heading">Competitor below our cost ({{ number_format($scan['below_cost_count'] ?? 0) }})</x-slot>
        <x-slot name="description">Lowest competitor sells at or under what we pay — unwinnable on price. A supplier-renegotiation list. Worst first.</x-slot>

        @php $belowCost = $scan['below_cost'] ?? []; @endphp
        @if (count($belowCost) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">No products are priced below our cost by competitors. 🎉</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr><th class="py-1 pr-4">SKU</th><th class="py-1 pr-4">Name</th><th class="py-1 pr-4">Our cost (ex)</th><th class="py-1 pr-4">Lowest comp (ex)</th><th class="py-1">Margin</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($belowCost as $r)
                            <tr>
                                <td class="py-1 pr-4 font-mono">{{ $r['sku'] }}</td>
                                <td class="py-1 pr-4">{{ \Illuminate\Support\Str::limit($r['name'], 45) }}</td>
                                <td class="py-1 pr-4 text-gray-500">{{ $money($r['cost_ex']) }}</td>
                                <td class="py-1 pr-4 text-danger-600 font-medium">{{ $money($r['comp_ex']) }}</td>
                                <td class="py-1 text-danger-600">{{ $pct($r['margin_bps']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (($scan['below_cost_count'] ?? 0) > count($belowCost))
                <p class="mt-2 text-xs text-gray-500">Showing the worst {{ count($belowCost) }} of {{ number_format($scan['below_cost_count']) }}.</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
