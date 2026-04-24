# MeetingStore Ops — Cutover Handover Runbook

**Audience:** Operations team (ops@meetingstore.co.uk)
**Purpose:** Replace the legacy WordPress plugins (Stock Updater + itgalaxy Bitrix24) with the Laravel ops app as the sole source of truth for meetingstore.co.uk.
**Last updated:** 2026-04-24 (Phase 7 Plan 06 ship — v1 milestone)
**Authoritative sources:** `.planning/REQUIREMENTS.md` §DASH + §CUT; `.planning/phases/07-dashboard-polish-cutover/07-CONTEXT.md` (21 locked decisions D-01..D-21).

## Quick Reference

| Destination | URL / command |
|-------------|---------------|
| Filament admin | https://ops.meetingstore.co.uk/admin |
| Home Dashboard | https://ops.meetingstore.co.uk/admin (default landing — 9 widgets) |
| Horizon (queue supervisor) | https://ops.meetingstore.co.uk/horizon (admin-only) |
| Notification Centre | https://ops.meetingstore.co.uk/admin/notifications (4 tabs) |
| Cutover go/no-go | `php artisan cutover:checklist` (exit 0 ⇒ go; exit 1 ⇒ pending) |
| Suggestions Inbox | Filament → **Suggestions** (CRM replay, auto-create retry, margin changes, new-product opportunities) |

**Gold rule:** if a command has both `--dry-run` and `--live`, the default is dry-run. Never skip the dry-run step.

---

## Section 1 — How to resume a sync (CUT-06.1)

**When it happens:** the Laravel sync worker crashed mid-run, the VPS rebooted, or the Horizon supervisor was restarted while a supplier sync was in-flight.

**Signal:**
- Home Dashboard → `LastSyncRunWidget` shows red / amber (age > 25h).
- Notification Centre → **Failed jobs** tab lists `SyncChunkJob` entries.
- Filament → **Supplier Sync Status** page has a row with `status = running` but no active Horizon job (check `/horizon` → **Pending** for the `sync-woo-push` queue).

**Action (Phase 2 SYNC-03 contract):**

1. In Filament, open **Supplier Sync Status**.
2. Find the incomplete run — `status = running` or `status = failed`. Note the `run_id`.
3. SSH to the VPS and run:
   ```
   php artisan sync:supplier --resume={run_id}
   ```
4. Horizon resumes from the last processed cursor (from the `sync_run_items` table). No SKU is pushed twice; no SKU is skipped (SYNC-03 ledger semantics).
5. Monitor the run in Filament → **Supplier Sync Status**; duration + updated/failed counts update live on the dashboard.

**If `--resume` fails:** check Horizon's `failed_jobs` table + Filament → **Sync Errors** table for the underlying exception. If the run is stuck in `status = running` but no job is active, mark it `failed` in Filament (admin-only action), then start a fresh run with `php artisan sync:supplier --live`. The SYNC-03 ledger will skip SKUs already processed in the failed run so the new run picks up where the old one left off.

**If `WOO_WRITE_ENABLED=false` (shadow mode):** the run writes to `sync_diffs` only. This is intentional during the parallel-run window. Parity is measured via `cutover:divergence-scan` (see Section 4 + Appendix A).

---

## Section 2 — How to replay a failed CRM push (CUT-06.2)

**When it happens:** Bitrix24 API outage, OAuth token expiry, webhook HMAC mismatch, network partition, or a Bitrix field mapping drifted out of sync.

**Signal:**
- Home Dashboard → `CrmPushSuccessRateWidget` shows < 95% (red).
- Notification Centre → **Pending suggestions** tab shows entries with `kind = crm_push_failed`.
- Weekly digest (Monday 07:00 London) "CRM" section reports elevated DLQ count.

**Action (Phase 4 CrmPushRetryApplier):**

