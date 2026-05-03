{{--
    Shared partial: read-only Horizon jobs table (Pending / Completed / Silenced).
    Failed-jobs gets its own dedicated view because it needs Retry + Delete actions.

    @var \Illuminate\Support\Collection $jobs
    @var int $total
--}}
<x-filament::section>
    <p class="text-sm text-gray-500 mb-3">Total: {{ $total }} (showing latest {{ $jobs->count() }}).</p>
    @if ($jobs->isEmpty())
        <p class="text-sm text-gray-500">No jobs in this state.</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                    <th class="py-2 pr-4 w-44">ID</th>
                    <th class="py-2 pr-4 w-28">Queue</th>
                    <th class="py-2 pr-4">Job</th>
                    <th class="py-2 pr-4 w-20 text-right">Attempts</th>
                    <th class="py-2 w-44">Reserved / Completed</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($jobs as $job)
                    <tr class="border-b border-gray-100 dark:border-gray-800 align-top">
                        <td class="py-2 pr-4 font-mono text-xs">{{ \Illuminate\Support\Str::limit((string) ($job->id ?? '—'), 18) }}</td>
                        <td class="py-2 pr-4 font-mono text-xs">{{ $job->queue ?? '—' }}</td>
                        <td class="py-2 pr-4 font-mono text-xs">{{ $job->name ?? data_get($job->payload, 'displayName') ?? 'Unknown' }}</td>
                        <td class="py-2 pr-4 text-right">{{ data_get($job->payload, 'attempts') ?? data_get($job->payload, 'data.attempts') ?? 0 }}</td>
                        <td class="py-2 text-xs">
                            @php($ts = $job->reserved_at ?? $job->completed_at ?? null)
                            @if ($ts)
                                {{ \Illuminate\Support\Carbon::createFromTimestamp((int) $ts)->diffForHumans() }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-filament::section>
