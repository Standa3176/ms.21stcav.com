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
        @php($tags = $this->getMonitoredTags())
        <x-filament::section>
            @if ($tags->isEmpty())
                <p class="text-sm text-gray-500">No tags are currently being monitored. Use the "Monitor new tag" header action to start.</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                            <th class="py-2 pr-4">Tag</th>
                            <th class="py-2 pr-4 w-32">Active + failed</th>
                            <th class="py-2 w-28 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tags as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-2 pr-4 font-mono">{{ $row['tag'] }}</td>
                                <td class="py-2 pr-4">{{ $row['count'] }}</td>
                                <td class="py-2 text-right">
                                    <button
                                        type="button"
                                        wire:click="stopMonitoring(@js($row['tag']))"
                                        wire:confirm="Stop monitoring '{{ $row['tag'] }}'?"
                                        class="text-danger-600 hover:underline"
                                    >
                                        Stop
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