1. Open Filament → **Suggestions**.
2. Filter: `kind = crm_push_failed`.
3. For each suggestion, review the evidence JSON:
   - `woo_order_id` — the Woo order ID
   - `bitrix_entity_type` — deal / contact / company
   - `error_message` — the exception / HTTP-status from the failed push
   - `attempt_count` — retries exhausted before DLQ
4. Click **Replay** on the row. This dispatches a fresh `PushOrderToBitrixJob` via `CrmPushRetryApplier`. The job runs on the `crm-bitrix` Horizon queue (rate-limited to 2 req/sec; 429 backoff handled automatically).
5. Wait ~30 seconds. Refresh the **CRM Push Log** Filament resource and confirm `status = success`.
6. The suggestion auto-closes when the replay succeeds (Phase 4 SuggestionApplier contract).

**If the replay also fails:**

- **401 / OAuth expired** → run `php artisan bitrix:schema:refresh` (see Section 3) — if schema has drifted the OAuth session may need re-establishment; follow the runbook there.
- **Unknown Bitrix field** → a custom field was added in Bitrix admin after our schema cache last refreshed (24h TTL). Run `bitrix:schema:refresh`.
- **429 / rate-limit** → Bitrix backoff is automatic; just wait 5 minutes and retry.
- **Duplicate deal / contact** → inspect `bitrix_entity_map` for a stale mapping. Admin can edit the row via tinker or a carefully crafted SQL UPDATE (then document the fix in an audit-log entry).
- **Persistent failure** → escalate to engineering on-call with the `correlation_id` from the CRM Push Log.

---

## Section 3 — How to refresh Bitrix schema (CUT-06.3)

**When it happens:** an admin added a new custom field in Bitrix24 admin UI and wants to map it in Filament's CRM Field Mapping page, OR a push fails with "unknown field" error.

**Signal:**
- Filament → **CRM Field Mappings** page does not show a field that is known to exist in Bitrix.
- Notification Centre → **Webhook DLQ** tab shows `integration_events` with `error_message LIKE '%unknown field%'`.

**Action (Phase 4 bitrix:schema:refresh command):**

1. Either CLI:
   ```
   php artisan bitrix:schema:refresh
   ```
2. Or Filament UI: **CRM Field Mappings** → **Refresh from Bitrix** button (admin role only).
3. The `bitrix_schema_cache` table (24-hour TTL) is rebuilt from live Bitrix; the dropdowns in the field mapping form repopulate.
4. Open a mapping row and click Save — Filament's push-time validation will now accept the newly-exposed field names.

**Cache backing:** Bitrix schema is cached per-entity-type (deal / contact / company) in the `bitrix_schema_cache` table. The TTL is `config('bitrix.schema_cache_ttl_hours')` (default 24). The refresh command force-refreshes without waiting for the TTL to expire.

**Verification:** after a refresh, inspect:
```
SELECT entity_type, field_count, refreshed_at FROM bitrix_schema_cache ORDER BY refreshed_at DESC LIMIT 3;
```
`refreshed_at` should be within the last minute.

---

## Section 4 — How to interpret the Notification Centre (CUT-06.4)

**URL:** `/admin/notifications` — four tabs, Livewire-polled every `config('dashboard.widget_poll_seconds')` seconds (default 60).

### Tab 1: Failed jobs (7-day window)

**Source:** Horizon's `failed_jobs` table filtered to the last 7 days, PLUS the Phase 1 `ThrottledFailedJobNotifier` dedup log (5-minute windows).

**Quick action:** Click **Retry** → dispatches `horizon:retry {uuid}` (admin-only).

**Rule of thumb:**
- Transient failure (rate-limit, network hiccup) → retry immediately.
- Persistent failure (bad payload, missing config, unknown field) → investigate root cause first; retry alone won't fix it.

### Tab 2: Stale feeds (competitor CSVs not seen in >48h)

**Source:** `competitor_prices` latest-per-competitor row + `config('competitor.stale_feed_hours')` (default 48).

