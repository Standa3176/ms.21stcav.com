---
phase: 07-dashboard-polish-cutover
plan: 05-cutover-commands
subsystem: cutover
tags: [artisan-commands, cutover-runbook, env-gated-live, divergence-scan, override-populator, mysql-snapshot, rollback-drill, wp-cli-over-rest, deptrac-wpdirectdb-ban, d-12, d-14, d-15, d-16, d-17, d-18, d-19, d-20, d-21]

requires:
  - phase: 07-01
    provides: "config/cutover.php 9 keys (parity_threshold_percent, parity_window_days, 3 env-var NAMES, 2 storage paths, 2 legacy plugins, 3 legacy cron hooks); DashboardSnapshot model + upsertByKey()"
  - phase: 07-02
    provides: "SnapshotAggregator computeSyncDiffsParity reads sync_diffs provider='divergence-scan' rows — this plan's DivergenceScanner is the ONLY writer of those rows"
  - phase: 01-03
    provides: "App\\Console\\Commands\\BaseCommand (correlation_id threading via Context + LogBatch); App\\Foundation\\Audit\\Services\\Auditor"
  - phase: 01-04
    provides: "sync_diffs table (id, provider, channel, method, endpoint, woo_id, payload JSON, correlation_id, created_at, applied_at, status)"
  - phase: 02-02
    provides: "App\\Domain\\Sync\\Services\\WooClient::get() — read path with IntegrationLogger threading"
  - phase: 02-05
    provides: "SYNC-04 / Deptrac WpDirectDb ban — no direct DB facade to WP database"
  - phase: 03-01
    provides: "PriceCalculator::stripVat + compute (reserved for future price-delta divergence; not yet consumed here — Woo returns price gross and we compare as floats)"
  - phase: 04-01
    provides: "sync_diffs.provider column for multi-provider shadow (reused as divergence-scan channel)"
  - phase: 06-01
    provides: "ProductOverride 8 pin_* columns + D-10 pin semantics"
  - phase: 06-04
    provides: "Phase 6 D-20 carry-forward gate identifiers (supplier-probe, woo-sandbox, feature-suite)"

provides:
  - "6 artisan commands under app/Console/Commands/Cutover/ (divergence-scan, populate-overrides, snapshot-woo-db, drill-rollback, disable-legacy-plugins, checklist) — all extending App\\Console\\Commands\\BaseCommand"
  - "6 Domain services under app/Domain/Cutover/Services/ (WooFieldComparator, DivergenceScanner, OverridePopulator, WooDbSnapshotter, RollbackDrill, LegacyPluginDisabler, CutoverChecklistReporter — 7 services actually)"
  - "sync_diffs rows with provider='divergence-scan' — field/laravel/live/pin_column/product_id encoded in payload JSON (no schema migration required — shape fits existing columns)"
  - "dashboard_snapshots.sync_diffs_parity refreshed by cutover:divergence-scan --live with source='cutover:divergence-scan' discriminator"
  - "D-15 merge-never-clear-pins semantics in OverridePopulator: existing pin=true stays true regardless of scan result; pin=false flips to true only when scan detects divergence"
  - "D-17 env-gated --live on cutover:drill-rollback (CUTOVER_DRILL_ALLOWED=true required)"
  - "D-18 double-gated --live on cutover:disable-legacy-plugins (CUTOVER_DISABLE_LIVE_ALLOWED=true + interactive confirmation)"
  - "D-19 runbook sequence codified in CutoverChecklistReporter::gates() (snapshot → scan → populate → drill → disable → flag → monitor)"
  - "D-20 carry-forward gate integration (supplier-probe __synthesized detection, woo-sandbox MANUAL, feature-suite dashboard_snapshots-driven)"
  - "D-21 --update-status sub-command on cutover:checklist writing storage/app/cutover/checklist-state.json"
  - "CUTOVER_DIVERGENCE_SCAN_SCHEDULE_ENABLED opt-in schedule entry (daily 01:00 Europe/London, sync-bulk-queue-routable via the base schedule)"
  - "3 Pest feature test files under tests/Feature/Cutover/ with 40 cases total (16 divergence-scan/populate + 14 snapshot/drill/disable + 10 checklist)"

affects:
  - "07-06-handover-deptrac-verification — will (a) register the Cutover Deptrac layer in depfile.yaml + deptrac.yaml (deferred per planner permission), (b) author docs/ops/cutover-handover.md covering the exact 6-command sequence, (c) run the MySQL-deferred Pest feature backlog to close the 07-01/02/03/04/05 execution gap"

