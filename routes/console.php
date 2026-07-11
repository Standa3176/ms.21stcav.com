<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Retention prune schedule (D-09)
|--------------------------------------------------------------------------
|
| All prunes run at 03:00 window staggered by 5–10 minute intervals to spread
| DB load. `withoutOverlapping(30)` prevents a slow prune colliding with the
| next day's cron fire. `onOneServer()` ensures multi-worker deployments
| only run each prune once.
|
| Phase 2 will add: sync-errors:prune (D-07)
| Phase 5 will add: competitor-csv:prune (D-06)
*/

// D-04: audit_log — 365 days
Schedule::command('activitylog:prune --days=365')
    ->dailyAt('03:00')
    ->withoutOverlapping(30)
    ->onOneServer();

// D-05: integration_events — 90 days
Schedule::command('integration-events:prune --days=90')
    ->dailyAt('03:10')
    ->withoutOverlapping(30)
    ->onOneServer();

// D-07: sync_errors — 90 days (Phase 2 Plan 05 — replaces the Phase 1 TODO marker)
Schedule::command('sync-errors:prune', ['--days' => 90])
    ->dailyAt('03:20')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Prune sync_errors older than 90 days (D-07 retention)');

// Quick task 260607-9c6 (SECURITY-REVIEW.md H-1) — daily 03:25 London prune
// of webhook_receipts.raw_body. Per-topic retention (defaults applied by the
// command itself): order=30d, customer=7d (tightest GDPR window), other=90d.
// Slots between sync-errors:prune (03:20) and sync-diffs:prune (03:30) in the
// 03:00 retention cascade — keeps the staggered-DB-load invariant.
Schedule::command('webhooks:prune-receipts')
    ->cron('25 3 * * *')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Prune webhook_receipts by per-topic GDPR retention (H-1, 260607-9c6)');