**Quick action:** Click **Re-ingest** → dispatches `IngestCompetitorCsvJob` for the stale competitor's most recent CSV (admin + pricing_manager).

**Rule of thumb:**
- n8n dropped a new CSV but watcher missed it → re-ingest clears the backlog.
- CSV itself is missing (n8n workflow broken) → fix n8n first, then re-ingest.
- MAP-policy competitor (intentionally infrequent) → mark the competitor `receives_stale_feed_alerts = false` in admin to suppress.

### Tab 3: Pending suggestions (grouped by kind)

**Source:** `suggestions` where `status = pending`, grouped by `kind` with count + oldest-age badge.

**Quick action:** Click **View** → jumps to the Suggestions inbox pre-filtered by that kind.

**Kind cheat sheet:**
- `margin_change` (Phase 5) — competitor price shifted; review delta; approve to update the matching `PricingRule`.
- `new_product_opportunity` (Phase 5/6) — competitor has a SKU we don't sell; review supplier feed to see if it's available.
- `crm_push_failed` (Phase 4) — see Section 2.
- `auto_create_failed` (Phase 6) — `CreateWooProductJob` exhausted retries; review error + click Replay.

### Tab 4: Webhook DLQ (failed inbound webhook processing)

**Source:** `integration_events` where `direction = inbound` AND `provider IN (woo, bitrix)` AND `status = failed` (7-day window), OR `webhook_receipts` where downstream listener failed.

**Quick action:** Click the `correlation_id` to open the full `integration_events` log for that webhook delivery.

**Rule of thumb:**
- HMAC mismatch → check Phase 1 webhook secret env vars didn't drift (`WOO_WEBHOOK_SECRET`, `BITRIX_WEBHOOK_SECRET`). Ensure your Woo site is signing with the matching secret.
- Missing Woo order → check `webhook_receipts` for the raw payload; if payload looks valid but processing failed, use Suggestion → Replay to re-process.
- Stale Bitrix deal → run `bitrix:schema:refresh` (Section 3).

---

## Appendix A — D-19 Cutover Sequence (authoritative)

Execute in exactly this order. Each step up to step 5 is recoverable; step 6 flips the source of truth.

| Step | Command | Expected outcome | Gate |
|------|---------|-------------------|------|
| 1 | `php artisan cutover:snapshot-woo-db --label=pre-cutover` | `woo-db-backup-{YYYY-MM-DD-HHMMSS}-pre-cutover.sql.gz` in `storage/app/cutover/backups/` + audit_log entry | `WOO_DB_*` env vars set (see Appendix B) |
| 2 | `php artisan cutover:divergence-scan --live` | `sync_diffs` rows with `provider='divergence-scan'`; parity % visible on Home Dashboard → `SyncDiffsParityWidget` | `WOO_BASE_URL` + `WOO_CONSUMER_KEY` + `WOO_CONSUMER_SECRET` live |
| 3 | `php artisan cutover:populate-overrides --live` | `product_overrides` rows with `pin_*=true` for every divergent field (merge-never-clear-pins per D-15) + audit_log | Step 2 must have persisted rows |
| 4 | **STAGING ONLY:** `CUTOVER_DRILL_ALLOWED=true php artisan cutover:drill-rollback --live` | 5-step drill completes; report at `storage/app/cutover/drill-report-{YYYY-MM-DD}.md` | `CUTOVER_DRILL_ALLOWED=true` env var |
| 5 | `CUTOVER_DISABLE_LIVE_ALLOWED=true php artisan cutover:disable-legacy-plugins --live` | WP crons deregistered + plugins deactivated; audit_log records outcome per command (including `manual_required` if WP-CLI-over-REST unavailable) | `CUTOVER_DISABLE_LIVE_ALLOWED=true` + interactive confirmation |
| 6 | **Ops edit production .env:** set `WOO_WRITE_ENABLED=true`, then `php artisan config:clear` | First Laravel Woo write succeeds (verify via `sync:supplier --live` and Filament **Sync Runs**) | Step 5 complete |
| 7 | Monitor for 7 days — `php artisan cutover:checklist` stays green daily | Parity threshold ≥99% over rolling 7-day window; no rollback needed | Parity widget green 7 days consecutive |

