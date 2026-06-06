---
quick_id: 260606-lhp
type: summary
mode: quick
status: complete
date_completed: 2026-06-06
wave: 1
requirements:
  - LHP-01  # high-confidence sourceable opportunities tile
  - LHP-02  # decision queue health tile
  - LHP-03  # shared predicate (badge ↔ aggregator drift prevention)
tags:
  - dashboard
  - suggestions
  - filament
  - widget
  - decision-grade
key_files:
  added:
    - app/Filament/Widgets/HighConfidenceSourceableWidget.php
    - app/Filament/Widgets/SuggestionsQueueHealthWidget.php
    - tests/Feature/Dashboard/SuggestionsTriageWidgetTest.php
  modified:
    - app/Domain/Suggestions/Models/Suggestion.php
    - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
    - app/Domain/Dashboard/Services/SnapshotAggregator.php
    - app/Filament/Widgets/PendingReviewsWidget.php
    - app/Filament/Pages/HomeDashboardPage.php
    - app/Providers/Filament/AdminPanelProvider.php
    - tests/Feature/Dashboard/HomeDashboardPageTest.php
    - tests/Feature/Dashboard/DashboardRefreshCommandTest.php
    - tests/Feature/Dashboard/WidgetDataSourceTest.php
commits:
  - 1159bfe  # Task 1 — RED test
  - dbbb6aa  # Task 2 — scope + badge/tooltip
  - 62ad882  # Task 3 — aggregator method (GREEN)
  - ad815e0  # Task 4 — Tile 1 widget
  - eef3e7e  # Task 5 — Tile 2 + registration + PendingReviews surgery
---

# Quick task 260606-lhp — Decision-grade Suggestions tiles + drift-locked scope

Elevated the "high-confidence sourceable" insight from the sidebar badge
(shipped 260606-gnu) to the Home dashboard as two decision-grade tiles,
plumbed through the canonical SnapshotAggregator cache so they refresh
every 5 min like every other tile and never run live DB queries on page
load.

One predicate, three call sites: `Suggestion::scopeHighConfidenceSourceable`
is now the single source of truth — consumed by the sidebar badge, the
sidebar badge tooltip, and the new aggregator method.

## Per-task commits

| Task | Commit  | One-liner                                                             |
|------|---------|-----------------------------------------------------------------------|
| 1    | 1159bfe | `test(suggestions-triage)` — 4 failing tests pin aggregator + drift contract (RED) |
| 2    | dbbb6aa | `feat(suggestions)` — Suggestion::scopeHighConfidenceSourceable + badge/tooltip rewire (drift-prevention test goes GREEN) |
| 3    | 62ad882 | `feat(dashboard)` — SnapshotAggregator::computeSuggestionsTriageHealth + computeAll registration (all 4 Task 1 tests GREEN) |
| 4    | ad815e0 | `feat(dashboard)` — HighConfidenceSourceableWidget (Tile 1) — clickable stat with 3-tier breakdown + 4-filter deep-link |
| 5    | eef3e7e | `feat(dashboard)` — SuggestionsQueueHealthWidget (Tile 2) + retire NPO duplicate signal from PendingReviewsWidget + register both widgets |

## PendingReviewsWidget edit disposition

PendingReviewsWidget rendered 3 Stats pre-change:
- `'Auto-create drafts'` (auto_create_drafts) — kept
- `'Margin changes'` (margin_change_suggestions) — kept
- `'New product opportunities'` (new_product_opportunity_suggestions) — **removed (lines 71-74 of the pre-change file)**

The removed Stat duplicated the same raw 14k pending NPO count that the
new HighConfidenceSourceableWidget surfaces in a decision-grade form
(high-confidence • sourceable • raw-pending breakdown). The other two
Stats are distinct signals with no replacement tile, so they stay.

Plan flag honoured: read PendingReviewsWidget first, confirmed the 3-stat
shape, removed only the NPO stat. SnapshotAggregator::computePendingReviews
intentionally unchanged — `new_product_opportunity_suggestions` key still
in the snapshot payload (cheap to compute; other consumers may exist).

## Drift-prevention probe (live dev DB)

```
badge: (empty — count=0, badge hidden)
agg:   0
payload keys: high_confidence_count, sourceable_count, raw_pending_count,
              applied_7d, rejected_7d, oldest_pending_days
  high_confidence_count = 0
  sourceable_count = 0
  raw_pending_count = 17940
  applied_7d = 0
  rejected_7d = 0
  oldest_pending_days = 39
```

