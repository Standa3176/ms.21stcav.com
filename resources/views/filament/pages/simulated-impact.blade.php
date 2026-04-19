<x-filament-panels::page>
    <div class="space-y-6">
        {{--
            Simulated Impact — PRCE-09
            Shows which SKUs would change if this rule were saved as-is.
            Transactional dry-run: DB::beginTransaction + DB::rollBack wraps the
            resolver walk so nothing persists. See
            App\Domain\Pricing\Services\SimulatedImpactCalculator.
        --}}

        {{-- Header: current state of the rule under test --}}
        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-start justify-between">
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Rule under test</div>
                    <div class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">
                        #{{ $rule->id }} — scope: <span class="font-mono">{{ $rule->scope }}</span>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-4 text-sm text-gray-600 dark:text-gray-300">
                        @if ($rule->brand_id !== null)
                            <span>Brand: <span class="font-mono">{{ $rule->brand_id }}</span></span>
                        @endif
                        @if ($rule->category_id !== null)
                            <span>Category: <span class="font-mono">{{ $rule->category_id }}</span></span>
                        @endif
                        <span>Margin: <strong>{{ number_format($rule->margin_basis_points / 100, 2) }}%</strong></span>
                        <span>Priority: {{ $rule->priority }}</span>
                        <span>Active: {{ $rule->active ? 'yes' : 'no' }}</span>
                    </div>
                </div>

                <x-filament::button
                    wire:click="simulate"
                    icon="heroicon-o-chart-bar"
                    color="primary"
                >
                    Simulate impact
                </x-filament::button>
            </div>
        </div>

        {{-- Result: count + first 50 rows with sku/current/proposed/delta --}}
        @if ($result !== null)
            <div class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900"
                data-testid="simulated-impact-result">

                <div class="border-b border-gray-200 p-6 dark:border-gray-700">
                    <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                        {{ number_format($result['count']) }}
                        SKU{{ $result['count'] === 1 ? '' : 's' }}
                        would change
                    </div>
                    <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        @if ($result['count'] > count($result['rows']))
                            Showing first {{ count($result['rows']) }} of {{ number_format($result['count']) }}
                            — full CSV export ships in Phase 7.
                        @else
                            All {{ count($result['rows']) }} affected rows shown below.
                        @endif
                    </div>
                    <div class="mt-2 text-xs italic text-gray-400 dark:text-gray-500">
                        Transactional dry-run — nothing persisted, no ProductPriceChanged events emitted.
                    </div>
                </div>

                @if (count($result['rows']) > 0)
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">SKU</th>
                                    <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Source</th>
                                    <th class="px-4 py-2 text-right font-medium text-gray-700 dark:text-gray-300">Current</th>
                                    <th class="px-4 py-2 text-right font-medium text-gray-700 dark:text-gray-300">Proposed</th>
                                    <th class="px-4 py-2 text-right font-medium text-gray-700 dark:text-gray-300">Δ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach ($result['rows'] as $row)
                                    <tr>
                                        <td class="px-4 py-2 font-mono text-gray-800 dark:text-gray-200">{{ $row['sku'] ?: '—' }}</td>
                                        <td class="px-4 py-2 text-gray-600 dark:text-gray-400">{{ $row['resolutionSource'] }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums text-gray-700 dark:text-gray-300">
                                            £{{ number_format($row['currentPennies'] / 100, 2) }}
                                        </td>
                                        <td class="px-4 py-2 text-right tabular-nums text-gray-900 dark:text-gray-100">
                                            £{{ number_format($row['proposedPennies'] / 100, 2) }}
                                        </td>
                                        <td class="px-4 py-2 text-right tabular-nums font-semibold {{ $row['deltaPennies'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                                            {{ $row['deltaPennies'] > 0 ? '+' : '' }}£{{ number_format($row['deltaPennies'] / 100, 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="p-6 text-sm text-gray-500 dark:text-gray-400">
                        No SKUs would change under this rule.
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
