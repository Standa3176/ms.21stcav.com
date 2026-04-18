# Phase 2: Supplier Sync - Context

**Gathered:** 2026-04-18
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 2 delivers the daily supplier-sync pipeline that replaces the legacy Stock Updater WordPress plugin. Operator runs `php artisan sync:supplier` (or the scheduled daily cron on `sync-bulk` queue); the command pulls every Woo product's (SKU + price + stock) from the `21stcav.com` supplier API (JWT-authed with auto-refresh on 401), compares against Woo's current values, and writes differences back via REST only through the existing Phase 1 `WooClient`. Crashed runs resume cleanly from a persisted cursor; per-item failures land in `sync_errors` instead of being dropped; a CSV report with per-SKU actions emails to admins on completion; `_exclude_from_auto_update`-tagged products are skipped (but counted); missing-from-supplier SKUs flip to `pending` unless they carry the `custom-ms` tag. Filament "Supplier Sync Status" + "Import Issues" pages visualise the run history and catalogue health. Domain events (`SupplierPriceChanged`, `SupplierStockChanged`, `SupplierSkuMissing`) fire after each row update so downstream phases can subscribe without touching sync core.

Scope is fixed by ROADMAP.md Phase 2 and REQUIREMENTS.md SYNC-01..SYNC-13. Discussion clarified implementation choices that research + REQUIREMENTS couldn't resolve (variable-product handling, default run mode, abort policy, report distribution).

</domain>

<decisions>
## Implementation Decisions

### Variable product handling (CRITICAL — drives data model)

- **D-01:** MeetingStore sells a **significant variant-product catalogue** (chairs/desks with colour/size/finish variations). Phase 2 MUST support WooCommerce variable products properly — `ProductVariant` model + parent-child relationship are in scope.
- **D-02:** **Mixed mapping strategy.** Phase 2 branches at sync time based on the Woo product type:
  - **Variable products** (Woo returns `type: variable` + `variations: [id,...]`) → variant-level sync. Each variation has its own SKU; sync fetches `GET /products/{id}/variations` and matches each variation's SKU against the supplier feed. One supplier SKU = one Woo variation.
  - **Simple products** (Woo returns `type: simple`) → parent-level sync. One supplier SKU = one Woo product, no variation traversal.
  The `WooProductIterator` (per RESEARCH §12 iterator pattern) yields both parent products AND variations as a flat SKU stream; the matcher doesn't care which type it came from.
- **D-03:** **Missing-variant handling — granular:** when a supplier feed omits a variation's SKU, only that variation flips to `private` (Woo's "hidden from shop" state; variations don't have `pending` status). Parent product stays `publish`. Sibling variations unaffected. Matches customer experience of stockouts — only the specific colour/size disappears, not the whole product line.

### Default run mode (dry vs live)

- **D-04:** **`php artisan sync:supplier` defaults to DRY-RUN.** No flags → shadow mode (writes to `sync_diffs` only, zero Woo REST calls). Operator must pass `--live` to enable actual Woo writes. Belt-and-braces protection against accidental CLI invocations on production — even post-Phase 7 cutover, a typo shouldn't push real changes. Flag combinations:
  - `sync:supplier` → dry-run (explicit or implicit `--dry-run`)
  - `sync:supplier --live` → live writes (gated by `WOO_WRITE_ENABLED` env flag too)
  - `sync:supplier --live --dry-run` → error (mutually exclusive)
- **D-05:** **Daily scheduled cron runs `sync:supplier --live` unconditionally post-Phase-7-cutover.** Assumes Phase 7 cutover runbook proved parity before enabling the cron. Cron is disabled (commented entry in `routes/console.php`) until the runbook explicitly enables it. No separate `SYNC_CRON_LIVE` feature flag — the cron entry itself is the kill-switch.

### Error threshold — abort vs complete