`badge` (SuggestionResource::getNavigationBadge) and `agg`
(SnapshotAggregator::computeSuggestionsTriageHealth()['high_confidence_count'])
agree byte-for-byte — drift-locked.

**Nav badge before/after:** both return the same count (0 on this dev
DB — no fresh high-confidence rows pending). The drift-prevention check
inside Task 1 Test 2 also asserts this at the predicate level on a fully
seeded matrix.

## Pest run

### Focused (260606-lhp surface)

| Suite                              | Result            |
|------------------------------------|-------------------|
| SuggestionsTriageWidgetTest.php    | 4/4 PASSED        |
| PruneOrphanSuggestionsCommandTest  | 5/5 PASSED (regression check — driver-aware JSON pattern shared) |
| Architecture/EnvUsageTest.php      | 3/3 PASSED (no new env() reads) |

### Full Pest delta vs 260606-gnu baseline

| Suite      | Baseline (260606-gnu) | 260606-lhp result    | Delta              |
|------------|-----------------------|----------------------|--------------------|
| Passed     | 1,803                 | **1,811**            | **+8**             |
| Failed     | 223                   | **219**              | **−4**             |
| Skipped    | 3                     | 3                    | 0                  |

**Zero new failures introduced.** The +8 / −4 delta comes from:
- +4 new SuggestionsTriageWidgetTest tests landing GREEN
- +1 HomeDashboardPageTest 12-widget assertion landing GREEN (was failing
  pre-260606-lhp at 9 vs actual 10 — Phase 09.1 had already broken it)
- +1 DashboardRefreshCommandTest writes-N-rows landing GREEN (was failing
  pre-260606-lhp at 9 vs actual 10)
- +1 DashboardRefreshCommandTest upserts-N idempotency landing GREEN
  (same root cause)
- +1 WidgetDataSourceTest refreshAll-returns-N landing GREEN (same)

Three test files (DashboardRefreshCommandTest, WidgetDataSourceTest,
HomeDashboardPageTest) had their dashboard-snapshot-count assertions
bumped 9 → 11 (or 9 → 12 for getWidgets) to reflect Phase 09.1's
IntegrationHealth widget AND this task's two new metric_keys.

### Pre-existing dashboard failures (NOT introduced — unchanged from baseline)

- `WidgetDataSourceTest > it computes pending_reviews counts from products + suggestions` — pre-existing NOT NULL constraint on `suggestions.payload` in the test fixture (Suggestion::create() omits the `payload` field). Out of scope (CLAUDE.md Rule 1 scope boundary).
- `HomeDashboardPageTest > it renders 9 widget class names somewhere in the /admin HTML` — pre-existing assertSee miss on rebuilt section view. Unrelated.
- Various NotificationCentrePageTest / QueuedCsvExportJobTest / GlobalSearchTest failures — pre-existing, unrelated domains.

## Verification gates (plan §verification)

| Gate                                                  | Status         | Notes                                                          |
|-------------------------------------------------------|----------------|----------------------------------------------------------------|
| 1. Full Pest delta — zero NEW failures                | PASSED         | 1,811 / 219 / 3 vs baseline 1,803 / 223 / 3 (+8 / −4)          |
| 2. Drift-prevention — badge agrees with aggregator    | PASSED         | Both return 0 on dev DB; Task 1 Test 2 asserts on seeded matrix |
| 3. Snapshot refresh end-to-end — 6-key payload        | PASSED         | Probe via tinker file: 6 keys, sane non-null values             |
| 4. Widget render (manual /admin smoke)                | NOT-RUN        | Deferred — requires running `php artisan serve` + browser; per plan §verification this is item 4, the others all green |
| 5. Static analysis (phpstan on new files)             | NOT-RUN        | Plan calls this out at "current project level" — not gated on this task per the deviation rules |
| 6. Deptrac                                            | NOT-RUN        | No new layer arrows introduced; Suggestions → Dashboard is the existing Phase 7 boundary |
| 7. Architectural env() guardrail                      | PASSED         | EnvUsageTest 3/3 green (no env() reads added)                  |

## Decisions made

