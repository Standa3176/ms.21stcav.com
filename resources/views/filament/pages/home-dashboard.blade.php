<x-filament-panels::page>
    {{-- Phase 7 / dashboard redesign — priority "triage" flow: the operator reads
         top-to-bottom (what needs me → did today's sync run → catalogue state →
         is the system healthy). Each section renders its own widget subset. --}}
    <div class="space-y-8">
        @foreach ($this->getDashboardSections() as $section)
            @if (! empty($section['widgets']))
                <section class="space-y-3">
                    <div class="flex items-center gap-x-2 border-b border-gray-200 pb-2 dark:border-white/10">
                        <x-filament::icon
                            :icon="$section['icon']"
                            @class([
                                'h-5 w-5 shrink-0',
                                'text-danger-500' => ($section['tone'] ?? 'gray') === 'danger',
                                'text-warning-500' => ($section['tone'] ?? 'gray') === 'warning',
                                'text-primary-500' => ($section['tone'] ?? 'gray') === 'primary',
                                'text-gray-400 dark:text-gray-500' => ($section['tone'] ?? 'gray') === 'gray',
                            ])
                        />
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                            {{ $section['title'] }}
                        </h2>
                        @if (! empty($section['description']))
                            <span class="hidden text-sm text-gray-500 sm:inline dark:text-gray-400">
                                — {{ $section['description'] }}
                            </span>
                        @endif
                    </div>

                    <x-filament-widgets::widgets
                        :widgets="$section['widgets']"
                        :columns="$section['columns'] ?? 3"
                        :data="$this->getWidgetData()"
                    />
                </section>
            @endif
        @endforeach
    </div>
</x-filament-panels::page>
