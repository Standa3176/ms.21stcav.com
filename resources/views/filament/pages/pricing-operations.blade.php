<x-filament-panels::page>
    {{-- Pricing Operations — 4 clickable summary tiles (mountAction → modal + CSV)
         over 4 preview panels. Data from PricingOpsReport. --}}

    @php
        $money = fn (?int $pennies) => '£' . number_format(((int) $pennies) / 100, 2);
        $gbp = fn ($decimal) => '£' . number_format((float) $decimal, 2);
        $floorPct = number_format(($scan['floor_bps'] ?? 600) / 100, 1);
        $exportUrl = fn (string $bucket) => route('pricing-ops.export', ['bucket' => $bucket]);
        $exportXls = fn (string $bucket) => route('pricing-ops.export', ['bucket' => $bucket, 'format' => 'xlsx']);
    @endphp

    {{-- ── Clickable summary tiles ───────────────────────────────────── --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <button type="button" wire:click="mountAction('matched')"
            class="rounded-lg border border-gray-200 bg-white p-3 text-left transition hover:border-primary-400 hover:shadow dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">Matched (cost + competitor) ↗</div>
            <div class="text-2xl font-semibold">{{ number_format($scan['matched_count'] ?? 0) }}</div>
        </button>
        <button type="button" wire:click="mountAction('winnable')"
            class="rounded-lg border border-gray-200 bg-white p-3 text-left transition hover:border-primary-400 hover:shadow dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">Winnable (undercut OK) ↗</div>
            <div class="text-2xl font-semibold text-success-600">{{ number_format($scan['winnable_count'] ?? 0) }}</div>
        </button>
        <button type="button" wire:click="mountAction('atFloor')"
            class="rounded-lg border border-gray-200 bg-white p-3 text-left transition hover:border-primary-400 hover:shadow dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">At/below floor ({{ $floorPct }}%) ↗</div>
            <div class="text-2xl font-semibold text-warning-600">{{ number_format($scan['at_floor_count'] ?? 0) }}</div>
        </button>
        <button type="button" wire:click="mountAction('belowCost')"
            class="rounded-lg border border-gray-200 bg-white p-3 text-left transition hover:border-primary-400 hover:shadow dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">Below our cost ↗</div>
            <div class="text-2xl font-semibold text-danger-600">{{ number_format($scan['below_cost_count'] ?? 0) }}</div>
        </button>
    </div>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
        Click any number for the full list + CSV export. Positions computed
        {{ \Illuminate\Support\Carbon::parse($scan['computed_at'])->diffForHumans() }}
        (competitor prices ≤ {{ $scan['max_age_days'] ?? 30 }} days old, ex-VAT). “Recompute positions” refreshes.
    </p>

    {{-- ── Catalogue-expansion tiles (sourced separately from the cost tiles) ── --}}
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <button type="button" wire:click="mountAction('addCandidates')"
            class="flex w-full items-center justify-between rounded-lg border border-gray-200 bg-white p-3 text-left transition hover:border-primary-400 hover:shadow dark:border-gray-700 dark:bg-gray-900">
            <div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Products to add — suppliers carry, we don’t sell (≥{{ $addCandidates['min_suppliers'] ?? 2 }} suppliers) ↗</div>
                <div class="text-2xl font-semibold text-primary-600">{{ number_format($addCandidates['count'] ?? 0) }}</div>
            </div>
            <div class="text-xs text-gray-400">
                @if (($addCandidates['computed_at'] ?? null))
                    scanned {{ \Illuminate\Support\Carbon::parse($addCandidates['computed_at'])->diffForHumans() }}
                @else
                    not scanned yet
                @endif
            </div>
        </button>

        <button type="button" wire:click="mountAction('sourcingGaps')"
            class="flex w-full items-center justify-between rounded-lg border border-gray-200 bg-white p-3 text-left transition hover:border-primary-400 hover:shadow dark:border-gray-700 dark:bg-gray-900">
            <div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Sourcing gaps — competitor sells, no supplier carries it ↗</div>
                <div class="text-2xl font-semibold text-gray-600 dark:text-gray-300">{{ number_format($sourcingGaps['count'] ?? 0) }}</div>
            </div>
            <div class="text-xs text-gray-400">
                @if (($sourcingGaps['computed_at'] ?? null))
                    scanned {{ \Illuminate\Support\Carbon::parse($sourcingGaps['computed_at'])->diffForHumans() }}
                @else
                    not scanned yet
                @endif
            </div>
        </button>
    </div>

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
            <div class="mt-3 flex gap-4">
                <a href="{{ $exportUrl('recent_changes') }}" target="_blank" class="text-sm text-primary-600 hover:underline">Export CSV →</a>
                <a href="{{ $exportXls('recent_changes') }}" target="_blank" class="text-sm text-primary-600 hover:underline">Export XLS →</a>
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
            <div class="mt-3 flex gap-4">
                <a href="/admin/auto-create-reviews" class="text-sm text-primary-600 hover:underline">Open the review inbox →</a>
                <a href="{{ $exportUrl('new_skus') }}" target="_blank" class="text-sm text-primary-600 hover:underline">Export CSV →</a>
                <a href="{{ $exportXls('new_skus') }}" target="_blank" class="text-sm text-primary-600 hover:underline">Export XLS →</a>
            </div>
        @endif
    </x-filament::section>

    {{-- ── Panel 3 — Competitor at/below our floor ───────────────────── --}}
    <x-filament::section icon="heroicon-o-exclamation-triangle" class="mt-6">
        <x-slot name="heading">Competitor at/below our {{ $floorPct }}% floor ({{ number_format($scan['at_floor_count'] ?? 0) }})</x-slot>
        <x-slot name="description">Competitor is above our cost but so close that undercutting would breach the floor. Worst margins first — click the tile above for the full list.</x-slot>

        @php $atFloor = array_slice($scan['at_floor'] ?? [], 0, 25); @endphp
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
                                <td class="py-1 pr-4 text-gray-500">
                                    <span class="block">{{ $money($r['cost_ex']) }}</span>
                                    @if (! empty($r['supplier_name']))
                                        <span class="block text-xs text-gray-400">{{ \Illuminate\Support\Str::limit($r['supplier_name'], 24) }}</span>
                                    @endif
                                </td>
                                <td class="py-1 pr-4">
                                    <span class="block">{{ $money($r['comp_ex']) }}</span>
                                    @if (! empty($r['competitor_name']))
                                        <span class="block text-xs text-gray-400">{{ \Illuminate\Support\Str::limit($r['competitor_name'], 24) }}</span>
                                    @endif
                                </td>
                                <td class="py-1 text-warning-600">{{ number_format($r['margin_bps'] / 100, 1) }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3 flex gap-4">
                <button type="button" wire:click="mountAction('atFloor')" class="text-sm text-primary-600 hover:underline">View all {{ number_format($scan['at_floor_count'] ?? 0) }} →</button>
                <a href="{{ $exportUrl('at_floor') }}" target="_blank" class="text-sm text-primary-600 hover:underline">Export CSV →</a>
                <a href="{{ $exportXls('at_floor') }}" target="_blank" class="text-sm text-primary-600 hover:underline">Export XLS →</a>
            </div>
        @endif
    </x-filament::section>

    {{-- ── Panel 4 — Competitor below our cost ───────────────────────── --}}
    <x-filament::section icon="heroicon-o-no-symbol" class="mt-6">
        <x-slot name="heading">Competitor below our cost ({{ number_format($scan['below_cost_count'] ?? 0) }})</x-slot>
        <x-slot name="description">Lowest competitor sells at or under what we pay — unwinnable on price. A supplier-renegotiation list. Worst first.</x-slot>

        @php $belowCost = array_slice($scan['below_cost'] ?? [], 0, 25); @endphp
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
                                <td class="py-1 pr-4 text-gray-500">
                                    <span class="block">{{ $money($r['cost_ex']) }}</span>
                                    @if (! empty($r['supplier_name']))
                                        <span class="block text-xs text-gray-400">{{ \Illuminate\Support\Str::limit($r['supplier_name'], 24) }}</span>
                                    @endif
                                </td>
                                <td class="py-1 pr-4 text-danger-600 font-medium">
                                    <span class="block">{{ $money($r['comp_ex']) }}</span>
                                    @if (! empty($r['competitor_name']))
                                        <span class="block text-xs font-normal text-gray-400">{{ \Illuminate\Support\Str::limit($r['competitor_name'], 24) }}</span>
                                    @endif
                                </td>
                                <td class="py-1 text-danger-600">{{ number_format($r['margin_bps'] / 100, 1) }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3 flex gap-4">
                <button type="button" wire:click="mountAction('belowCost')" class="text-sm text-primary-600 hover:underline">View all {{ number_format($scan['below_cost_count'] ?? 0) }} →</button>
                <a href="{{ $exportUrl('below_cost') }}" target="_blank" class="text-sm text-primary-600 hover:underline">Export CSV →</a>
                <a href="{{ $exportXls('below_cost') }}" target="_blank" class="text-sm text-primary-600 hover:underline">Export XLS →</a>
            </div>
        @endif
    </x-filament::section>

    {{-- Mounted tile-action modals --}}
    <x-filament-actions::modals />
</x-filament-panels::page>
