<x-filament-panels::page>
    @php($banner = $this->getRedisBannerData())
    @if ($banner)
        <x-filament::section>
            <div class="flex items-start gap-3 text-warning-600">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-5 h-5 mt-0.5" />
                <div>
                    <p class="font-semibold">{{ $banner['message'] }}</p>
                    <p class="text-sm font-mono mt-1">{{ $banner['error'] }}</p>
                    <p class="text-xs mt-2">Stat tiles are hidden until Redis is reachable. Start your Redis server (or check the connection in <code>config/database.php</code>) and refresh.</p>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