**Go/no-go signal per step:** `php artisan cutover:checklist` exits 0 ⇒ safe to proceed to the next step. Exit 1 ⇒ resolve pending items first. See also the `--update-status` sub-command for manual gate ticks (e.g. `--update-status=woo-sandbox-validated:pass`).

**Rehearsal requirement:** before executing step 5 in production, at minimum steps 1-4 must have been rehearsed against a staging clone. The `CUTOVER_DRILL_ALLOWED=true` env var is the safety belt — it is only ever set on staging.

**Phase 6 carry-forward gates (D-20):**
1. `supplier-probe` — run `php artisan supplier:probe-single-sku <live-sku>` with LIVE 21stcav.com credentials (not the Phase 6 synthesized offline fallback). The `cutover:checklist` command auto-detects `__synthesized=true` in `storage/app/research/supplier-probe.json` and marks the gate PENDING until a fresh live probe overwrites it.
2. `woo-sandbox-validated` — manual POST to `/wp-json/wc/v3/products` with `images[]` payload against a live Woo sandbox; confirms the URL-pass-through behaviour before flipping `config('product_auto_create.mode')` from `draft` to `immediate_publish`. Manual gate — tick via `cutover:checklist --update-status=woo-sandbox-validated:pass`.
3. `feature-suite` — `vendor/bin/pest` against MySQL-online `meetingstore_ops_testing` must be green; gate reads `dashboard_snapshots.feature_suite_last_run` written by the verifier.

All 3 gates MUST be PASS / MANUAL-accepted before step 5 starts.

---

## Appendix B — Environment Variable Inventory

### Feature flags (cutover gates)

| Variable | Default | Purpose |
|----------|---------|---------|
| `WOO_WRITE_ENABLED` | `false` | Phase 1 FOUND-08 shadow-mode flag. Flip to `true` in step 6 of D-19 sequence. While `false`, all Woo writes land in `sync_diffs` only. |
| `CRM_WRITE_ENABLED` | `false` | Phase 4 CRM shadow-mode flag. Flip independently if CRM cutover precedes Woo cutover. |
| `CUTOVER_DRILL_ALLOWED` | `false` | Required for `cutover:drill-rollback --live`. **STAGING ONLY.** |
| `CUTOVER_DISABLE_LIVE_ALLOWED` | `false` | Required for `cutover:disable-legacy-plugins --live`. Production-safe; double-gated with interactive confirmation. |
| `CUTOVER_DIVERGENCE_SCAN_SCHEDULE_ENABLED` | `false` | Enables the daily 01:00 London divergence-scan schedule entry in `routes/console.php`. Ops turns ON at start of parallel-run window; OFF after 7-day monitoring completes. |

### Dashboard tunables (Plan 07-01 `config/dashboard.php`)

| Variable | Default | Purpose |
|----------|---------|---------|
| `DASHBOARD_SNAPSHOT_TTL_MINUTES` | 15 | Widget stale-amber border trigger |
| `DASHBOARD_WIDGET_POLL_SECONDS` | 60 | Livewire `wire:poll` frequency |
| `DASHBOARD_REFRESH_INTERVAL_MINUTES` | 5 | Scheduled `dashboard:refresh` cadence |
| `DASHBOARD_SNAPSHOT_RETENTION_DAYS` | 30 | `snapshots:prune` retention (for future sparkline widgets) |
| `DASHBOARD_CSV_EXPORT_HARD_CAP` | 100000 | Above this, export hard-fails with user notification |
| `DASHBOARD_CSV_EXPORT_QUEUE_THRESHOLD` | 10000 | Above this, user is prompted to queue-to-email instead of streaming inline |
| `DASHBOARD_GLOBAL_SEARCH_DEBOUNCE_MS` | 300 | Filament 3 header search debounce |

