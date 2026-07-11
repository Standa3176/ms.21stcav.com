# 15b-02 — Marketing dashboard (read-only presentation)

**Type:** GSD phase-plan slice (TDD, atomic commits). Executor does NOT push/deploy.
**Parent:** Phase 15 (expanded) — Marketing Intelligence. Presentation over 15a data + 15b-01 advice.
**Decisions:** D15-1/2/3 — read-only, advice-only. This slice is PURE PRESENTATION (no new data, no writes).

## Goal
A single **Marketing** dashboard page giving an at-a-glance view of channel/campaign performance (from
`ga_channel_metrics_daily`, 15a-02) and the latest agent advice (`ad_optimisation` Suggestions, 15b-01).
It is read-only and must render cleanly with a friendly **empty state when GA4 isn't connected yet**
(no rows) — never an error. Nothing here changes data or calls Google.

## Template to MIRROR (verified)
- Page: `app/Domain/Pricing/Filament/Pages/PricingOperationsPage.php` — `extends Page` with
  `$navigationIcon/$navigationGroup/$navigationSort/$navigationLabel/$title/$view/$slug` + `canAccess()`
  + a blade `$view` + `getHeaderWidgets()`.
- Stats widget: `app/Filament/Widgets/CrmPushSuccessRateWidget.php` — `extends StatsOverviewWidget`,
  `getStats(): array` of `Stat::make(...)`.
- Chart widget: `app/Domain/Competitor/Filament/Widgets/SkuPriceTrendChart.php` — `extends ChartWidget`,
  `$heading`, `getType()`, `getData()`.
- Data: `App\Domain\Integrations\Models\GaChannelMetric` (columns date/channel_group/source_medium/
  campaign/sessions/key_events/transactions/purchase_revenue_pennies). Advice: `Suggestion` where
  `kind='ad_optimisation'`. Nav group **'Marketing'** already exists (added 15a-02, after Competitors).

## Tasks

### Task 1 — `MarketingDashboardPage` (TDD)
`app/Domain/Integrations/Filament/Pages/MarketingDashboardPage.php` (keep it in the domain that owns the
GA data, alongside the 15a-02 GA4 Channels resource — presentation layer, deptrac-clean).
- `$navigationGroup = 'Marketing'`, `$navigationSort = 10` (first in the group, before the GA4 Channels
  resource), `$navigationLabel = 'Marketing Dashboard'`, `$title`, `$slug = 'marketing-dashboard'`,
  a heroicon (e.g. `heroicon-o-presentation-chart-line`), `$view = 'filament.pages.marketing-dashboard'`.
- `canAccess()` consistent with the GA4 Channels resource (authed workspace read).
- `getHeaderWidgets()` returns the three widgets below.
- Blade view: header + a short subheading; if there are zero `GaChannelMetric` rows, render an
  **empty-state callout** ("Connect Google Analytics 4 in Integration Credentials to populate this
  dashboard") instead of empty charts.
Test: page renders (200) for an admin via Livewire; empty-state text present when no rows; absent when
rows exist.

### Task 2 — `MarketingOverviewStats` widget (TDD, StatsOverviewWidget)
`app/Domain/Integrations/Filament/Widgets/MarketingOverviewStats.php`. Over the last 30 days from
`ga_channel_metrics_daily`:
- Sessions (sum), Transactions (sum), Revenue (sum of `purchase_revenue_pennies` → £), and Top channel
  by revenue (channel_group label). Use `Stat::make`. Driver-portable aggregates (SUM/GROUP BY only —
  no MySQL-only date fns; filter `whereBetween('date', [from, today])`).
- Zero-safe: with no rows, show `£0.00` / `0` / `—`, no divide-by-zero.
Test: seeded rows → correct sums + top channel; no rows → zero-state stats, no error.

### Task 3 — `MarketingRevenueTrendChart` widget (TDD, ChartWidget)
`app/Domain/Integrations/Filament/Widgets/MarketingRevenueTrendChart.php`. Daily revenue (£, from pennies)
over the last 30 days — `getType()='line'` (or bar), `getData()` labels=dates, dataset=revenue.
Driver-portable (group by `date`). Zero-safe: empty dataset renders an empty chart, not an error.
Test: seeded rows across a few days → dataset matches; no rows → empty dataset, no error.

### Task 4 — `LatestMarketingAdviceWidget` (TDD, table widget)
`app/Domain/Integrations/Filament/Widgets/LatestMarketingAdviceWidget.php` — a table widget of the most
recent pending `ad_optimisation` Suggestions (created_at desc, limit ~10): columns action_type / target /
confidence / created_at, with a link/action to the Suggestions inbox filtered to the kind. Read-only —
no approve/reject here (that stays in the inbox). Mirror an existing table widget
(`app/Filament/Widgets/*` that extends a table widget).
Test: seeded `ad_optimisation` Suggestions appear; other kinds excluded; no rows → empty table, no error.

### Task 5 — Blade view + wiring
`resources/views/filament/pages/marketing-dashboard.blade.php` — Filament page scaffold rendering the
header widgets (mirror `resources/views/filament/pages/pricing-operations.blade.php`), plus the
empty-state callout logic from Task 1. Confirm the page appears under the Marketing nav group in the
correct order relative to the GA4 Channels resource.

## Verify
- `pest` on the new page + 3 widgets (render + aggregate correctness + empty-state) — GREEN. Run a wider
  Filament/Integrations smoke to confirm no panel-boot regression.
- `php artisan route:list --path=admin` exit 0 — `admin/marketing-dashboard` resolves.
- `pint` pass on touched files.
- `vendor/bin/deptrac analyse` → **0 violations** (page + widgets are presentation in the Integrations
  domain — already modeled as allowed; if flagged, they belong under the presentation/Http exemption —
  do NOT add allow-list edges without noting why).

## Guardrails / out of scope
- PURE PRESENTATION. No new tables/migrations, no data pull, no writes, no Google calls, no changes to
  the agent/tools/mapper. No approve/reject actions on the dashboard (inbox owns those).
- Empty-state is a hard requirement — the dashboard must render friendly with zero GA4 rows (pre-creds).
- Driver-portable aggregates (SQLite tests / MariaDB prod — no MySQL-only date/JSON fns). Money is stored
  as pennies; divide by 100 for display only.
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP/composer via Herd (~/.config/herd/bin/php84/php.exe). No push, no deploy. Atomic commits per task.
  Write `15b-02-SUMMARY.md` on completion (commit SHAs, widgets, empty-state + aggregate test results,
  verify results).