- **D-06:** **Tiered abort policy.** Per-item failures are non-fatal by default — logged to `sync_errors`, sync continues. But systemic failure triggers abort:
  - **Abort (a):** Error rate >20% after first 500 SKUs processed (supplier feed likely degraded)
  - **Abort (b):** 50+ consecutive failures (supplier API likely down or auth broken)
  - **Abort (c):** JWT refresh failure on 401 (auth credentials broken — no point continuing)
  On abort: cursor persists, partial results saved in `sync_runs.status='aborted'`, emailed report marked `ABORTED` with abort reason + stats so far, `ThrottledFailedJobNotifier` fires (using Phase 1 Pitfall M's 5-min dedup so one abort = one alert).
- **D-07:** **Aborted runs are RESUMABLE.** The cursor (last-processed supplier SKU ID or offset) persists on abort — same persistence path as worker-crash recovery (SYNC-03). Operator fixes the underlying issue, then: `php artisan sync:supplier --resume={run_id} --live` picks up where it stopped. Zero duplicate pushes via SyncRun state guard + idempotent upserts. Abort reason stays on the `sync_runs` row for post-mortem.

### Reporting + new-SKU detection

- **D-08:** **Reuse `alert_recipients` for sync report distribution.** Same table as D-12 (Phase 1) failed-job alerts. **Migration addition:** new nullable boolean column `receives_sync_reports` (default `true` so existing fallback `ops@meetingstore.co.uk` starts receiving sync reports). Recipients opt out per channel via Filament edit. No new `sync_report_recipients` table — one list keeps ops mental model simple.
- **D-09:** **Unknown supplier SKUs (no matching Woo product) trigger two things in Phase 2:**
  1. Emit `NewSupplierSkuDetected` domain event (Phase 6's `AUTO-01` producer is this event; Phase 2 establishes it so Phase 6 just wires a listener without backfill).
  2. Log row to `import_issues` (a new table shared with SYNC-12's "missing cost/price" list; Phase 2 creates the schema).
  Phase 2 ships a no-op stub listener for the event so it doesn't pile up in `failed_jobs` waiting for Phase 6.
- **D-10:** **CSV report — standard column set:**
  ```
  sku, woo_product_id, woo_variation_id (nullable), action, reason,
  old_price, new_price, old_stock, new_stock, error_message, correlation_id
  ```
  `action` is enum: `updated` | `skipped` | `failed` | `missing` | `unknown_sku`. One row per SKU touched. `correlation_id` column enables cross-reference with `integration_events` + `audit_log` + `sync_errors`. Aggregated totals in the email body; attachment = full CSV.

### Claude's Discretion

The following areas were not discussed — planner/researcher may pick the default best-practice approach:

- **Chunk size** — derive from Woo 100/min ceiling and queue timeout (RESEARCH-phase question). Sensible default: 50 SKUs per `SyncChunkJob`, batch-dispatched with `withoutOverlapping`.
- **JWT refresh strategy** — retry once on 401 with fresh token, then fail the SKU (contributes to D-06 consecutive-failure counter). Don't loop.
- **Rate-limit middleware** — adaptive exponential backoff when Woo returns `429`. Cap at 5 retries per SKU; longer = skip + log.
- **Supplier → Woo SKU matcher** — in-memory hash map built from a single `woo_products + woo_variations` pass, per run. ~15k SKUs fit easily in memory.
- **Progress reporting granularity** — every 50 SKUs print cursor + elapsed + ETA. Use Laravel's `$this->output->progressStart(...)` for the artisan command.
- **Tolerance comparisons** — exact string match on stock (integers); price treated as 2dp strings from both sides (trim trailing zeros). Rounding edge cases are supplier's responsibility.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 1 Foundation (authoritative — Phase 2 consumes these contracts)

- `.planning/phases/01-foundation/01-01-SUMMARY.md` — Package pins (April-2026 verified), module skeleton, Deptrac ruleset, FeedGenerator contract
- `.planning/phases/01-foundation/01-02-SUMMARY.md` — Shield + 4 roles seeded + admin user + permission naming pattern (LIKE queries for forward-compat)
- `.planning/phases/01-foundation/01-03-SUMMARY.md` — AttachCorrelationId + Context::hydrated queue bridge + IntegrationLogger + DomainEvent base + Auditor + BaseCommand (all Phase 2 artisan/queue/event code MUST use these)
- `.planning/phases/01-foundation/01-04-SUMMARY.md` — WooClient shadow-mode gate + webhook_receipts + suggestions inbox (Phase 2's WooClient.get() extension lives here)
- `.planning/phases/01-foundation/01-05-SUMMARY.md` — 7 Horizon supervisors (sync-woo-push + sync-bulk are Phase 2's queues) + ThrottledFailedJobNotifier + retention prune pattern
- `.planning/phases/01-foundation/01-CONTEXT.md` — 17 locked decisions (D-01..D-17) from Phase 1
- `.planning/phases/01-foundation/01-VERIFICATION.md` — Phase 1 PASS WITH NOTES; list of 5 VPS-deploy human checks (doesn't affect Phase 2)

### Project foundations

- `.planning/PROJECT.md` — Core value, constraints, decisions
- `.planning/REQUIREMENTS.md` — SYNC-01 through SYNC-13
- `.planning/ROADMAP.md` — Phase 2 goal + 6 success criteria

### Research artefacts

- `.planning/research/STACK.md` — Package versions (April-2026 verified pins from Phase 1 execution)
- `.planning/research/ARCHITECTURE.md` — Module boundaries (Sync domain is Phase 2's home)
- `.planning/research/FEATURES.md` §A Supplier sync — feature-level patterns
- `.planning/research/PITFALLS.md` — Critical: D (Tailwind), E (queue=redis), F (Redis persistence), J (Context queue boundary — already mitigated in Phase 1), new ones this phase will likely surface

### Code contracts Phase 2 consumes (Phase 1 artefacts)

- `app/Domain/Sync/Services/WooClient.php` — write-path shadow-mode functional; Phase 2 adds `get(string $endpoint, array $query = []): array` + properly types `$inner` to `Automattic\WooCommerce\Client`
- `app/Domain/Sync/Models/SyncDiff.php` — migration + model exist; Phase 2 reads back via dashboard
- `app/Foundation/Integration/Services/IntegrationLogger.php` — use for all outbound API calls (supplier + Woo reads)
- `app/Foundation/Integration/Models/IntegrationEvent.php` — reader for dashboard queries
- `app/Foundation/Events/DomainEvent.php` — extend for `SupplierPriceChanged`, `SupplierStockChanged`, `SupplierSkuMissing`, `NewSupplierSkuDetected`
- `app/Foundation/Audit/Services/Auditor.php` — use for sync_run state transitions
- `app/Console/Commands/BaseCommand.php` — extend for `SyncSupplierCommand` (threads correlation_id automatically)
- `app/Domain/Alerting/Models/AlertRecipient.php` — D-08 adds `receives_sync_reports` column via migration
- `app/Domain/Alerting/Listeners/ThrottledFailedJobNotifier.php` — already catches Phase 2 job failures via JobFailed event

### No external specs

No ADRs, RFCs, or external specs for Phase 2 — the SYNC-01..SYNC-13 requirements + this CONTEXT.md + research files constitute the full contract.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets (Phase 1 delivered)

- **WooClient skeleton** — write methods functional via shadow-mode gate; Phase 2 EXTENDS with `get()` + types `$inner` properly. Do NOT create a separate SupplierWooClient.
- **IntegrationLogger** — every supplier API call AND Woo REST call logs through this (auto-redacts 6 sensitive headers, threads correlation_id).
- **DomainEvent base** — auto-fills correlation_id, implements ShouldDispatchAfterCommit (events don't fire in rolled-back transactions). All 4 Phase 2 events extend this.
- **Auditor** — use for model-change tracking on `sync_runs`, `sync_errors`, `products`, `product_variants`. Pulls correlation_id from Context automatically.
- **BaseCommand** — `SyncSupplierCommand` extends this. Gets correlation_id threading, Context hydration, console-visible CID output for ops.
- **ThrottledFailedJobNotifier** — no action needed; automatically catches sync chunk job failures (`JobFailed` event subscription) with 5-min dedup.
- **`alert_recipients` table** — D-08 migration adds `receives_sync_reports` column; existing seeder covers `ops@meetingstore.co.uk` fallback.
- **Horizon supervisors** — `sync-bulk` for the master SyncSupplierCommand (1800s timeout), `sync-woo-push` for per-SKU Woo write jobs (120s timeout, 2-3 workers respecting Woo 100/min).

### Established Patterns (from Phase 1 SUMMARYs)

- **Migration timestamps ≥ 2026_04_18_180200** — Phase 1 ended there; Phase 2 starts at `2026_04_18_190000` for logical ordering.
- **Filament Resource pattern** — Shield auto-generates per-Resource permissions. Plan 02's seeder uses LIKE patterns (`%_sync_run`, `%_product_variant`) so new Resources auto-attach permissions to roles when Plan 01 Task's `shield:generate` runs.
- **Pitfall: `shield:generate` is DESTRUCTIVE** — confirmed on 3 policies in Phase 1 (SuggestionPolicy, AlertRecipientPolicy, RolePolicy). Any hand-edited policy must be restored post-regenerate. Mitigation: architecture test grep for `{{ Placeholder }}` literal strings (Plan 02-P05 deferred to Phase 2 as hardening candidate).
- **Testing DB isolation** — `phpunit.xml` overrides `DB_DATABASE` to `meetingstore_ops_testing`. Phase 2 tests follow this pattern (no changes needed).
- **RefreshDatabase** — all tests use `meetingstore_ops_testing` DB; refreshes don't touch the dev DB.
- **->authorize() mandatory on Filament Actions** — belt-and-braces over `->visible()`. Apply to any sync-status action (e.g., "Retry run" admin button).

### Integration Points

- **Inbound:** `SyncSupplierCommand` → `SupplierClient->fetchAllProducts()` (JWT-authed) → `WooProductIterator` (paginates Woo) → per-SKU diff logic → `WooClient->put()` (shadow or live)
- **Outbound:** `SupplierPriceChanged` event dispatched per row update → Phase 3 PricingEngine listener consumes (Phase 3 stubs this)
- **New migrations needed:** `products`, `product_variants` (D-01), `sync_runs`, `sync_cursors` (or denormalised into sync_runs), `sync_errors`, `import_issues`
- **New Filament Resources:** `SupplierSyncStatusResource` (read-only drill-down of `sync_runs` + `sync_errors`), `ImportIssuesResource` (writable by pricing_manager for triage), `ProductResource` + `ProductVariantResource` (pricing_manager can edit pricing-related fields; read-only otherwise per D-02 Phase 1)

</code_context>

<specifics>
## Specific Ideas

- **`ProductVariant` is new Phase 2 scope (D-01 driven).** Migration + model + relationship + Resource. `products` table also new. Neither was in REQUIREMENTS.md explicitly; this is the D-01 expansion.
- **`receives_sync_reports` migration** — additive column on existing `alert_recipients` table (D-08). Single-column migration at timestamp after all Phase 2 table migrations.
- **`import_issues` table shape:** `id`, `sku`, `woo_product_id` (nullable — unknown SKU case), `issue_type` (enum: `missing_at_supplier` | `unknown_sku` | `missing_cost_price` | `exclude_flag_no_metadata`), `detected_at`, `last_seen_at`, `resolved_at` (nullable), `notes` (text), `correlation_id` (indexed). Pricing_manager role gets edit on this Resource per D-02 Phase 1.
- **`sync_runs` status enum:** `queued` | `running` | `completed` | `aborted` | `failed` (`aborted` distinguishes systemic abort from unhandled exception failure).
- **Sync-errors retention prune command** — Plan 05's `routes/console.php` has a TODO for this. Phase 2 implements `sync-errors:prune` command + schedules it at 03:20 daily (D-07 Phase 1 = 90 days).
- **Phase 2 must NOT modify `WooClient::writeOrShadow()`** — the Phase 1 shadow gate contract is stable. Phase 2 only ADDS the `get()` method and wires the real `Automattic\WooCommerce\Client` via DI when `WOO_WRITE_ENABLED=true`.
- **Architectural test for SYNC-04** — Deptrac rule extending the Phase 1 module-boundary ruleset: ban imports of `Illuminate\Support\Facades\DB::connection('mysql_woo')` or similar from `app/Domain/Sync/*` — only `WooClient` is allowed. New Deptrac layer `WpDirectDb` with a deny rule.
- **JWT credentials** — `.env.example` additions: `SUPPLIER_API_URL`, `SUPPLIER_API_USERNAME`, `SUPPLIER_API_PASSWORD`. Plan 01 ships these keys already — Phase 2 populates them.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within Phase 2 scope. Topics explicitly NOT discussed (deferred to Claude's Discretion per the decisions section above):

- Chunk size tuning (research-derived)
- Rate-limit backoff exact shape (standard pattern)
- Supplier field-level comparison tolerance (exact match assumed)
- Horizon worker auto-scaling within sync-bulk supervisor (Phase 1 supervisor config handles)
- Bulk Woo-side edits conflicting with sync (deferred to Phase 6 `ProductOverride` / Phase 7 cutover design)
- Variable-product bulk UI (operator edits all variations at once) — if needed, Phase 6 adds via Filament RelationManager, not Phase 2

</deferred>

---

*Phase: 02-supplier-sync*
*Context gathered: 2026-04-18*