### Cutover tunables (Plan 07-01 `config/cutover.php`)

| Variable | Default | Purpose |
|----------|---------|---------|
| `CUTOVER_PARITY_THRESHOLD_PERCENT` | 99 | Below this, SyncDiffsParityWidget turns red + checklist gate fails |
| `CUTOVER_PARITY_WINDOW_DAYS` | 7 | Rolling window for parity evaluation |
| (hardcoded) | `storage/app/cutover/backups` | `CUT-04` mysqldump output path |
| (hardcoded) | `storage/app/cutover` | `CUT-05` drill report markdown output path |

### External service credentials (pre-existing)

| Variable group | Purpose |
|----------------|---------|
| `WOO_BASE_URL`, `WOO_CONSUMER_KEY`, `WOO_CONSUMER_SECRET` | Phase 2 Plan 02 WooClient — REST auth |
| `WOO_WEBHOOK_SECRET` | Phase 1 — HMAC verification on inbound Woo webhooks |
| `SUPPLIER_API_URL`, `SUPPLIER_JWT_USER`, `SUPPLIER_JWT_PASS` | Phase 2 Plan 02 SupplierClient — JWT auth |
| `BITRIX_WEBHOOK_URL`, `BITRIX_CLIENT_ID`, `BITRIX_CLIENT_SECRET` | Phase 4 Plan 01 BitrixClient |
| `WOO_DB_HOST`, `WOO_DB_USERNAME`, `WOO_DB_PASSWORD`, `WOO_DB_DATABASE` | Plan 07-05 — `cutover:snapshot-woo-db` mysqldump access |
| `MAIL_*` | Laravel mail driver — used by weekly digest + QueuedCsvExportMail + StaleFeedNotification + ThrottledFailedJobNotifier |
| `HORIZON_*` | Horizon dashboard auth (admin-role gate in AppServiceProvider) |
| `AI_*`, `CLAUDE_*`, `OPENAI_*` | Not used by Phase 7 (carried from root CLAUDE.md / Phase 1 scaffolding if ever extended) |

**Security note:** this runbook lists variable NAMES only, never values. Production values live in the production server's `.env` which is NOT committed to the repo.

---

## Appendix C — Rollback Runbook

**Objective:** Revert Laravel's authoritative-writer role to the legacy plugins.

**Trigger:** parity % drops below `config('cutover.parity_threshold_percent')` for > 4 consecutive hours, OR a critical data-integrity incident is detected.

### Rollback steps (reverse of D-19 steps 5-6)

1. Edit production `.env`: `WOO_WRITE_ENABLED=false`, `CRM_WRITE_ENABLED=false`.
2. SSH to the VPS and run `php artisan config:clear`.
3. Laravel immediately stops writing to Woo + Bitrix; all subsequent writes land in `sync_diffs` only (shadow mode).
4. Re-enable legacy plugin crons in WordPress admin:
   - `wp cron event schedule stock_updater_daily_sync --every-30-minutes` (or the cadence that was active before disable)
   - `wp plugin activate stock-updater`
   - `wp plugin activate woocommerce-bitrix24-integration`
   - (The exact cron schedules that were deregistered are logged in the `audit_log` row from `cutover:disable-legacy-plugins --live`.)
5. If Laravel mutated Woo DB in a way that must be undone → restore from the pre-cutover snapshot:
   ```
   gunzip < storage/app/cutover/backups/woo-db-backup-{YYYY-MM-DD-HHmmss}-pre-cutover.sql.gz \
     | mysql -h {WOO_DB_HOST} -u {WOO_DB_USERNAME} -p{WOO_DB_PASSWORD} {WOO_DB_DATABASE}
   ```
   **WARNING** — this wipes every Woo change since the snapshot. Only restore if the divergence is catastrophic; otherwise prefer manual targeted fixes via WP admin.
