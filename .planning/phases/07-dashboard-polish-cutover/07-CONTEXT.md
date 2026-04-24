# Phase 7: Dashboard Polish + Cutover - Context

**Gathered:** 2026-04-24 (auto-mode — recommended options selected without interactive input)
**Status:** Ready for planning
**Phase type:** FINAL — ships the v1 cutover from legacy WordPress plugins to Laravel sole-source-of-truth

<domain>
## Phase Boundary

Phase 7 is the last v1 milestone phase. It has two tightly-coupled halves:

**Half A — Dashboard Polish (DASH-01..06):** Graduate the app from per-phase Filament Resources (6 sub-domains each with their own surfaces) to a unified operator experience. Home dashboard aggregates health tiles from Sync (last run duration + updated/failed counts) + CRM (push failures + stale mappings) + Competitor (stale feeds + pending margin suggestions) + Auto-Create (pending-review count + low-completeness backlog) + Horizon (failed-jobs count). Admin header gains a global-search input (Filament 3 built-in) scoped across Product / PricingRule / CrmPushLog / Suggestion / CompetitorPrice / AutoCreateReview. Every tabular Resource gains saved-filter + CSV-export actions (Filament bulk action). A weekly scheduled report assembles sync counts + margin deltas + CRM success rate into a single email to `AlertRecipient.receives_weekly_digest=true` recipients. A notification centre unifies Horizon failed jobs + stale competitor feeds + pending suggestions + webhook-DLQ entries into one Filament page with filter tabs.

**Half B — Cutover (CUT-01..07):** The live migration from the legacy `itgalaxycompany/woocommerce-bitrix24-integration` + the in-house Stock Updater plugin to Laravel. Shadow-mode monitoring dashboard compares every Woo write Laravel WOULD have made (from `sync_diffs.provider='woo'` + `sync_diffs.provider='bitrix'`) against the live Woo + Bitrix state; parity threshold configurable (default 99%); rolling window configurable (default 7 days). Pre-cutover divergence scan walks every Product field Laravel owns (price, stock, title, description, image, pricing rule application) and where Woo's live value differs from Laravel's computed value, auto-creates a `ProductOverride` row with the corresponding `pin_*=true` so the flip to `WOO_WRITE_ENABLED=true` doesn't steamroll past human edits. A `cutover:drill-rollback` artisan command walks the full rollback playbook (flip flag → restore snapshot → verify legacy cron re-engages) against a staging clone. The Stock Updater and itgalaxy plugins are disabled in WordPress via `wp_unschedule_event` calls (or an ops WP-CLI one-liner) AFTER the parallel-run window closes clean. Ops handover docs cover the 4 documented operator scenarios from CUT-06 plus the 3 Phase 6 carry-forward items (supplier probe re-run with live creds, Woo sandbox image pass-through re-validation, Feature-tier MySQL suite run).

Scope is fixed by ROADMAP.md Phase 7 and REQUIREMENTS.md DASH-01..06 + CUT-01..07. Discussion auto-resolved 8 gray areas + inherits the 3 Phase 6 operator follow-ups as blocking gates.

</domain>

<decisions>
## Implementation Decisions

### Home dashboard tile set (DASH-01, DASH-02)

- **D-01:** **Home dashboard at route `/admin` (Filament default)** replaces the current empty Filament dashboard. Implemented as a custom Filament Page extending `Filament\Pages\Dashboard` with widget composition. Tile count: **9 widgets** organised as 3 rows × 3 columns on desktop, stacked on mobile.
  - Row 1 (freshness — what happened recently): `LastSyncRunWidget` (run_id + duration + updated/failed counts + traffic-light age), `CrmPushSuccessRateWidget` (24h rolling — success/retry/DLQ counts), `CompetitorFreshnessWidget` (traffic-light: fresh/stale/missing per-competitor counts from Phase 5 CompetitorCheckStaleCommand output)
  - Row 2 (actions needed — what ops should look at): `PendingReviewsWidget` (auto-create draft count + orphan-suggestion count + margin-change suggestion count, click to navigate to Suggestions inbox), `ImportIssuesWidget` (csv_parse_errors unresolved + quarantined CSVs + low-completeness auto-create drafts), `HorizonFailedJobsWidget` (Horizon linked + 5-min-window failure count + link-out to `/horizon`)
  - Row 3 (system health — the big picture): `SyncDiffsParityWidget` (shadow-mode divergence % — directly supports CUT-01), `ProductCatalogueHealthWidget` (published vs draft vs pending counts per brand — sortable), `WeeklyReportStatusWidget` (last weekly email sent + recipient count + next run ETA)
