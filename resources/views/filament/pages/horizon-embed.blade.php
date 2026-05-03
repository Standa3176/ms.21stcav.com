{{-- Phase 7 Plan 02 — D-03 UX-correction patch. Embeds Horizon dashboard --}}
{{-- inside Filament admin chrome via iframe. Replaces "open in new tab"     --}}
{{-- NavigationItem so operators retain sidebar context while monitoring     --}}
{{-- queues. See HorizonEmbedPage class docblock for rollback path.          --}}
<x-filament-panels::page>
    <iframe
        src="/horizon/dashboard"
        class="w-full"
        style="height: calc(100vh - 200px); border: 0;"
        frameborder="0"
        title="Horizon Dashboard"
    ></iframe>
</x-filament-panels::page>