6. Log the rollback as an audit_log entry:
   ```
   php artisan tinker
   >>> App\Foundation\Audit\Services\Auditor::record('cutover.manual_rollback', ['reason' => 'parity_drop', 'snapshot_restored' => true, 'operator' => 'ops@meetingstore.co.uk']);
   ```
7. Open a GSD phase for post-mortem (`/gsd-phase` → "rollback post-mortem").

### Drill this against staging (CUT-05)

Before the live cutover, steps 1-6 MUST have been rehearsed against a staging clone:

```
CUTOVER_DRILL_ALLOWED=true php artisan cutover:drill-rollback --live
```

The drill walks the 5-step playbook in verbose mode + writes `storage/app/cutover/drill-report-{date}.md`. Review the report for any gaps (missing env vars, unreadable backup paths, unavailable legacy plugins) and update THIS runbook + the command's checklist accordingly before the production cutover.

---

## Appendix D — Troubleshooting FAQ

**Q: Horizon supervisors are red and no jobs are running.**
A: SSH to the VPS and check `supervisord` / systemd status. Restart with `php artisan horizon:terminate` (graceful) then re-launch via the service manager. Confirm Redis is up (`redis-cli ping` returns PONG). If Horizon doesn't restart cleanly, check `storage/logs/laravel.log` for boot errors.

**Q: Divergence-scan reports `parity=null` on Home Dashboard.**
A: Either the scan has never run yet, or the `products` table is empty. Run `php artisan cutover:divergence-scan --live` manually and confirm `sync_diffs` rows were written. The `SyncDiffsParityWidget` will flip to green/amber/red on the next dashboard refresh (5-minute cadence).

**Q: `populate-overrides` created zero rows.**
A: Either (a) the divergence-scan produced no diffs (ideal — Woo is fully in sync with Laravel; nothing to pin), OR (b) the scan hasn't persisted data (you passed `--dry-run` or no `--live`). Check:
```
SELECT COUNT(*) FROM sync_diffs WHERE provider = 'divergence-scan';
```

**Q: Weekly digest didn't arrive this Monday.**
A: Check in order:
1. `SELECT COUNT(*) FROM alert_recipients WHERE receives_weekly_digest = 1;` returns > 0.
2. `php artisan schedule:list | grep reports:weekly-digest` shows the scheduled entry.
3. `SELECT * FROM dashboard_snapshots WHERE metric_key = 'weekly_report_status' ORDER BY computed_at DESC LIMIT 1;` — `last_sent_at` should be recent.
4. The Laravel scheduler is actually running (crontab: `* * * * * cd {project} && php artisan schedule:run >> /dev/null 2>&1`).

The `WeeklyReportStatusWidget` on Home Dashboard surfaces (3).

**Q: Bitrix push fails with "unknown field UF_CRM_..."**
A: Run `php artisan bitrix:schema:refresh` — the 24h schema cache has drifted from Bitrix reality. Re-save the field mapping in Filament → **CRM Field Mappings**.

**Q: How do I see what Woo write Laravel WOULD have made (shadow mode)?**
A: Query `sync_diffs` where `provider = 'woo'` (pre-cutover). These are the diff rows written while `WOO_WRITE_ENABLED=false`. Each row's `payload` JSON contains the exact PUT/POST body Laravel would have sent.

**Q: Can I flip `WOO_WRITE_ENABLED=true` before the parity threshold clears?**
A: Technically yes; operationally NO. The `cutover:checklist` command refuses (exit 1) until the `parity-threshold` gate is PASS. Overriding this risks overwriting operator edits in Woo admin with Laravel's computed values — the `pin_*` columns on `ProductOverride` are the only escape hatch; see CUT-02 populate-overrides semantics.

