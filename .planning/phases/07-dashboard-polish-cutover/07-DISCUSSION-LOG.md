# Phase 7: Dashboard Polish + Cutover - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-24
**Phase:** 07-dashboard-polish-cutover
**Mode:** `--auto` (recommended defaults auto-selected without interactive prompting)
**Areas discussed:** Dashboard home widgets, global search + CSV exports + saved filters, weekly digest + notification centre, cutover sequencing + rollback drill + legacy plugin disable, Phase 6 carry-forward integration

---

## Dashboard home tile set (auto-selected)

| Option | Description | Selected |
|--------|-------------|----------|
| 9-widget grid (3×3) covering freshness / actions / health (Recommended) | LastSyncRunWidget + CrmPushSuccessRate + CompetitorFreshness + PendingReviews + ImportIssues + HorizonFailedJobs + SyncDiffsParity + ProductCatalogueHealth + WeeklyReportStatus | ✓ |
| 4-widget minimalist | Single-glance health only; loses drill-down signal | |
| 15+ widget rich overview | UX noise, slower load, ops don't read more than 9 things | |

**Auto-choice:** 9 widgets in 3 rows (freshness / actions / health).
**Rationale:** Pre-aggregated via `dashboard:refresh` every 5 min into `dashboard_snapshots` table; <500ms page load; sparklines deferred.

---

## Global search scope (auto-selected)

| Option | Description | Selected |
|--------|-------------|----------|
| 6 Resources indexed, Filament built-in, RBAC-filtered (Recommended) | Product / PricingRule / CrmPushLog / Suggestion / CompetitorPrice / AutoCreateReview | ✓ |
| All 20+ Resources indexed | Search noise; too many low-value hits | |
| Only Product + Suggestion | Too narrow; ops use global search to jump to any entity | |

**Auto-choice:** 6-Resource scope via `getGloballySearchableAttributes()`.

---

## CSV export + saved filters (auto-selected)

| Option | Description | Selected |
|--------|-------------|----------|
| Stream-to-browser default + queue-to-email for >10k rows (Recommended) | spatie/simple-excel backend; filename convention; 100k hard cap | ✓ |
| Always queue-to-email | Slower UX for 99% of day-to-day exports | |
| No export | Ops live in Excel; exports are table stakes | |

**Auto-choice:** Stream default + queue-on-large. Per-user saved filters via lightweight `user_saved_filters` table (cross-user sharing deferred).

---

## Weekly digest recipients + content (auto-selected)

| Option | Description | Selected |
|--------|-------------|----------|
| Monday 07:00 Europe/London email with 5-section summary (Recommended) | Sync / Margin / CRM / Auto-Create / Competitor sections; HTML + text fallback; new `receives_weekly_digest` AlertRecipient toggle | ✓ |
| Real-time dashboard only, no email | Ops already live in email; digest catches people who don't log in daily | |
| Daily digest | Noise; weekly cadence matches ops weekly cycle | |

**Auto-choice:** Monday 07:00 GMT with `receives_weekly_digest` opt-in toggle.

---

## Notification centre shape (auto-selected)

| Option | Description | Selected |
|--------|-------------|----------|
| Unified Filament Page with 4 tabs over existing tables (Recommended) | Failed jobs / Stale feeds / Pending suggestions / Webhook DLQ — no new table, aggregates from 4 existing datasources with per-tab quick actions | ✓ |
| New `notifications` table with push-to-Slack | Over-engineered for v1; Livewire polling is sufficient | |
| Per-phase inbox in each Resource | Already exists; user needs single view | |

**Auto-choice:** Unified Page, polling-based, quick actions per tab.

---

## Shadow-mode divergence scan (CUT-01) (auto-selected)

| Option | Description | Selected |
|--------|-------------|----------|
| Artisan scan, sync_diffs provider='divergence-scan', 99% threshold, 7d window (Recommended) | `cutover:divergence-scan` scheduled daily at 01:00 London during parallel-run window | ✓ |
| Real-time on-every-write comparison | Scales badly; adds latency to Phase 2 sync writes | |
| Nightly mass-compare all Woo state | Too crude; scan-per-Product is more actionable | |

**Auto-choice:** Scheduled artisan scan with configurable threshold + window.

---

## Pre-cutover ProductOverride auto-population (CUT-02) (auto-selected)

| Option | Description | Selected |
|--------|-------------|----------|
| One-shot command, merge-semantics never-clear-pins (Recommended) | `cutover:populate-overrides`, dry-run default, --live opt-in | ✓ |
| Auto-populate continuously during parallel-run | Risk of pin-toggle wars if ops edit Woo mid-scan | |
| Manual per-product review | Too slow for thousands of SKUs | |

