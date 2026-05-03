<x-filament-panels::page>
    @php($banner = $this->getRedisBannerData())
    @if ($banner)
        <x-filament::section>
            <div class="flex items-start gap-3 text-warning-600">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-5 h-5 mt-0.5" />
                <div>
                    <p class="font-semibold">{{ $banner['message'] }}</p>
                    <p class="text-sm font-mono mt-1">{{ $banner['error'] }}</p>
                </div>
            </div>
        </x-filament::section>
    @else
        @php($jobs = $this->getJobs())
        @php($total = $this->getTotal())
        <x-filament::section>
            <p class="text-sm text-gray-500 mb-3">Total: {{ $total }} (showing latest {{ $jobs->count() }}).</p>
            @if ($jobs->isEmpty())
                <p class="text-sm text-gray-500">No failed jobs. Nice.</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                            <th class="py-2 pr-4 w-44">ID</th>
                            <th class="py-2 pr-4 w-28">Queue</th>
                            <th class="py-2 pr-4">Job</th>
                            <th class="py-2 pr-4">Exception</th>
                            <th class="py-2 pr-4 w-32">Failed</th>
                            <th class="py-2 w-44 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($jobs as $job)
                            <tr class="border-b border-gray-100 dark:border-gray-800 align-top">
                                <td class="py-2 pr-4 font-mono text-xs">{{ \Illuminate\Support\Str::limit((string) ($job->id ?? '—'), 18) }}</td>
                                <td class="py-2 pr-4 font-mono text-xs">{{ $job->queue ?? '—' }}</td>
                                <td class="py-2 pr-4 font-mono text-xs">{{ $job->name ?? data_get($job->payload, 'displayName') ?? 'Unknown' }}</td>
                                <td class="py-2 pr-4 font-mono text-xs text-danger-600" title="{{ $job->exception ?? '' }}">
                                    {{ \Illuminate\Support\Str::limit((string) ($job->exception ?? ''), 80) }}
                                </td>
                                <td class="py-2 pr-4 text-xs">
                                    @if (! empty($job->failed_at))
                                        {{ \Illuminate\Support\Carbon::createFromTimestamp((int) $job->failed_at)->diffForHumans() }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-2 text-right">
                                    <button
                                        type="button"
                                        wire:click="retry(@js((string) $job->id))"
                                        class="text-primary-600 hover:underline mr-3"
                                    >
                                        Retry
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="deleteFailed(@js((string) $job->id))"
                                        wire:confirm="Permanently delete failed job {{ $job->id }}?"
                                        class="text-danger-600 hover:underline"
                                    >
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
