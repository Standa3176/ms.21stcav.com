<x-filament-panels::page>
    {{-- Dashboard redesign — priority "triage" flow (top-to-bottom: what needs me →
         did today's sync run → catalogue state → system health). The scoped CSS
         below fixes the two problems with the default Filament stat grid:
           1. Responsiveness — force a real auto-fill grid so cards REFLOW as the
              window resizes (Filament's fixed column count doesn't).
           2. Flatness — add depth (shadow + hover lift) and a severity accent
              stripe driven by the .fi-color-* class Filament already sets. --}}
    <style>
        /* 1 ── Responsive reflow: target the stat-grid container by class AND by
               "is the direct parent of a stat card" so it works regardless of the
               exact Filament container class name. */
        #ms-dashboard .fi-wi-stats-overview-stats-ctn,
        #ms-dashboard *:has(> .fi-wi-stats-overview-stat) {
            display: grid !important;
            grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr)) !important;
            gap: 1rem !important;
        }

        /* 2 ── Depth: lift the cards off the page + interactive hover. */
        #ms-dashboard .fi-wi-stats-overview-stat {
            position: relative;
            overflow: hidden;
            border-radius: 0.875rem;
            box-shadow: 0 1px 2px rgba(2, 6, 23, 0.04), 0 10px 22px -10px rgba(2, 6, 23, 0.16);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        #ms-dashboard .fi-wi-stats-overview-stat:hover {
            transform: translateY(-3px);
            box-shadow: 0 2px 4px rgba(2, 6, 23, 0.06), 0 18px 36px -12px rgba(2, 6, 23, 0.26);
        }

        /* 3 ── Severity accent stripe — colour comes from the description's
               .fi-color-* class that Filament renders per Stat->color(). */
        #ms-dashboard .fi-wi-stats-overview-stat::after {
            content: "";
            position: absolute;
            inset-block: 0;
            inset-inline-start: 0;
            width: 4px;
            background: transparent;
        }
        #ms-dashboard .fi-wi-stats-overview-stat:has(.fi-color-danger)::after  { background: rgb(239 68 68); }
        #ms-dashboard .fi-wi-stats-overview-stat:has(.fi-color-warning)::after { background: rgb(245 158 11); }
        #ms-dashboard .fi-wi-stats-overview-stat:has(.fi-color-success)::after { background: rgb(22 163 74); }
        #ms-dashboard .fi-wi-stats-overview-stat:has(.fi-color-info)::after    { background: rgb(59 130 246); }

        #ms-dashboard .fi-wi-stats-overview-stat-value { font-variant-numeric: tabular-nums; }

        /* Section header chip */
        #ms-dashboard .ms-section {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border-radius: 9999px;
            padding: 0.25rem 0.75rem 0.25rem 0.5rem;
            font-weight: 600;
            font-size: 0.8125rem;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }
        #ms-dashboard .ms-section[data-tone="danger"]  { background: rgb(254 242 242); color: rgb(185 28 28); }
        #ms-dashboard .ms-section[data-tone="primary"] { background: rgb(239 246 255); color: rgb(29 78 216); }
        #ms-dashboard .ms-section[data-tone="gray"]    { background: rgb(243 244 246); color: rgb(55 65 81); }
        .dark #ms-dashboard .ms-section[data-tone="danger"]  { background: rgb(127 29 29 / 0.35); color: rgb(252 165 165); }
        .dark #ms-dashboard .ms-section[data-tone="primary"] { background: rgb(30 58 138 / 0.35); color: rgb(147 197 253); }
        .dark #ms-dashboard .ms-section[data-tone="gray"]    { background: rgb(255 255 255 / 0.06); color: rgb(209 213 219); }
    </style>

    <div id="ms-dashboard" class="space-y-8">
        @foreach ($this->getDashboardSections() as $section)
            @if (! empty($section['widgets']))
                <section class="space-y-3">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="ms-section" data-tone="{{ $section['tone'] ?? 'gray' }}">
                            <x-filament::icon :icon="$section['icon']" class="h-4 w-4" />
                            {{ $section['title'] }}
                        </span>
                        @if (! empty($section['description']))
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $section['description'] }}</span>
                        @endif
                    </div>

                    <x-filament-widgets::widgets
                        :widgets="$section['widgets']"
                        :columns="1"
                        :data="$this->getWidgetData()"
                    />
                </section>
            @endif
        @endforeach
    </div>
</x-filament-panels::page>