tech-stack:
  added:
    - "app/Domain/Cutover/ — new domain directory (PSR-4 autoloaded via composer.json)"
    - "app/Console/Commands/Cutover/ — artisan command namespace for the 6 cutover commands"
    - "tests/Feature/Cutover/ — Pest feature test namespace for the 3 feature test files"
  patterns:
    - "Dry-run default + --live opt-in (Phase 2 D-04 convention) on all 4 destructive-adjacent commands (divergence-scan, populate-overrides, drill-rollback, disable-legacy-plugins)"
    - "Two-step env-gate on --live: config key stores the env var NAME (cutover.drill_allowed_env_var='CUTOVER_DRILL_ALLOWED') so ops setting the env alone doesn't auto-approve; the command must explicitly read the config key (Plan 07-01 pattern)"
    - "sync_diffs payload-JSON encoding for per-field divergence: no schema migration needed because the existing payload JSON column accepts {product_id, sku, field, laravel, live, pin_column}"
    - "SyncDiff correlation_id threads one UUID per scan across every diff row — OverridePopulator groups by `latest('created_at') correlation_id` to isolate the most recent scan"
    - "Static-scan regression guard for WpDirectDb ban: DL4 test greps LegacyPluginDisabler.php for DB:: facade literals — fails the build if any appear (SYNC-04 Deptrac complement)"
    - "WP-CLI over REST with documented fallback: LegacyPluginDisabler attempts POST wp-cli-command, expects 404 in practice, records 'manual_required' for ops to run over SSH"

key-files:
  created:
    - "app/Domain/Cutover/Services/WooFieldComparator.php"
    - "app/Domain/Cutover/Services/DivergenceScanner.php"
    - "app/Domain/Cutover/Services/OverridePopulator.php"
    - "app/Domain/Cutover/Services/WooDbSnapshotter.php"
    - "app/Domain/Cutover/Services/RollbackDrill.php"
    - "app/Domain/Cutover/Services/LegacyPluginDisabler.php"
    - "app/Domain/Cutover/Services/CutoverChecklistReporter.php"
    - "app/Console/Commands/Cutover/DivergenceScanCommand.php"
    - "app/Console/Commands/Cutover/PopulateOverridesCommand.php"
    - "app/Console/Commands/Cutover/SnapshotWooDbCommand.php"
    - "app/Console/Commands/Cutover/DrillRollbackCommand.php"
    - "app/Console/Commands/Cutover/DisableLegacyPluginsCommand.php"
    - "app/Console/Commands/Cutover/CutoverChecklistCommand.php"
    - "tests/Feature/Cutover/DivergenceScanCommandTest.php"
    - "tests/Feature/Cutover/PopulateOverridesCommandTest.php"
    - "tests/Feature/Cutover/SnapshotWooDbCommandTest.php"
    - "tests/Feature/Cutover/DrillRollbackCommandTest.php"
    - "tests/Feature/Cutover/DisableLegacyPluginsCommandTest.php"
    - "tests/Feature/Cutover/CutoverChecklistCommandTest.php"
  modified:
    - "app/Providers/AppServiceProvider.php (+6 cutover commands registered in runningInConsole commands() block)"
    - "routes/console.php (+ opt-in schedule entry for cutover:divergence-scan daily 01:00 London, gated by CUTOVER_DIVERGENCE_SCAN_SCHEDULE_ENABLED env)"