- **D-02:** **Widget data sources are pre-aggregated via a scheduled `dashboard:refresh` command** (every 5 minutes). Results land in a `dashboard_snapshots` table (columns: `metric_key`, `metric_value_json`, `computed_at`, TTL 15min). Widgets read from the snapshot (fast + constant DB cost); never run live aggregations on page load. Rationale: home dashboard must load <500ms even if Horizon + CRM logs have 100k+ rows.
- **D-03:** **Horizon link from admin header** via Filament's `->plugin()` for an `HorizonLinkPlugin` (simple URL plugin to `/horizon`) rather than modifying the admin panel provider directly. Scoped to admin role only.

### Global search scope + UX (DASH-03)

- **D-04:** **Filament 3 built-in global search** activated on 6 Resources: `ProductResource` (search by sku, name, brand), `PricingRuleResource` (by name, scope), `CrmPushLogResource` (by correlation_id, woo_order_id, bitrix_deal_id), `SuggestionResource` (by kind + description), `CompetitorPriceResource` (by sku + competitor.name), `AutoCreateReviewResource` (by sku + title). `getGloballySearchableAttributes()` method on each Resource configures the field list; `getGlobalSearchResultTitle` + `getGlobalSearchResultDetails` render the dropdown rows with contextual previews (e.g. CRM log shows `order #{woo_order_id} · deal #{bitrix_deal_id} · {status}`).
- **D-05:** **Search is RBAC-filtered** via each Resource's existing `->authorize()` policies. Sales role sees only `CrmPushLogResource` results; pricing_manager sees Product + PricingRule + CompetitorPrice; read_only sees everything readable. Filament handles the RBAC filter via each Resource's policy `viewAny` check — no custom code required.

### CSV export + saved filters (DASH-04)

- **D-06:** **Every Filament table Resource gains `->exportable()` action via `laravel-excel` OR Filament's built-in export action.** Default: Filament's built-in `Tables\Actions\ExportBulkAction` with `spatie/simple-excel` backend (already installed — Phase 2). Export streams directly to the browser (no queued email for v1). Filename convention: `{resource_slug}_{YYYY-MM-DD}_{correlation_id_suffix}.csv`. Large-export guard: tables with >10k filtered rows prompt "Queue this export to email?" instead of blocking the browser.
- **D-07:** **Saved filters per-user via `filament/spatie-laravel-settings-plugin`** (or a lightweight `user_saved_filters` table: `user_id`, `resource_slug`, `filter_name`, `filter_payload_json`). Admin UI on each Resource with a "Save current filter" button → saves the active filter state; a dropdown beside the filter panel lists saved filters + one-click apply. Cross-user sharing deferred (v1 scope: per-user private).

### Weekly report content + recipients (DASH-05)

- **D-08:** **Weekly scheduled command `reports:weekly-digest` runs every Monday 07:00 Europe/London.** Compiles a single HTML email sent via Laravel Mail to all `AlertRecipient` rows where `receives_weekly_digest=true` (NEW nullable boolean column, default true for existing fallback `ops@meetingstore.co.uk`). Email sections:
  - Sync: `N runs completed / average duration / updated SKUs / failed SKUs / top 5 failing SKUs table`
  - Margin: `N margin-change suggestions created / M approved / largest delta of the week`
  - CRM: `N deals pushed / M retries / K failed → suggestions`
  - Auto-Create: `N drafts created / M approved / K rejected (by reason)`
  - Competitor: `N CSV files ingested / M parse errors / top 3 margin-movers`
- **D-09:** **Email template at `resources/views/emails/weekly-digest.blade.php`** with inline CSS (Mailable-compatible). Plain-text fallback at `resources/views/emails/weekly-digest.text.blade.php`. Subject: `MeetingStore Ops Weekly Digest — {YYYY-MM-DD}`. `Mail::markdown()` NOT used (brittle styling); explicit HTML + text views.

### Notification centre shape (DASH-06)

