# 260712-mdr — Marketing dashboard date-range selector — SUMMARY

**One-liner:** Added a date-range control (presets 7/30/90d / this year / all time + custom, default
90d) to the Marketing Dashboard that drives the two GA-data header widgets via Filament v3's built-in
`HasFiltersForm` → `InteractsWithPageFilters` mechanism, with a single shared `MarketingDateRange`
resolver as the source of truth so both widgets resolve identical windows.

**Status:** COMPLETE. All 4 tasks done, TDD, atomic commits. No push, no deploy.

## Approach used: `HasFiltersForm` (idiomatic Filament v3) — NOT the query-string fallback

The plan offered a query-string fallback "only if `HasFiltersForm` does not compose cleanly with the base
`Filament\Pages\Page`". It **composed cleanly** — no fallback needed. Evidence:

- `use \Filament\Pages\Dashboard\Concerns\HasFiltersForm;` on the page (which itself pulls in `HasFilters`,
  adding the `#[Url] public ?array $filters` state + the `mountHasFilters()` hook) mounted without conflict
  on the existing `MarketingDashboardPage extends Page`. The page test `assertSet('filters.range', '90d')`
  confirmed the default hydrates correctly at mount.
- The one wrinkle vs a stock `Dashboard` page: the built-in `pages/dashboard.blade.php` auto-injects
  `['filters' => $this->filters]` into widget data, but this page uses a **custom view** rendered via
  `<x-filament-panels::page>`, whose header widgets receive only `getWidgetData()`. So I **overrode
  `getWidgetData()`** on the page to return `['filters' => $this->filters]`. That is the seam the widgets'
  `#[Reactive] public ?array $filters` (from `InteractsWithPageFilters`) consumes.
- Reactivity verified by design: `StatsOverviewWidget` re-renders server-side on the reactive prop change;
  `ChartWidget::rendering()` → `updateChartData()` re-diffs the checksum and dispatches fresh data to
  Chart.js. Both react when the page `filters` change.

Why this over query-string: no cross-request URL plumbing, native Filament session-persistence of the last
chosen range, and the presets/custom pickers get Filament form validation/visibility for free
(`from`/`to` `DatePicker`s are `->visible(fn (Get $get) => $get('range') === 'custom')`).

## Shared resolver (single source of truth)

`app/Domain/Integrations/Support/MarketingDateRange.php` — a `final readonly` value object.
`MarketingDateRange::resolve(?range, ?from, ?to)` → `{range, from, to (Y-m-d), label}`. Rules:
`7d/30d/90d` = trailing N-1 days inclusive of today; `ytd` = Jan 1 → today; `all` = 2000-01-01 → today;
`custom` = the two pickers (blank/invalid → fall back to 90d; reversed bounds swap); default/unknown → 90d.
Driver-portable date-only strings; TZ = app default. Both widgets call the identical resolver, so they can
never disagree on the window. `MarketingDateRange::options()` also drives the filter `Select`.

Lives in the **Integrations domain layer** (not the Filament/Http subdir), so the presentation widgets (Http
layer) depend on it cleanly — deptrac 0 violations.

## Tasks & commits

| Task | Commit | What |
| ---- | ------ | ---- |
| 1 — Shared range resolver (TDD) | `0bc1c0a` | `MarketingDateRange` + 14 unit tests (presets, custom, fallback, labels, options) |
| 2 — Page filters form (TDD) | `9b5f597` | `HasFiltersForm` + `filtersForm()` (Select default 90d + conditional from/to) + `getWidgetData()` override + blade render; empty-state & "Review with Claude" intact |
| 3 — Widgets read the range (TDD) | `95bc21e` | Both widgets use `InteractsWithPageFilters` + shared resolver; removed hardcoded `WINDOW_DAYS`; per-range tests (7d/90d/custom) |
| 4 — Regression guard | (no code change) | `MarketingWidgetsLivewireRegistrationTest` kept GREEN; discovery untouched (only traits added) |

## Per-range test results (the meat) — ALL GREEN

Seeded `ga_channel_metrics_daily` across a date spread with `Carbon::setTestNow('2026-07-12')` and drove
each window:

