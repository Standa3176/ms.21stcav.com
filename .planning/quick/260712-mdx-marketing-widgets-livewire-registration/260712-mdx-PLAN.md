# 260712-mdx — HOTFIX: Marketing dashboard 500 (widgets not registered as Livewire components)

**Type:** GSD quick task (TDD, atomic commit). Executor does NOT push/deploy.
**Severity:** prod 500 on `admin/marketing-dashboard` (deployed @ bc3493b). Isolated to that new page.

## Root cause (confirmed from prod log)
`Livewire\Exceptions\ComponentNotFoundException: Unable to find component:
[app.domain.integrations.filament.widgets.marketing-revenue-trend-chart]` — thrown on the chart
widget's follow-up Livewire AJAX request (charts lazy-load their data). The three Marketing widgets
live in `App\Domain\Integrations\Filament\Widgets`, but `AdminPanelProvider` has **no
`->discoverWidgets`** pointer for that path — see the comment at ~line 197 ("need no ->discoverWidgets
pointer — that would also leak them onto the main dashboard"). That reasoning is WRONG for this app:
`HomeDashboardPage extends Dashboard` with an EXPLICIT `getWidgets()` that is the sole source of widget
composition, so discovered widgets do NOT auto-appear there (proven by the Competitor domain, which IS
discovered at ~line 215 and does not leak). Without discovery the widgets are never registered as
Livewire components → the page's first render works but the chart's update request 500s.

Tests missed it because `Livewire::test()` / Filament page tests register the component directly and
never exercise the cross-request component-registry lookup that fails in prod.

## Fix (TDD — write the regression test FIRST, watch it fail, then fix)

### Task 1 — Regression test that reproduces the prod failure
New test asserting the three Marketing widgets are resolvable Livewire components via the panel's
registry — the exact mechanism that threw `ComponentNotFoundException`:
- For each of `MarketingOverviewStats`, `MarketingRevenueTrendChart`, `LatestMarketingAdviceWidget`:
  resolve its Livewire component NAME then resolve that name back to the class via
  `app(\Livewire\Mechanisms\ComponentRegistry::class)` — `getName($class)` then `getClass($name)`
  round-trips to the class (getClass throws ComponentNotFoundException when unregistered). Boot the
  admin panel first (mirror how existing panel/widget tests boot Filament — e.g. the 15b-02 page test
  or an existing AdminPanel test). This MUST fail on the current code (unregistered) and pass after.
- Belt-and-braces (guard the "leak" worry): assert `HomeDashboardPage::getWidgets()` does NOT contain
  any of the three Marketing widgets (they must stay OFF the home dashboard).

### Task 2 — Register the Integrations widgets for discovery
In `app/Providers/Filament/AdminPanelProvider.php`, in the Integrations discovery block (~L194-198),
add — mirroring the Competitor pattern at ~L215:
```php
->discoverWidgets(in: app_path('Domain/Integrations/Filament/Widgets'), for: 'App\\Domain\\Integrations\\Filament\\Widgets')
```
and correct the now-inaccurate comment (the leak concern is handled by HomeDashboardPage's explicit
getWidgets(); discovery only registers the Livewire components — it does not add them to the home
dashboard).

## Verify (against the REAL failure mode, not just SQLite render)
- The new regression test: RED before the fix (`ComponentNotFoundException` / name-not-resolvable),
  GREEN after.
- `pest` on the 15b-02 dashboard/widget tests + the new test — GREEN, no regression.
- `php artisan route:list --path=admin` exit 0.
- Confirm no home-dashboard leak (the belt-and-braces assertion above).
- `pint` pass; `vendor/bin/deptrac analyse` → 0 violations (no new namespaces, just a discovery pointer).

## Guardrails / out of scope
- One-line panel change + regression test. Do NOT touch the widgets/page/agent logic themselves — the
  bug is purely registration.
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP/composer via Herd (~/.config/herd/bin/php84/php.exe). No push, no deploy. Atomic commit:
  `fix(260712-mdx): register Integrations Marketing widgets for Livewire (fixes dashboard 500)`.
  Write `260712-mdx-SUMMARY.md` (root cause, the RED→GREEN regression, verify results, note that
  redeploy needs config/route cache rebuild — deploy.sh handles it; NO composer/migrate this time).
