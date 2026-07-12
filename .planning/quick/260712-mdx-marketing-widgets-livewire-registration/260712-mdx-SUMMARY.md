# 260712-mdx — HOTFIX Summary: Marketing dashboard 500 (widgets not registered as Livewire components)

**Status:** DONE — committed, NOT pushed, NOT deployed (executor scope).
**Commit:** `a40cebe` — `fix(260712-mdx): register Integrations Marketing widgets for Livewire (fixes dashboard 500)`
**One-liner:** Added the missing `->discoverWidgets` pointer for `App\Domain\Integrations\Filament\Widgets` so the three Marketing widgets register as Livewire components — the chart widget's lazy-load AJAX no longer throws `ComponentNotFoundException` and 500s `admin/marketing-dashboard`.

## Root cause (confirmed)

`admin/marketing-dashboard` renders fine on first paint, but the chart widget lazy-loads its
data on a follow-up Livewire AJAX request that resolves the widget by its component NAME through
`Livewire\Mechanisms\ComponentRegistry`. `AdminPanelProvider` had a `->discoverPages` pointer for
`App\Domain\Integrations\Filament\Pages` but NO `->discoverWidgets` pointer for the sibling
`.../Widgets` directory. Filament registers discovered widgets as Livewire components in
`Panel::register()` → `registerLivewireComponents()`; without discovery the three widgets were
never registered, so `ComponentRegistry::getClass($name)` threw:

```
Livewire\Exceptions\ComponentNotFoundException: Unable to find component:
[app.domain.integrations.filament.widgets.marketing-revenue-trend-chart]
```

The old code comment ("need no `->discoverWidgets` pointer — that would also leak them onto the
main dashboard") was wrong for this app: `HomeDashboardPage extends Dashboard` with an explicit
`getWidgets()` that is the sole source of home-dashboard composition, so discovered widgets do NOT
auto-appear there (proven by the Competitor domain, which IS discovered and does not leak).

Existing Filament page tests (`Livewire::test()`) register the component directly and never
exercise the cross-request registry lookup, which is why they stayed green while prod 500'd.

## The fix (TDD, one atomic commit)

1. **RED regression test** — `tests/Feature/Integrations/MarketingWidgetsLivewireRegistrationTest.php`.
   Builds + registers the real admin panel (`(new AdminPanelProvider(app()))->panel(Panel::make())->register()`
   — mirrors the `ShieldInstallationTest` panel-build pattern and the actual boot mechanism), then for
   each of `MarketingOverviewStats`, `MarketingRevenueTrendChart`, `LatestMarketingAdviceWidget`
   round-trips `ComponentRegistry::getName($class)` → `getClass($name)` and asserts it returns the class.
   Plus a belt-and-braces assertion that `HomeDashboardPage::getWidgets()` contains none of the three
   (no home-dashboard leak).
2. **Fix** — added to `app/Providers/Filament/AdminPanelProvider.php` (Integrations discovery block):
   ```php
   ->discoverWidgets(in: app_path('Domain/Integrations/Filament/Widgets'), for: 'App\\Domain\\Integrations\\Filament\\Widgets')
   ```
   and corrected the inaccurate "would leak them onto the main dashboard" comment.
3. **GREEN** — confirmed.

## RED → GREEN confirmation

- **RED (before fix):** new test file → 3 failed / 1 passed. All three widget round-trips threw
  `ComponentNotFoundException: Unable to find component: [app.domain.integrations.filament.widgets.*]`
  — the exact prod error. The no-leak assertion passed.
- **GREEN (after fix):** new test file → **4 passed (6 assertions)**.

## Verify results

| Check | Result |
|---|---|
| New regression test | RED (3 failed) → GREEN (4 passed, 6 assertions) |
| `tests/Feature/Integrations/` (incl. 15b-02 dashboard/widget + new) | **74 passed, 1 skipped (pre-existing), 0 failed**, exit 0 |
| `route:list --path=admin` | exit 0; `admin/marketing-dashboard` route resolves |
| No home-dashboard leak | asserted (belt-and-braces test passes) |
| `pint --test` (changed files) | pass |
| `deptrac analyse` | 0 violations, 0 errors, exit 0 |

## Scope / guardrails honoured

- One-line panel change + regression test. Widgets/page/agent logic untouched — bug was purely registration.
- Committed ONLY the two intended files. Did NOT stage the pre-existing working-tree noise
  (`storage/app/research/supplier-probe.json` deletion, `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`,
  untracked `.claude/`).
- PHP/composer via Herd (`~/.config/herd/bin/php84/php.exe`). NOT pushed, NOT deployed.

## Deploy note (for whoever deploys)

Redeploy needs a **config/route cache rebuild** so the panel re-registers with the new discovery
pointer (Filament also caches components via `filament:cache-components` in prod) — `deploy.sh`
handles this. **No composer install and no migration this time** — it's a single provider edit
plus a test.

## Self-Check: PASSED

- Files exist: `app/Providers/Filament/AdminPanelProvider.php`, `tests/Feature/Integrations/MarketingWidgetsLivewireRegistrationTest.php`, this SUMMARY.
- Commit exists: `a40cebe` (fix). No file deletions introduced by the commit.