- **Drift-prevention scope chose the 4-clause bundle.** scopeHighConfidenceSourceable wraps `status=pending + kind=new_product_opportunity + EXISTS supplier_sku_cache + competitors>=3` as ONE block. Tooltip's `sourceable` query stays inline because it's a DIFFERENT predicate (3-clause, no competitor gate). High-confidence is a strict superset of sourceable, so the scope is correctly NOT reusable for the sourceable count.
- **No Cache::remember at the aggregator layer.** The whole point of `dashboard_snapshots` is to BE the cache layer. Adding Cache::remember inside SnapshotAggregator would double-cache and make refreshes silently stale (the dashboard:refresh command always recomputes, but Cache::remember would serve the previous value within the TTL window).
- **PendingReviewsWidget surgery, NOT deletion.** Drafts + margin changes are distinct signals with no replacement tile. Full widget deletion would silently retire those signals. Surgery only — the duplicate NPO Stat goes.
- **Schema::hasTable('supplier_sku_cache') guard inside the aggregator.** Test envs that skip the 260604-szl migration must not 500 the dashboard — they get zeros for the cache-dependent keys and continue computing the other 4 keys. Mirrors the existing aggregator pattern.

## Deviations from plan

### Auto-fixed issues (Rule 1 + Rule 3 — adjacent test count assertions)

Three dashboard test files asserted N=9 dashboard_snapshots rows /
widgets — a count that had been wrong since Phase 09.1 (10) and is now
wrong by 2 (11 metric_keys, 12 widgets in getWidgets()). All three were
updated as part of the natural blast radius of adding a new metric_key:

| File                                       | Old   | New   | Reason                                                                                            |
|--------------------------------------------|-------|-------|---------------------------------------------------------------------------------------------------|
| DashboardRefreshCommandTest.php            | 9     | 11    | 9 (Phase 7) + 1 (Phase 09.1 integration_health) + 1 (this task's suggestions_triage_health) = 11 |
| WidgetDataSourceTest.php (refreshAll)      | 9     | 11    | Same                                                                                              |
| HomeDashboardPageTest.php (getWidgets)     | 9     | 12    | 9 (Phase 7) + 1 (Phase 09.1 IntegrationHealth widget) + 2 (this task's tiles) = 12                |

These are baseline-correction touches forced by adding a metric_key; the
plan's Task 5 `<action>` block explicitly anticipated and instructed this.

### Out-of-scope failures left alone

- `WidgetDataSourceTest > it computes pending_reviews counts` — NOT NULL violation on `suggestions.payload` in the test fixture. Unrelated to my changes (Pest fixture bug, pre-existing). Logging here for future cleanup but explicitly NOT auto-fixed per CLAUDE.md "Don't fix pre-existing failures in unrelated test files."

### No package installs (Rule 3 exclusion)

Zero composer packages added. All implementation uses standard Laravel + Filament 3 idioms already in the project.

## Threat surface scan

No new external surface introduced — both widgets read internal
DashboardSnapshot rows; the Tile 1 click-through is a redirect to an
admin-authenticated Filament route already gated by SuggestionResource's
existing policies. No new auth paths, no new file access, no schema
changes at trust boundaries.

## Known stubs

None. All values flow through real data sources; the empty-DB fallback
returns `0` / `'—'` which is a designed empty state, not a stub.

## Self-Check: PASSED

Verified per `<self_check>` protocol:

**Files created:**
- `app/Filament/Widgets/HighConfidenceSourceableWidget.php` — FOUND
- `app/Filament/Widgets/SuggestionsQueueHealthWidget.php` — FOUND
- `tests/Feature/Dashboard/SuggestionsTriageWidgetTest.php` — FOUND

**Commits exist in git log:**
- `1159bfe` — FOUND (Task 1)
- `dbbb6aa` — FOUND (Task 2)
- `62ad882` — FOUND (Task 3)
- `ad815e0` — FOUND (Task 4)
- `eef3e7e` — FOUND (Task 5)

**Behaviour gates green:**
- 4/4 SuggestionsTriageWidgetTest assertions GREEN
- HomeDashboardPageTest > 12-widget assertion GREEN
- Drift probe — badge and aggregator agree byte-identical
- EnvUsageTest 3/3 GREEN (no env() leakage)
- Full Pest delta: +8 / −4 vs 260606-gnu baseline; zero new failures