**Q: An operator manually edited a Woo product title after the flag was flipped — will the next sync overwrite it?**
A: No, IF the title's corresponding pin is set. Run `cutover:divergence-scan --live` to detect; it will create a `sync_diffs` row. Then either (a) operator accepts Laravel's value (no pin needed — next sync reconciles), or (b) operator wants the manual edit to persist (run `cutover:populate-overrides --live` to auto-pin, OR manually toggle `pin_title=true` via Filament → Product → Field Pins tab).

**Q: Divergence scan hit a Woo 500 mid-run.**
A: The scan is idempotent — re-run `cutover:divergence-scan --live`. Per-SKU failures are logged to `integration_events` with `status=failed`; the scan skips those SKUs on the next run's retry unless you fix the underlying Woo issue.

**Q: `cutover:checklist` says `feature-suite` is PENDING but I ran the suite yesterday.**
A: The gate reads `dashboard_snapshots` keyed on `feature_suite_last_run`. The verifier (or an ops-run pest suite) must write that row:
```
App\Domain\Dashboard\Models\DashboardSnapshot::upsertByKey('feature_suite_last_run', ['status' => 'pass', 'timestamp' => now()->toIso8601String()]);
```
This happens automatically when the Plan 07-06 verifier runs `vendor/bin/pest` in a green state.

---

## Appendix E — Horizon Primer

**Horizon** is the Laravel queue dashboard at `/horizon`. Admin-only (gated via the `Horizon::auth` closure in the Phase 1 AppServiceProvider).

### What you see
- **Dashboard:** running / waiting / failed / delayed job counts per queue
- **Metrics:** throughput + runtime per queue and per job class (24h rolling)
- **Failed jobs:** searchable list; retry individually or in bulk; view full exception + payload
- **Pending:** backlog count per queue with ETA estimates
- **Completed:** recent history with per-job runtime

### Queues by workload (Phase 1 FOUND-09 — 7 supervisors)

| Queue | Typical workload |
|-------|------------------|
| `critical` | Webhook-dispatched jobs that must run within seconds (HMAC-verified inbound events) |
| `sync-woo-push` | Per-SKU Woo writes during supplier sync (rate-sensitive) |
| `sync-bulk` | Bulk recompute batches, dashboard refresh, CSV exports, auto-create image processing, weekly digest |
| `crm-bitrix` | Bitrix push jobs — rate-limited to 2 req/sec with 429 backoff |
| `competitor-csv` | n8n-dropped CSV ingest + margin-analyser batches |
| `webhook-inbound` | Webhook handler jobs (after HMAC verification + dedup) |
| `default` | Listener fallbacks + unclassified work |

### Operational rules of thumb

- **Failed-job floor:** 0 per 24h is the target. More than 5 in a rolling 5-min window triggers `ThrottledFailedJobNotifier` → AlertRecipient email.
- **Backlog floor:** `sync-bulk` regularly accumulates during weekly digest / divergence scan; acceptable up to ~200 pending. `crm-bitrix` should stay below 50 (2 req/sec × 25 sec).
- **Stuck jobs:** if a job stays in "running" > its `$timeout`, it's being terminated but Horizon may not have noticed yet. Use `php artisan horizon:purge` to clean up.

### If Horizon is offline

Queued jobs still accumulate in the `jobs` table but don't execute. The Home Dashboard → `HorizonFailedJobsWidget` will count the last 5-min window from Horizon's log; absence of Horizon means absence of that widget data too. Bring Horizon back:
```
php artisan horizon:terminate
php artisan horizon
```
(or via systemd / supervisord — depends on deployment).

---

## Revision History

| Date | Plan | Change |
|------|------|--------|
| 2026-04-24 | 07-06 | Initial version — v1 cutover milestone |

---

*Version-committed to this repo at `docs/ops/cutover-handover.md`. Update via pull request. For urgent ops issues outside this runbook, escalate to the engineering on-call.*
