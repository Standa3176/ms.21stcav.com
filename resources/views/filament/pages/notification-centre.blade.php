{{-- Phase 7 Plan 04 — NotificationCentrePage view (D-10 / D-11). --}}
<x-filament-panels::page>
    <div wire:poll.{{ (int) config('dashboard.widget_poll_seconds', 60) }}s>
        {{-- Tab header --}}
        <div class="flex flex-wrap gap-2 border-b border-gray-200 dark:border-gray-700 pb-2 mb-4">
            @foreach ($this->getTabs() as $key => $label)
                <button
                    type="button"
                    wire:click="switchTab('{{ $key }}')"
                    @class([
                        'px-4 py-2 text-sm font-medium rounded-t-md transition',
                        'bg-primary-500 text-white shadow' => $activeTab === $key,
                        'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' => $activeTab !== $key,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @php
            $data = $this->getData();
        @endphp

        {{-- Failed jobs tab --}}
        @if ($activeTab === 'failed-jobs')
            <div class="rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="text-left p-3">Failed at</th>
                            <th class="text-left p-3">Queue</th>
                            <th class="text-left p-3">Exception</th>
                            <th class="text-right p-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data['failed-jobs'] as $row)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="p-3 whitespace-nowrap text-gray-600 dark:text-gray-300">{{ $row['failed_at'] }}</td>
                                <td class="p-3"><code class="text-xs">{{ $row['queue'] }}</code></td>
                                <td class="p-3 font-mono text-xs text-red-600 dark:text-red-400">{{ $row['exception_summary'] }}</td>
                                <td class="p-3 text-right">
                                    @can('admin-only')
                                    @endcan
                                    <button
                                        type="button"
                                        wire:click="retryFailedJob('{{ $row['uuid'] }}')"
                                        class="px-3 py-1 text-xs bg-primary-500 text-white rounded hover:bg-primary-600"
                                    >Retry</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="p-6 text-center text-gray-500">No failed jobs in the last 7 days.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        {{-- Stale feeds tab --}}
        @elseif ($activeTab === 'stale-feeds')
            <div class="rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="text-left p-3">Competitor</th>
                            <th class="text-left p-3">Hours since last ingest</th>
                            <th class="text-left p-3">Status</th>
                            <th class="text-right p-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data['stale-feeds'] as $row)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="p-3">{{ $row['name'] }}</td>
                                <td class="p-3">{{ $row['hours_since'] ?? '—' }}</td>
                                <td class="p-3">
                                    <span @class([
                                        'px-2 py-0.5 rounded text-xs font-medium',
                                        'bg-red-100 text-red-700' => $row['hours_since'] === null,
                                        'bg-amber-100 text-amber-700' => $row['hours_since'] !== null,
                                    ])>
                                        {{ $row['hours_since'] === null ? 'Missing' : 'Stale' }}
                                    </span>
                                </td>
                                <td class="p-3 text-right">
                                    <button
                                        type="button"
                                        wire:click="reingestCompetitor({{ $row['competitor_id'] }})"
                                        class="px-3 py-1 text-xs bg-warning-500 text-white rounded hover:bg-warning-600"
                                    >Re-ingest</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="p-6 text-center text-gray-500">All competitor feeds fresh.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        {{-- Pending suggestions tab --}}
        @elseif ($activeTab === 'pending-suggestions')
            <div class="rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="text-left p-3">Kind</th>
                            <th class="text-left p-3">Count</th>
                            <th class="text-left p-3">Oldest</th>
                            <th class="text-right p-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data['pending-suggestions'] as $row)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="p-3"><code class="text-xs">{{ $row['kind'] }}</code></td>
                                <td class="p-3 font-semibold">{{ $row['count'] }}</td>
                                <td class="p-3 text-gray-600 dark:text-gray-300">{{ $row['oldest'] }}</td>
                                <td class="p-3 text-right">
                                    <a
                                        href="/admin/suggestions?tableFilters[kind][value]={{ $row['kind'] }}"
                                        class="px-3 py-1 text-xs bg-primary-500 text-white rounded hover:bg-primary-600"
                                    >View</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="p-6 text-center text-gray-500">No pending suggestions.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        {{-- Webhook DLQ tab --}}
        @elseif ($activeTab === 'webhook-dlq')
            <div class="rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="text-left p-3">Failed at</th>
                            <th class="text-left p-3">Channel</th>
                            <th class="text-left p-3">Operation</th>
                            <th class="text-left p-3">Correlation</th>
                            <th class="text-left p-3">Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data['webhook-dlq'] as $row)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="p-3 whitespace-nowrap">{{ $row['failed_at'] }}</td>
                                <td class="p-3"><code class="text-xs">{{ $row['channel'] }}</code></td>
                                <td class="p-3">{{ $row['operation'] }}</td>
                                <td class="p-3 font-mono text-xs">{{ $row['correlation_id'] }}</td>
                                <td class="p-3 text-red-600 dark:text-red-400">{{ $row['error'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="p-6 text-center text-gray-500">No webhook DLQ entries in the last 7 days.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
