<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Placeholder view shipped in Task 1 for page routing; Task 3 populates full UI. --}}
        <div class="text-sm">Rule #{{ $record->id }} — scope: {{ $record->scope }}, margin: {{ number_format($record->margin_basis_points / 100, 2) }}%, priority: {{ $record->priority }}</div>

        <x-filament::button wire:click="simulate" icon="heroicon-o-chart-bar">
            Simulate
        </x-filament::button>

        @if ($result)
            <div class="text-sm">{{ $result['count'] }} SKUs would change.</div>
        @endif
    </div>
</x-filament-panels::page>
