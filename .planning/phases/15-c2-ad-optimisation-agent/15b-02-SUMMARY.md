---
phase: 15
plan: 15b-02
subsystem: Integrations / Marketing Intelligence
tags: [marketing, dashboard, filament, read-only, presentation, ga4, ad-optimisation, admin-pull, tdd]
requires:
  - ga_channel_metrics_daily + App\Domain\Integrations\Models\GaChannelMetric (15a-02)
  - ad_optimisation Suggestions + App\Domain\Agents\Jobs\RunAdOptimisationJob (15b-01)
  - agents:run-ad-optimisation lookback config (agents.ad_optimisation.data_lookback_days)
  - Marketing nav group (added 15a-02)
  - Http/presentation Deptrac layer allowing Domain/*/Filament/* to read across domains
provides:
  - Marketing Dashboard Filament page (slug admin/marketing-dashboard) under the Marketing nav group
  - MarketingOverviewStats (30d sessions/transactions/revenue/top-channel)
  - MarketingRevenueTrendChart (daily £ revenue trend, 30d)
  - LatestMarketingAdviceWidget (latest 10 pending ad_optimisation Suggestions, read-only)
  - On-demand "Review with Claude" header action (admin-pull; reuses RunAdOptimisationJob)
affects:
  - 15c (closed-loop actioning) — this slice remains advice-only presentation
tech-stack:
  added: []
  patterns:
    - "PURE PRESENTATION Filament page + widgets in Domain/Integrations/Filament (Http layer); no new tables/migrations, no writes, no Google calls"
    - "Driver-portable aggregates only: SUM + GROUP BY + whereBetween('date', …) (SQLite tests / MariaDB prod agree)"
    - "Money stored as integer pennies; divide by 100 for DISPLAY only"
    - "Hard empty-state: page + every widget render friendly with ZERO GaChannelMetric rows (never an error, no divide-by-zero)"
    - "Header widgets returned by getHeaderWidgets() need no ->discoverWidgets pointer (that would also leak them onto the main dashboard)"
    - "On-demand admin-pull action mirrors RunPricingAgentAction (authorize + requiresConfirmation + dispatch + Notification), reusing the existing job"
    - "No-data guard replicates the agents:run-ad-optimisation lookback VERBATIM so button + schedule agree"
key-files:
  created:
    - app/Domain/Integrations/Filament/Pages/MarketingDashboardPage.php
    - app/Domain/Integrations/Filament/Widgets/MarketingOverviewStats.php
    - app/Domain/Integrations/Filament/Widgets/MarketingRevenueTrendChart.php
    - app/Domain/Integrations/Filament/Widgets/LatestMarketingAdviceWidget.php
    - resources/views/filament/pages/marketing-dashboard.blade.php
    - tests/Feature/Integrations/MarketingOverviewStatsTest.php
    - tests/Feature/Integrations/MarketingRevenueTrendChartTest.php
    - tests/Feature/Integrations/LatestMarketingAdviceWidgetTest.php
    - tests/Feature/Integrations/MarketingDashboardPageTest.php
    - tests/Feature/Integrations/MarketingReviewWithClaudeActionTest.php
  modified:
    - app/Providers/Filament/AdminPanelProvider.php
decisions:
  - "Page + widgets placed in App\\Domain\\Integrations\\Filament (alongside the 15a-02 GA4 Channels resource) — presentation/Http Deptrac layer; deptrac stays 0 violations with no new allow-list edges."
  - "Widgets are page HEADER widgets (getHeaderWidgets) — only ->discoverPages added for Integrations, NOT ->discoverWidgets, to avoid leaking the marketing widgets onto the main Dashboard."
  - "LatestMarketingAdviceWidget surfaces the FIRST bundled proposal's action_type/target/confidence (the 15b-01 agent writes ONE bundled Suggestion carrying payload.proposals[]) plus a proposals count; canView gates on Suggestion viewAny (admin) matching the inbox."
  - "Review action gated admin-only via hasRole('admin') (same admin-pull posture as RunPricingAgentAction); no-data guard replicates RunAdOptimisationCommand's lookback so button + schedule agree; shadow-mode note added when agents.write_enabled=false (still dispatches — AgentRun recorded)."
  - "Tests use Queue::fake()/Queue::assertPushed (matching the existing RunAdOptimisationCommandTest) rather than Bus::fake — RunAdOptimisationJob is a ShouldQueue job, so Queue::fake is the correct interceptor."
metrics:
  duration: ~40m
  completed: 2026-07-11
  tasks: 6
---

# Phase 15 Plan 15b-02: Marketing Dashboard Summary

Read-only Marketing dashboard (Filament page + 3 header widgets) presenting GA4 channel/campaign
performance (15a-02) and the latest ad-optimisation advice (15b-01), plus an admin-only on-demand
"Review with Claude" header action that reuses the existing 15b-01 RunAdOptimisationJob — PURE
PRESENTATION with a hard empty-state, zero new tables/writes/Google calls, deptrac-clean.

## What shipped

- **Page:** `MarketingDashboardPage` — slug `admin/marketing-dashboard`, nav group **Marketing**,
  sort 5 (before the GA4 Channels resource at sort 10). `canAccess()` = authed workspace read
  (GaChannelMetric viewAny). Renders a friendly empty-state callout when zero GA4 rows exist.
- **Widgets (header widgets on the page):**
  - `MarketingOverviewStats` — Sessions / Transactions / Revenue (£) / Top channel by revenue, 30d.
  - `MarketingRevenueTrendChart` — daily revenue (£) line chart, 30d.
  - `LatestMarketingAdviceWidget` — latest 10 pending `ad_optimisation` Suggestions (read-only) with
    a header link deep-linking to the Suggestions inbox filtered to the kind.
- **Action:** `review_with_claude` page header action — admin-gated, requiresConfirmation, no-data
  guard (mirrors `agents:run-ad-optimisation` lookback), dispatches `RunAdOptimisationJob`, shadow-mode
  honesty note when `agents.write_enabled=false`.
- **Wiring:** added `->discoverPages(Domain/Integrations/Filament/Pages)` to `AdminPanelProvider`.

## Commits (per task)

| Task | Description | Commit |
| ---- | ----------- | ------ |
| 2 | MarketingOverviewStats widget + test | `68e092a` |
| 3 | MarketingRevenueTrendChart widget + test | `c088eba` |
| 4 | LatestMarketingAdviceWidget widget + test | `d531814` |
| 1 & 5 | MarketingDashboardPage + blade + panel wiring + test | `3e6e40b` |
| 6 | "Review with Claude" header action + test | `98113ef` |

Note: Tasks 1 (page class) and 5 (blade + wiring) were committed together — the page's render test
depends on both the blade view and the panel discovery pointer, so they are inseparable for a green
commit. All other tasks map 1:1 to a commit. Commit order is widgets → page → action so every commit
is independently green (the page's `getHeaderWidgets()` references the widget classes).

## Verify results

- **pest** — new page + 3 widgets + review action: all GREEN.
  - MarketingOverviewStatsTest: 4 passed
  - MarketingRevenueTrendChartTest: 3 passed
  - LatestMarketingAdviceWidgetTest: 3 passed
  - MarketingDashboardPageTest: 4 passed
  - MarketingReviewWithClaudeActionTest: 5 passed
  - Wider `tests/Feature/Integrations` smoke: 70 passed, 1 skipped (pre-existing skip), 299 assertions.
  - `tests/Feature/Agents/Marketing` (15b-01 regression): 34 passed, 105 assertions.
- **route:list --path=admin** — exit 0; `admin/marketing-dashboard` resolves to
  `filament.admin.pages.marketing-dashboard`.
- **pint** — pass on all touched files (`--test` clean).
- **deptrac analyse** — **0 violations** (0 skipped, 0 warnings, 0 errors).

## Requested confirmations

- **(a) Empty-state tested:** yes —
  - Page: "shows the empty-state callout when there are zero GA4 rows" / "hides … once GA4 rows exist".
  - Widgets: MarketingOverviewStats "renders a friendly empty-state with zero rows (£0.00 / —, no
    divide-by-zero)"; MarketingRevenueTrendChart "returns an empty dataset with zero rows (no error)";
    LatestMarketingAdviceWidget "renders a friendly empty state with zero advice rows".
- **(b) Review-action no-data → no dispatch:** yes — "dispatches NOTHING and warns when there is no
  recent GA4 data" and "does not dispatch when GA4 rows are older than the lookback window"
  (Queue::assertNothingPushed + warning notification).
- **(c) Admin-only authorization:** yes — "hides the action for non-admins" (assertActionHidden);
  the advice widget's `canView` is admin-gated ("is visible only to admins").

## Deviations from Plan

**None functionally.** Two structural notes (not behavioural changes):
1. **[Commit granularity]** Tasks 1 and 5 committed as one commit (`3e6e40b`) — the page render test
   requires the blade view and panel discovery together; they cannot form separate green commits.
2. **[Rule 3 — blocking issue]** During Task 1, the page docblock contained the literal string
   `app/Domain/*/Filament/*`, whose `*/` closed the PHP docblock early → parse error. Reworded to
   "the domain Filament subdir" before the first green run. Caught pre-commit by `php -l`.

## CLAUDE.md compliance

Filament 3.x, no v4/Tailwind 4, Chart.js built-in widget (no extra chart package), driver-portable
SQL, money as pennies. No new dependencies. WooCommerce/Bitrix untouched.

## Guardrails honoured

No new tables/migrations, no data pull, no writes, no Google calls, no changes to the 15b-01
agent/tools/mapper/job. Review action reuses the existing `RunAdOptimisationJob` only. Pre-existing
working-tree noise (`storage/app/research/supplier-probe.json`, the Competitor freshness test, untracked
`.claude/`) was NOT staged. No push, no deploy.

## Self-Check: PASSED

All 6 created files exist on disk; all 5 per-task commits present in git history.