// D-08: sync_diffs — conditional (no-op while WOO_WRITE_ENABLED=false per Pitfall L)
Schedule::command('sync-diffs:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping(30)
    ->onOneServer();

// Quick task 260504-m5w — daily Woo catalogue refresh.
// Pulls publish + draft + private products from meetingstore.co.uk into the
// local products table so supplier:db-sync (03:30) and downstream pricing
// queries see today's catalogue. LIVE — no kill-switch (idempotent + ops
// verified manually pre-deployment).
Schedule::command('woo:import-products')
    ->dailyAt('03:00')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Daily Woo catalogue import (publish + draft + private products)');

// Quick task 260606-gnu — Mon 06:00 London prune of stale competitor-only orphan suggestions.
// Runs BEFORE supplier:db-sync (Mon-Fri 07:00) so the prune never touches rows whose
// sourceability status is about to change. Conservative gates (off-supplier + <2 competitors
// + >=30 days old) mean a misclassified row is at worst preserved one extra week.
Schedule::command('suggestions:prune-orphans')
    ->cron('0 6 * * 1')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Prune stale orphan suggestions (Mon 06:00, before supplier sync)');

// Quick task 260626-q2b — Mon-Fri 06:55 London pull of the REAL supplier feed
// dates (feeds.remote_date + cron_run + status) into suppliers.feed_remote_date.
// Runs JUST BEFORE the 07:00 supplier:db-sync price sync so the Suppliers page
// shows today's true feed dates. Metadata-only — writes NO prices/stock and
// preserves operator-owned fields (is_active, stale_after_days, notes).
Schedule::command('suppliers:sync-feed-dates')
    ->cron('55 6 * * 1-5') // Mon-Fri at 06:55 (cron DOW: 1=Mon ... 5=Fri)
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Mon-Fri supplier feed-date metadata sync (06:55 London, before price sync; 260626-q2b)');

// Quick task 260504-m5w + 260504-onx — Mon-Fri supplier DB pull at 07:00 London.
// Re-pitched from daily 03:30 to Mon-Fri 07:00 per ops preference: aligns the
// freshest supplier price + stock with start-of-day decisions and skips weekends
// (supplier feed source itself doesn't refresh weekends per their cron cadence).
// LIVE — no kill-switch (idempotent + ops verified manually pre-deployment).
Schedule::command('supplier:db-sync')
    ->cron('0 7 * * 1-5') // Mon-Fri at 07:00 (cron DOW: 1=Mon ... 5=Fri)
    ->withoutOverlapping(60)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Mon-Fri supplier MySQL VPS price + stock sync (07:00 London)');

// Warm the cached set of sourceable supplier SKUs used by the
// "On supplier DB" Filament filter on /admin/suggestions. Runs 5 minutes
// after the supplier sync so the filter reflects today's catalogue.
Schedule::command('supplier:refresh-sku-cache')
    ->cron('5 7 * * 1-5')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Refresh sourceable-SKU cache (post-supplier-sync)');

// Stock-updater parity glue — flip published Products with NULL/zero buy_price
// to status='pending' so they fall out of the storefront until a real cost
// lands. Port of the legacy plugin's logProductChanges() / handle_pending_product()
// behaviour. Mon-Fri 07:15 London — 15 min after supplier:db-sync.
Schedule::command('products:flag-missing-buy-price')
    ->cron('15 7 * * 1-5')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Flip products with missing buy_price to pending (post-supplier-sync)');

// Quick task 260611-qcq — products:hydrate-stock-from-offers Mon-Fri 07:20 London.
// Closes the supplier_offer_snapshots → products.stock_quantity gap (proved on
// prod 2026-06-11 with HA310-2EP: snapshot had Ingram stock=5659, products
// had stock_quantity=0). Slots BETWEEN flag-missing-buy-price (07:15) and
// suggestions:auto-apply (07:30) so a freshly-pending product doesn't get
// its stock_status hydrated the same minute it was demoted to pending.
// --only-stale=24 keeps the daily cron incremental — first manual full-catalogue
// rehydrate is `--only-stale=0` (run by hand post-deploy).
Schedule::command('products:hydrate-stock-from-offers --only-stale=24')
    ->cron('20 7 * * 1-5') // Mon-Fri at 07:20 (cron DOW: 1=Mon ... 5=Fri)
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Hydrate products.stock_quantity from supplier_offer_snapshots (260611-qcq); Mon-Fri 07:20 London');

// Stock-updater parity glue — auto-apply margin_change Suggestions whose delta
// crosses pricing.auto_apply_threshold_bps (default 800bps = 8pp). Port of
// the legacy plugin's setPer() rule. Mon-Fri 07:30 London — after the
// pending-flip so margin changes for newly-published products are in scope.
Schedule::command('suggestions:auto-apply')
    ->cron('30 7 * * 1-5')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Auto-apply margin_change Suggestions above threshold (post-supplier-sync)');

// Quick task 260608-g8x — Mon-Fri 07:45 London snapshot of every supplier's
// fresh/amber/stale state. Slots BETWEEN suggestions:auto-apply (07:30) and
// reports:supplier-sync-digest (08:00). 45 min after supplier:db-sync (07:00)
// so today's sync had a chance to write fresh recorded_at rows — running
// BEFORE supplier:db-sync would inflate "stale" by 1 day.
//
// DO NOT move to 06:30 or 07:30 — both contended (07:30 = suggestions:auto-apply;
// 06:30 would run before today's supplier sync).
Schedule::command('suppliers:check-stale')
    ->cron('45 7 * * 1-5')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Snapshot per-supplier freshness (260608-g8x); Mon-Fri 07:45 London');

// Quick task 260702-h50 — products:refresh-brands-to-add Mon-Fri 07:50 London.
// Piece 1 of the "Brands to Add" workflow. Slots 5 min AFTER suppliers:check-stale
// (07:45) and AFTER supplier:db-sync (07:00) so today's supplier feed is fresh
// before we resolve each pending SKU's manufacturer → Woo brand. Tags every
// pending new_product_opportunity suggestion (evidence.brand + brand_on_woo)
// and caches the brands-to-add summary under 'suggestions.brands_to_add' for
// the Piece-2 page. Read-only re. Woo + no Claude spend. The scheduled run
// writes (no --dry-run); operator previews interactively with --dry-run.
Schedule::command('products:refresh-brands-to-add')
    ->cron('50 7 * * 1-5') // Mon-Fri at 07:50 (cron DOW: 1=Mon ... 5=Fri)
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Refresh brands-to-add index + tag pending suggestions (260702-h50); Mon-Fri 07:50 London');

// Stock-updater parity glue — daily post-supplier-sync digest email to
// AlertRecipients with receives_sync_reports=true. Replaces the legacy
// plugin's send_results_and_cleanup() 4-CSV email. Mon-Fri 08:00 London.
Schedule::command('reports:supplier-sync-digest')
    ->cron('0 8 * * 1-5')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Daily supplier sync digest email (08:00 Mon-Fri)');

// Stock-updater parity glue — safety-net second pass at 09:00 in case the
// 03:00 woo:import-products or 07:00 supplier:db-sync silently failed. Both
// commands are idempotent (updateOrCreate on snapshots, plain SET on prices),
// so re-running them is a no-op when the morning runs succeeded.
Schedule::command('woo:import-products')
    ->cron('0 9 * * 1-5')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Woo catalogue import — safety-net retry (09:00 Mon-Fri)');

Schedule::command('supplier:db-sync')
    ->cron('5 9 * * 1-5')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Supplier DB sync — safety-net retry (09:05 Mon-Fri)');

// Phase 5 Plan 05 Task 1 — daily 03:40 CSV archive retention prune (COMP-12).
// Default retention: config('competitor.csv_retention_days', 90). NEVER touches
// competitor_prices / ingest_runs / csv_parse_errors rows — archive files only.
// The 03:40 slot continues the 03:00/03:10/03:20/03:30 cascade from Phases 1 + 2.
Schedule::command('competitor:csv-prune')
    ->dailyAt('03:40')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Prune competitor CSV archive files older than 90d (COMP-12; D-09 auditable)');

// Phase 11.1 Plan 01 + Quick task 260504-onx — twice-weekly competitor FTP pull (D-07).
// Sun+Wed 06:00 London (operator preference 2026-05-23) — lands competitor data
// ahead of the 07:00 supplier sync + morning ops review. Files land in
// storage/app/competitors/incoming/ for competitor:watch to pick up on its next
// 5-min sweep via the >30s mtime gate. Failures increment a per-source counter;
// 3 consecutive failures auto-disable the source and notify recipients with
// receives_competitor_ftp_alerts=true (D-12).
Schedule::command('competitor:ftp-pull --live')
    ->cron('0 6 * * 0,3') // Sun + Wed at 06:00 (cron DOW: 0=Sun, 3=Wed)
    ->withoutOverlapping(20)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Pull competitor CSVs Sun+Wed 06:00 London (Phase 11.1 Plan 01)');

// Phase 5 Plan 02 — 5-minute competitor CSV watcher (COMP-01 + COMP-04).
// Picks up aged files from storage/app/competitors/incoming/ and dispatches
// IngestCompetitorCsvJob on the competitor-csv queue. withoutOverlapping(10)
// prevents a slow cycle (e.g. 50k-row CSV buffering) colliding with the next
// tick. onOneServer() ensures multi-worker deployments only process files once.
Schedule::command('competitor:watch')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Watch storage/app/competitors/incoming/ for aged CSVs (Phase 5 Plan 02)');

// Phase 5 Plan 03 — nightly 02:00 sales-counter recache (COMP-08 / COMP-09
// hybrid strategy). Chunks Product by 100 SKUs per RecacheSalesCountsJob on
// the sync-bulk queue. A3 fallback: job body is currently a stub (WooClient
// lacks /orders) — real-time IncrementSkuSalesCount listener is authoritative
// until WooClient gains a getOrders method in a post-Phase-5 plan.
Schedule::command('competitor:sales-recache')
    ->dailyAt('02:00')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Recompute last_sales_count_90d for every Product (Phase 5 Plan 03; A3 fallback stub)');

// Phase 5 Plan 04b — hourly stale-feed detector (COMP-11). Notifies every
// AlertRecipient where receives_competitor_alerts=true when an active
// competitor hasn't ingested in >stale_feed_hours (default 48h). 24h per-
// competitor dedup via Cache::add keyed on YYYY-MM-DD so the hourly cadence
// cannot alert-fatigue ops — first miss of the day wins.
Schedule::command('competitor:check-stale')
    ->hourly()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Check for stale competitor feeds (>48h) and notify subscribers (Phase 5 Plan 04b, 24h dedup)');

// Phase 2 (D-05) — Daily supplier sync. COMMENTED OUT; Phase 7 cutover runbook
// enables this entry once parity with the legacy Stock Updater plugin is proven.
// The commented entry itself is the kill-switch — no separate SYNC_CRON_LIVE flag.
// Schedule::command('sync:supplier --live')
//     ->dailyAt('02:00')
//     ->onOneServer()
//     ->withoutOverlapping(60)
//     ->onQueue('sync-bulk')
//     ->timezone('Europe/London')
//     ->description('Daily 21stcav.com supplier sync (D-05 — enable post-Phase-7-cutover)');

// Phase 7 Plan 02 (D-02) — dashboard:refresh every 5 minutes.
// Aggregates the 9 home-dashboard metrics into dashboard_snapshots so widget
// reads are a single indexed lookup on /admin page load. onOneServer keeps the
// scheduler safe across multi-worker deployments; withoutOverlapping(5) prevents
// a slow refresh colliding with the next tick (worst case 5-min skip, which is
// still within the 15-min snapshot_ttl ceiling before widgets show amber).
Schedule::command('dashboard:refresh')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Aggregate home-dashboard metrics into dashboard_snapshots (Phase 7 Plan 02, D-02)');

// Phase 7 Plan 02 — snapshots:prune daily 03:50 (continues the 03:00/03:10/03:20/
// 03:30/03:40 cascade from Phases 1 + 2 + 5). Retention default is 30 days via
// config('dashboard.snapshot_retention_days'); --days=0 is an explicit no-op
// safety guard (Phase 5 CompetitorCsvPrune precedent).
Schedule::command('snapshots:prune')
    ->dailyAt('03:50')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Prune dashboard_snapshots older than 30 days (Phase 7 Plan 02)');

// Quick task 260504-muq — history:prune daily 04:00 (continues the 03:00..03:50
// retention cascade with a 10-min gap). Deletes product_price_snapshots +
// supplier_offer_snapshots older than config('history.retention_days', 90).
// Distinct command name from Phase 7's snapshots:prune (which targets the
// dashboard_snapshots table) — registering both as snapshots:prune would
// silently shadow one based on AppServiceProvider registration order.
Schedule::command('history:prune')
    ->dailyAt('04:00')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Prune product + supplier-offer snapshots older than retention window (Quick task 260504-muq)');

// Phase 7 Plan 04 (D-08) — reports:weekly-digest Monday 07:00 Europe/London.
// Composes the 5-section ops digest (Sync / Margin / CRM / Auto-Create / Competitor)
// and sends to AlertRecipient rows where receives_weekly_digest=true. On success,
// upserts dashboard_snapshots.weekly_report_status so the Phase 7 Plan 02
// WeeklyReportStatusWidget reflects last_sent_at + recipient_count + next_run ETA.
// onOneServer + withoutOverlapping(30) prevents double-sends across multi-worker
// scheduler deployments; timezone ensures ops see the 07:00 cadence in their
// local TZ regardless of underlying server clock.
Schedule::command('reports:weekly-digest')
    ->weeklyOn(1, '07:00')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Weekly ops digest — Monday 07:00 Europe/London (DASH-05 / Phase 7 Plan 04)');

// 2026-05-25 — supplier:scan-add-candidates weekly (Sunday 05:00 London).
// Heavy remote GROUP BY over the supplier feed; caches "products to add" (parts
// stocked by ≥2 suppliers but not on meetingstore) for the Pricing Operations
// dashboard "Products to add" tile. Read-only; never writes Woo.
Schedule::command('supplier:scan-add-candidates')
    ->weeklyOn(0, '05:00')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Cache supplier add-candidates for the dashboard (weekly, Sun 05:00)');

// 2026-05-27 — pricing:scan-sourcing-gaps weekly (Sunday 05:30 London, 30 min
// after add-candidates so the two heavy feed scans don't overlap). Caches
// "sourcing gaps" (parts a competitor lists that NO supplier carries + we don't
// sell — likely obsolete) for the Pricing Operations dashboard. Read-only.
Schedule::command('pricing:scan-sourcing-gaps')
    ->weeklyOn(0, '05:30')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Cache competitor-only no-supplier sourcing gaps for the dashboard (weekly, Sun 05:30)');

// Quick task 260607-t6w — Weekly category audit Fri 22:00 London.
//
// Mon-Sun pattern doesn't matter — just Friday so Monday morning the ecom
// manager has a fresh report to triage over the week. Rule-based + free
// (no Claude spend); typical runtime under 30 seconds on the live catalogue.
// timezone('Europe/London') resolves GMT/BST so 22:00 is wall-clock London.
//
// Friday slot was empty (no other Fri job) so no collision risk. The
// audit TRUNCATE-and-replaces category_audit_findings — snapshot semantics
// per scope. Unconditional schedule (no opt-in flag): the command is safe
// to run repeatedly because TRUNCATE-and-replace is idempotent.
Schedule::command('products:audit-categories')
    ->cron('0 22 * * 5') // cron DOW: 5=Friday
    ->withoutOverlapping(60)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Weekly category audit (Fri 22:00 London) for ecom manager review');

// Quick task 260609-nku — Weekly stock-divergence audit Mon 09:15 London.
//
// Mon 09:00 is taken by woo:import-products safety-net retry (line 161-166)
// and Mon 09:05 by supplier:db-sync safety-net retry (line 168-173). 09:15
// sits AFTER both safety-net retries so today's woo stock_quantity values
// are guaranteed fresh in the local products table before the audit's
// NOT EXISTS subquery runs. timezone('Europe/London') resolves GMT/BST.
//
// TRUNCATE-and-replaces stock_divergence_findings — snapshot semantics
// identical to 260607-t6w category_audit_findings.
Schedule::command('products:audit-stock-divergence')
    ->cron('15 9 * * 1') // Mon at 09:15 (cron DOW: 1=Mon)
    ->withoutOverlapping(60)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Weekly stock-divergence audit (Mon 09:15 London) — phantom-stock detection (260609-nku)');

// Phase 12 Plan 05 SEOAGT-05 — nightly SEO agent batch at 04:30 Europe/London.
// Slots between competitor:ftp-pull (Sun+Wed 02:00) and supplier:db-sync
// (Mon-Fri 07:00). Single nightly cadence per SEOAGT-05 success criterion 1.
// Open Question O-2: env flag allows operator emergency disable without code
// deploy (AGENT_SEO_BATCH_SCHEDULE_ENABLED default true). P12-E (between-
// dispatch monthly budget recheck) is enforced inside the command itself.
// Use config() not env() — env() returns the default in cached-config mode
// (deploy.sh runs config:cache), silently disabling the schedule. See
// config/agents.php for the env-var binding. (Bug found 2026-05-31 when the
// first post-cutover 08:00 BST `pricing:undercut-competitors --live` cron
// silently missed.)
if ((bool) config('agents.seo_batch_schedule_enabled', true)) {
    Schedule::command('agents:run-seo-batch')
        ->cron('30 4 * * *')
        ->withoutOverlapping(60)
        ->onOneServer()
        ->timezone('Europe/London')
        ->description('Phase 12 SEOAGT-05 — nightly SEO agent batch (04:30 Europe/London)');
}

// Phase 15 Plan 15b-01 — advice-only AdOptimisationAgent, several times a day.
// everySixHours is SAFE because (a) the command no-ops when there are no recent
// ga_channel_metrics_daily rows (no dispatch, no LLM spend — so it is safe to
// schedule NOW before real GA4 data flows) and (b) the daily 300p budget cap
// bounds worst-case spend. withoutOverlapping guards a slow run from colliding
// with the next fire. Config()-gated (not env()) — env() returns the default in
// cached-config mode (deploy runs config:cache), silently disabling the schedule.
if ((bool) config('agents.ad_optimisation_schedule_enabled', true)) {
    Schedule::command('agents:run-ad-optimisation')
        ->everySixHours()
        ->withoutOverlapping()
        ->onOneServer()
        ->timezone('Europe/London')
        ->description('Phase 15 — advice-only AdOptimisationAgent (every 6h, Europe/London; no-op when no recent GA4 data)');
}

// Quick task 260708-b4f — products:reconcile-woo-maintenance nightly 04:30 London.
// READ-ONLY paged Woo GET /products that mirrors each live product's real Woo
// state (image count / EAN / category count / stock) into the local woo_*
// columns for the Woo Maintenance dashboard. Slots off-peak AFTER the 03:xx
// retention prunes + history:prune (04:00) and BEFORE the ~05:00 supplier /
// SEO scans. withoutOverlapping() guards the ~47-page catalogue crawl from a
// slow run colliding with the next night's fire. Never writes to Woo.
Schedule::command('products:reconcile-woo-maintenance')
    ->dailyAt('04:30')
    ->withoutOverlapping();

// Phase 8 Plan 05 (D-07) — agents:prune-archive annual on 1 Jan 02:00 Europe/London.
// Exports AgentRun rows where completed_at < NOW() - INTERVAL 5 YEAR to
// storage/app/agent-archives/agent-runs-{YYYY-MM-DD-HHmmss}.json.gz then
// DELETEs the rows. Audit row in activity_log. Disk projection ~3GB after
// 5y of 100 runs/day; archives stay <100MB compressed. Annual cadence
// keeps cron load minimal — operator can also invoke ad-hoc with --days=N.
Schedule::command('agents:prune-archive')
    ->yearlyOn(1, 1, '02:00')
    ->withoutOverlapping(120)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Phase 8 D-07 — annual AgentRun 5y retention prune (1 Jan 02:00 Europe/London)');

// Phase 7 Plan 05 — cutover:divergence-scan daily 01:00 Europe/London.
// OPT-IN via env CUTOVER_DIVERGENCE_SCAN_SCHEDULE_ENABLED=true — ops enables
// during the parallel-run window (D-19 monitoring phase) and disables again
// once WOO_WRITE_ENABLED=true has been flipped and the 7-day monitoring window
// passes cleanly. --live persists SyncDiff rows + writes dashboard_snapshots
// sync_diffs_parity so the /admin widget reflects real-time parity. Routed on
// sync-bulk queue so the scan doesn't starve default/sync-woo-push.
if ((bool) config('cutover.divergence_scan_schedule_enabled', false)) {
    Schedule::command('cutover:divergence-scan --live')
        ->dailyAt('01:00')
        ->withoutOverlapping(120)
        ->onOneServer()
        ->timezone('Europe/London')
        ->description('CUT-01 parallel-run divergence scan (opt-in; ops env-enabled during cutover window)');
}

// Quick task 260611-rl4 — cutover:auto-sync daily 23:00 London.
// Nightly chain that closes MS↔Woo drift overnight: divergence-scan → push
// divergences → re-scan → parity-regression detector. Re-scan parity-after
// LESS THAN parity-before exits 1 (cron logs / Horizon alarm) — a push that
// broadens divergence usually means the comparator predicate is wrong, Woo
// cache hasn't invalidated, or a different writer is touching Woo behind us.
//
// 23:00 London uncontended: 22:00 Fri-only audit-categories + 00:30 daily
// quotes:expire are the closest neighbours. Slots after business close, before
// 07:00 supplier:db-sync writes tomorrow's snapshot.
//
// withoutOverlapping(120) — defensive: if a run drags past 2hrs (capped by
// --max-products=500), the next firing skips. Prevents two concurrent push
// runs racing on the same sync_diffs.
Schedule::command('cutover:auto-sync --field=stock_quantity,buy_price,category_id --max-products=500')
    ->cron('0 23 * * *')
    ->timezone('Europe/London')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->name('cutover:auto-sync')
    ->description('Quick task 260611-rl4 — nightly MS↔Woo drift self-heal (23:00 Europe/London)');

// Phase 11 Plan 05 (QUOT-08) — quotes:expire daily 00:30 Europe/London.
// Flips status=sent → status=expired for quotes whose expires_at has passed.
// --live opt-in is REQUIRED here (the command itself defaults to dry-run per
// cross-cutting invariant 3); the scheduler invokes the live mutation path
// because the cron is the production trigger. Optional customer email is
// gated by config('quote.email_on_expiry') — operator opts in post-cutover.
// onOneServer + withoutOverlapping(30) prevents double-flips across multi-
// worker scheduler deployments; the (status, expires_at) composite index
// from Plan 11-01 keeps the query index-covered.
Schedule::command('quotes:expire --live')
    ->dailyAt('00:30')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('QUOT-08 — flip status=sent → expired for quotes past expires_at (Phase 11 Plan 05)');

// Core-loop step #3 — weekly auto-draft of competitor-only SKUs, Sunday 14:00
// Europe/London (operator spec). Finds SKUs on competitors but NOT on
// meetingstore (supplier-carried only — generate-drafts skips the rest),
// prioritised by competitor count, and runs the review-first pipeline
// (content → taxonomy → images). Drafts land in the Auto-Create Review inbox
// for manual publish; NOTHING posts to Woo (review-first + WOO_WRITE_ENABLED
// gate). --limit=25 bounds weekly Claude spend (raise for a manual backfill).
// withoutOverlapping(120) guards the slow (Claude-bound) run.
Schedule::command('products:draft-competitor-skus --limit=25')
    ->weeklyOn(0, '14:00')
    ->withoutOverlapping(120)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Core loop #3 — weekly competitor-only SKU auto-draft (Sunday 14:00 Europe/London)');

// Core-loop step #1 — daily competitor-undercut repricing at 08:00 Europe/London
// (after the 03:00 Woo import + Mon-Fri 07:00 supplier:db-sync, so cost +
// competitor data are fresh). --live writes products.sell_price + dispatches
// ProductPriceChanged → PushPriceChangeToWoo (gated by WOO_WRITE_ENABLED).
// OPT-IN via PRICING_UNDERCUT_SCHEDULE_ENABLED (default false): a full-catalogue
// --live run churns sell_price + thousands of shadow SyncDiffs daily, pointless
// before cutover — flip it on AT cutover (or now if you want daily local
// staging). Floor + undercut amount read from config/competitor.php.
if ((bool) config('pricing.undercut_schedule_enabled', false)) {
    Schedule::command('pricing:undercut-competitors --live')
        ->dailyAt('08:00')
        ->withoutOverlapping(120)
        ->onOneServer()
        ->timezone('Europe/London')
        ->description('Core loop #1 — daily competitor-undercut repricing (08:00 Europe/London; opt-in via PRICING_UNDERCUT_SCHEDULE_ENABLED)');
}

// Phase 15 Plan 15a-02 — google:pull-ga4 twice-daily (06:00 + 14:00 London).
// READ-ONLY pull of GA4 channel/campaign metrics into ga_channel_metrics_daily.
// SAFE TO SHIP NOW: the command no-ops (logs + exits 0) whenever GA4 is
// unconfigured — GoogleAnalyticsClient::fetchChannelMetrics() returns [] until a
// GA4 service-account credential is saved. So this schedule stays a harmless
// no-op in prod today and starts populating the snapshot table the instant the
// operator provisions the integration. withoutOverlapping() guards against a
// slow pull colliding with the next fire. The default 7-day window means the
// 14:00 run refreshes the partial current day the 06:00 run captured.
Schedule::command('google:pull-ga4')
    ->twiceDaily(6, 14)
    ->withoutOverlapping()
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Twice-daily GA4 channel/campaign pull (06:00 + 14:00 London); no-op until GA4 credential saved (Phase 15 15a-02)');

// Quick task 260711-aps — twice-weekly straight-to-live auto-PUBLISH of pending,
// sourceable, 2-or-3-competitor SKUs (operator spec: "auto create twice a week
// any pending suggested SKU where we have a supplier and 2 or 3 competitors show
// the product — push straight to live, and keep a record of what was pushed and
// when"). Mon+Thu 05:00 London — ahead of the 07:00 supplier sync + morning ops
// review, uncontended in the 05:00 window.
//
// --auto-approve dispatches PublishProductJob per draft (POST straight to live
// Woo). Each CONFIRMED real publish writes one auto_publish_log row capturing the
// 2-vs-3 competitor split (viewable under Woo Maintenance → Auto-Publish Log).
//
// CRITICAL: live publishing requires WOO_WRITE_ENABLED=true. Until that flag is
// flipped, PublishProductJob records a SyncDiff instead of touching Woo and does
// NOT mark rows published — so this run is a SAFE SHADOW NO-OP (no live writes,
// no false 'published' status, and NO audit-log row) until cutover.
//
// Batch cap via config('product_auto_create.scheduled_publish_limit') (env
// AUTO_PUBLISH_SCHEDULED_LIMIT, default 25) — tunable without a deploy.
// withoutOverlapping() guards the slow (Claude + Woo) run from colliding.
Schedule::command('products:draft-from-suggestions', [
    '--min-competitors' => 2,
    '--max-competitors' => 3,
    '--auto-approve' => true,
    '--create-missing-brands' => true,
    '--source-images' => true,
    '--limit' => (int) config('product_auto_create.scheduled_publish_limit', 25),
    '--no-confirm' => true,
])
    // Twice weekly = Mon (1) + Thu (4) at 05:00. Laravel's scheduler has no
    // twiceWeekly() helper (only twiceDaily/twiceMonthly), so express it as the
    // equivalent cron — matches this file's ->cron('... 1-5') convention.
    ->cron('0 5 * * 1,4')
    ->withoutOverlapping()
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Twice-weekly straight-to-live auto-publish of 2/3-competitor sourceable pending SKUs (Mon+Thu 05:00 London; safe shadow no-op until WOO_WRITE_ENABLED=true; 260711-aps)');