- **D-10:** **Unified `NotificationCentrePage` at `/admin/notifications`** with 4 tabs aggregating from existing tables (no new `notifications` table — Laravel's built-in `notifications` table is used only if richer routing becomes needed):
  - **Failed jobs tab:** query Horizon's `failed_jobs` table + `ThrottledFailedJobNotifier` dedup log (last 7 days)
  - **Stale feeds tab:** query `competitor_prices` for stale competitors (last 48h+ staleness per Phase 5) + sync_runs for missing daily-sync runs
  - **Pending suggestions tab:** query `suggestions` where status=pending, grouped by kind, with count + oldest-age badge
  - **Webhook DLQ tab:** query `webhook_receipts` where downstream listener failed OR `integration_events` where provider=woo AND direction=inbound AND status=failed (last 7d)
- **D-11:** **Per-tab quick actions:** retry failed job (dispatches Horizon re-queue), re-ingest stale CSV (re-dispatches `IngestCompetitorCsvJob`), approve/reject suggestion (reuses Phase 1 SuggestionResource actions), replay webhook (redelivers via SuggestionReplayAction if applicable). All gated by `->authorize()`; admin + pricing_manager can act on their scope per existing policies.

### Shadow-mode divergence scan algorithm (CUT-01)

- **D-12:** **`cutover:divergence-scan` artisan command (scheduled OR on-demand):** walks every Product in Laravel, computes what Laravel WOULD push (price via PriceCalculator, stock from products.stock_level, title/slug/description from products table), and compares against live Woo state via `WooClient::get('/products?sku={sku}')`. Disagreements land in `sync_diffs` with `provider='divergence-scan'` + `detected_at` timestamp. Parity percentage = `1 - (diverged_count / scanned_count)`. Configurable threshold in `config/cutover.php => 'parity_threshold_percent' => 99`. Dashboard widget `SyncDiffsParityWidget` (D-01) reads the latest scan's percentage with traffic-light colouring.
- **D-13:** **Divergence scan runs daily at 01:00 Europe/London during the parallel-run window.** Also runnable on-demand via `php artisan cutover:divergence-scan --live` (dry-run default per Phase 2 D-04 convention; `--live` opt-in persists results; no flag = emits to stdout only).

### Pre-cutover ProductOverride auto-population (CUT-02)

- **D-14:** **`cutover:populate-overrides` artisan command (admin-run, one-shot):** walks the latest divergence-scan results and for every Product where Woo's live value differs from Laravel's computed value, creates a `ProductOverride` row with the corresponding `pin_*=true` column set (matching Phase 6 D-10's 8 pin columns). Rationale: Woo's live value represents ongoing operator edits we must preserve; the pin prevents Phase 2 sync from steamrolling the admin's work post-cutover. Dry-run default + `--live` opt-in. Outputs a summary: `N products had M field divergences; created K ProductOverride rows`.
- **D-15:** **Conflict resolution when a ProductOverride already exists for a product:** merge semantics. If the existing row has `pin_title=true` and the scan detects divergence on title, leave `pin_title=true` (no-op). If existing row has `pin_title=false` BUT scan finds divergence, update to `pin_title=true` (add pin). Never CLEAR a pin via this command — pins are sacred human intent. Log merge actions to audit_log with actor=`cutover-populate-overrides-command` for audit trail.

### Rollback drill (CUT-05)

- **D-16:** **`cutover:drill-rollback --dry-run` artisan command** walks the rollback playbook in verbose mode WITHOUT executing destructive steps. Steps: (1) verify `WOO_WRITE_ENABLED` env is readable, (2) simulate flag flip (log only), (3) verify DB snapshot backup exists + can be restored (mysqldump + mysql --dry-run), (4) verify WordPress legacy plugin crons can re-engage (CURL check against Woo admin HTML looking for registered cron hooks), (5) emit a drill report to `storage/app/cutover/drill-report-{YYYY-MM-DD}.md`.
- **D-17:** **`cutover:drill-rollback --live` is gated by `CUTOVER_DRILL_ALLOWED=true` env var** — the live drill actually flips + restores against a staging clone. NOT runnable against prod without an admin flipping the env var + confirming. Default: `--live` fails fast with "Drill not allowed in this environment" message. Staging-only safety.

### Legacy plugin deregistration (CUT-03, CUT-07)

- **D-18:** **`cutover:disable-legacy-plugins --dry-run` artisan command** walks the WordPress cron deregistration + plugin-disable sequence via WP-CLI calls tunneled through the existing `WooClient` HTTP path (or documented as ops WP-CLI one-liner if WP-CLI-over-REST proves unreliable). Steps: `wp_unschedule_event('stock_updater_daily_sync')`, `wp_unschedule_event('itgalaxy_bitrix24_send')`, `wp_unschedule_event('itgalaxy_bitrix24_status')`, `wp plugin deactivate woocommerce-bitrix24-integration`, `wp plugin deactivate stock-updater`. Dry-run default emits the exact commands the ops team will run; `--live` requires `CUTOVER_DISABLE_LIVE_ALLOWED=true` env + interactive confirmation.
- **D-19:** **Cron deregistration happens BEFORE `WOO_WRITE_ENABLED=true` flip.** Sequence documented in ops handover (CUT-06): (1) Snapshot Woo DB (CUT-04), (2) Run divergence scan → populate overrides (CUT-02), (3) Run rollback drill on staging (CUT-05), (4) `cutover:disable-legacy-plugins --live`, (5) Flip `WOO_WRITE_ENABLED=true` in prod .env + cache:clear, (6) Monitor for 7 days with divergence scan running daily (CUT-01), (7) If parity ≥ 99% + no rollback needed → legacy plugins stay disabled (CUT-07).

### Pre-cutover operator checklist (blocking gates inheriting from Phase 6)

- **D-20:** **3 Phase-6-carry-forward items are mandatory pre-cutover gates:**
  1. **Supplier API Q1 re-probe:** `php artisan supplier:probe-single-sku <live-sku>` with live 21stcav.com credentials; output validated against Phase 6's synthesized `supplier-probe.json`; differences reviewed + ProductImageFetcher field paths updated if needed. Recorded as a checklist item in `cutover:checklist` artisan command output.
  2. **Woo sandbox Q5 re-validation:** manual POST to `/wp-json/wc/v3/products` with `images[]` payload against a live Woo sandbox (not prod); confirms URL-pass-through behaviour observed in Phase 6 RESEARCH still holds; blocks flipping `config('product_auto_create.mode')` to `immediate_publish`.
  3. **Feature-tier full-suite run:** `vendor/bin/pest` against `meetingstore_ops_testing` MySQL online; ~150+ deferred tests from Phase 6 execute; any MySQL-dependent failures triaged + fixed before cutover.
- **D-21:** **`cutover:checklist` artisan command** lists these 3 gates + 10+ cutover runbook steps as a structured checklist with PASS/PENDING/FAIL states. Each item has a verification command (e.g. item 1 runs the supplier probe; item 2 opens a browser URL; item 3 runs the pest suite). Command prints markdown-formatted report + optional `--update-status {item-id} pass|fail` sub-command for ops to tick off items as they go. Exit code 1 if any item is FAIL or PENDING.

### Claude's Discretion

Areas not separately discussed — planner/researcher may pick the default best-practice approach:

- **Ops handover docs format (CUT-06).** Single markdown runbook at `docs/ops/cutover-handover.md` with 4 top-level sections matching CUT-06 mandate: (1) How to resume a sync (referring to `sync:supplier --resume={run_id}`); (2) How to replay a failed CRM push (referring to SuggestionResource Replay action on kind `crm_push_failed`); (3) How to refresh Bitrix schema (referring to `bitrix:schema:refresh` command); (4) How to interpret the notification centre (referring to /admin/notifications tabs). Plus appendix with: the Phase 7 cutover sequence (D-19), environment-variable inventory, rollback runbook, troubleshooting FAQ, Horizon dashboard primer. Version-committed to the repo.
- **Dashboard widget polling strategy.** Widgets use Livewire's `wire:poll.60s` for auto-refresh on the home page; full page reload NOT required. When a widget's underlying `dashboard_snapshots` row is older than 15 minutes, the widget shows a stale-data amber border + triggers a background refresh.
- **Global search debounce.** Filament 3 default (300ms). Planner may tune based on live UX — too aggressive triggers superfluous queries.
- **CSV export row cap.** Hard cap at 100,000 rows per export (server-protection). Above that, admin sees "Use the artisan command or narrow your filter" message. `exports:competitor-prices --since={date} --until={date}` artisan command covers bulk-export needs via the `sync-bulk` queue.
- **`dashboard_snapshots` retention.** 30 days rolling prune via scheduled `snapshots:prune` (Phase 1 prune pattern). History enables sparkline widgets for trend visualisation later.
- **Weekly digest opt-out.** Recipients can untick `receives_weekly_digest` in the AlertRecipientResource form (already extensible — 4th toggle alongside `receives_sync_reports`/`receives_crm_alerts`/`receives_competitor_alerts`/`receives_auto_create_alerts`).
- **Parity threshold configurability.** Per CONTEXT D-12 the default is 99% but ops can adjust per `config/cutover.php` without redeploy (env-overridable). Phase 7 ships with 99% locked as the go-live threshold.
- **Rolling window for parity.** CONTEXT says 7 days default but configurable. Planner ships with 7 but exposes `config('cutover.parity_window_days')` for later tuning.
- **WP-CLI over REST feasibility.** D-18's cron deregistration may not work via WP-CLI-over-REST if the plugin's cron hooks are set via WP filesystem write access. Planner investigates + falls back to "documented ops WP-CLI one-liner + admin manual run" if CLI-over-REST proves unreliable. Either path satisfies CUT-03; it's an execution-detail choice.
- **Backup strategy (CUT-04).** MySQL native `mysqldump` + `gzip` + upload to an ops-designated backup location (S3 or NAS). `cutover:snapshot-woo-db --label={reason}` artisan command automates the backup + records to `audit_log`. Backup filename convention: `woo-db-backup-{YYYY-MM-DD-HHmmss}-{label}.sql.gz`.
- **Notification-centre real-time push.** Laravel Echo / pusher NOT in scope for v1. Livewire polling (every 60s) is sufficient for ops use. WebSocket push deferred.
- **`AlertRecipient` schema extension.** New column `receives_weekly_digest` (boolean, nullable, default true) added to the existing table. Migration backfills existing `ops@meetingstore.co.uk` fallback to true.

### Folded Todos

None — no pending todos matched Phase 7 scope at discussion time.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 1 Foundation (auditor + alerting + shadow-mode gate + suggestions seam)

- `.planning/phases/01-foundation/01-CONTEXT.md` — 17 decisions; D-08 `WOO_WRITE_ENABLED` shadow-mode flag (Phase 7 is the phase that FLIPS it); D-12 AlertRecipient pattern (extended with `receives_weekly_digest` per D-08 above)
- `.planning/phases/01-foundation/01-04-SUMMARY.md` — sync_diffs table (provider column added by Phase 4/5); webhook_receipts table (notification centre queries); SuggestionApplier contract
- `.planning/phases/01-foundation/01-05-SUMMARY.md` — Horizon supervisors (dashboard links to Horizon); ThrottledFailedJobNotifier dedup log (notification centre queries)

### Phase 2 Supplier Sync (parallel-run baseline — legacy plugin is replaced here)

- `.planning/phases/02-supplier-sync/02-CONTEXT.md` — D-04 dry-run CLI pattern (reused by all Phase 7 cutover commands); D-08 AlertRecipient.receives_* boolean pattern
- `.planning/phases/02-supplier-sync/02-03-SUMMARY.md` — SyncChunkJob produces sync_diffs entries; Phase 7 divergence scan adds a separate provider='divergence-scan' rowset
- `.planning/phases/02-supplier-sync/02-05-SUMMARY.md` — Deptrac WpDirectDb guardrail; Phase 7's cutover commands MUST NOT write directly to WordPress DB — WP-CLI over REST or documented ops one-liners only

### Phase 3 Pricing Engine (divergence scan comparison inputs)

- `.planning/phases/03-pricing-engine/03-CONTEXT.md` — D-05 PriceCalculator::stripVat + integer pennies math (divergence scan uses this when comparing prices against Woo)
- `.planning/phases/03-pricing-engine/03-02-SUMMARY.md` — RuleResolver and ProductPriceChanged event (dashboard Margin section metrics)

### Phase 4 Bitrix24 CRM Sync (CRM push success metrics + Bitrix schema refresh for handover)

- `.planning/phases/04-bitrix24-crm-sync/04-CONTEXT.md` — D-11 retry semantics (CrmPushSuccessRateWidget metrics source); D-12 CrmPushRetryApplier (Notification Centre retry action)
- `.planning/phases/04-bitrix24-crm-sync/04-03-SUMMARY.md` — Bitrix schema cache + `bitrix:schema:refresh` command (ops handover CUT-06 references)

### Phase 5 Competitor Analysis (freshness + margin suggestion metrics)

- `.planning/phases/05-competitor-analysis/05-CONTEXT.md` — D-05 thresholds (weekly digest margin-movers summary); stale-feed config (CompetitorFreshnessWidget)
- `.planning/phases/05-competitor-analysis/05-04b-SUMMARY.md` — CompetitorCheckStaleCommand + StaleFeedNotification (notification centre stale-feeds tab reuses this data)

### Phase 6 Product Auto-Create (review inbox metrics + ProductOverride for cutover)

- `.planning/phases/06-product-auto-create/06-CONTEXT.md` — D-07/D-08 completeness score (PendingReviewsWidget low-completeness tile); D-10 ProductOverride 8 pin columns (CUT-02 populate-overrides command writes here); D-11 ApplyPinsDuringSync listener (cutover relies on pins surviving sync)
- `.planning/phases/06-product-auto-create/06-VERIFICATION.md` — FLAG verdict + 3 carry-forward items (D-20 gates integrate these as blocking cutover prerequisites)

### Project foundations

- `.planning/PROJECT.md` — Core Value, Constraints (audit everything, suggestions pattern), Cutover constraint ("Parity first: must run in parallel with old plugins before they're disabled")
- `.planning/REQUIREMENTS.md` — DASH-01..06 + CUT-01..07 acceptance criteria
- `.planning/ROADMAP.md` §Phase 7 — 5 success criteria; depends-on ALL previous phases
- `.planning/STATE.md` — Phase 6 complete; 100% plans done; status `verifying`

### Research artefacts

- `.planning/research/FEATURES.md` §Module F + §Module G — Dashboard + Cutover brief items + differentiators
- `.planning/research/PITFALLS.md` — cutover/rollback pitfalls; Woo webhook storm; Bitrix24 duplicate contacts (CRM push log filters)
- `.planning/research/STACK.md` — Filament dashboard widgets + Laravel scheduler patterns

### Legacy plugins being retired

- `C:/Users/sonny.tanda/Documents/1 - Laravel Projects/woo project/bitrix24-extracted/woocommerce-bitrix24-integration/` — itgalaxy plugin (disabled by D-18; referenced in handover docs for historical comparison)
- Stock Updater WordPress plugin (source not in repo; referenced by file path in WP install; disabled by D-18)

### No external specs

No ADRs, RFCs, or external docs beyond the above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets (Phase 1–6 delivered — Phase 7 is 99% composition, 1% net-new)

- **`AlertRecipient` model** — 4 existing `receives_*` booleans; Phase 7 adds `receives_weekly_digest` (5th).
- **`sync_diffs` table** — already partitioned by `provider` column (Phase 4/5 extension); Phase 7 adds `provider='divergence-scan'` rowset for CUT-01.
- **`ProductOverride` table with 8 pin columns** (Phase 6 D-10) — CUT-02's `cutover:populate-overrides` writes here.
- **`PriceCalculator::stripVat` + `compute`** (Phase 3) — divergence scan's price comparison reuses these (integer pennies, BCMath where needed).
- **`WooClient`** (Phase 2) — `get('/products?sku={sku}')` for the divergence scan; `post('/wp-json/wc/v3/products')` already proven for image URL pass-through in Phase 6.
- **`Auditor`** (Phase 1) — logs cutover command invocations + flag flips + backup snapshots to `audit_log`.
- **`IntegrationLogger`** (Phase 1) — every outbound Woo/Bitrix call during divergence scan logs to `integration_events`.
- **`BaseCommand`** (Phase 1) — all Phase 7 artisan commands extend this (correlation_id threading + console CID visibility).
- **`DomainEvent` base + `ShouldDispatchAfterCommit`** (Phase 1) — `CutoverFlagFlipped`, `DivergenceScanCompleted`, `WeeklyDigestSent` events if downstream subscribers emerge.
- **Horizon supervisors** (Phase 1 FOUND-09) — 7 queues. Phase 7 dashboard uses `sync-bulk` for the dashboard refresh + weekly report + divergence scan; `default` queue for lightweight notification-centre polling jobs.
- **`spatie/simple-excel`** (Phase 2) — CSV export backend (D-06).
- **`spatie/laravel-activitylog`** (Phase 1) — audit trail on overrides + flag flips.
- **Filament 3 built-in:** Dashboard Page pattern, global search (`getGloballySearchableAttributes`), Chart widget (dashboard tiles with trend sparklines), Bulk actions (CSV export).
- **Horizon dashboard** at `/horizon` — admin-auth gated by Phase 1; Phase 7 links from header.
- **All existing Filament Resources** gain `->getGloballySearchableAttributes()` implementation — a few-line addition each.

### Established Patterns (from Phase 1–6)

- **Migration timestamps** — Phase 6 used `2026_04_22_*` and `2026_04_23_*`; Phase 7 starts `2026_04_24_*`.
- **Domain layout** — `app/Domain/Dashboard/` (new) for Home dashboard + widgets + NotificationCentrePage. `app/Domain/Cutover/` (new) for divergence scan + populate-overrides + drill-rollback + disable-legacy-plugins + snapshot-woo-db + checklist commands. Both domains allowed-to-depend-on ALL prior domains in Deptrac (dashboard aggregates everything; cutover orchestrates everything) — BUT other domains MUST NOT depend on Dashboard/Cutover (one-way arrow).
- **Deptrac layer** — New `Dashboard` + `Cutover` layers. Allow-list `[Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Competitor, CRM, ProductAutoCreate]` (everything). Deny-list: other layers MUST NOT import Dashboard or Cutover. Extend BOTH `depfile.yaml` AND `deptrac.yaml` (Phase 5 dual-config lesson).
- **Filament Resource Global Search:** one-method extension per Resource.
- **Policy template integrity** — `tests/Architecture/PolicyTemplateIntegrityTest` auto-checks new Dashboard + Cutover policies. Floor bumped.
- **Testing DB** — `meetingstore_ops_testing` MySQL (Phase 1 P03). Phase 7 tests follow the same pattern. D-20's gate 3 is a hard requirement: Feature suite MUST run before cutover.
- **`->authorize()` on Filament Actions** — Publish dashboard widgets + Notification Centre quick actions + CSV export + saved-filter save.
- **Pitfall P5-F** — every `shield:generate --all` in Phase 7 MUST follow the restoration protocol (Phase 5 04a SUMMARY).
- **Dual-YAML Deptrac** — both `depfile.yaml` + `deptrac.yaml` kept in sync (Phase 5 05-05 lesson).
- **WP DB write ban** — Deptrac `WpDirectDb` layer test (Phase 2 05) still enforces. Phase 7 cutover commands MUST use WP-CLI over HTTP OR documented ops one-liners only.

### Integration Points

- **Dashboard widgets:** read from `dashboard_snapshots` (fresh) OR fall through to live aggregation + snapshot write (first-ever request).
- **Global search:** Filament queries each Resource's `getGloballySearchableAttributes()`; RBAC-filtered by existing policies.
- **CSV export:** Filament Bulk Action → `spatie/simple-excel` writer → browser stream; large exports prompt queue-to-email.
- **Notification centre:** Livewire-polled page aggregating 4 existing datasources (no new table needed).
- **Weekly digest:** scheduled command → assembles metrics → `WeeklyDigestMail` → Laravel Mail → AlertRecipient routing.
- **Divergence scan:** scheduled command → iterates Products → WooClient GETs → compares → writes sync_diffs rows → updates dashboard snapshot.
- **Populate-overrides:** ad-hoc command → reads latest divergence scan → writes ProductOverride rows with pins.
- **Rollback drill:** ad-hoc command → simulates or executes rollback → writes drill report.
- **Disable-legacy-plugins:** ad-hoc command → WP-CLI over REST OR documented ops one-liners.
- **Snapshot Woo DB:** ad-hoc command → mysqldump + gzip + upload.
- **Cutover checklist:** ad-hoc command → structured PASS/PENDING/FAIL report.

### New migrations

- `add_receives_weekly_digest_to_alert_recipients` — nullable boolean, default true, backfill existing.
- `create_dashboard_snapshots_table` — metric_key (indexed), metric_value_json, computed_at (indexed).
- `create_user_saved_filters_table` — user_id (FK), resource_slug, filter_name, filter_payload_json, timestamps.
- (Optional — if planner picks spatie/laravel-settings plugin) — `settings` table migration from the package.

### New Filament surfaces

- `HomeDashboardPage` (replaces default Filament dashboard).
- 9 dashboard widgets.
- `NotificationCentrePage`.
- Saved-filter component on every Resource (shared trait).
- Global-search extension on 6 Resources.

</code_context>

<specifics>
## Specific Ideas

- **Phase 7 is mostly composition of prior-phase artefacts.** The net-new code (dashboard widgets, notification centre, cutover commands) is a thin orchestration layer over 5+ existing domains. Implementation risk is LOW; operational-sequencing risk is HIGH (cutover ordering matters).
- **Cutover command sequencing is load-bearing.** D-19 documents the exact 7-step order: snapshot → scan → populate → drill → disable-legacy → flip → monitor. Handover docs (CUT-06) MUST replicate this sequence verbatim with ops-friendly language.
- **Divergence scan parity threshold of 99%** is load-bearing. Ops should NOT flip `WOO_WRITE_ENABLED=true` until 7 consecutive days of ≥99% parity. Dashboard widget makes this observable.
- **Pre-cutover ProductOverride auto-population is a one-shot command.** Running it twice is idempotent (merge semantics per D-15 never clear pins). Running it BEFORE the flip is mandatory; running it AFTER means operator edits between flip and command WILL be lost.
- **Rollback drill MUST run against staging, not prod.** D-17 gates the `--live` flag on `CUTOVER_DRILL_ALLOWED=true` env var. Ops handover docs emphasise the test-in-staging requirement.
- **Phase 6 carry-forward items (D-20) are NOT optional.** They're prerequisite gates baked into the cutover checklist. Skipping the supplier probe + Woo sandbox + Feature suite run risks prod surprises.
- **`cutover:checklist` is the SINGLE source of truth for cutover readiness.** Ops run it repeatedly during the parallel-run window; its green exit code + 100% PASS status is the go/no-go signal for the `WOO_WRITE_ENABLED=true` flip.
- **Weekly digest runs AFTER cutover too.** It's a permanent feature; the 7-day parallel-run window just happens to be when ops watches it most closely.
- **Horizon link + notification centre + Home dashboard together form the "morning coffee page"** — ops open this one page and know what happened overnight. This is the mental-model test for Phase 7.

</specifics>

<deferred>
## Deferred Ideas

These surfaced during analysis or research F.3 but are explicitly scoped out of Phase 7 to keep the cutover ship goal tight:

- **Cross-user saved-filter sharing** (D-07) — v1 is per-user private. Sharing + templates deferred to v2.
- **WebSocket real-time push for notifications** — Livewire polling is sufficient for v1; Laravel Echo + Pusher deferred.
- **Sparkline trend widgets on the home dashboard** — `dashboard_snapshots` keeps 30d history; widgets currently show latest-value only. Sparklines using historical data are a v1.x polish enhancement.
- **Saved dashboard views per user** — single home dashboard in v1; per-user widget customisation deferred.
- **Dark mode + mobile responsiveness** (research F.2) — Filament 3 ships both; Phase 7 inherits without explicit polish work. True mobile-first redesign deferred.
- **Impersonation for support** (research F.2) — useful but non-critical for cutover; deferred to v1.x.
- **Embedded BI / pivot-table builder** (research F.4 anti-feature) — out of scope forever; export-to-CSV + Excel / Metabase path.
- **Per-brand dashboard views** — single unified dashboard in v1.
- **Slack notification channel** (currently email-only) — post-cutover enhancement if email proves too slow.
- **Automated rollback trigger** — manual operator decision in v1. Auto-rollback on divergence spike deferred to v1.x.
- **Custom React/Vue SPA dashboard** (research F.4 anti-feature) — Filament-native forever.
- **Multi-store support** (PROJECT.md Out of Scope) — remains out of scope forever.
- **Customer-facing portal** (PROJECT.md Out of Scope) — remains out of scope forever.
- **`audit_log` full-text search** — basic indexed queries only in v1; full-text search engine integration deferred.
- **Horizon custom skin / embedded-in-Filament** (research F.4 anti-feature) — link-out to `/horizon` is sufficient.
- **Weekly digest per-brand breakdown** — single unified digest in v1; per-brand sections deferred based on ops feedback.

### Reviewed Todos (not folded)

No pending todos matched Phase 7 scope — none to defer.

</deferred>

---

*Phase: 07-dashboard-polish-cutover*
*Context gathered: 2026-04-24 via auto-mode (recommended defaults selected inline)*
*Milestone position: FINAL phase — ships v1 cutover from legacy WordPress plugins to Laravel sole-source-of-truth*
