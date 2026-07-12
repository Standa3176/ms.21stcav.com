# 260712-mdr — Marketing dashboard date-range selector

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Ask:** operator wants to choose the dashboard's date range (currently hardcoded 30 days, unhelpful
with sparse May–July data + a recent gap).

## Current state
`MarketingDashboardPage` (Integrations domain) renders 3 header widgets; `MarketingOverviewStats`
(`WINDOW_DAYS = 30`) and `MarketingRevenueTrendChart` (last 30d) each hardcode a 30-day trailing window.
`LatestMarketingAdviceWidget` lists recent pending `ad_optimisation` Suggestions (NOT GA-date-windowed).

## Goal
A date-range control at the top of the Marketing Dashboard that drives the two GA-data widgets. Presets
**Last 7 / 30 / 90 days / This year / All time** + **Custom** (from–to). Default **90 days** (more useful
than 30 given current data). The advice widget stays range-independent (advice is "recent pending",
not a GA window) — leave it unchanged.

## Approach (idiomatic Filament v3 — with an explicit fallback)
Use Filament's built-in page-filters→widgets mechanism:
- Page: add `use \Filament\Pages\Dashboard\Concerns\HasFiltersForm;` and implement `filtersForm(Form
  $form)` with a `Select` (the presets, keyed `7d/30d/90d/ytd/all/custom`, default `90d`) plus two
  `DatePicker`s (`from`,`to`) that are `->visible(fn (Get $get) => $get('range') === 'custom')`. Render
  the filters form in the blade (`{{ $this->filtersForm }}`) ABOVE the widgets. The trait exposes
  `$this->filters` and shares it with header widgets.
- Widgets: `use \Filament\Widgets\Concerns\InteractsWithPageFilters;` in `MarketingOverviewStats` and
  `MarketingRevenueTrendChart`; read `$this->filters['range'] / ['from'] / ['to']` and resolve to a
  concrete `[from, to]`.
- **Centralize the preset→dates resolution** in ONE shared place (a small `private`/`static` helper or a
  tiny `MarketingDateRange` value object in the Integrations domain) so both widgets resolve a range
  IDENTICALLY. Rules: `7d/30d/90d` = trailing N-1 days inclusive of today; `ytd` = Jan 1 this year →
  today; `all` = a wide floor (e.g. 2000-01-01) → today; `custom` = the two pickers (fall back to `90d`
  if either is blank/invalid). Driver-portable date strings (Y-m-d), TZ = app default.

**Fallback (only if `HasFiltersForm` does not compose cleanly with the base `Filament\Pages\Page`):**
switch to a **query-string** approach — the filters form navigates with `?range=...&from=...&to=...`
and each widget resolves its window from `request()->query()` via the SAME shared helper. This is fully
robust (no cross-component Livewire state) and acceptable if the trait fights the custom page. Note in
the SUMMARY which path was used and why.

## Tasks

### Task 1 — Shared range resolver (TDD)
The single source of truth mapping `(range, from, to)` → `[Carbon $from, Carbon $to]` (Y-m-d strings).
Pure, unit-tested: each preset yields the right window; `custom` honours the pickers; blank/invalid
custom falls back to `90d`; `all` spans everything.

### Task 2 — Page filters form (TDD)
Add the filters form to `MarketingDashboardPage` (default `90d`; custom pickers conditionally visible),
rendered in the blade above the widgets. Keep the existing empty-state callout + "Review with Claude"
action intact. Test: the page renders (200) for an admin with the filters form present; default range is
`90d`.

### Task 3 — Widgets read the range (TDD)
Refactor `MarketingOverviewStats` + `MarketingRevenueTrendChart` to resolve their window via the shared
resolver from the page filters (remove the hardcoded `WINDOW_DAYS`/30d). Zero-safe as today.
Tests (the meat): seed `ga_channel_metrics_daily` rows across a spread of dates; drive the page filter
(`Livewire::test(MarketingDashboardPage::class)->set('filters.range', '7d')` etc., or the query-string
equivalent if fallback) and assert the stats totals + chart dataset reflect ONLY the selected window;
switch to `90d`/`custom` and assert the numbers change accordingly. Include a custom from–to case.

### Task 4 — Regression guard (keep the mdx fix green)
Re-run / keep `MarketingWidgetsLivewireRegistrationTest` GREEN — the widgets must STILL resolve as
Livewire components (adding `InteractsWithPageFilters` must not break registration). If the page path
changed (fallback), ensure `->discoverWidgets` for the Integrations domain still applies.

## Verify
- `pest`: the resolver, the page, the two widgets (per-range correctness), and the mdx registration test
  — all GREEN. Wider Integrations suite: no regression.
- `php artisan route:list --path=admin` exit 0.
- `pint` pass; `vendor/bin/deptrac analyse` → 0 violations (all presentation in Integrations).

## Guardrails / out of scope
- Only the two GA-data widgets react to the range; the advice widget is unchanged.
- No new tables/migrations, no writes, no Google calls, no changes to the agent/pull/command.
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP/composer via Herd (~/.config/herd/bin/php84/php.exe). No push, no deploy. Atomic commits. Write
  `260712-mdr-SUMMARY.md` (approach used [trait vs query-string] + why, per-range test results, mdx
  registration still green, verify results; redeploy = code-only cache rebuild, no composer/migrate).