**Auto-choice:** One-shot command with merge semantics + audit log.

---

## Rollback drill + legacy plugin disable (auto-selected)

### Sub-Q1: Drill execution path

| Option | Description | Selected |
|--------|-------------|----------|
| Artisan drill against staging with `CUTOVER_DRILL_ALLOWED=true` env gate (Recommended) | Dry-run default + env-gated --live, drill report to storage/app/cutover/ | ✓ |
| Manual playbook only | No automation; ops error risk | |
| Live-prod drill with rollback | Too risky without a staging clone | |

**Auto-choice:** Artisan drill with env gate + staging-only --live.

### Sub-Q2: Legacy plugin disable mechanism

| Option | Description | Selected |
|--------|-------------|----------|
| WP-CLI over REST + fallback to documented ops one-liner (Recommended) | `cutover:disable-legacy-plugins` dry-run default; if WP-CLI-over-REST unreliable, docs team runs a WP-CLI one-liner | ✓ |
| Direct WP DB write | Deptrac WpDirectDb layer test FORBIDS this — would fail CI | |
| WordPress admin manual click | No audit trail | |

**Auto-choice:** WP-CLI-preferred + docs-fallback. Honours the Phase 2 WP-DB-write ban.

---

## Pre-cutover operator checklist (D-20 — Phase 6 carry-forward integration)

| Option | Description | Selected |
|--------|-------------|----------|
| Blocking gates via `cutover:checklist` artisan command (Recommended) | Integrates Phase 6 D-20 items (supplier probe, Woo sandbox, Feature suite) as hard gates with PASS/PENDING/FAIL states | ✓ |
| Informational docs only | Risk of ops skipping the gates in a rush | |
| Separate readiness command per gate | Fragmented UX — ops wants one go/no-go signal | |

**Auto-choice:** Single `cutover:checklist` command with structured states + optional `--update-status` sub-command for ops to tick off items.

---

## Claude's Discretion (defaults documented in CONTEXT.md)

- Ops handover docs format: single markdown runbook at `docs/ops/cutover-handover.md` (4 CUT-06 sections + appendices including D-19 cutover sequence)
- Dashboard widget polling: `wire:poll.60s` Livewire default
- Global search debounce: 300ms Filament default
- CSV export row cap: 100k hard cap, artisan command path for larger exports
- `dashboard_snapshots` retention: 30 days rolling prune (Phase 1 pattern)
- Weekly digest opt-out: `AlertRecipient.receives_weekly_digest=false`
- Parity threshold: 99% default, configurable via `config/cutover.php`
- Parallel-run window: 7 days default, configurable
- WP-CLI over REST feasibility investigation deferred to Plan 1 task
- Backup strategy: `mysqldump + gzip + upload` via `cutover:snapshot-woo-db`
- Real-time WebSocket push: deferred (Livewire polling sufficient)
- `AlertRecipient.receives_weekly_digest` column extension — matches Phase 2/4/5/6 `receives_*` pattern

## Deferred Ideas

- Cross-user saved filter sharing (v1 = per-user private)
- WebSocket push notifications (Livewire polling v1)
- Sparkline trend widgets (snapshots table keeps 30d history; sparklines v1.x)
- Per-user saved dashboard views
- True mobile-first redesign (Filament 3 mobile OK; no custom polish)
- Admin impersonation (post-cutover enhancement)
- BI / pivot-table builder (anti-feature)
- Per-brand dashboard views
- Slack notification channel (email-only v1)
- Automated rollback trigger (manual operator v1)
- React/Vue SPA dashboard (anti-feature)
- Multi-store support (Out-of-scope forever)
- Customer-facing portal (Out-of-scope forever)
- audit_log full-text search
- Horizon custom skin (link-out is sufficient)
- Per-brand weekly digest sections

## Phase 6 Carry-Forward Items (now Phase 7 D-20 blocking gates)

- **Gate 1:** Supplier API Q1 re-probe with live 21stcav.com credentials — `php artisan supplier:probe-single-sku <live-sku>`
- **Gate 2:** Woo sandbox Q5 re-validation — manual POST `/wp-json/wc/v3/products` against live Woo sandbox (HARD gate for `config('product_auto_create.mode')` → `immediate_publish`)
- **Gate 3:** Feature-tier full-suite run — `vendor/bin/pest` against `meetingstore_ops_testing` MySQL online; ~150+ Phase 6 deferred tests execute; any failures triaged + fixed before cutover

All three wired into `cutover:checklist` artisan command (D-21).
