# Quick Task 260525-pnk — Summary

**Description:** Pricing Operations dashboard + PHP 8.4 test-infra fixes (operator asked for a single screen showing price changes / new SKUs / competitor-below-floor / competitor-below-cost).
**Date:** 2026-05-25
**Status:** ✅ Shipped + pushed.

## Commits
- `4cc1b1e` — fix(agents): remove `public $queue` trait collision (PHP 8.4) + test-infra
- `24f03aa` — feat(pricing): Pricing Operations dashboard (single-pane core-loop view)

## Pricing Operations dashboard (`24f03aa`)
New page **`/admin/pricing-operations`** (Operations nav group), RBAC = CompetitorPrice viewAny (admin/pricing_manager/sales). Four panels:
1. **Recent sell-price changes** — day-over-day moves from `product_price_snapshots` (LAG/ROW_NUMBER window query; old→new→date).
2. **New SKUs awaiting review** — auto-drafted products (incl. weekly competitor-only drafts) pending publish; links to the review inbox.
3. **Competitor at/below our 6% floor** — winnable but margin < floor (we hold at the floor); worst margins first.
4. **Competitor below our cost** — the unwinnable / supplier-renegotiation list.

`CompetitorPositionScanner` (Domain/Pricing/Services) reuses the **exact ex-VAT margin math** as `pricing:floor-report` (`below_cost` = margin ≤ 0; `at_floor` = 0 < margin < floor_bps), via one window-function pass (latest row per competitor, lowest across competitors, sku↔mpn match) so the numbers agree with the report + the undercut command. Panels 3-4 cached 15 min; a header "Recompute" action busts the cache. Summary strip shows matched / winnable / at-floor / below-cost counts + "computed X ago".

**Tests (7 green):** scanner bucketing incl. mpn-match, latest-per-competitor + lowest-across, stale-window exclusion; page render with data, empty catalogue, and read_only denied. Verified the window SQL runs on SQLite (tests) — same syntax works on MySQL 8 (prod).

## PHP 8.4 test-infra fixes (`4cc1b1e`)
While trying to run the suite for cutover Gate 3, found + fixed:
- **Real latent bug:** `AgentAlertNotification` + `RunAgentJob` declared `public string $queue`, which collides with the `Queueable` trait's untyped `$queue` under **PHP 8.4** (fatal). Dormant on prod's PHP 8.3 but would break any 8.4 upgrade, and it blocked the local suite from booting. Now set via `onQueue()` in the constructor (the guard the other jobs already use).
- Added **`pestphp/pest-plugin-livewire`** (dev) — it was missing entirely, so 30+ Filament `livewire()` tests literally could not run.
- Aligned 2 nav-assertion tests with the 2026-05-25 nav simplification (StockUpdater Admin→Settings; Quote→Catalogue).

## ⚠️ Test-suite remediation = separate milestone (NOT a cutover blocker)
Running the suite surfaced **~165 pre-existing failures + 1 hanging test**, spanning domains untouched this session (Agents/GDPR, CRM/Bitrix, Quotes, Dashboard, ProductAutoCreate, TradePricing, pricing golden-fixtures). Sampled root causes are **test-infra rot, not production bugs**: fixtures not seeding FK deps (e.g. `customer_groups`), Filament action-visibility drift (`callTableAction` on a correctly-hidden action now throws), and MySQL-vs-SQLite skip-guards — all compounded by local **PHP 8.4 vs prod PHP 8.3**. The suite had clearly not been runnable for a long time (the missing livewire plugin proves it).

**Decision (operator):** re-scope — don't block cutover on this. Greening the full suite is its own milestone, ideally run on **PHP 8.3 in CI**, burned down domain-by-domain. Evidence of production health for **Gate 3** instead: app boots, all 81 admin routes resolve, prod runs 8.3, and critical-path/changed suites are green (Pricing 107/107, Sync 23/23, Products 20/20, Suggestions 16/16, PublishProductJob 5/5, + the 7 new dashboard tests).

## Deploy
`sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (prod installs `--no-dev`, so the new dev plugin is not installed there — fine). View at `/admin/pricing-operations`.
