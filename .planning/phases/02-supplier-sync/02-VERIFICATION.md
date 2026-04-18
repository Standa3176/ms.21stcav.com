# Phase 02 — Supplier Sync — Verification

**Phase goal (ROADMAP.md):** The daily supplier sync from 21stcav.com replaces
the legacy Stock Updater plugin — crashed runs resume cleanly, per-item
failures captured, Woo written only through REST, emailed CSV report on
completion, observable in Filament, hardened by Deptrac + retention prunes.

**Phase HEAD at verification time:** post-Plan 02-05 completion.

---

## Success Criteria Coverage (6 criteria)

| # | Success Criterion | Plan(s) | Verification Command |
|---|-------------------|---------|---------------------|
| 1 | `php artisan sync:supplier --dry-run` → emailed CSV, zero Woo writes, diffs in `sync_diffs` | 02-03 (command + default dry-run) + 02-04 (CSV + mail) | `vendor/bin/pest --filter="DryRunMode\|SyncReportCsvGenerator\|SyncReportMail"` |
| 2 | Worker killed mid-run + `--resume={id}` continues from cursor | 02-03 (SyncSupplierCommand + SyncChunkJob idempotency + SyncRun::findResumable) | `vendor/bin/pest --filter="SyncResume\|SyncSupplierCommandFlags"` |
| 3 | Architectural test fails on direct WP DB access | **02-05 (Deptrac WpDirectDb + DeptracSyncLayerTest)** | `vendor/bin/pest --filter="DeptracSyncLayer"` (2 tests — positive + negative) |
| 4 | Missing SKU → `pending` unless `custom-ms`; `_exclude_from_auto_update` skipped + counted | 02-03 (MarkMissingSkusJob + SyncDiffEngine) | `vendor/bin/pest --filter="MissingSkuHandling\|ExcludeFromAutoUpdate"` |
| 5 | Filament "Supplier Sync Status" + "Import Issues" pages with drill-down | 02-04 (SyncRunResource + ImportIssueResource) | `vendor/bin/pest --filter="SyncRunResource\|ImportIssueResource"`; visit `/admin/sync-runs` + `/admin/import-issues` as admin |
| 6 | Domain events fire observably in `integration_events` with matching correlation_id | 02-03 (DomainEvent ShouldDispatchAfterCommit + 4 events) | `vendor/bin/pest --filter="DomainEventAfterCommit\|SupplierEventDispatch"` |

---

## Scope Additions Verification (beyond REQUIREMENTS.md)

| Item | Source | Plan | Verified by |
|------|--------|------|-------------|
| `products` + `product_variants` tables (D-01 variable-product support) | D-01 | 02-01 | `Schema::hasTable('products')` + `Phase02DataModelTest` |
| `import_issues` table | D-09 / SYNC-12 | 02-01 | `Schema::hasTable('import_issues')` + `ImportIssueResourceTest` |
| `receives_sync_reports` column on `alert_recipients` | D-08 | 02-04 | `ReceivesSyncReportsColumnTest` |
| `NewSupplierSkuDetected` event + stub listener | D-09 | 02-03 | `SupplierEventDispatchTest` |
| `--live` + flag conflict validation (`--live --dry-run` → exit 1) | D-04 | 02-03 | `SyncSupplierCommandFlagsTest` |
| `sync-errors:prune` command + 03:20 schedule | RESEARCH §Extra (D-07) | **02-05** | `vendor/bin/pest --filter="PruneSyncErrors"` (5 tests) |
| `ShouldDispatchAfterCommit` retrofit on DomainEvent base | Pitfall P2-I | 02-03 | `DomainEventAfterCommitTest` + Phase 1 regression green |
| `PolicyTemplateIntegrityTest` (Architecture suite) | Pitfall P2-H | **02-05** (promoted from 02-04 Feature version) | `vendor/bin/pest --filter="PolicyTemplateIntegrity"` (3 tests) |
| `sync_run_items` append-only CSV source (D-10 11-col) | RESEARCH §9 / D-10 | 02-01 + 02-03 + 02-04 | `Schema::hasTable('sync_run_items')` + `SyncReportCsvGeneratorTest` |
| `WpDirectDb` Deptrac layer (SYNC-04) | SYNC-04 | **02-05** | `DeptracSyncLayerTest` + `vendor/bin/deptrac analyse` exit 0 |