decisions:
  - "BaseCommand is at App\\Console\\Commands\\BaseCommand (Phase 1 Plan 03), NOT App\\Foundation\\Console\\BaseCommand as the plan sketch referenced. Same class, different namespace — all 6 cutover commands extend the actual Phase 1 class so correlation_id threading + LogBatch semantics carry over unchanged."
  - "WooClient is at App\\Domain\\Sync\\Services\\WooClient (Phase 2 Plan 02), NOT App\\Domain\\Sync\\Clients\\WooClient as the plan sketch referenced. DivergenceScanner + LegacyPluginDisabler typehint the actual class."
  - "sync_diffs schema does not have field/laravel_value/live_value/product_id/detected_at columns — those are encoded in the existing payload JSON column instead. No new migration needed. The ROADMAP + Plan 07-02 SnapshotAggregator already assume this shape (07-02 counts rows by provider+created_at without inspecting payload)."
  - "Product model uses decimal-cast sell_price (not integer pennies) and long_description (not `description`) + image_url (not `primary_image_url`). WooFieldComparator bridges: compares sell_price as float with 0.005 tolerance (half-penny); maps long_description ↔ woo['description']; maps image_url ↔ woo['images'][0]['src']."
  - "ProductOverride has no variant_id column in Phase 6 Plan 01 — D-09 documented parent-only granularity. OverridePopulator keys ProductOverride lookups on product_id alone."
  - "Deptrac Cutover-layer registration DEFERRED to Plan 07-06 per the plan's explicit planner-permission clause. The services currently sit in the Uncovered bucket; Plan 07-06 will add a 'Cutover' layer with allow-list [Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Competitor, CRM, ProductAutoCreate, Dashboard] and ensure dual-config-sync across depfile.yaml + deptrac.yaml (Phase 5 Plan 05-05 lesson)."
  - "routes/console.php schedule entry is OPT-IN via env CUTOVER_DIVERGENCE_SCAN_SCHEDULE_ENABLED. Ops enables during the parallel-run window and disables after the 7-day monitoring window closes. This mirrors Phase 2 Plan 03's commented-out sync:supplier --live schedule — the kill-switch lives in env/code, not a DB flag."
  - "LegacyPluginDisabler does NOT use the DB:: facade anywhere. The class-level comment header documents the WpDirectDb regression guard (Test DL4 greps the file for literal 'DB::' and 'Illuminate\\\\Support\\\\Facades\\\\DB'). Originally the comment header contained those literal tokens as a documentation warning; the static-scan test caught this as a false positive and the comments were rephrased to describe the ban without using the banned literals (classic 'include the forbidden string in a comment' pitfall)."
  - "CutoverChecklistReporter gates the D-20 feature-suite check via a dashboard_snapshots.feature_suite_last_run row. Plan 07-06 (or the verifier) must write this row after a full-suite pest run with {status: pass|fail, timestamp: ISO8601}. The aggregator pattern is future-compatible — SnapshotAggregator could add a computeFeatureSuiteStatus method later without changing this reporter."
  - "Checklist exit semantics: SUCCESS only when every gate is PASS or MANUAL; otherwise FAILURE (exit 1). MANUAL doesn't count as failure because it's a state ops explicitly cannot automate (woo-sandbox validation). This means a checklist with all 3 MANUAL gates and 10 PASS gates still exits 0 — correctness preserved while acknowledging human-only validation."

metrics:
  completed_at: "2026-04-24T10:02Z"
  duration_minutes: 19
  tasks_completed: 3
  files_created: 19
  files_modified: 2
  commits: 3
  commands_shipped: 6
  services_shipped: 7
  pest_cases_authored: 40
  deptrac_cutover_violations: 0
  deptrac_layer_deferred_to: "07-06"

requirements:
  - CUT-01 (divergence scan + parity threshold + dashboard widget feed)
  - CUT-02 (ProductOverride populator with D-15 merge-never-clear-pins)
  - CUT-03 (legacy-plugin cron deregistration)
  - CUT-04 (Woo-DB mysqldump snapshot)
  - CUT-05 (rollback drill with env-gated --live)
  - CUT-07 (legacy plugin deactivation via WP-CLI)
---

# Phase 07 Plan 05: Cutover Commands — Summary

The core of the v1 cutover is in place. Six artisan commands under `cutover:*` orchestrate the legacy-plugin → Laravel migration end-to-end: `cutover:snapshot-woo-db` takes the pre-cutover DB safety net, `cutover:divergence-scan` establishes parity baseline + feeds the dashboard widget, `cutover:populate-overrides` preserves human edits via D-15 merge-never-clear-pins semantics, `cutover:drill-rollback` simulates the 5-step rollback playbook (env-gated live on staging only), `cutover:disable-legacy-plugins` deregisters the Stock Updater + itgalaxy Bitrix24 crons and deactivates both WP plugins (via WP-CLI over REST with documented fallback), and `cutover:checklist` is the single PASS/PENDING/FAIL go/no-go signal integrating Phase 6 D-20 carry-forward gates (supplier probe / Woo sandbox / Feature suite) with the D-19 runbook sequence.

## Accomplishments

### The 6 commands at a glance

| Command                              | CUT       | Mode gate                        | Writes to                                                  |
| ------------------------------------ | --------- | -------------------------------- | ---------------------------------------------------------- |
| `cutover:snapshot-woo-db --label=X`  | CUT-04    | `--label` required               | `storage/app/cutover/backups/*.sql.gz` + audit_log         |
| `cutover:divergence-scan [--live]`   | CUT-01    | dry-run default                  | `sync_diffs` (provider='divergence-scan') + `dashboard_snapshots.sync_diffs_parity` |
| `cutover:populate-overrides [--live]`| CUT-02    | dry-run default                  | `product_overrides` pin_* columns (merge-never-clear)       |
| `cutover:drill-rollback [--live]`    | CUT-05    | `--live` → CUTOVER_DRILL_ALLOWED | `storage/app/cutover/drill-report-{date}.md` + audit_log   |
| `cutover:disable-legacy-plugins [--live]` | CUT-03+07 | `--live` → CUTOVER_DISABLE_LIVE_ALLOWED + confirm() | audit_log + (attempted) WP-CLI-over-REST calls    |
| `cutover:checklist [--update-status]`| D-21      | exit 1 if any item PENDING/FAIL  | `storage/app/cutover/checklist-state.json`                 |

