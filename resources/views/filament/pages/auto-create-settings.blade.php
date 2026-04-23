<x-filament-panels::page>
    <div class="space-y-6">
        {{--
            Auto-Create Settings — AUTO-07, D-09.
            Singleton — one row in auto_create_settings. Admin-only access
            enforced by AutoCreateSettingsPage::canAccess() + save()
            abort_unless (Warning 9 defence-in-depth).
        --}}

        <form wire:submit="save">
            {{ $this->form }}

            <div class="mt-6 flex items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-o-check">
                    Save Settings
                </x-filament::button>
            </div>
        </form>

        @php($currentMode = $this->data['mode'] ?? 'draft')
        @if ($currentMode === 'immediate_publish')
            <div class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200"
                data-testid="auto-create-immediate-warning">
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 h-5 w-5 flex-none text-amber-600 dark:text-amber-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                    </svg>
                    <div>
                        <div class="font-semibold">Immediate publish is ACTIVE</div>
                        <p class="mt-1">
                            Products meeting the completeness threshold will publish without review. Ensure the Phase 7 operator runbook has been followed + Horizon DLQ monitoring is active. Switch back to <strong>draft</strong> above to revert.
                        </p>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
