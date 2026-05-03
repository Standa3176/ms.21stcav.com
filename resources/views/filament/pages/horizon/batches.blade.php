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
    @endif

    @php($batches = $this->getBatches())
    <x-filament::section>
        <p class="text-sm text-gray-500 mb-3">Showing the {{ $batches->count() }} most recent batch(es).</p>
        @if ($batches->isEmpty())
            <p class="text-sm text-gray-500">No batches recorded yet.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                        <th class="py-2 pr-4">ID</th>
                        <th class="py-2 pr-4">Name</th>
                        <th class="py-2 pr-4 w-20 text-right">Total</th>
                        <th class="py-2 pr-4 w-24 text-right">Pending</th>
                        <th class="py-2 pr-4 w-24 text-right">Processed</th>
                        <th class="py-2 pr-4 w-20 text-right">Failed</th>
                        <th class="py-2 w-44">Created</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($batches as $batch)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-4 font-mono text-xs">{{ \Illuminate\Support\Str::limit($batch->id ?? '—', 12) }}</td>
                            <td class="py-2 pr-4">{{ $batch->name ?? 'Unnamed' }}</td>
                            <td class="py-2 pr-4 text-right">{{ $batch->totalJobs ?? 0 }}</td>
                            <td class="py-2 pr-4 text-right">{{ $batch->pendingJobs ?? 0 }}</td>
                            <td class="py-2 pr-4 text-right">{{ ($batch->totalJobs ?? 0) - ($batch->pendingJobs ?? 0) }}</td>
                            <td class="py-2 pr-4 text-right">
                                @php($failed = $batch->failedJobs ?? 0)
                                <span class="@if ($failed > 0) text-danger-600 font-semibold @endif">{{ $failed }}</span>
                            </td>
                            <td class="py-2 text-xs">
                                @if (isset($batch->createdAt))
                                    {{ \Illuminate\Support\Carbon::createFromTimestamp($batch->createdAt)->diffForHumans() }}
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
</x-filament-panels::page>