### CUT-01 — divergence scan (Task 1)

`DivergenceScanner` iterates every `Product` via `cursor()`, calls `WooClient::get('products', ['sku' => $sku])`, and hands the pair (local row, live woo dict) to `WooFieldComparator::diff()` which emits a per-field diff list. With `--live`, each field divergence becomes one `SyncDiff` row (`provider='divergence-scan'`, `correlation_id=<uuid>`, `payload={product_id,sku,field,laravel,live,pin_column}`). After the scan finishes, a single `DashboardSnapshot::upsertByKey('sync_diffs_parity', …)` write makes Plan 07-02's `SyncDiffsParityWidget` reflect the fresh parity percentage — the widget's traffic-light derives the colour from `parity_percent >= cutover.parity_threshold_percent`.

Comparator covers 7 fields: `name` → `pin_title`, `slug` → `pin_slug`, `short_description` → `pin_short_description`, `long_description` (mapped from woo's `description`) → `pin_long_description`, `sell_price` (float compare with half-penny tolerance, no pin column), `image_url` (from `woo.images[0].src`) → `pin_image`, and an `exists` meta-field when Woo returns `[]` for a Laravel SKU. Variable-product parents (`sku=null`) are skipped — children carry SKUs.

### CUT-02 — override populator with D-15 merge semantics (Task 1)

`OverridePopulator::populateFromScan($persist)` reads the most recent divergence-scan `correlation_id` and groups rows by `payload.product_id`. For each product it collects the set of `pin_column` targets from the scan's field diffs (skipping non-pinnable fields like `sell_price`). On `--live`, the logic is strictly additive:

- **No override exists:** create one with the scanned pin set.
- **Override exists, pin currently false, scan saw divergence:** flip to true.
- **Override exists, pin currently true:** LEAVE TRUE regardless of scan result.

The last case is the load-bearing D-15 guarantee — an ops operator who has manually set `pin_title=true` will never see that pin cleared by a subsequent divergence scan that happens to find no title diff (e.g. because someone restored the Woo title to match Laravel in the meantime). The regression test `it('merge semantics NEVER clear an existing pin')` seeds exactly this scenario and asserts `pin_title` survives.

Every merge + create writes an audit_log entry with `actor='cutover-populate-overrides-command'` plus the product id and the pins that were flipped.

### CUT-03 + CUT-07 — legacy plugin disabler (Task 2)

`LegacyPluginDisabler::run()` emits the exact WP-CLI command list based on `config('cutover.legacy_cron_hooks')` (3 hooks) + `config('cutover.legacy_plugins')` (2 slugs):

```
wp cron event delete 'stock_updater_daily_sync'
wp cron event delete 'itgalaxy_bitrix24_send'
wp cron event delete 'itgalaxy_bitrix24_status'
wp plugin deactivate 'stock-updater'
wp plugin deactivate 'woocommerce-bitrix24-integration'
```

Dry-run returns the list for ops review. Live attempts `WooClient::get('wp-cli-command', …)` which will 404 on the majority of WP installs (the wp-cli REST plugin isn't installed by default) — that's the expected path: each failed command is recorded as `'manual_required'` in the audit_log + ops run it over SSH. The key property: **zero `DB::` facade calls in the file** — test DL4 static-scans `LegacyPluginDisabler.php` and asserts no `DB::` / `Illuminate\Support\Facades\DB` literal appears, preserving the Phase 2 SYNC-04 WpDirectDb ban as a runtime regression guard alongside Deptrac's static layer boundary.

### CUT-04 — Woo-DB snapshotter (Task 2)

`WooDbSnapshotter::snapshot($label)` runs `mysqldump --single-transaction --skip-lock-tables | gzip >` against `WOO_DB_*` env credentials, producing `woo-db-backup-{YYYY-MM-DD-HHMMSS}-{safe-label}.sql.gz` under `config('cutover.backup_path')`. Every argument passes through `escapeshellarg` — the T-07-05-03 mitigation for password leakage via shell injection. `--label` is REQUIRED on the artisan command so no backup lands unlabelled (ops needs to distinguish pre-cutover / drill-run / post-cutover). Every success writes an audit_log row with `{filename, path, label, size_bytes}`.

### CUT-05 — rollback drill (Task 2)

`RollbackDrill::run($live)` executes the 5-step playbook deterministically in both modes:

1. Flag readable — verifies `env('WOO_WRITE_ENABLED')` is set (so the flip-back is possible).
2. Flag flip simulated — log-only in both modes (the `.env` change is out-of-band).
3. Backup verifiable — globs `config('cutover.backup_path')/woo-db-backup-*.sql.gz` and reports the latest.
4. Legacy cron check — MANUAL (ops verifies WP admin or `wp cron event list`).
5. Drill report written — emits markdown to `config('cutover.drill_report_path')/drill-report-{YYYY-MM-DD}.md`.

`--live` is gated by `env(config('cutover.drill_allowed_env_var'))` — `CUTOVER_DRILL_ALLOWED=true` in `.env` is required, otherwise the command exits 1 with `"Drill not allowed in this environment"`. Even `--live` is semantically STAGING-ONLY (ops sets the env var only on staging; production never has it) — no step actually touches the Woo DB.

### D-21 — the checklist (Task 3)

`CutoverChecklistReporter::gates()` returns 13 gates in a fixed order — 3 Phase 6 D-20 carry-forward items followed by 10 D-19 runbook steps. Each gate has an id, title, current status, and an action string telling ops what to do to move it to PASS. Auto-detection handles the objectively checkable ones:

- `supplier-probe` — reads `storage/app/research/supplier-probe.json`; PENDING if the file is absent or contains `__synthesized=true`; PASS otherwise.
- `feature-suite` — reads `dashboard_snapshots.feature_suite_last_run`; PASS if `status=pass`, FAIL if `status=fail`, PENDING otherwise.
- `woo-db-snapshot` — globs `config('cutover.backup_path')/*.sql.gz`; PASS if any exist.
- `divergence-scan` — PASS if `dashboard_snapshots.sync_diffs_parity` row exists.
- `parity-threshold` — FAIL if stored `parity_percent < config('cutover.parity_threshold_percent')`, PASS when ≥ threshold.
- `weekly-digest-landed` — reads `dashboard_snapshots.weekly_report_status.last_sent_at`.
- `handover-docs` — PASS if `docs/ops/cutover-handover.md` exists (Plan 07-06 writes it).

The remaining 6 gates are MANUAL / ops-driven — state comes from `storage/app/cutover/checklist-state.json`, written by `cutover:checklist --update-status=<id>:pass`. Exit code is **1 when any gate is not PASS or MANUAL** (green exit is the flag-flip go-signal — suitable for CI/CD wiring).

### Schedule — opt-in daily divergence scan

`routes/console.php` now contains a guarded entry:

```php
if ((bool) env('CUTOVER_DIVERGENCE_SCAN_SCHEDULE_ENABLED', false)) {
    Schedule::command('cutover:divergence-scan --live')
        ->dailyAt('01:00')->withoutOverlapping(120)
        ->onOneServer()->timezone('Europe/London');
}
```

Ops enables it at the start of the parallel-run window (D-19 step 7 monitoring), disables it after the 7-day window closes. Mirrors the Phase 2 commented-out `sync:supplier --live` pattern — kill-switch lives in env.

## Task Commits

1. **Task 1 — CUT-01 + CUT-02 (divergence scan + override populator)** — `e56d312`
   - WooFieldComparator + DivergenceScanner + OverridePopulator services
   - DivergenceScanCommand + PopulateOverridesCommand
   - AppServiceProvider registration (2 of 6); routes/console.php opt-in schedule
   - DivergenceScanCommandTest (9 cases) + PopulateOverridesCommandTest (7 cases)

2. **Task 2 — CUT-03 + CUT-04 + CUT-05 + CUT-07 (snapshot + drill + disable-legacy)** — `8484217`
   - WooDbSnapshotter + RollbackDrill + LegacyPluginDisabler services (zero `DB::` facade in LegacyPluginDisabler)
   - SnapshotWooDbCommand + DrillRollbackCommand + DisableLegacyPluginsCommand
   - AppServiceProvider adds 3 more command registrations
   - SnapshotWooDbCommandTest (5 cases) + DrillRollbackCommandTest (5 cases) + DisableLegacyPluginsCommandTest (5 cases)

3. **Task 3 — D-21 checklist integrating D-20 gates + D-19 runbook** — `ecae861`
   - CutoverChecklistReporter with 13 gates + auto-detection + --update-status persistence
   - CutoverChecklistCommand
   - AppServiceProvider completes the 6-command registration
   - CutoverChecklistCommandTest (10 cases)

## Deviations from Plan

### [Rule 3 — Blocking] BaseCommand namespace correction

- **Found during:** Task 1 DivergenceScanCommand authoring
- **Issue:** Plan sketch referenced `App\Foundation\Console\BaseCommand` — this namespace does not exist in the codebase. Phase 1 Plan 03 actually ships `App\Console\Commands\BaseCommand`.
- **Fix:** All 6 cutover commands extend the actual class. No behavioural difference — same correlation_id threading + LogBatch semantics.
- **Commit:** `e56d312` (Task 1 — first occurrence)

### [Rule 3 — Blocking] WooClient namespace correction

- **Found during:** Task 1 DivergenceScanner authoring
- **Issue:** Plan sketch referenced `App\Domain\Sync\Clients\WooClient` — this namespace does not exist. Phase 2 Plan 02 ships `App\Domain\Sync\Services\WooClient`.
- **Fix:** DivergenceScanner + LegacyPluginDisabler typehint the actual class. Same WP-REST `get()` signature.
- **Commit:** `e56d312`

### [Rule 3 — Blocking] sync_diffs schema does not have field/laravel_value/live_value/product_id columns

- **Found during:** Task 1 DivergenceScanner authoring
- **Issue:** Plan sketch wrote `SyncDiff::create(['product_id' => …, 'field' => …, 'laravel_value' => …, 'live_value' => …, 'detected_at' => …])`. The actual `sync_diffs` table (Phase 1 Plan 04 + Phase 4 Plan 01) has only: provider, channel, method, endpoint, woo_id, payload (JSON), correlation_id, created_at, applied_at, status. No per-field columns. Plan 07-02 SnapshotAggregator already counts divergence-scan rows by `provider + created_at` without inspecting payload shape, confirming the existing schema is the target.
- **Fix:** DivergenceScanner encodes `{product_id, sku, field, laravel, live, pin_column}` inside the existing `payload` JSON column; OverridePopulator reads from the same JSON structure. No migration required — the plan's success criterion ("sync_diffs rows with provider='divergence-scan'") is preserved.
- **Commit:** `e56d312`

### [Rule 3 — Blocking] Product/ProductOverride column-name corrections

- **Found during:** Task 1 WooFieldComparator authoring
- **Issue:** Plan sketch used `primary_image_url` (actual: `image_url`), `description` (actual: `long_description`), and integer-pennies `sell_price_pennies` (actual: decimal-cast `sell_price`). Plan sketch also included `variant_id` on ProductOverride — Phase 6 Plan 01 D-09 explicitly ships parent-only granularity (no variant_id column).
- **Fix:** Comparator uses the actual Product columns (name, slug, short_description, long_description, meta_description, sell_price, image_url) with a half-penny float tolerance on sell_price (`0.005`) instead of integer-pennies equality. OverridePopulator queries ProductOverride on `product_id` alone (no `whereNull('variant_id')`).
- **Commit:** `e56d312`

### [Rule 1 — Bug] LegacyPluginDisabler comment header contained the forbidden `DB::` literal

- **Found during:** Task 2 LegacyPluginDisabler test authoring (DL4 regression guard)
- **Issue:** The class-level comment header documenting the WpDirectDb ban originally contained the literal strings `"DB::"` and `"Illuminate\Support\Facades\DB"` as warning references. Test DL4 `file_get_contents` + `str_contains` would therefore false-positive on the very class it's guarding.
- **Fix:** Rewrote the comment header to describe the ban without using the banned literal tokens. The test now sees zero matches, as intended. The guidance to developers is preserved; only the literal token references were removed.
- **Commit:** `8484217`

### [Rule 2 — Missing Critical] Deptrac Cutover layer — deferred to Plan 07-06

- **Found during:** Post-Task-1 deptrac run
- **Issue:** Plan 07-05 flagged the Deptrac Cutover-layer registration as "planner's call; document in SUMMARY". Running deptrac post-Task-1 shows zero Cutover-related violations (the 18 reported violations are pre-existing from Plan 07-03's CsvExportWriter Concerns→Domain edges — out of scope for this plan).
- **Fix:** Explicitly deferred to Plan 07-06 per the planner's authorisation. Plan 07-06 will add a `Cutover` layer to both `depfile.yaml` + `deptrac.yaml` (dual-config-sync per Phase 5 Plan 05-05 lesson) with an allow-list matching the cross-domain reads (Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Competitor, CRM, ProductAutoCreate, Dashboard). No functional change required in this plan — the Cutover files currently sit in deptrac's Uncovered bucket, same as many framework-adjacent files.
- **Commit:** documented in this SUMMARY; no code change

---

**Total deviations:** 6 auto-fixed (4 blockers from plan-vs-reality namespace/schema drift, 1 comment-header bug, 1 missing-critical with planner-authorised defer). No Rule 4 architectural asks.

## Authentication Gates

None — all 6 cutover commands run via artisan on the operator's VPS. The `--live` paths on `cutover:drill-rollback` and `cutover:disable-legacy-plugins` require env variables which the operator sets via `.env` on the target server (staging for drill, production for disable-legacy) — these are operator-controlled configuration, not authentication credentials the AI needs to acquire.

## Issues Encountered

1. **MySQL not reachable in execution environment.** `PDO::connect` to the testing DB (`127.0.0.1:3306`) fails with SQLSTATE[HY000] [2002] — the exact Phase 6 / 07-01..04 precedent documented in every prior Phase 7 summary. All 40 Pest cases authored against correct schema + `RefreshDatabase` boot, execution deferred until Plan 07-06 verification runs against a live `meetingstore_ops_testing` MySQL. PHP lint on all 19 created files was clean; `php artisan list` confirms all 6 cutover commands are registered correctly.

2. **Deptrac Cutover layer not yet registered.** Documented as a Rule-2-with-planner-permission-defer above; Plan 07-06 owns it.

3. **Live-path runtime verification is inherently hard to test.** `WooDbSnapshotter::snapshot()` requires `mysqldump` on `$PATH` + WOO_DB_* credentials. The test suite stubs the snapshotter subclass to avoid real mysqldump invocations. `LegacyPluginDisabler::run(true)` requires a Woo site that exposes the `wp-cli-command` REST endpoint; the test suite stubs WooClient to always throw, verifying the `'manual_required'` fallback path. End-to-end staging verification is a Plan 07-06 operator task.

## Next Plan (07-06) Readiness

### Plan 07-06 (handover + verification) can assume

- 6 cutover commands registered + invocable: `cutover:snapshot-woo-db`, `cutover:divergence-scan`, `cutover:populate-overrides`, `cutover:drill-rollback`, `cutover:disable-legacy-plugins`, `cutover:checklist`.
- `cutover:checklist` is the single go/no-go signal — Plan 07-06 should reference it verbatim in `docs/ops/cutover-handover.md` and wire its exit code into the CI pre-cutover gate.
- The checklist's `handover-docs` gate flips to PASS automatically when `docs/ops/cutover-handover.md` is committed.
- Plan 07-06 owns the Deptrac `Cutover` layer registration in both `depfile.yaml` + `deptrac.yaml` with allow-list `[Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Competitor, CRM, ProductAutoCreate, Dashboard]`.
- Plan 07-06 MUST run the MySQL-deferred Feature suite — tests authored across 07-01 (5) + 07-02 (3) + 07-03 (various CSV/SavedFilter/GlobalSearch) + 07-04 (Digest/NotificationCentre) + this plan (6 files, 40 cases) — against a live `meetingstore_ops_testing` MySQL to close the execution backlog.
- The checklist's `feature-suite` gate reads `dashboard_snapshots.feature_suite_last_run` — Plan 07-06's verifier should upsert `{status: pass|fail, timestamp: ISO8601}` to that key after the suite run.

### Downstream integrations already live

- **Plan 07-02 SyncDiffsParityWidget** — reads `dashboard_snapshots.sync_diffs_parity`. `cutover:divergence-scan --live` is now the primary writer. Widget will flip green once parity ≥ 99% (default threshold).
- **Plan 07-04 weekly digest status** — the `weekly-digest-landed` gate reads `dashboard_snapshots.weekly_report_status.last_sent_at`, already written by `reports:weekly-digest` after each successful send.
- **Phase 6 Plan 01 supplier-probe** — the `supplier-probe` gate reads `storage/app/research/supplier-probe.json` + detects the `__synthesized=true` marker that the offline-fallback probe writes. Ops re-runs `php artisan supplier:probe-single-sku <LIVE-SKU>` with live 21stcav.com creds + the gate auto-flips to PASS.

### Known concerns for Plan 07-06

1. **MySQL Feature-suite backlog.** Phases 6 → 7 have each added `RefreshDatabase`-backed feature tests that never executed in the dev environment. Plan 07-06 MUST run the whole Pest suite against live MySQL to validate both schema integrity and behaviour — this is the last chance before the cutover go/no-go decision.
2. **Deptrac layer registration dual-config-sync.** When Plan 07-06 adds the Cutover layer, both `depfile.yaml` AND `deptrac.yaml` must receive the layer definition + allow-list. Phase 5 Plan 05-05 + Phase 7 Plan 02 both documented this trap (forgetting one file silently drops violations on CI).
3. **Live-path operator handover.** `docs/ops/cutover-handover.md` must include the exact 6-command invocation sequence (D-19), the two env gates (`CUTOVER_DRILL_ALLOWED` + `CUTOVER_DISABLE_LIVE_ALLOWED`), and the rollback procedure (restore the `.sql.gz`, unset `WOO_WRITE_ENABLED`, `php artisan config:clear`). No live-path automation should ship in Plan 07-06 — this is operator territory.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| threat_flag: shell-exec | `app/Domain/Cutover/Services/WooDbSnapshotter.php` | Spawns `mysqldump` via `Process::fromShellCommandline` with `WOO_DB_PASSWORD` interpolated after `escapeshellarg` (T-07-05-03). Password briefly appears in `ps` output — threat register marked `mitigate`; operator runs this on a private VPS per the threat model. |
| threat_flag: file-write-backup-path | `app/Domain/Cutover/Services/WooDbSnapshotter.php` | Creates `storage/app/cutover/backups/*.sql.gz` — files contain unredacted copy of WP DB including `wp_users` password hashes. Path is inside the app's storage tree; file mode defaults from `umask`. Threat register: operator-only VPS access. |
| threat_flag: state-file-write | `app/Domain/Cutover/Services/CutoverChecklistReporter.php` | Writes `storage/app/cutover/checklist-state.json` with ops-supplied status overrides. Non-sensitive data (gate-id + PASS/PENDING/FAIL/MANUAL literals only). |
| threat_flag: env-read-trust-boundary | `app/Console/Commands/Cutover/DrillRollbackCommand.php`, `app/Console/Commands/Cutover/DisableLegacyPluginsCommand.php` | Both commands read `env(config('cutover.*_env_var'))` at --live time. Trust boundary: env-file integrity (plus `.env` not committed). Two-step safety via config+env split already documented. |

## Self-Check

**Files on disk (verified via ls):**

- `app/Domain/Cutover/Services/WooFieldComparator.php` — FOUND
- `app/Domain/Cutover/Services/DivergenceScanner.php` — FOUND
- `app/Domain/Cutover/Services/OverridePopulator.php` — FOUND
- `app/Domain/Cutover/Services/WooDbSnapshotter.php` — FOUND
- `app/Domain/Cutover/Services/RollbackDrill.php` — FOUND
- `app/Domain/Cutover/Services/LegacyPluginDisabler.php` — FOUND
- `app/Domain/Cutover/Services/CutoverChecklistReporter.php` — FOUND
- `app/Console/Commands/Cutover/DivergenceScanCommand.php` — FOUND
- `app/Console/Commands/Cutover/PopulateOverridesCommand.php` — FOUND
- `app/Console/Commands/Cutover/SnapshotWooDbCommand.php` — FOUND
- `app/Console/Commands/Cutover/DrillRollbackCommand.php` — FOUND
- `app/Console/Commands/Cutover/DisableLegacyPluginsCommand.php` — FOUND
- `app/Console/Commands/Cutover/CutoverChecklistCommand.php` — FOUND
- `tests/Feature/Cutover/DivergenceScanCommandTest.php` — FOUND
- `tests/Feature/Cutover/PopulateOverridesCommandTest.php` — FOUND
- `tests/Feature/Cutover/SnapshotWooDbCommandTest.php` — FOUND
- `tests/Feature/Cutover/DrillRollbackCommandTest.php` — FOUND
- `tests/Feature/Cutover/DisableLegacyPluginsCommandTest.php` — FOUND
- `tests/Feature/Cutover/CutoverChecklistCommandTest.php` — FOUND

**Commits verified via `git log --oneline`:**

- `e56d312` — Task 1 (divergence-scan + populate-overrides + 2 tests) — FOUND
- `8484217` — Task 2 (snapshot + drill + disable-legacy + 3 tests) — FOUND
- `ecae861` — Task 3 (checklist + test) — FOUND

**Runtime verification (executed successfully in this environment):**

- `php -l` on all 19 new PHP files — 0 syntax errors
- `php artisan list | grep cutover:` — all 6 cutover commands listed with their descriptions
- `grep -cE "DB::|Illuminate.Support.Facades.DB" app/Domain/Cutover/Services/LegacyPluginDisabler.php` → **0 matches** (DL4 regression guard)
- `vendor/bin/deptrac analyse --no-progress | grep -iE "Domain.Cutover"` → **0 Cutover-related violations** (18 pre-existing from Plan 07-03 CsvExportWriter are out of scope)

**Deferred verification (requires MySQL online — Plan 07-06 owns):**

- `vendor/bin/pest tests/Feature/Cutover/` — 40 cases authored, execution blocked by `PDO::connect` refusal against `meetingstore_ops_testing` at 127.0.0.1:3306 (same precedent as 07-01..04).
- Live-path CUT-04 (`cutover:snapshot-woo-db`) — requires `mysqldump` on PATH + WOO_DB_* creds; operator-run on staging.
- Live-path CUT-05 (`cutover:drill-rollback --live`) — requires `CUTOVER_DRILL_ALLOWED=true` on staging.
- Live-path CUT-03+07 (`cutover:disable-legacy-plugins --live`) — requires `CUTOVER_DISABLE_LIVE_ALLOWED=true` + interactive operator confirmation on production at cutover time.

## Self-Check: PASSED

---

*Phase: 07-dashboard-polish-cutover*
*Plan: 05-cutover-commands*
*Completed: 2026-04-24*