---

## Requirement ID Coverage (SYNC-01 … SYNC-13)

| ID  | Requirement (short)                                              | Covered in Plan(s)      |
|-----|------------------------------------------------------------------|-------------------------|
| SYNC-01 | Fetch supplier feed + write Woo via REST only                | 02-02 + 02-03           |
| SYNC-02 | JWT auth + 50-min cache + retry-once-on-401                 | 02-02                   |
| SYNC-03 | Resumable sync (cursor + `--resume={id}`)                   | 02-01 + 02-03           |
| SYNC-04 | Architectural test forbids WP direct DB writes in Sync      | **02-05**               |
| SYNC-05 | `sync_errors` table + per-SKU failure path                  | 02-01 + 02-03           |
| SYNC-06 | `sync_run_items` action enum + missing-SKU marking          | 02-01 + 02-03           |
| SYNC-07 | `_exclude_from_auto_update` meta honoured (skipped)         | 02-03 (SyncDiffEngine)  |
| SYNC-08 | D-08 emailed CSV report on run completion                   | 02-04                   |
| SYNC-09 | `dry_run` default + `--live` opt-in                         | 02-01 + 02-03           |
| SYNC-10 | Woo 429 backoff with Retry-After + jitter                   | 02-02                   |
| SYNC-11 | Filament `SyncRunResource` with drill-down                  | 02-04                   |
| SYNC-12 | Filament `ImportIssueResource` with 4-type filter           | 02-01 + 02-04           |
| SYNC-13 | 4 domain events publish reliably after-commit               | 02-03                   |

All 13 SYNC-* requirements are covered by at least one plan.

---

## Full Phase Verification Script

```bash
# 1. Dependencies
composer show automattic/woocommerce                   # expect 3.1.0
composer show spatie/simple-excel                      # expect 3.9.0

# 2. Schema
php artisan db:table products
php artisan db:table product_variants
php artisan db:table sync_runs
php artisan db:table sync_errors
php artisan db:table import_issues
php artisan db:table sync_run_items

# 3. Architecture enforcement
vendor/bin/deptrac analyse --no-progress               # expect 0 violations

# 4. Policy integrity (Pitfall P2-H)
grep -rn '{{ ' app/Policies/ app/Domain/*/Policies/    # expect empty
vendor/bin/pest --filter="PolicyTemplateIntegrity"     # 3 tests green

# 5. SYNC-04 Deptrac enforcement
vendor/bin/pest --filter="DeptracSyncLayer"            # 2 tests green

# 6. Full test suite
vendor/bin/pest                                        # expect ≥ 233 pass, 2 skipped

# 7. Lint + static analysis (when configured)
# vendor/bin/pint --test
# vendor/bin/phpstan analyse --memory-limit=1G

# 8. Schedules
php artisan schedule:list | grep -E '(sync-errors:prune|sync-diffs:prune|activitylog:prune|integration-events:prune)'
# expect 4 entries — 03:00 activitylog, 03:10 integration-events, 03:20 sync-errors, 03:30 sync-diffs
# Daily supplier sync itself remains COMMENTED until Phase 7 cutover runbook enables it.

# 9. CLI smoke
php artisan sync:supplier --live --dry-run             # expect exit 1 + "mutually exclusive" error
php artisan sync:supplier --help                       # shows --live, --dry-run, --resume options
php artisan sync-errors:prune --days=90                # writes an Auditor row; prints "Pruned N ..." or "0 ..."
```

---

## Known Deviations / Accepted Debt

- **Plan-level deviations:** each plan's `02-0N-SUMMARY.md` documents its own
  deviations from plan text. High-level:
  - 02-01: 3 auto-fixes (observer timing, forceFill+saveQuietly, task ordering)
  - 02-02: 5 auto-fixes (composer 3.1.0 vs 3.1.1, HttpClient private field, PATCH routing,
    VARCHAR(36) correlation_id, `final` modifier removed for test seam)
  - 02-03: 4 auto-fixes (Artisan::starting missing on Laravel 12, Deptrac Sync→Products,
    B1 test design, PHP PATH env)
  - 02-04: 8 auto-fixes (step=7 rollback, Deptrac Sync→Alerting, Shield `{{ Placeholder }}`
    recurrence, `::` separator permissions, `final` Mockery limitation, CSV BOM,
    Mail::assertSent signature, Filament multi-select syntax)
  - 02-05: see `02-05-SUMMARY.md`.

