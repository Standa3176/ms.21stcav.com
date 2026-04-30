<x-filament-panels::page>
    {{-- Phase 10 Plan 05 D-09 — single-purpose triage view for rejected
         margin_change Suggestions enriched by the PricingAgent. The page
         class (App\Filament\Pages\AgentRunRejectionInboxPage) owns the
         table; this Blade is the panel-chrome wrapper. --}}
    {{ $this->table }}
</x-filament-panels::page>
