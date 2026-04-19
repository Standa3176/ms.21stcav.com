<x-filament-panels::page>
    <div class="space-y-6">
        {{--
            CRM Pipeline Settings — D-05, D-07, CRM-07.
            Singleton — one row in crm_pipeline_settings. Admin-only access enforced
            by CrmPipelineSettingsPage::canAccess() + save() abort_unless.
        --}}

        <form wire:submit="save">
            {{ $this->form }}

            <div class="mt-6 flex items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-o-check">
                    Save Settings
                </x-filament::button>
            </div>
        </form>

        @php($currentPipeline = $this->data['bitrix_pipeline_id'] ?? null)
        @if (empty($currentPipeline))
            <div class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200"
                data-testid="crm-pipeline-warning">
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 h-5 w-5 flex-none text-amber-600 dark:text-amber-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                    </svg>
                    <div>
                        <div class="font-semibold">Pipeline not configured</div>
                        <p class="mt-1">
                            Run <code class="rounded bg-amber-200/60 px-1 py-0.5 font-mono text-xs dark:bg-amber-800/40">php artisan bitrix:bootstrap</code> first, then pick a pipeline + landing stage above before flipping <code class="rounded bg-amber-200/60 px-1 py-0.5 font-mono text-xs dark:bg-amber-800/40">CRM_WRITE_ENABLED=true</code>.
                        </p>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