- **Pitfall P2-J stock race** — ACCEPTED for v1. Meeting Store B2B traffic at
  02:00-03:00 UTC is near-zero. Delta-apply pattern documented in
  `02-RESEARCH.md §Pitfall P2-J` for future tightening (Phase 6+).

- **A1/A2 supplier API shape** — MEDIUM confidence; 02-02 `SupplierClient` may need
  field-name adjustments after first live run against production 21stcav.com.

- **Horizon worker timeout bump (90→120s per Pitfall P2-E)** applied in 02-03 at
  job level (`SyncChunkJob::$timeout`). Supervisor-level config was not modified
  (see 02-03 decisions).

- **Deptrac `qossmic/deptrac-shim` PHP deprecation warnings** on Laravel 12 strict
  runtime — cosmetic; analysis still completes successfully. Defer upgrade of
  `qossmic/deptrac` to a post-Phase-2 ticket.

---

## VPS Operator Handoff — Before First Live Sync

1. **Populate `.env` on VPS:**
   - `SUPPLIER_API_URL=https://21stcav.com` (default already documented in config)
   - `SUPPLIER_API_USERNAME=...` — obtained from 21stcav.com ops team
   - `SUPPLIER_API_PASSWORD=...` — obtained from 21stcav.com ops team
   - `WOO_URL=https://meetingstore.co.uk`
   - `WOO_CONSUMER_KEY=ck_...` — from Woo → Settings → Advanced → REST API
   - `WOO_CONSUMER_SECRET=cs_...` — from Woo → Settings → Advanced → REST API
   - `WOO_WRITE_ENABLED=false` (stays false until the cutover runbook flips it)

2. **Migrate + seed (idempotent):**
   ```bash
   php artisan migrate --force
   php artisan db:seed --class=RolePermissionSeeder --force
   php artisan db:seed --class=AlertRecipientSeeder --force
   ```

3. **Configure real ops emails** via Filament `/admin/alert-recipients`:
   - Add real recipients (ops, pricing manager)
   - Toggle `receives_sync_reports=true` for each
   - The seeded `ops@meetingstore.co.uk` fallback can be deactivated but the row
     should remain for Pitfall M safety.

4. **Verify Horizon running** via `supervisorctl status horizon`. See Phase 1
   Plan 05 runbook for supervisor config.

5. **Dry-run smoke test first:**
   ```bash
   php artisan sync:supplier
   # default is --dry-run — check the emailed CSV before enabling the scheduled cron
   ```

6. **Enable the cron only after Phase 7 parity runbook** completes — at that
   point uncomment the `Schedule::command('sync:supplier --live')` block in
   `routes/console.php` (the commented-out entry IS the kill-switch, per D-05).

7. **Post-cutover retention schedule** — once `WOO_WRITE_ENABLED=true` flips,
   `sync-diffs:prune` starts actually deleting rows (until then it's a no-op
   per Pitfall L). `sync-errors:prune` runs 03:20 daily from day one regardless.

---

## Phase 2 Readiness Signals for Phase 3 (Pricing Engine)

- `pricing_manager` role has 26 Shield-granted permissions (Product 12 + ImportIssue 12 + view-only SyncRun 2); new Phase 3 `PricingRule` Resource will auto-include via the seeder's dual-style LIKE patterns (`_` and `::`).
- `SupplierPriceChanged` domain event (02-03) is wired with `ShouldDispatchAfterCommit` — Phase 3 `MarginRecomputeListener` can subscribe safely; the DB mirror is guaranteed persisted before the listener fires.
- Product editing in Filament is functional for `pricing_manager` — buy/sell/cost price fields are editable, identity fields (woo_product_id, sku, type) are disabled.
- Deptrac ruleset: `Sync` already allows `Products` (02-03); Phase 3's `Pricing` layer will similarly need to be allowed to read Products — add `Pricing: [Foundation, Products]` when Phase 3 Plan 01 lands.
- Policy integrity tested on every CI run — any Phase 3 `shield:generate` regen is caught immediately.

---

*Phase 02 verification generated: 2026-04-19*
*HEAD: post 02-05 SUMMARY commit*