- **Stats widget** (`MarketingOverviewStatsTest`): 7d aggregates only in-window rows (40 sessions / £100.00,
  excludes the June row); 90d widens to include both (940 / £8,900.00); custom `2026-05-01..05-31` shows only
  the May row (70 / £200.00, excludes `£5,000.00`); default 90d excludes a 100-day-old row.
- **Chart widget** (`MarketingRevenueTrendChartTest`): 7d dataset = `['2026-07-10'] → [300.0]`; 90d =
  `['2026-06-01','2026-07-10'] → [900.0, 300.0]`; custom May = `['2026-05-15'] → [200.0]`; null filters →
  90d default excludes far-out rows.
- **Page seam**: `getWidgetData()['filters']['range']` = `90d` at mount, `7d` after `set('filters.range','7d')`
  — proves the page hands the chosen range to the (lazy-loaded) header widgets.

Note on test technique: Filament header widgets are **lazy-loaded** in a page `Livewire::test`, so their
data is not in the parent's initial HTML. Per-range correctness is therefore asserted by mounting each
widget directly with a `filters` prop (`Livewire::test(Widget::class, ['filters' => [...]])` / setting the
public prop for the chart's `getData()` reflection), and the page→widget propagation is asserted via the
`getWidgetData()` seam.

## mdx Livewire-registration regression — STILL GREEN

`MarketingWidgetsLivewireRegistrationTest` (the prod-500 guard) passes 4/4 after adding
`InteractsWithPageFilters` to both widgets. Adding the trait did not disturb the name↔class round-trip in
`ComponentRegistry`; `AdminPanelProvider` discovery for `App\Domain\Integrations\Filament\Widgets` was left
untouched.

## Verify results

- `pest` full Integrations feature + unit suites: **131 passed, 1 skipped** (pre-existing skip), 482
  assertions — no regression.
- Resolver: 14 passed. Page: 8 passed. Stats: 9 passed. Chart: 8 passed. Registration: 4 passed.
- `php artisan route:list --path=admin` → **exit 0**.
- `pint --test` on all 8 touched files → **pass**.
- `vendor/bin/deptrac analyse` → **0 violations** (0 skipped, 0 errors).

## Guardrails honoured

- Only the two GA-data widgets react to the range; `LatestMarketingAdviceWidget` unchanged.
- No new tables/migrations, no writes, no Google calls, no agent/pull/command changes.
- Empty-state callout + "Review with Claude" header action preserved and tested.
- Driver-portable (`whereBetween` on Y-m-d date strings; SQLite tests / MariaDB prod).
- Did **not** stage/touch pre-existing working-tree noise (`storage/app/research/supplier-probe.json`
  deletion, `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP via Herd `~/.config/herd/bin/php84/php.exe`. No push, no deploy.

## Redeploy note

Code-only change (PHP + one Blade view). Prod redeploy = pull + rebuild Filament/view/route caches
(`optimize:clear` / `filament:optimize`); **no** `composer install`, **no** `migrate`. No env changes.

## Files

- Created: `app/Domain/Integrations/Support/MarketingDateRange.php`,
  `tests/Unit/Domain/Integrations/MarketingDateRangeTest.php`
- Modified: `app/Domain/Integrations/Filament/Pages/MarketingDashboardPage.php`,
  `app/Domain/Integrations/Filament/Widgets/MarketingOverviewStats.php`,
  `app/Domain/Integrations/Filament/Widgets/MarketingRevenueTrendChart.php`,
  `resources/views/filament/pages/marketing-dashboard.blade.php`,
  `tests/Feature/Integrations/MarketingDashboardPageTest.php`,
  `tests/Feature/Integrations/MarketingOverviewStatsTest.php`,
  `tests/Feature/Integrations/MarketingRevenueTrendChartTest.php`

## Deviations from plan

None affecting scope. The plan's query-string fallback was not needed (`HasFiltersForm` composed cleanly).
Test-technique adaptation (lazy widgets → direct-mount + `getWidgetData()` seam) is a testing detail, not a
behaviour change.

## Self-Check: PASSED

- `app/Domain/Integrations/Support/MarketingDateRange.php` — FOUND
- SUMMARY commits `0bc1c0a`, `9b5f597`, `95bc21e` — all present in `git log`
