# Phase 2: Supplier Sync — Research

**Researched:** 2026-04-18
**Domain:** Laravel 12 + Filament 3 supplier sync pipeline (JWT-authed inbound feed → diffing → Woo REST writes; resumable, tiered-abort, emailed CSV report)
**Confidence:** HIGH on stack/patterns (Phase 1 seams are shipped and tested); MEDIUM on the supplier-side API shape (21stcav.com JSON — flagged for ops confirmation); HIGH on Woo REST + Filament + Horizon specifics
**Consumed by:** `gsd-planner` producing the Phase 2 plan set

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**D-01 — Variable product catalogue is in scope.** MeetingStore sells a significant variant-product catalogue (chairs/desks with colour/size/finish variations). Phase 2 MUST support WooCommerce variable products properly — `ProductVariant` model + parent-child relationship are in scope.

**D-02 — Mixed mapping strategy.** `WooProductIterator` yields both parent products AND variations as a flat SKU stream. Branch at sync time:
- `type: variable` + `variations: [id,...]` → variant-level sync. Each variation has its own SKU; fetch `GET /products/{id}/variations` and match each variation's SKU against the supplier feed. One supplier SKU = one Woo variation.
- `type: simple` → parent-level sync. One supplier SKU = one Woo product, no variation traversal.

**D-03 — Missing-variant handling is granular.** Supplier feed omits a variation's SKU → only that variation flips to `private` (variations have no `pending` status). Parent stays `publish`. Sibling variations unaffected.

**D-04 — `sync:supplier` defaults to DRY-RUN.** Zero flags = shadow mode (writes to `sync_diffs` only, no Woo REST calls). Operator must pass `--live` to enable real writes. Flag combinations:
- `sync:supplier` → dry-run (implicit `--dry-run`)
- `sync:supplier --live` → live writes (gated by `WOO_WRITE_ENABLED` env flag too)
- `sync:supplier --live --dry-run` → **error (mutually exclusive)**

**D-05 — Daily cron runs `sync:supplier --live` unconditionally post-Phase-7 cutover.** Cron entry in `routes/console.php` is the kill-switch — disabled (commented) until Phase 7 runbook enables it. No separate `SYNC_CRON_LIVE` feature flag.

**D-06 — Tiered abort policy.** Per-item failures are non-fatal (logged to `sync_errors`, continue). Abort triggers:
- **(a)** Error rate >20% after first 500 SKUs processed
- **(b)** 50+ consecutive failures
- **(c)** JWT refresh failure on 401 (auth credentials broken)

On abort: cursor persists, `sync_runs.status='aborted'`, emailed report marked `ABORTED` with abort reason, `ThrottledFailedJobNotifier` fires (5-min dedup per Phase 1 Pitfall M).

**D-07 — Aborted runs are RESUMABLE.** Same persistence path as worker-crash recovery (SYNC-03). Operator fixes underlying issue → `php artisan sync:supplier --resume={run_id} --live`. Zero duplicate pushes via SyncRun state guard + idempotent upserts. Abort reason stays on the `sync_runs` row for post-mortem.

**D-08 — Reuse `alert_recipients` for sync report distribution.** New nullable boolean column `receives_sync_reports` (default `true` so existing `ops@meetingstore.co.uk` fallback starts receiving sync reports). Surfaced in Filament `AlertRecipientResource` for per-channel opt-out. **No new `sync_report_recipients` table.**

**D-09 — Unknown supplier SKUs (no matching Woo product) trigger:**
1. Emit `NewSupplierSkuDetected` domain event (Phase 6 AUTO-01 producer — Phase 2 establishes it; Phase 6 just wires a listener).
2. Log row to new `import_issues` table (shared with SYNC-12 "missing cost/price" list; Phase 2 creates the schema).
3. Ship a no-op stub listener for the event so it doesn't pile up in `failed_jobs`.

**D-10 — CSV report — standard column set (11 columns):**
```
sku, woo_product_id, woo_variation_id (nullable), action, reason,
old_price, new_price, old_stock, new_stock, error_message, correlation_id
```
- `action` enum: `updated` | `skipped` | `failed` | `missing` | `unknown_sku`
- One row per SKU touched
- `correlation_id` column enables cross-reference with `integration_events` + `audit_log` + `sync_errors`
- Aggregated totals in email body; attachment = full CSV

### Claude's Discretion

Planner/researcher may pick defaults — research below supplies these. Discretion areas:

- **Chunk size** — derive from Woo 100/min ceiling and queue timeout. Sensible default: 50 SKUs per `SyncChunkJob`, batch-dispatched with `withoutOverlapping`.
- **JWT refresh strategy** — retry once on 401 with fresh token, then fail the SKU (contributes to D-06 consecutive-failure counter). **Don't loop.**
- **Rate-limit middleware** — adaptive exponential backoff when Woo returns `429`. Cap at 5 retries per SKU; longer = skip + log.
- **Supplier → Woo SKU matcher** — in-memory hash map built from a single `woo_products + woo_variations` pass, per run. ~15k SKUs fit easily in memory.
- **Progress reporting granularity** — every 50 SKUs print cursor + elapsed + ETA. Use Laravel's `$this->output->progressStart(...)` for the artisan command.
- **Tolerance comparisons** — exact string match on stock (integers); price treated as 2dp strings from both sides (trim trailing zeros). Rounding edge cases are supplier's responsibility.

### Deferred Ideas (OUT OF SCOPE)

None — discussion stayed within Phase 2 scope. Explicitly deferred to Claude's Discretion (all resolved in this RESEARCH):
- Chunk size tuning (research-derived below)
- Rate-limit backoff exact shape (standard pattern below)
- Supplier field-level comparison tolerance (exact match assumed)
- Horizon worker auto-scaling within sync-bulk supervisor (Phase 1 supervisor config handles)
- Bulk Woo-side edits conflicting with sync (deferred to Phase 6 `ProductOverride` / Phase 7 cutover design)
- Variable-product bulk UI (operator edits all variations at once) — if needed, Phase 6 adds via Filament RelationManager, not Phase 2

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| SYNC-01 | Scheduled daily job pulls every Woo product's SKU + price + stock from `21stcav.com` | §2 SupplierClient + §3 WooProductIterator + §5 Chunked execution |
| SYNC-02 | JWT tokens auto-refreshed on 401; failing request retried once | §2 JWT lifecycle (retry-once-then-abort per D-06c) |
| SYNC-03 | Sync progress persisted in `sync_runs` + `sync_cursors`; resume via `--resume={run_id}` | §4 SyncRun state machine + §5 Chunked resumable execution |
| SYNC-04 | Woo updates via REST only — direct WP DB writes forbidden, covered by architectural test | §12 Deptrac extension (WpDirectDb layer) |
| SYNC-05 | `products/batch` responses parsed per-item; failed items written to `sync_errors` | §3 Per-page dispatch + §5 Per-SKU upsert with batch response parsing |
| SYNC-06 | Missing SKUs → `pending` unless `custom-ms` tag | §7 Missing-variant handling (granular per D-03) |
| SYNC-07 | `_exclude_from_auto_update` products skipped but counted | §8 Tag/meta detection + cache |
| SYNC-08 | On completion, admin distribution list receives emailed CSV report | §9 CSV report generator (spatie/simple-excel + Mailable) |
| SYNC-09 | Dry-run mode writes only to `sync_diffs` — Woo untouched | §4 SyncRun.dry_run flag + D-04 default-dry-run command wiring |
| SYNC-10 | `withoutOverlapping` + adaptive rate-limit middleware — Woo 100/min ceiling | §10 Rate limit + §5 withoutOverlapping on orchestrator |
| SYNC-11 | Filament "Supplier Sync Status" page — last run, duration, counts, per-SKU drill-down | §11 SyncRunResource + RelationManager |
| SYNC-12 | Filament "Import Issues" page — missing-at-supplier, pending, missing cost/price | §11 ImportIssueResource |
| SYNC-13 | Domain events `SupplierPriceChanged`, `SupplierStockChanged`, `SupplierSkuMissing` fire after each row update | §6 + §7 event dispatch with correlation_id |

</phase_requirements>

## Summary

Phase 2 replaces the legacy Stock Updater WordPress plugin with a Laravel 12 + Horizon + Filament 3 pipeline. It extends Phase 1's `WooClient` (adds `get()` for reads; fills the `LogicException` real-write branch), introduces a `ProductVariant` model for Meeting Store's variable-product catalogue (the D-01 expansion), and ships two new Filament Resources (Supplier Sync Status + Import Issues) on top of five new tables (`products`, `product_variants`, `sync_runs`, `sync_errors`, `import_issues`) plus a two-column migration on the existing `alert_recipients` table.

The pipeline is driven by `SyncSupplierCommand` (extends `BaseCommand` for correlation-id threading, runs on `sync-bulk-supervisor` — 1 proc, 1800s timeout). The command defaults to DRY-RUN (D-04 belt-and-braces), requires `--live` to touch Woo. It fetches the full supplier catalogue through `SupplierClient` (JWT-authed against 21stcav.com, 401 refresh-once-on-error), streams Woo's product catalogue through `WooProductIterator` (paginates both simple products and variations at 100/page — Woo's hard cap), then fans out per-page work as `SyncChunkJob`s on `sync-woo-push-supervisor` (2-3 procs, 90s timeout — respects Woo's observed 100 req/min ceiling). A run is resumable: cursor is `(run_id, last_processed_page, last_processed_sku)` persisted on `sync_runs`. Per-item failures write to `sync_errors`; tiered aborts (D-06 a/b/c) flip run status to `aborted` with cursor intact for `--resume`. A CSV report (11 columns per D-10, generated with `spatie/simple-excel`) emails to active `alert_recipients` rows where `receives_sync_reports=true` on completion — both success and aborted paths.

**Primary recommendation:** Ship Phase 2 as **5 plans** along the dependency gradient: (P01) data model + migrations, (P02) SupplierClient + WooClient.get() + JWT middleware, (P03) SyncRun + SyncChunkJob orchestration with cursor persistence, (P04) CSV report + Mailable + `receives_sync_reports` column + Filament Resources, (P05) architectural test (SYNC-04 Deptrac) + sync-errors prune command + stub event listener. Every plan gates on `vendor/bin/pest` + `vendor/bin/deptrac analyse` green.

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `automattic/woocommerce` | ^3.1 (3.1.1 released 2026-01-30) | Official Woo REST client | Official Automattic SDK; thin Guzzle wrapper; PHP 8.5 support added in 3.1.1. `WooClient::$inner` was left as `mixed` in Phase 1 — Phase 2 types it properly. [VERIFIED: packagist.org/packages/automattic/woocommerce] |
| `spatie/simple-excel` | ^3.9 | CSV generation (report output) | Generator-based; constant memory for CSVs of any size; already in STACK.md for Phase 5 CSV ingest. Writer uses `writeHeader()` + `addRow()` + implicit `close()` on destruct. [VERIFIED: github.com/spatie/simple-excel] |
| `laravel/horizon` | ^5.45 (installed) | Queue dashboard + supervisors | Already configured in `config/horizon.php` with `sync-bulk-supervisor` + `sync-woo-push-supervisor` supervisors. No changes needed to the supervisor block. [VERIFIED: config/horizon.php lines 206-215, 196-205] |
| `spatie/laravel-activitylog` | ^4.12 (installed) | Audit log for model changes | Phase 1's `Auditor::record()` wraps this. Phase 2 uses for `sync_runs.status` transitions + `products.price` / `product_variants.price` changes. |
| `filament/filament` | ^3.3 (installed) | Admin UI | SyncRunResource + ImportIssueResource extend Filament 3's `Resource` base. |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `Http::` facade (Guzzle) | Built-in | Supplier API client | Wrap with `Http::retry($times, $sleepMs, when: fn($e) => ...)` for the 401-refresh + Retry-After honouring. STACK.md §3 rejects Saloon for v1 scope. |
| Laravel Mail + Mailable | Built-in | Emailing the CSV report | `Mail::to(AlertRecipient::active()->reportSubscribed())->send(new SupplierSyncReportMail($run))`. Attach CSV via `$this->attach($path)`. |
| `Context::` facade | Built-in (Laravel 12) | Correlation-id threading | Phase 1 wires `Context::hydrated` to re-open `spatie/activitylog` LogBatch on queue-side. Phase 2 just uses `Context::add('correlation_id', ...)` in `BaseCommand::handle()` (already done by base class). |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `automattic/woocommerce` | `guidemaster/laravel-woocommerce` | Thin facade wrapper over Automattic; adds no value, couples us to a low-usage maintainer. **Rejected by STACK.md.** |
| `spatie/simple-excel` | `league/csv` | Lower-level; we'd re-implement Laravel integration. SimpleExcel's generator API is the right abstraction. |
| `spatie/simple-excel` | `maatwebsite/laravel-excel` | Loads whole workbook into RAM; meant for Excel, not CSV. Memory blows up on > 5k rows. **Explicitly out of scope in REQUIREMENTS.md.** |
| In-memory SKU matcher | Redis-backed matcher | 15k SKUs × ~120 bytes = ~2MB RAM — trivially fits. Redis adds a dependency with zero benefit at this scale. |
| Queue-per-SKU dispatch | Chunk-per-page dispatch | Queue-per-SKU = 15k job rows per run = Horizon UI overload. Chunk-per-page (50 SKUs per job) is the middle ground. |

**Installation (additions to Phase 1 composer.json):**
```bash
# Woo REST client — types WooClient::$inner properly (replaces mixed)
composer require "automattic/woocommerce:^3.1"

# CSV generator for sync reports (will also be used by Phase 5)
composer require "spatie/simple-excel:^3.9"
```

**Version verification:**
- `composer show automattic/woocommerce` (after install) should return `3.1.1`. Published 2026-01-30 [VERIFIED via web search].
- `composer show spatie/simple-excel` should return `^3.9.x` (latest 3.x line). The writer's generator-based `addRow()` avoids loading the full dataset into memory [VERIFIED: freek.dev/1488].
- Phase 1 STACK.md needs propagated correction: `spatie/laravel-permission` is `^6.0` (not `^7.2`) and `rmsramos/activitylog` is `^2.0` (not `^1.0`) — already discovered in Phase 1 Plan 01 deviations. Out of Phase 2 scope but planner should flag for CLAUDE.md refresh.

## Project Constraints (from CLAUDE.md)

- **Tech stack fixed:** Laravel 12, PHP 8.2+, Filament 3, Horizon + Redis, MySQL, Pest — no substitutions
- **WooCommerce REST only, never direct WP DB writes** — covered by SYNC-04 Deptrac test
- **Emit domain events from day one** — `SupplierPriceChanged`, `SupplierStockChanged`, `SupplierSkuMissing`, `NewSupplierSkuDetected` all extend `App\Foundation\Events\DomainEvent` (auto correlation_id + occurredAt + `afterCommit`)
- **Audit everything** — every Woo push logs via `IntegrationLogger` (automatic header redaction); every SyncRun state transition writes `Auditor::record('sync.run.{state}', [...])`
- **Suggestions seam** — Phase 2 does not produce suggestions itself, but `NewSupplierSkuDetected` is the Phase 6 AUTO-01 producer event (stub listener only in Phase 2)
- **Feed abstraction** — Phase 2 does not touch FeedGenerator (Phase 8)
- **Parity first** — `--dry-run` (D-04 default) exists specifically to enable Phase 7 parallel-run parity checking

## Architecture Patterns

### Recommended Project Structure

```
app/Domain/Sync/
├── Commands/
│   └── SyncSupplierCommand.php         # extends BaseCommand; orchestrator on sync-bulk queue
├── Services/
│   ├── WooClient.php                    # EXISTING (Phase 1); Phase 2 adds get() + types $inner
│   ├── SupplierClient.php               # NEW — JWT-authed 21stcav.com wrapper
│   ├── WooProductIterator.php           # NEW — paginates /products + /products/{id}/variations
│   ├── SyncDiffEngine.php               # NEW — compares supplier vs Woo, emits actions
│   ├── SkuMatcher.php                   # NEW — in-memory supplier-SKU → Woo (product|variation) map
│   └── AbortGuard.php                   # NEW — D-06 tiered abort counter/policy
├── Jobs/
│   └── SyncChunkJob.php                 # NEW — per-page work unit on sync-woo-push queue
├── Models/
│   ├── SyncDiff.php                     # EXISTING (Phase 1) — read-only in Phase 2
│   ├── SyncRun.php                      # NEW — run lifecycle state machine
│   ├── SyncError.php                    # NEW — per-item failure log
│   └── ImportIssue.php                  # NEW — SYNC-12 catalogue health
├── Events/
│   ├── SupplierPriceChanged.php         # NEW — SYNC-13
│   ├── SupplierStockChanged.php         # NEW — SYNC-13
│   ├── SupplierSkuMissing.php           # NEW — SYNC-13
│   └── NewSupplierSkuDetected.php       # NEW — D-09 + Phase 6 seed
├── Listeners/
│   └── StubNewSupplierSkuListener.php   # NEW — D-09 no-op stub until Phase 6
├── Mail/
│   └── SupplierSyncReportMail.php       # NEW — SYNC-08 Mailable
├── Reports/
│   └── SyncReportCsvGenerator.php       # NEW — D-10 11-column CSV writer
├── Policies/
│   └── SyncRunPolicy.php                # NEW — view for everyone; "Retry" action admin-only
└── Filament/
    └── Resources/
        ├── SyncRunResource.php          # NEW — SYNC-11
        ├── SyncRunResource/RelationManagers/SyncErrorsRelationManager.php
        └── ImportIssueResource.php      # NEW — SYNC-12

app/Domain/Products/                     # NEW — D-01 expansion
├── Models/
│   ├── Product.php                      # NEW
│   └── ProductVariant.php               # NEW
├── Policies/
│   ├── ProductPolicy.php                # NEW — pricing_manager edit, read_only view
│   └── ProductVariantPolicy.php         # NEW — same gating
└── Filament/Resources/
    ├── ProductResource.php              # NEW
    └── ProductVariantResource.php       # NEW (RelationManager on ProductResource too)
```

### Pattern 1: Chunked Orchestrator + Per-Page Job Fan-Out

**What:** `SyncSupplierCommand` (on `sync-bulk` queue — 1 proc, 1800s timeout) does NOT process SKUs itself. It:
1. Creates a `SyncRun` row (`status=queued`, `dry_run=!hasLiveFlag`, `correlation_id` from Context)
2. Fetches the full supplier catalogue into memory (`SupplierClient::fetchAllProducts()` — JWT-authed)
3. Builds the SKU matcher (single pass over supplier feed → hashmap)
4. Paginates Woo via `WooClient::get('products', ['per_page' => 100, 'page' => $n])` — dispatches `SyncChunkJob` per page
5. For variable products: fetches variations per-page and adds to the chunk
6. Waits for all chunks to complete (`Bus::batch` pattern), then finalises run + emails report

**When to use:** Standard pattern for long-running Laravel batch work where per-item failures must not break the whole run. [CITED: PITFALLS.md Pitfall 1 — "Each chunk is its own queued job (`SyncSupplierChunkJob`) that reads from and writes to the `SyncRun` row inside a DB transaction"]

**Example:**
```php
// app/Domain/Sync/Commands/SyncSupplierCommand.php
final class SyncSupplierCommand extends BaseCommand
{
    protected $signature = 'sync:supplier
        {--live : Enable real Woo writes (default is dry-run per D-04)}
        {--dry-run : Explicit dry-run (default; mutually exclusive with --live)}
        {--resume= : Resume an aborted/crashed run by run_id}';

    protected function perform(): int
    {
        $this->assertFlagsNotMutuallyExclusive();  // D-04
        $isLive = $this->option('live');

        $run = $this->option('resume')
            ? SyncRun::findResumable($this->option('resume'))
            : SyncRun::create([
                'started_at' => now(),
                'status' => SyncRun::STATUS_RUNNING,
                'dry_run' => ! $isLive,
                'correlation_id' => Context::get('correlation_id'),
                'cursor_page' => 0,
                'cursor_sku' => null,
            ]);

        try {
            $supplierFeed = app(SupplierClient::class)->fetchAllProducts();  // ~15k SKU hashmap
            $matcher = app(SkuMatcher::class)->build($supplierFeed);

            $batch = Bus::batch([])->name("supplier-sync-{$run->id}")->allowFailures();
            foreach (app(WooProductIterator::class)->pages(fromPage: $run->cursor_page) as $pageData) {
                $batch->add(new SyncChunkJob(
                    runId: $run->id,
                    page: $pageData['page'],
                    skus: $pageData['skus'],  // pre-extracted for speed
                    supplierFeed: $supplierFeed,  // serialised w/ job; ~2MB — OK
                ));
            }
            $dispatchedBatch = $batch->dispatch();

            // Orchestrator waits via polling so --resume semantics stay clean
            $this->pollBatchCompletion($dispatchedBatch, $run);

            $run->finalise();  // status=completed, computes aggregate counts
            app(SyncReportCsvGenerator::class)->generate($run);
            Mail::to(AlertRecipient::reportSubscribers()->get())
                ->send(new SupplierSyncReportMail($run));

            return Command::SUCCESS;
        } catch (AbortException $e) {
            $run->abort($e->getReason());  // D-06/D-07
            app(SyncReportCsvGenerator::class)->generate($run);  // partial report
            Mail::to(AlertRecipient::reportSubscribers()->get())
                ->send(new SupplierSyncReportMail($run, aborted: true));
            return Command::FAILURE;
        }
    }

    private function assertFlagsNotMutuallyExclusive(): void
    {
        if ($this->option('live') && $this->option('dry-run')) {
            throw new \InvalidArgumentException(
                '--live and --dry-run are mutually exclusive (D-04)'
            );
        }
    }
}
```

### Pattern 2: Per-Chunk Atomic Work Unit

**What:** `SyncChunkJob` processes exactly one page of Woo products (≤100 SKUs including variations) atomically. Updates the run cursor on each SKU so a crash mid-chunk loses at most one SKU's progress.

```php
// app/Domain/Sync/Jobs/SyncChunkJob.php
final class SyncChunkJob implements ShouldQueue
{
    public int $tries = 3;
    public int $timeout = 90;  // matches sync-woo-push-supervisor
    public array $backoff = [10, 30, 90];

    public function __construct(
        public readonly int $runId,
        public readonly int $page,
        public readonly array $skus,         // [[sku, woo_product_id, woo_variation_id, type, ...], ...]
        public readonly array $supplierFeed, // sku => [price, stock]
    ) {
        $this->onQueue('sync-woo-push');
    }

    public function handle(
        WooClient $woo,
        SyncDiffEngine $diffEngine,
        AbortGuard $abortGuard,
        EventDispatcher $events
    ): void {
        $run = SyncRun::findOrFail($this->runId);

        foreach ($this->skus as $skuRow) {
            // Idempotency: if another chunk already synced this SKU during a resume, skip
            if ($this->alreadySyncedInThisRun($skuRow, $run)) {
                continue;
            }

            $abortGuard->throwIfTriggered($run);  // D-06 (a)(b)(c)

            try {
                $action = $diffEngine->diff($skuRow, $this->supplierFeed[$skuRow['sku']] ?? null);

                if ($run->dry_run) {
                    // Dry-run: write to sync_diffs via existing WooClient shadow-mode path
                    // (WOO_WRITE_ENABLED=false means WooClient::put() already writes to sync_diffs)
                } else {
                    $woo->put(/* products/{id} or products/{id}/variations/{vid} */, $payload);
                }

                // Emit events AFTER successful write (DomainEvent uses ShouldDispatchAfterCommit semantics)
                $this->dispatchDomainEvents($action, $skuRow, $events);

                $run->incrementCounter($action->type);  // updated/skipped/missing/unknown_sku
                $abortGuard->recordSuccess();
            } catch (\Throwable $e) {
                SyncError::create([
                    'sync_run_id' => $run->id,
                    'sku' => $skuRow['sku'],
                    'woo_product_id' => $skuRow['woo_product_id'] ?? null,
                    'woo_variation_id' => $skuRow['woo_variation_id'] ?? null,
                    'error_message' => $e->getMessage(),
                    'error_class' => $e::class,
                    'correlation_id' => Context::get('correlation_id'),
                ]);
                $run->incrementCounter('failed');
                $abortGuard->recordFailure();  // Tracks consecutive failures for D-06(b)
            }

            // Update cursor after every SKU so crash = at most 1 SKU replay
            $run->update(['cursor_page' => $this->page, 'cursor_sku' => $skuRow['sku']]);
        }
    }
}
```

### Pattern 3: Events Fire After Write, Not Before

**What:** Every successful Woo write dispatches a `SupplierPriceChanged` / `SupplierStockChanged` / `SupplierSkuMissing` event. Events extend `App\Foundation\Events\DomainEvent` which uses `Dispatchable + SerializesModels`. [VERIFIED: app/Foundation/Events/DomainEvent.php]

**Critical constraint (Pitfall 13 from PITFALLS.md):** Events fired inside a DB transaction that later rolls back still queue the listener. Use `DB::afterCommit(fn() => event(...))` OR configure listener as `ShouldQueueAfterCommit`. Phase 1's DomainEvent base DOES NOT currently include `ShouldDispatchAfterCommit` — **Phase 2 verification task:** confirm via test that events dispatched inside a transaction that rolls back don't queue. If they do, add the interface to DomainEvent (Phase 2 hardening candidate).

```php
// app/Domain/Sync/Events/SupplierPriceChanged.php
final class SupplierPriceChanged extends DomainEvent
{
    public function __construct(
        public readonly string $sku,
        public readonly int $wooProductId,
        public readonly ?int $wooVariationId,
        public readonly string $oldPrice,     // strings for 2dp exactness
        public readonly string $newPrice,
        public readonly string $reason = 'supplier_sync',
    ) {
        parent::__construct();  // auto-fills correlationId + occurredAt
    }
}
```

### Pattern 4: Tiered Abort via Counter Service

**What:** `AbortGuard` is a per-run singleton (bound in `AppServiceProvider::boot`) that tracks:
- Total processed count
- Total failure count (→ error rate when processed ≥ 500)
- Consecutive failure count (reset on success)
- JWT refresh failure flag

Called from `SyncChunkJob` after each SKU — `throwIfTriggered()` raises `AbortException` with reason. The job's `failed()` hook catches this and marks the run `aborted`.

```php
// app/Domain/Sync/Services/AbortGuard.php
final class AbortGuard
{
    private const ERROR_RATE_THRESHOLD = 0.20;    // D-06 (a)
    private const ERROR_RATE_MIN_SAMPLES = 500;
    private const CONSECUTIVE_FAILURE_THRESHOLD = 50;  // D-06 (b)

    public function throwIfTriggered(SyncRun $run): void
    {
        $stats = $run->currentStats();  // query or cached Redis counters

        if ($stats->total_processed >= self::ERROR_RATE_MIN_SAMPLES
            && $stats->failure_rate() > self::ERROR_RATE_THRESHOLD) {
            throw new AbortException(SyncRun::ABORT_ERROR_RATE_EXCEEDED);
        }

        if ($stats->consecutive_failures >= self::CONSECUTIVE_FAILURE_THRESHOLD) {
            throw new AbortException(SyncRun::ABORT_CONSECUTIVE_FAILURES);
        }

        if ($stats->jwt_refresh_failed) {
            throw new AbortException(SyncRun::ABORT_JWT_REFRESH_FAILED);
        }
    }
}
```

### Anti-Patterns to Avoid

- **Batch all SKUs into one job:** Pitfall 1 + 8 — single monolithic job blocks the queue and loses cursor on crash. Use per-page chunking.
- **Compute SKU match per-write:** O(n²) blow-up on 15k products. Build matcher once at orchestrator start.
- **Queue listener for Auditor writes:** Auditor::record runs inline (already the Phase 1 pattern); don't queue it — audit is belt-and-braces synchronous.
- **Re-fetch Woo tag/meta per SKU:** Cache during the single iterator pass (§8).
- **Use `queue:listen` on production:** Horizon-only per Pitfall 8.
- **Sync-side stock writes on unchanged supplier stock:** Pitfall 18 race condition — only PUT when supplier value changed since last sync. `SyncDiffEngine` checks this.
- **Write `sync_runs` as simple insert then update:** Use model's guarded `$fillable` + explicit state transition methods (`markRunning()`, `abort($reason)`, `finalise()`) for auditability.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Woo REST pagination | Custom `while($data = http()->get(...))` loop | `Automattic\WooCommerce\Client` with explicit `per_page` + `page` | Official client handles URL signing, `?per_page=100` max, error envelope parsing. [VERIFIED: packagist] |
| JWT refresh-on-401 | Manual try/catch with re-fetch | `Http::retry(2, 500, when: fn($e) => $e->response?->status() === 401)` with middleware that swaps header | Laravel's retry-facade handles the sleep + re-execution correctly; manual loops have off-by-one retry bugs. |
| CSV writing | `fputcsv()` loop with manual header + quoting | `spatie/simple-excel::create($path)->addRow([...])` | Generator-based writer; handles Excel-safe quoting; constant memory. [VERIFIED: freek.dev/1488] |
| Event correlation | Stuff correlation_id into every event manually | `App\Foundation\Events\DomainEvent` base (Phase 1) — auto-fills | Phase 1 already wires this; test coverage proven. |
| Integration logging | Write rows to `integration_events` directly | `IntegrationLogger::log([...])` service (Phase 1) | Auto-redacts 6 sensitive headers; auto-threads correlation_id; foundation contract. |
| Throttled failed-job alerts | Roll your own dedup | `ThrottledFailedJobNotifier` (Phase 1) listens on `JobFailed` | Already handles 5-min Cache::add atomic lock; D-06 aborts fire through same path. |
| Horizon supervisor config | Hand-craft YAML | Phase 1's `config/horizon.php` — `sync-bulk-supervisor` + `sync-woo-push-supervisor` already defined | No changes needed to the supervisor block. |
| Woo write shadow mode | Branch every writer | `WooClient::put/post/patch/delete` — existing Phase 1 class has the gate | Phase 2 ONLY extends: adds `get()` + fills the `LogicException` real-write branch. Do NOT touch `writeOrShadow()`. |
| Tag / meta filtering | Query Woo per-product | Cache the `tags` + `meta_data` arrays during iteration | Single fetch per Woo API call; re-use for exclusion + custom-ms check. |
| HMAC on outbound Woo | Compute base64(hash_hmac(...)) | Automattic client handles this for non-HTTPS endpoints; our Woo is HTTPS → consumer key/secret in query args | STACK.md Known Friction Point — "Automattic client HTTP defaults". |

**Key insight:** Phase 1 delivered a fully-tested Foundation (IntegrationLogger, DomainEvent, BaseCommand, Auditor, WooClient skeleton, HMAC middleware, Horizon supervisors, ThrottledFailedJobNotifier). Phase 2's job is to *consume* these without extending them — the only Phase 1 class Phase 2 modifies is `WooClient` (additive: `get()` method + type `$inner`).

## Runtime State Inventory

> Phase 2 is greenfield — not a rename/refactor. All five new tables (products, product_variants, sync_runs, sync_errors, import_issues) have no pre-existing runtime state. The one additive migration (`alert_recipients.receives_sync_reports`) is a pure column add with a `default(true)` backfill. No OS-registered state, no live service config. Nothing to inventory.

**Nothing found — verified by absence of prior Phase 2 artefacts in .planning/STATE.md and confirmed by the 5 existing migrations in database/migrations/ (all Phase 1).**

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.2+ | All Laravel code | ✓ | 8.3 (Herd on Windows; production VPS will match) | — |
| MySQL 8 | All models | ✓ | Local Docker MySQL 8.4; testing DB `meetingstore_ops_testing` already granted | — |
| Redis 7.x | Horizon queues | ✓ | Local Docker (AOF config already set) + prod VPS needs `/etc/redis/redis.conf` per Phase 1 user-setup | — |
| `automattic/woocommerce` package | WooClient.$inner typing | ✗ | — | Phase 2 composer-requires it; no fallback needed |
| `spatie/simple-excel` package | CSV report | ✗ | — | Phase 2 composer-requires it |
| Woo REST credentials (WOO_URL, WOO_CONSUMER_KEY, WOO_CONSUMER_SECRET) | Live writes only | Unknown | — | Dry-run works without them (D-04 default); live writes blocked until Phase 7 cutover anyway |
| 21stcav.com API credentials (SUPPLIER_API_URL, SUPPLIER_API_USERNAME, SUPPLIER_API_PASSWORD) | Any real run | Unknown | — | Dry-run with fixture data; live run requires operator to populate `.env` |
| Tesseract / OCR binaries | Not used | — | — | — |

**Missing dependencies with no fallback:** None — both composer packages install clean.

**Missing dependencies with fallback:** Woo + 21stcav credentials can be deferred — dry-run path works without them in tests using Guzzle mocks.

## Implementation Focus Areas

### §1 Product + ProductVariant Data Model (D-01)

**`products` table (new):**
```php
Schema::create('products', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('woo_product_id')->unique();  // Woo source of identity
    $t->string('sku', 100)->nullable()->index();          // null for variable-parent; non-null for simple
    $t->string('name');
    $t->enum('type', ['simple', 'variable', 'grouped', 'external'])->default('simple')->index();
    $t->enum('status', ['publish', 'pending', 'draft', 'private'])->default('publish')->index();
    $t->enum('stock_status', ['instock', 'outofstock', 'onbackorder'])->default('instock');
    $t->decimal('buy_price', 12, 4)->nullable();     // From supplier (ex-VAT)
    $t->decimal('sell_price', 12, 4)->nullable();    // Computed (Phase 3 overwrites via listener)
    $t->decimal('cost_price', 12, 4)->nullable();    // Historical — kept for auditing
    $t->boolean('is_custom_ms')->default(false)->index();           // Cached from Woo tags
    $t->boolean('exclude_from_auto_update')->default(false)->index(); // Cached from Woo meta
    $t->json('tags')->nullable();                    // Full Woo tag array (slug+name+id) for future use
    $t->timestamp('last_synced_at')->nullable()->index();
    $t->uuid('last_sync_run_id')->nullable();        // Cross-ref to SyncRun
    $t->timestamps();
    $t->softDeletes();
});
```

**`product_variants` table (new):**
```php
Schema::create('product_variants', function (Blueprint $t) {
    $t->id();
    $t->foreignId('product_id')->constrained()->cascadeOnDelete();
    $t->unsignedBigInteger('woo_variation_id')->unique();
    $t->string('sku', 100)->unique();  // Variation SKUs are always unique in Woo
    $t->string('name')->nullable();    // e.g., "Red, Large"
    $t->decimal('buy_price', 12, 4)->nullable();
    $t->decimal('sell_price', 12, 4)->nullable();
    $t->decimal('old_buy_price', 12, 4)->nullable();  // For diff engine 1-sync lookback
    $t->decimal('old_sell_price', 12, 4)->nullable();
    $t->integer('stock_quantity')->default(0);
    $t->integer('old_stock_quantity')->nullable();
    $t->enum('status', ['publish', 'private'])->default('publish')->index();  // variations can't be 'pending'
    $t->json('attributes')->nullable();  // [{name: "Colour", option: "Red"}, ...]
    $t->timestamp('last_synced_at')->nullable()->index();
    $t->timestamps();
});
```

**Relationship:**
```php
class Product extends Model {
    public function variants(): HasMany {
        return $this->hasMany(ProductVariant::class);
    }
}
class ProductVariant extends Model {
    public function product(): BelongsTo {
        return $this->belongsTo(Product::class);
    }
}
```

**Critical data-model decisions:**
- **Woo is the source of identity:** `woo_product_id` and `woo_variation_id` are unique — the Laravel `id` is purely local. Never expose Laravel IDs to Woo writes.
- **`products.sku` is nullable:** Variable parents in Woo have empty SKU at the parent level — every variation has its own SKU. The unique is therefore on `woo_product_id` only, not on `sku`. [VERIFIED: WooCommerce REST docs — woocommerce-rest-api-docs/wp-api-v3/_product-variations.md]
- **`stock_status` vs `stock_quantity`:** Woo tracks both at parent + variation level; `manage_stock` flag governs which is authoritative. For variable products, `manage_stock: 'parent'` on a variation means it inherits parent stock. Phase 2 stores both raw values and respects the flag via `SyncDiffEngine`. [VERIFIED via web search — "Stock management at the variation level is controlled by the `manage_stock` parameter, which can be either a boolean or 'parent', with a default of false"]
- **`old_*` columns on ProductVariant** (not Product): we diff variations against their prior sync values; for simple products the diff is against Woo (since Phase 2 IS the source of local state, there's no pre-history). One-sync lookback is enough — longer history lives in `activity_log` via `LogsActivity` trait.

### §2 SupplierClient (21stcav.com API)

**Expected API shape** (MEDIUM confidence — from PROJECT.md "external systems" table + legacy plugin memory; planner should confirm with ops team in Phase 2 kickoff):

```
POST /generate_token.php
  Body: {"username": "...", "password": "..."}
  Response: {"token": "<jwt>", "expires_in": 3600}  // assumed 1h TTL

GET /api/index.php?endpoint=products&page=1&per_page=500
  Header: Authorization: Bearer <jwt>
  Response: {"data": [{"sku": "...", "price": "...", "stock": 5, ...}, ...], "next_page": 2}
```

**JWT lifecycle:**
- Token cached in `Cache::remember('supplier.jwt.token', now()->addSeconds($expires_in - 60), fn() => $this->generateToken())` — subtract 60s for clock-skew safety margin. [CITED: PITFALLS.md Pitfall 12 — "Cache token in Redis with TTL = (token_expiry - 60s) so app-side eviction fires before API-side expiry"]
- On 401: invalidate cache, generate fresh token, retry request ONCE (D-06c locks this: second 401 = abort, don't loop).
- Token cache key includes `SUPPLIER_API_USERNAME` so credential rotation naturally invalidates: `supplier.jwt.{md5($username)}`.

**Client shape:**
```php
final class SupplierClient
{
    public function __construct(
        private IntegrationLogger $logger,
        private Repository $cache
    ) {}

    /** Returns all SKUs flat: ['SKU-123' => ['price' => '199.00', 'stock' => 5], ...]  ~15k entries. */
    public function fetchAllProducts(): array
    {
        $out = [];
        $page = 1;
        do {
            $response = $this->authed(fn() => Http::baseUrl(config('services.supplier.url'))
                ->withToken($this->getToken())
                ->timeout(30)
                ->retry(
                    times: 2,
                    sleepMilliseconds: 500,
                    when: fn (\Throwable $e) => $e instanceof ConnectionException
                )
                ->get('/api/index.php', ['endpoint' => 'products', 'page' => $page, 'per_page' => 500])
            );

            $this->logger->log([/* channel=supplier, operation=fetch.page.N, ... */]);

            if ($response->status() === 401) {
                throw new JwtRefreshFailedException();  // D-06(c) trigger
            }

            foreach ($response->json('data') as $row) {
                $out[$row['sku']] = ['price' => $row['price'], 'stock' => $row['stock']];
            }

            $page++;
            $hasMore = $response->json('next_page') !== null;
        } while ($hasMore);

        return $out;
    }

    private function authed(Closure $call): Response {
        try {
            return $call();
        } catch (RequestException $e) {
            if ($e->response?->status() !== 401) throw $e;
            $this->cache->forget($this->tokenCacheKey());
            return $call();  // retry ONCE with fresh token — if this 401s, let the exception propagate
        }
    }
}
```

**.env additions:**
```
SUPPLIER_API_URL=https://21stcav.com
SUPPLIER_API_USERNAME=
SUPPLIER_API_PASSWORD=
```
Plan 01 of Phase 2 ships these keys in `.env.example`; operator populates real values before running the first live sync.

### §3 WooProductIterator

**Woo's hard constraint:** `per_page=100` maximum for `/products` endpoint. [VERIFIED: STACK.md §1 + PITFALLS.md Pitfall 15] Pagination via `?page=N` query param.

**Response shape** (from WooCommerce REST API v3 docs):
```json
[
  {
    "id": 1234,
    "name": "...",
    "sku": "SIMPLE-ABC",
    "type": "simple",
    "status": "publish",
    "price": "199.00",
    "stock_status": "instock",
    "stock_quantity": 5,
    "manage_stock": true,
    "tags": [{"id": 42, "name": "Custom MS", "slug": "custom-ms"}],
    "meta_data": [{"id": 88, "key": "_exclude_from_auto_update", "value": "yes"}],
    "variations": []
  },
  {
    "id": 1235,
    "name": "Red Chair",
    "sku": "",                              // empty at parent for variable products
    "type": "variable",
    "variations": [5001, 5002, 5003],       // variation IDs
    "tags": [...],
    "meta_data": [...]
  }
]
```

**Iterator pattern — yields a flat stream of "sync units":**
```php
final class WooProductIterator
{
    public function __construct(private WooClient $woo) {}

    /** @return \Generator yielding ['page' => int, 'skus' => array] per page */
    public function pages(int $fromPage = 1): \Generator
    {
        $page = max(1, $fromPage);
        do {
            $products = $this->woo->get('products', ['per_page' => 100, 'page' => $page]);
            if (empty($products)) break;

            $skus = [];
            foreach ($products as $p) {
                $isCustomMs = $this->hasSlug($p['tags'] ?? [], 'custom-ms');
                $excludeFlag = $this->hasMeta($p['meta_data'] ?? [], '_exclude_from_auto_update', 'yes');

                if ($p['type'] === 'simple') {
                    $skus[] = [
                        'type' => 'simple',
                        'sku' => $p['sku'],
                        'woo_product_id' => $p['id'],
                        'woo_variation_id' => null,
                        'price' => $p['price'],
                        'stock_quantity' => $p['stock_quantity'] ?? 0,
                        'manage_stock' => $p['manage_stock'] ?? false,
                        'is_custom_ms' => $isCustomMs,
                        'exclude_from_auto_update' => $excludeFlag,
                    ];
                } elseif ($p['type'] === 'variable') {
                    // Fetch variations in bulk per parent
                    $vars = $this->woo->get("products/{$p['id']}/variations", ['per_page' => 100]);
                    foreach ($vars as $v) {
                        $skus[] = [
                            'type' => 'variation',
                            'sku' => $v['sku'],
                            'woo_product_id' => $p['id'],
                            'woo_variation_id' => $v['id'],
                            'price' => $v['price'],
                            'stock_quantity' => $v['stock_quantity'] ?? 0,
                            'manage_stock' => $v['manage_stock'] ?? false,
                            'is_custom_ms' => $isCustomMs,  // inherits parent's tag
                            'exclude_from_auto_update' => $excludeFlag,  // inherits parent's meta
                            'attributes' => $v['attributes'] ?? [],
                        ];
                    }
                }
                // grouped/external: skip (out of scope v1)
            }

            yield ['page' => $page, 'skus' => $skus];
            $page++;
        } while (count($products) === 100);  // full page = maybe-more; < 100 = last page
    }
}
```

**Gotcha — variation count per parent:** For variable products with >100 variations (rare for AV kit but possible on configurable items), the inner `/products/{id}/variations` call itself needs pagination. Handle via the same `per_page=100` + `page=N` loop. [VERIFIED via WooCommerce REST docs]

### §4 SyncRun Lifecycle + State Machine

**`sync_runs` table (new):**
```php
Schema::create('sync_runs', function (Blueprint $t) {
    $t->id();
    $t->timestamp('started_at')->index();
    $t->timestamp('completed_at')->nullable();
    $t->enum('status', ['queued', 'running', 'completed', 'aborted', 'failed'])
        ->default('queued')->index();
    $t->boolean('dry_run')->default(true);

    // Aggregate counts (denormalised for report speed)
    $t->integer('total_skus')->default(0);
    $t->integer('updated_count')->default(0);
    $t->integer('skipped_count')->default(0);
    $t->integer('failed_count')->default(0);
    $t->integer('missing_count')->default(0);
    $t->integer('unknown_sku_count')->default(0);

    // Abort metadata
    $t->enum('abort_reason', ['error_rate', 'consecutive_failures', 'jwt_refresh', 'manual'])->nullable();
    $t->text('abort_message')->nullable();

    // Cursor persistence — enables SYNC-03 resume + D-07 abort-resume
    $t->integer('cursor_page')->default(0);
    $t->string('cursor_sku', 100)->nullable();

    $t->uuid('correlation_id')->index();
    $t->timestamps();
});
```

**Model — explicit state transitions (NEVER raw save-then-update):**
```php
class SyncRun extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABORTED = 'aborted';
    public const STATUS_FAILED = 'failed';

    public const ABORT_ERROR_RATE = 'error_rate';
    public const ABORT_CONSECUTIVE = 'consecutive_failures';
    public const ABORT_JWT_REFRESH = 'jwt_refresh';
    public const ABORT_MANUAL = 'manual';

    public function markRunning(): void {
        $this->update(['status' => self::STATUS_RUNNING]);
        app(Auditor::class)->record('sync.run.running', ['run_id' => $this->id]);
    }

    public function abort(string $reason, ?string $message = null): void {
        $this->update([
            'status' => self::STATUS_ABORTED,
            'abort_reason' => $reason,
            'abort_message' => $message,
            'completed_at' => now(),
        ]);
        app(Auditor::class)->record('sync.run.aborted', [
            'run_id' => $this->id, 'reason' => $reason, 'message' => $message,
        ]);
    }

    public function finalise(): void {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        app(Auditor::class)->record('sync.run.completed', ['run_id' => $this->id, 'stats' => $this->stats()]);
    }

    public function scopeResumable(Builder $q): Builder {
        return $q->whereIn('status', [self::STATUS_ABORTED, self::STATUS_FAILED, self::STATUS_RUNNING]);
    }

    public static function findResumable(int $id): self {
        $run = self::resumable()->findOrFail($id);
        $run->update(['status' => self::STATUS_RUNNING]);  // flip back to running for resume
        return $run;
    }
}
```

**Why not separate `sync_cursors` table?** CONTEXT.md lines 143-145 explicitly allow denormalising cursor into `sync_runs`. 1 cursor per run, no cross-run cursor queries needed → single row is simpler. If Phase 5 Competitor CSV sync needs cursors too, it'll get its own table with its own shape.

### §5 Chunked Resumable Execution — End-to-End Flow

**Orchestrator → chunks → idempotency:**

1. `SyncSupplierCommand` dispatched to `sync-bulk` queue (1 proc, 1800s timeout per `config/horizon.php`)
2. Creates `SyncRun` row (or resumes existing — `findResumable(id)`)
3. Builds supplier hashmap + SKU matcher in memory (~2MB for 15k SKUs — comfortably within 512MB supervisor memory)
4. Iterates Woo pages from `$run->cursor_page` (0 for fresh run, ≥1 for resume)
5. Each page → one `SyncChunkJob` dispatched to `sync-woo-push` queue (2-3 procs, 90s timeout — respects Woo rate limit)
6. `SyncChunkJob` processes all SKUs in its page, updating `cursor_sku` after each successful write

**Resume correctness:**
- Fresh run: `cursor_page=0`, `cursor_sku=null` → iterator starts page 1
- Resume after crash: `cursor_page=7`, `cursor_sku='SKU-123'` → iterator starts page 7, chunk job skips SKUs until it matches `cursor_sku`, then continues
- Idempotency per SKU within a resume: `if ($product->last_synced_at > $run->started_at) { skip; }` — means a prior chunk in the same run already did this SKU, safe to skip

**Chunk size = 50 SKUs** (default):
- Woo's 100 req/min ceiling → 100/2-3 workers = ~33-50 req/min per worker
- 90s timeout per job → 50 SKUs × ~1.5s average write = 75s (under timeout)
- Variable products: if a page has 10 variable products × 10 variations each, chunk = 100 writes — still under Woo headroom because chunks-per-page run sequentially within the job

**Why not `Bus::batch` with sub-batches?** Batch-wait semantics conflict with `--resume`: if half the batch completes and the orchestrator crashes, the remaining chunks are orphaned in Horizon. Phase 2 uses plain `dispatch()` with cursor-based resumption instead of batch orchestration. If a plan needs batch-wait UX (e.g., "orchestrator polls for all chunks done before emailing"), use `Bus::batch` with `allowFailures()` and `onConnection('redis')` so the batch metadata survives worker crashes.

### §6 Variable Product Sync Logic (D-02 Mixed)

**Branch at iterator time, not at chunk time:** `WooProductIterator` yields `'type' => 'simple'` or `'type' => 'variation'` — the chunk job treats them identically via the matcher. No per-product type branching inside `SyncChunkJob`.

**Write paths per type:**
```php
// SyncDiffEngine action builder
$endpoint = match ($skuRow['type']) {
    'simple' => "products/{$skuRow['woo_product_id']}",
    'variation' => "products/{$skuRow['woo_product_id']}/variations/{$skuRow['woo_variation_id']}",
};
$this->woo->put($endpoint, [
    'regular_price' => $newPrice,
    'stock_quantity' => $newStock,
    // only include fields that changed; empty payload → skipped action, not an update
]);
```

**Parent-product metadata sync:** When a variation updates, the parent's `last_synced_at` should also bump (for the SyncRunResource drill-down to show "parent was touched"). Handle in `ProductVariant::booted()` via Eloquent observer — bump parent's timestamp on variant save.

### §7 Missing Variant Handling (D-03)

**Algorithm:**
1. Before `SyncChunkJob`s dispatch, build `inWoo` set = all SKUs from the iterator pass (simples + variations)
2. Build `inSupplier` set = keys of the supplier hashmap
3. `missing = inWoo - inSupplier` (set difference)
4. For each missing SKU, determine if it's a simple product or a variation (look up in the local Woo data or the match table)
5. Emit `SupplierSkuMissing` event with `{sku, woo_product_id, woo_variation_id, parent_type, has_custom_ms_tag}`
6. Apply the SYNC-06 transition rules:

| In Woo as | `custom-ms` tag? | Action |
|-----------|------------------|--------|
| `type=simple` product | No | `PUT /products/{id}` with `status=pending` (D-03 + SYNC-06) |
| `type=simple` product | Yes | Skip — stays `publish` (D-03 carve-out) |
| `type=variation` under variable parent | N/A (variations don't have tags; parent's tag is checked) | `PUT /products/{parent}/variations/{vid}` with `status=private` (D-03 granular) |
| `type=variation`, parent has `custom-ms` | N/A | Parent's custom-ms tag does NOT exempt variations — missing variation still goes `private` (D-03 explicit: "only that variation flips to private") |

**Important:** Missing-SKU handling runs AFTER the main sync loop, not interleaved. This is because the "which SKUs are missing" computation requires the full Woo iteration pass to complete. Dispatch one final `MarkMissingSkusJob` per run after all sync chunks complete.

### §8 `custom-ms` Tag + `_exclude_from_auto_update` Meta Detection

**Tag matching:**
- Woo returns `tags: [{id: 42, name: "Custom MS", slug: "custom-ms"}]` on every product response
- Match on **slug** (not name), case-insensitive: `collect($product['tags'])->contains(fn($t) => strtolower($t['slug']) === 'custom-ms')`
- Store on `products.is_custom_ms` as a boolean, JSON `tags` column as raw array for future use

**Meta detection:**
- Woo returns `meta_data: [{id: 88, key: "_exclude_from_auto_update", value: "yes"}]`
- Match on key + truthy value: `value === 'yes' || value === '1' || value === true`
- Store on `products.exclude_from_auto_update` as a boolean

**Caching — single-pass design:**
Do NOT re-fetch tags/meta per SKU. The iterator pass (§3) extracts both once per product and passes them via the `$skuRow` array to `SyncChunkJob`. The chunk job reads them without additional HTTP calls.

**Counting skipped products:**
`_exclude_from_auto_update=true` → SyncChunkJob:
- Skips the write
- Still increments `skipped_count` on the run
- Still writes a CSV report row with `action=skipped, reason=exclude_from_auto_update`
- Does NOT emit `SupplierPriceChanged` / `SupplierStockChanged` (nothing changed)

### §9 CSV Report Generator (D-10)

**11 columns (fixed order):**
```
sku, woo_product_id, woo_variation_id, action, reason,
old_price, new_price, old_stock, new_stock, error_message, correlation_id
```

**Writer:**
```php
final class SyncReportCsvGenerator
{
    public function generate(SyncRun $run): string
    {
        $path = storage_path("app/private/sync-reports/run-{$run->id}.csv");
        File::ensureDirectoryExists(dirname($path));

        $writer = SimpleExcelWriter::create($path)->noHeaderRow();
        $writer->addRow([
            'sku', 'woo_product_id', 'woo_variation_id', 'action', 'reason',
            'old_price', 'new_price', 'old_stock', 'new_stock', 'error_message', 'correlation_id',
        ]);

        // Stream rows — denormalised via sync_run_items or an on-the-fly query across SyncError + SyncDiff
        SyncRunItem::forRun($run->id)->chunk(500, function ($chunk) use ($writer) {
            foreach ($chunk as $item) {
                $writer->addRow([
                    $item->sku,
                    $item->woo_product_id,
                    $item->woo_variation_id,
                    $item->action,
                    $item->reason,
                    $item->old_price,
                    $item->new_price,
                    $item->old_stock,
                    $item->new_stock,
                    $item->error_message,
                    $item->correlation_id,
                ]);
            }
        });

        // CRITICAL (Pitfall): Writer file handle is flushed on destruct — but we return the path
        // so the Mailable can attach it. Explicit close ensures buffer flush before read.
        unset($writer);  // triggers __destruct

        return $path;
    }
}
```

**New table needed: `sync_run_items`** — denormalised per-run row log (the source for the CSV). Alternative: compute by UNION ALL of `sync_errors` + synthesised "success" rows from audit log. **Recommendation:** ship `sync_run_items` as a thin table (11 columns + `sync_run_id` FK + created_at). It's append-only (never updated) so storage is cheap, and the CSV generation query is a trivial `WHERE sync_run_id = ?`.

**Actually:** re-reading CONTEXT.md `<specifics>`, the `sync_runs` + `sync_errors` + `sync_diffs` set is what was approved. Adding `sync_run_items` expands scope. **Planner call:** either (a) compute CSV by UNION of `sync_errors` + `sync_diffs` (filtered by correlation_id) + synthesised "skipped/unknown" rows from a per-run in-memory array captured by `SyncChunkJob` and persisted to a JSON column on `sync_runs.result_summary`, OR (b) ship `sync_run_items` as the clean option. Planner should pick (b) unless storage is a concern — it's cleaner and SYNC-11's drill-down reads from it directly.

**Mailable:**
```php
final class SupplierSyncReportMail extends Mailable
{
    public function __construct(public readonly SyncRun $run, public readonly bool $aborted = false) {}

    public function build(): self {
        $subject = $this->aborted
            ? "[ABORTED] Supplier sync {$this->run->id} — {$this->run->abort_reason}"
            : "Supplier sync {$this->run->id} — {$this->run->updated_count} updated";

        return $this->subject($subject)
            ->view('emails.supplier-sync-report')
            ->with([
                'run' => $this->run,
                'aborted' => $this->aborted,
                'stats' => $this->run->only([
                    'total_skus', 'updated_count', 'skipped_count', 'failed_count',
                    'missing_count', 'unknown_sku_count',
                ]),
            ])
            ->attach($this->reportPath());
    }
}
```

**Recipient scope:**
```php
AlertRecipient::query()
    ->where('is_active', true)
    ->where('receives_sync_reports', true)
    ->get();
```

### §10 Rate Limit Handling (Woo 429)

**Woo has no universal hard limit on REST, but:**
- Store API has a `RateLimit-Retry-After` header when throttled [VERIFIED: developer.woocommerce.com/docs/apis/store-api/rate-limiting]
- Host/WAF (Cloudflare, LiteSpeed, etc.) can impose 429s independently
- MySQL lock contention on `_postmeta` can effectively throttle writes past ~100 req/min [VERIFIED: PITFALLS.md Pitfall 15]

**Pattern — exponential backoff + Retry-After honouring:**

```php
// app/Domain/Sync/Services/WooClient.php — Phase 2 addition to writeOrShadow()
private function writeLive(string $method, string $endpoint, array $payload): array
{
    $attempt = 0;
    $maxAttempts = 5;
    $baseDelayMs = 500;

    while ($attempt < $maxAttempts) {
        $attempt++;
        try {
            $response = $this->inner->{strtolower($method)}($endpoint, $payload);
            $this->logger->log([/* success */]);
            return $response;
        } catch (HttpClientException $e) {
            if ($e->response?->status() === 429 && $attempt < $maxAttempts) {
                $retryAfter = (int) ($e->response->header('Retry-After') ?? 0);
                $delay = max($retryAfter * 1000, $baseDelayMs * (3 ** ($attempt - 1)));
                $delay = min($delay, 30_000);  // cap 30s
                usleep($delay * 1000 + random_int(0, 500_000));  // jitter
                continue;
            }
            throw $e;
        }
    }

    throw new RateLimitExceededException("Woo 429 after {$maxAttempts} attempts: {$endpoint}");
}
```

**Contribution to D-06 counters:**
- 429 that exhausts max retries → counts as a single `SyncError` (doesn't spam the consecutive-failure counter 5 times)
- Retry-After is respected as the floor; jitter prevents thundering-herd on retry

### §11 Filament Resources (SYNC-11, SYNC-12)

**`SyncRunResource` (SYNC-11):**
- **Gate:** read-only for everyone (admin + pricing_manager + sales + read_only all `->can('view_any_sync_run')`). "Retry aborted run" action admin-only via `->authorize(fn($record) => auth()->user()?->hasRole('admin'))`.
- **Table columns:** `id`, `started_at`, `completed_at`, `status` (badge), `dry_run` (icon), `updated_count`, `failed_count`, `correlation_id` (truncated w/ copy action)
- **Filters:** `status` (multi-select), `date range`, `dry_run` (boolean), `has_errors` (status=aborted OR failed_count > 0)
- **`getEloquentQuery()`:** `->withCount('errors')` — Pitfall 10 N+1 prevention. Must eager-load before the table renders.
- **Drill-down:** `SyncErrorsRelationManager` read-only lists `sync_errors` for the run with columns `sku`, `woo_product_id`, `error_class`, `error_message`, `created_at`.
- **"Retry" action** (admin only): dispatches `SyncSupplierCommand` with `--resume={run_id} --live` via artisan on queue.

**`ImportIssueResource` (SYNC-12):**
- **Gate:** admin + pricing_manager can edit (`update_import_issue` permission); sales + read_only view-only.
- **Issue types** (enum):
  - `missing_at_supplier` — Woo product has no supplier match (SYNC-06 transitions it to pending)
  - `unknown_sku` — Supplier has a SKU Woo doesn't know (D-09 — Phase 6 producer)
  - `missing_cost_price` — Product in DB but `buy_price` is NULL (populated from ProductResource)
  - `exclude_flag_no_metadata` — Product has `_exclude_from_auto_update` but no rationale in `notes`
- **Actions:** "Mark resolved" (admin + pricing_manager), "Re-check" (forces next sync to reconsider)
- **Filters:** `issue_type`, `resolved` (null vs not null), `date range`

**Shield permission integration:**
- Run `php artisan shield:generate --all --panel=admin` AFTER adding the Resources. **CRITICAL WARNING from Phase 1:** shield:generate regenerates `SuggestionPolicy.php`, `AlertRecipientPolicy.php`, `RolePolicy.php` — ALL of which were hand-edited in Phase 1 to override with `hasRole('admin')`. Plan must include a post-generate audit step that either:
  - (a) greps for `{{ Placeholder }}` literals in `app/Policies/` + `app/Domain/*/Policies/` and fails if found, OR
  - (b) explicitly lists the files to revert from git after generate
- New `SyncRunPolicy` and `ImportIssuePolicy` should be hand-written with `hasRole` gates BEFORE shield:generate runs so the first generate produces only the new Resource's Policy stubs.

**RolePermissionSeeder updates (Plan 02 pattern):**
- `pricing_manager` LIKE patterns add: `%_product`, `%_product_variant`, `%_import_issue` (edit)
- `read_only` LIKE patterns add: `view_any_%`, `view_%` for the above + `%_sync_run`
- `admin` automatically gets everything via existing `*` pattern

### §12 Architectural Test — SYNC-04 Deptrac Extension

**New Deptrac layer `WpDirectDb`:**
```yaml
# depfile.yaml addition
deptrac:
  layers:
    # ... existing layers ...
    - name: WpDirectDb
      collectors:
        - type: classLike
          regex: '^Illuminate\\Support\\Facades\\DB$'
        - type: className
          regex: '^.*mysql_woo.*$'  # any reference to the forbidden connection
    # ... existing Sync layer ...

  ruleset:
    Sync:
      - '+Foundation'
      - '+Products'  # new — Phase 2 Sync reads Product models
      - '-WpDirectDb'  # EXPLICIT DENY — SYNC-04
```

**Positive test (current code has zero violations):**
```php
// tests/Architecture/DeptracSyncLayerTest.php
test('Sync domain does not import DB facade for WP direct writes')
    ->expect(['vendor/bin/deptrac', 'analyse', '--no-progress'])
    ->toExitWithCode(0);
```

**Negative test (seed a violator, run deptrac, assert non-zero, cleanup):**
```php
test('Deptrac fails when Sync imports Illuminate\\Support\\Facades\\DB')
    ->group('architecture')
    ->after(function () {
        @unlink(base_path('app/Domain/Sync/Services/__DeptracViolator.php'));
    })
    ->test(function () {
        $violator = base_path('app/Domain/Sync/Services/__DeptracViolator.php');
        file_put_contents($violator, '<?php namespace App\Domain\Sync\Services; use Illuminate\Support\Facades\DB; class __DeptracViolator { public function bad() { DB::connection("mysql_woo")->table("wp_posts")->update([]); } }');

        $exitCode = 0;
        passthru('vendor/bin/deptrac analyse --no-progress', $exitCode);
        expect($exitCode)->toBeGreaterThan(0);
    });
```

**Key design point:** The negative test uses **real imports that deptrac can resolve** — unresolvable imports get marked `uncovered`, not `violating`. Plan 05 of Phase 1 already learned this [VERIFIED: 01-05-SUMMARY.md §"DeptracTest negative test didn't fire"].

### §Extra — `sync_errors` Prune Command

**Plan 05 of Phase 1 left this TODO in `routes/console.php`:**
```php
// TODO: Phase 2 adds `sync-errors:prune --days=90` (D-07) once Phase 2 ships the sync_errors table.
```

**Phase 2 delivers:**
- `app/Console/Commands/PruneSyncErrorsCommand.php` — extends `BaseCommand` (correlation-id + LogBatch threading), signature `sync-errors:prune {--days=90}`, deletes rows where `created_at < now()->subDays($days)`, writes `Auditor::record('sync-errors.pruned', ['deleted' => $n, 'cutoff' => $cutoff])`.
- Schedule in `routes/console.php` at 03:20 (between existing 03:10 integration_events and 03:30 sync_diffs) with `withoutOverlapping(30)` + `onOneServer()`.
- Retention: 90 days (matches Phase 1 D-07 convention — sync_errors bridge concept was already scoped in Phase 1 CONTEXT.md section §D-07).

## Migration Ordering

Phase 1's last timestamp: `2026_04_18_190000_create_alert_recipients_table.php` [VERIFIED via `ls database/migrations/`].

Phase 2 timestamps — strictly after:
```
2026_04_18_200000_create_products_table.php                        # §1 Product model (D-01)
2026_04_18_200100_create_product_variants_table.php                # §1 ProductVariant (D-01)
2026_04_18_200200_create_sync_runs_table.php                       # §4 SyncRun
2026_04_18_200300_create_sync_errors_table.php                     # Per-item failure log
2026_04_18_200400_create_import_issues_table.php                   # SYNC-12 + D-09
2026_04_18_200500_create_sync_run_items_table.php                  # §9 CSV source (if planner picks option b)
2026_04_18_200600_add_receives_sync_reports_to_alert_recipients.php # D-08 additive column
```

**Rationale:**
- `200000` series cleanly after Phase 1's `190000`
- FK ordering: Products → ProductVariants (FK); SyncRuns → SyncErrors (FK); SyncRuns → SyncRunItems (FK)
- `alert_recipients` alter must come last so rollback is safe (alter exists on an already-populated table)

## Pitfalls Specific to Phase 2

### Pitfall P2-A: `spatie/simple-excel` Writer Must Close Before Attachment
**SYNC-08** | **Severity: CRITICAL**

**What goes wrong:** `SimpleExcelWriter` buffers writes until `__destruct` is called. If the Mailable reads `$this->reportPath()` while the writer is still in scope, the last N rows are missing from the attachment.

**How to avoid:** Explicitly `unset($writer)` or wrap generation in a scoped closure: `$path = tap($writer)->close() ? $path : throw...` — ensure the writer object goes out of scope before `Mail::attach($path)` reads the file. [VERIFIED: github.com/spatie/simple-excel/blob/main/src/SimpleExcelWriter.php — "the file doesn't get written until the instance is garbage collected"]

**Warning sign:** CSV report missing final rows; row count in email body differs from attachment row count.

### Pitfall P2-B: JWT Token Cache Key Collision Across Credentials
**SYNC-02** | **Severity: MAJOR**

**What goes wrong:** Operator rotates SUPPLIER_API_PASSWORD in `.env` but the old JWT is still in Redis. Next run authenticates with the old token, gets 401, refreshes, succeeds — but if the cache lookup happens before credential change is detected, there's a window where stale token is used.

**How to avoid:** Cache key includes `md5($username.$password_hash)` so credential rotation naturally invalidates. Or: on boot, compare `Cache::get('supplier.credentials.hash')` to current env hash, flush token cache if different.

### Pitfall P2-C: Parent-Variant Clock Drift on `last_synced_at`
**SYNC-13** | **Severity: MINOR**

**What goes wrong:** ProductVariant updates its `last_synced_at`, but parent Product's `last_synced_at` isn't bumped. SyncRunResource drill-down shows "parent last synced 3 days ago" even though a variation was touched 5 minutes ago.

**How to avoid:** Eloquent observer on `ProductVariant::saved` → `$this->product->touch('last_synced_at')`. Document explicitly so future devs don't remove it thinking it's unnecessary.

### Pitfall P2-D: Memory Growth in SKU Matcher on Catalog Expansion
**D-01 expansion** | **Severity: MINOR (now); MAJOR if catalogue grows)**

**What goes wrong:** 15k SKUs × ~120 bytes = 1.8MB — fine. If MeetingStore expands to 50k+ SKUs (Phase 12 B2B catalogue hypothesis), the hashmap serialised into `SyncChunkJob` payload becomes ~6MB per dispatch × N chunks = Redis memory pressure.

**How to avoid:** For v1 (≤20k SKUs), serialise the matcher in the chunk job. For post-v1 scale (flagged in STACK.md friction points), swap to Redis-backed matcher with `Cache::tags('supplier-feed')->remember(...)`.

### Pitfall P2-E: Horizon Worker Lease Expiry Mid-Chunk
**SYNC-10** | **Severity: MAJOR**

**What goes wrong:** `sync-woo-push-supervisor` has `timeout: 90` in config/horizon.php. A chunk of 50 SKUs with 5 variations each = 50-100 Woo writes. At 1.5s each + 429 backoff jitter, total = 75-180s. Worker lease expires → Horizon marks job failed, retries — double-push risk.

**How to avoid:**
- Cap SKUs per chunk at 50 **including variations** (not 50 parent products)
- Increase `sync-woo-push-supervisor` timeout to 120s (already 90s in config — bump to 120 in Phase 2 Plan 03 via config change)
- Idempotency via `last_synced_at > $run->started_at` check skips already-written SKUs on retry

### Pitfall P2-F: Duplicate Chunk Job Execution (Worker Crash + Auto-Retry)
**SYNC-10** | **Severity: MAJOR**

**What goes wrong:** Worker crashes mid-chunk after writing 23/50 SKUs. Horizon retries the same job — second execution starts from SKU 1, double-pushes the 23 already-written ones.

**How to avoid:** `SyncChunkJob::handle()` starts with:
```php
foreach ($this->skus as $skuRow) {
    $product = Product::where('woo_product_id', $skuRow['woo_product_id'])->first();
    if ($product?->last_synced_at?->greaterThan($run->started_at)) {
        continue;  // already synced in this run — idempotent skip
    }
    // ... process SKU
}
```

### Pitfall P2-G: Filament N+1 on SyncRun Drill-Down
**SYNC-11** | **Severity: MINOR (pre-launch); MAJOR (3-6 months in)**

**What goes wrong:** SyncErrorsRelationManager lists 500 errors per run. Each row shows "Product" via a `morph` to Product — without eager load, that's 500 extra queries per page.

**How to avoid:** Override `getEloquentQuery()->with(['run', 'product'])` and use `withCount` for aggregate columns. Phase 1 Plan 04 already shipped this pattern for SuggestionResource — copy-paste. [VERIFIED: 01-04-SUMMARY.md — "getEloquentQuery()->with(['resolvedByUser']) eager-loads the belongsTo so the 'Resolved by' column does not fire N+1 queries"]

### Pitfall P2-H: `shield:generate` Regenerates Hand-Edited Policies
**SYNC-11 + SYNC-12** | **Severity: CRITICAL**

**What goes wrong:** Adding `SyncRunResource` and `ImportIssueResource` requires `shield:generate --all`. The Phase 1 handoff documented that this regenerates `SuggestionPolicy.php`, `AlertRecipientPolicy.php`, `RolePolicy.php` — losing the `hasRole('admin')` overrides AND reintroducing `{{ Placeholder }}` literals in RolePolicy.

**How to avoid:**
1. BEFORE running shield:generate, commit the current hand-edited policies (so you have git diff to revert)
2. Run shield:generate
3. Run a grep: `grep -rn '{{ ' app/Policies/ app/Domain/*/Policies/` — fails CI if found
4. Restore hand-edited policies via `git checkout HEAD -- app/Policies/RolePolicy.php app/Domain/Suggestions/Policies/SuggestionPolicy.php app/Domain/Alerting/Policies/AlertRecipientPolicy.php`
5. Add a Pest architecture test that greps for `{{ ` in all Policy files — permanent guardrail

### Pitfall P2-I: Events Fired Inside Transactions That Roll Back
**SYNC-13** | **Severity: MAJOR**

**What goes wrong:** `SyncChunkJob` wraps writes in `DB::transaction(fn() => ...)` for atomicity. Inside the transaction, a `SupplierPriceChanged` event is dispatched. If the transaction rolls back, Laravel still queues the listener — Phase 3's price recompute fires for a price change that never happened.

**How to avoid:** Phase 1's `DomainEvent` base does NOT include `ShouldDispatchAfterCommit`. Either:
- (a) Add it to the base class: `abstract class DomainEvent implements ShouldDispatchAfterCommit` — retroactive fix affects all Phase 1 events, needs regression testing
- (b) Wrap event dispatches in `DB::afterCommit(fn() => event(...))` at Phase 2 call sites

Recommendation: **(a)** — aligns with PITFALLS.md Pitfall 13 guidance, single code change, Phase 1's test suite re-runs will surface any regressions.

### Pitfall P2-J: Stock-Write Race Condition (Pitfall 18 Redux)
**SYNC-13 + SYNC-06** | **Severity: MAJOR**

**What goes wrong:** 02:30 sync reads Woo stock = 5. At 02:30:20 a customer orders 3 units; Woo decrements to 2. Sync writes back 5. Oversold condition.

**How to avoid:** Only write stock when supplier value ≠ last-known supplier value. Track in `Product.old_*` columns. On sync:
```php
if ($supplierStock !== $product->last_known_supplier_stock) {
    $delta = $supplierStock - $product->last_known_supplier_stock;
    $newWooStock = $currentWooStock + $delta;  // apply delta, not absolute
    $woo->put("products/{$id}", ['stock_quantity' => $newWooStock]);
    $product->update(['last_known_supplier_stock' => $supplierStock]);
}
```

Document assumption: Meeting Store's B2B traffic at 02:00-03:00 UTC is near-zero, so absolute overwrite is "probably fine" — but the delta approach is the strict-correct path. [CITED: PITFALLS.md Pitfall 18]

### Pitfall P2-K: `ShadowModeTest` Still Asserts LogicException Path
**D-04 default dry-run** | **Severity: MINOR**

**What goes wrong:** Phase 1's `tests/Feature/ShadowModeTest.php` asserts that `WooClient::put()` throws `LogicException` when `services.woo.write_enabled=true` (Phase 1's placeholder for Phase 2 real writes). Once Phase 2 fills that branch with real HTTP calls, this test fails.

**How to avoid:** Phase 2 Plan 02 updates ShadowModeTest — the `LogicException` test becomes "invokes Automattic\WooCommerce\Client with signed request" (tested via HTTP faker). The shadow-mode branch (flag=false) stays unchanged and all other ShadowModeTest cases pass.

## Code Examples

### Register the Automattic WooCommerce Client in the DI Container

```php
// app/Providers/AppServiceProvider.php (addition)
public function register(): void
{
    $this->app->singleton(\Automattic\WooCommerce\Client::class, function ($app) {
        return new \Automattic\WooCommerce\Client(
            config('services.woo.url'),
            config('services.woo.consumer_key'),
            config('services.woo.consumer_secret'),
            [
                'version' => 'wc/v3',
                'timeout' => 30,       // default 10s is too short for bulk writes
                'verify_ssl' => app()->isProduction(),  // local dev may use self-signed
            ]
        );
    });

    $this->app->singleton(WooClient::class, function ($app) {
        return new WooClient(
            $app->make(IntegrationLogger::class),
            $app->make(\Automattic\WooCommerce\Client::class)
        );
    });
}
```

### Handle JWT Refresh-on-401 with Laravel HTTP Facade

```php
// app/Domain/Sync/Services/SupplierClient.php (key method)
private function getToken(): string
{
    return $this->cache->remember(
        $this->tokenCacheKey(),
        now()->addMinutes(50),  // 10-min safety margin on 60-min token
        function () {
            $response = Http::post(
                config('services.supplier.url') . '/generate_token.php',
                [
                    'username' => config('services.supplier.username'),
                    'password' => config('services.supplier.password'),
                ]
            );

            $this->logger->log([
                'channel' => 'supplier',
                'operation' => 'token.generate',
                'status' => $response->successful() ? 'success' : 'failed',
                'http_status' => $response->status(),
            ]);

            throw_unless(
                $response->successful(),
                new JwtRefreshFailedException('Supplier token generation failed: HTTP ' . $response->status())
            );

            return $response->json('token');
        }
    );
}
```

### Filament Resource with Eager-Load (Pitfall 10 Prevention)

```php
// app/Domain/Sync/Filament/Resources/SyncRunResource.php (key method)
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()
        ->withCount('errors')   // for the "Errors" column — zero per-row queries
        ->latest('started_at');
}
```

## State of the Art

| Old Approach | Current Approach (Phase 2 chooses) | When Changed | Impact |
|--------------|------------------------------------|--------------|--------|
| Monolithic Stock Updater single-file plugin | Laravel modular monolith with per-queue Horizon supervisors | Phase 1 shipped supervisors; Phase 2 uses them | Crashes recover; no queue starvation |
| Direct `wpdb->update()` writes | REST-only via Automattic client (SYNC-04) | Phase 2 enforces via Deptrac | Future Shopify/Amazon channel expansion possible |
| Single-SKU REST PUT per product | Per-page chunk jobs (50 SKUs/chunk) with in-memory matcher | Phase 2 | 80-90% throughput improvement [CITED: FEATURES.md A.3] |
| In-memory JWT token | Redis-cached with TTL = token_expiry - 60s | Phase 2 | Cross-process sync (multiple workers share token) |
| "Sync all, email count" | 11-column per-SKU CSV with correlation_id cross-refs | Phase 2 D-10 | Trace any SKU's journey through audit_log + integration_events + sync_errors |
| Flat-tier margin on sync | Sync writes supplier buy_price; Phase 3 listener computes sell_price | Phase 3 | Decouples sync from pricing |

**Deprecated/outdated:**
- Stock Updater plugin's "sync from admin button" — replaced by `php artisan sync:supplier` + Filament "Retry" action
- itgalaxycompany Bitrix24 plugin — replaced by Phase 4 (CRM), not Phase 2
- Direct `fputcsv()` for reports — replaced by `spatie/simple-excel`

## Validation Architecture

> `workflow.nyquist_validation` is `false` in `.planning/config.json` — this section is included for structural rigour per the output prompt, but it is NOT required by the gsd config.

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 3.8.6 (built on PHPUnit 11.5.55) |
| Config file | `phpunit.xml` — `DB_DATABASE=meetingstore_ops_testing` override active |
| Quick run command | `vendor/bin/pest --filter=<TestClassName>` |
| Full suite command | `vendor/bin/pest` + `vendor/bin/deptrac analyse --no-progress` + `vendor/bin/phpstan analyse` + `vendor/bin/pint --test` |
| Phase 2 gate | All four clean + `--min=60` Pest coverage (existing CI threshold) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| SYNC-01 | Scheduled daily job pulls supplier products | integration | `vendor/bin/pest --filter=SupplierClientTest` | ❌ Wave 0 |
| SYNC-02 | JWT auto-refreshed on 401, retried once | unit | `vendor/bin/pest --filter=SupplierClientJwtRefreshTest` | ❌ Wave 0 |
| SYNC-03 | Crashed run resumable via `--resume` | feature | `vendor/bin/pest --filter=SyncResumeTest` | ❌ Wave 0 |
| SYNC-04 | No direct WP DB writes (Deptrac) | architecture | `vendor/bin/pest --filter=DeptracSyncLayerTest` | ❌ Wave 0 |
| SYNC-05 | Batch per-item failures → `sync_errors` | feature | `vendor/bin/pest --filter=SyncChunkFailureTest` | ❌ Wave 0 |
| SYNC-06 | Missing-at-supplier → pending (custom-ms stays publish) | feature | `vendor/bin/pest --filter=MissingSkuHandlingTest` | ❌ Wave 0 |
| SYNC-07 | `_exclude_from_auto_update` products skipped + counted | feature | `vendor/bin/pest --filter=ExcludeFromAutoUpdateTest` | ❌ Wave 0 |
| SYNC-08 | CSV report emailed on completion | feature | `vendor/bin/pest --filter=SyncReportMailTest` | ❌ Wave 0 |
| SYNC-09 | Dry-run writes only to sync_diffs | feature | `vendor/bin/pest --filter=DryRunModeTest` | ❌ Wave 0 |
| SYNC-10 | Rate-limit via `withoutOverlapping` + 429 backoff | unit + feature | `vendor/bin/pest --filter=WooRateLimitTest` | ❌ Wave 0 |
| SYNC-11 | Filament SyncRunResource page + drill-down | feature (Livewire::test) | `vendor/bin/pest --filter=SyncRunResourceTest` | ❌ Wave 0 |
| SYNC-12 | Filament ImportIssueResource page | feature | `vendor/bin/pest --filter=ImportIssueResourceTest` | ❌ Wave 0 |
| SYNC-13 | Domain events fire with correlation_id | feature | `vendor/bin/pest --filter=SupplierEventDispatchTest` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `vendor/bin/pest --filter=<TestsForChangedFiles>` (< 10s)
- **Per wave merge:** `vendor/bin/pest` full suite + `vendor/bin/deptrac analyse --no-progress` (< 60s combined)
- **Phase gate:** Full CI (Pint + Larastan + Deptrac + Pest --min=60) green

### Wave 0 Gaps
- [ ] `tests/Feature/SupplierClientTest.php` — covers SYNC-01, SYNC-02
- [ ] `tests/Feature/SupplierClientJwtRefreshTest.php` — covers SYNC-02 (401-retry-once)
- [ ] `tests/Feature/SyncResumeTest.php` — covers SYNC-03, D-07
- [ ] `tests/Architecture/DeptracSyncLayerTest.php` — covers SYNC-04 (positive + negative)
- [ ] `tests/Feature/SyncChunkFailureTest.php` — covers SYNC-05, D-06
- [ ] `tests/Feature/MissingSkuHandlingTest.php` — covers SYNC-06, D-03
- [ ] `tests/Feature/ExcludeFromAutoUpdateTest.php` — covers SYNC-07
- [ ] `tests/Feature/SyncReportMailTest.php` — covers SYNC-08, D-08, D-10
- [ ] `tests/Feature/DryRunModeTest.php` — covers SYNC-09, D-04
- [ ] `tests/Feature/WooRateLimitTest.php` — covers SYNC-10
- [ ] `tests/Feature/SyncRunResourceTest.php` — covers SYNC-11
- [ ] `tests/Feature/ImportIssueResourceTest.php` — covers SYNC-12, D-09
- [ ] `tests/Feature/SupplierEventDispatchTest.php` — covers SYNC-13
- [ ] `tests/Feature/PolicyTemplateIntegrityTest.php` — permanent guardrail for Pitfall P2-H (greps `{{ ` in policies)

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | 21stcav.com exposes `/generate_token.php` + `/api/index.php` JSON endpoints | §2 SupplierClient | If the real API uses SOAP or different paths, `SupplierClient` needs rewriting. **Ops must confirm at Phase 2 kickoff.** |
| A2 | Supplier JWT TTL is ~1 hour (60 min) | §2 JWT lifecycle | If 15 min, the 50-min cache TTL fails — sync dies mid-run before the refresh logic kicks in. Mitigation: if unknown, start conservative with 5-min cache and measure actual TTL from first successful token response (`expires_in` field). |
| A3 | Woo `?per_page=100` is the hard cap on `/products` endpoint | §3 WooProductIterator | [VERIFIED via STACK.md + PITFALLS.md Pitfall 15] — this is standard Woo. Low risk. |
| A4 | Meeting Store catalogue is ≤20k SKUs (including variations) | §5 chunk sizing + P2-D | If 50k+, in-memory SKU matcher serialised in SyncChunkJob becomes Redis memory pressure. Flagged as Phase 12 concern. |
| A5 | Woo's response includes `tags` + `meta_data` arrays inline on `/products` — no separate calls needed | §8 tag/meta caching | [VERIFIED via WooCommerce REST API v3 docs] — products endpoint returns both. Low risk. |
| A6 | Ops team is OK with CSV report format (11 columns, D-10 locked) — no custom XLSX sheets needed | §9 CSV report | D-10 explicit in CONTEXT.md. Low risk. |
| A7 | Phase 1's `DomainEvent` base does NOT include `ShouldDispatchAfterCommit` | Pitfall P2-I | [VERIFIED via read of `app/Foundation/Events/DomainEvent.php`] — confirmed. Phase 2 adds the interface; will re-run Phase 1's 92-test suite to catch regressions. |
| A8 | `spatie/simple-excel` writer's `__destruct` is reliable — doesn't need explicit `close()` | Pitfall P2-A | [VERIFIED via github.com/spatie/simple-excel/blob/main/src/SimpleExcelWriter.php] — confirmed "file doesn't get written until the instance is garbage collected". Explicit `unset()` is belt-and-braces. |
| A9 | Woo variations with >100 per parent are rare in MeetingStore catalogue | §3 WooProductIterator | If common, `/products/{id}/variations` needs inner pagination. Mitigation: add inner-pagination loop from the start — marginal code cost, zero behavioural risk. |
| A10 | Bitrix24 SDK availability in Phase 4 does NOT constrain Phase 2 data model | N/A | [VERIFIED via CONTEXT.md `<deferred>` + ROADMAP.md Phase 4 deps] — no Phase 2↔Phase 4 data coupling. Low risk. |

**If A1 or A2 are wrong**, Phase 2 Plan 02 (SupplierClient) needs rework. Plan ordering (P01 data model first, then P02 client) isolates this risk — P01 is independent of supplier API shape.

## Open Questions — RESOLVED

All Claude's Discretion items resolved with defaults:

1. **Chunk size?** → **50 SKUs per chunk** (including variations). Rationale: 100/min Woo ceiling ÷ 2-3 workers = 33-50/min/worker; 90s timeout ÷ ~1.5s/write = 60 writes headroom.
2. **JWT refresh retry pattern?** → **Retry once on 401 with fresh token, then fail the SKU (D-06c counter). Don't loop.** [VERIFIED via D-06c in CONTEXT.md]
3. **Rate-limit 429 backoff?** → **Exponential: 500ms → 1500ms → 4500ms → 13500ms → 30s cap; Retry-After header honoured as floor; max 5 retries per SKU; jitter 0-500ms.** Over cap = single `sync_errors` row, continue run.
4. **Supplier → Woo SKU matcher implementation?** → **In-memory associative array keyed by supplier SKU, value = `['woo_product_id', 'woo_variation_id', 'type']`.** Built in single pass over `WooProductIterator` at orchestrator start. ~2MB for 15k SKUs. Re-built fresh per run (no caching across runs — supplier SKUs can be removed).
5. **Progress reporting granularity?** → **Every 50 SKUs (i.e., once per chunk) call `$run->touch()` + log elapsed + ETA = (elapsed / processed) × (total - processed).** Artisan command uses `$this->output->progressStart($run->total_skus)` + `progressAdvance(50)` for console UI.
6. **Price tolerance comparison?** → **Exact 2dp string match.** Strip trailing zeros: `rtrim(number_format((float)$price, 2, '.', ''), '0')` then `trim('.')`. Stock: exact integer equality. [VERIFIED via D-discretion line 67 in CONTEXT.md — "exact match on stock (integers); price treated as 2dp strings from both sides"]

## Sources

### Primary (HIGH confidence)

- Phase 1 SUMMARYs 01-01 through 01-05 — local canonical reference for ALL Phase 1 contracts
- `.planning/phases/02-supplier-sync/02-CONTEXT.md` — the 10 locked decisions
- `.planning/REQUIREMENTS.md` — SYNC-01..SYNC-13 authoritative
- `.planning/research/STACK.md` — `automattic/woocommerce ^3.1`, `spatie/simple-excel ^3.9`, phpredis, Horizon supervisors
- `.planning/research/ARCHITECTURE.md` — modular monolith layout, event bus pattern, Flow A (supplier sync diagram)
- `.planning/research/PITFALLS.md` — Pitfalls 1 (resumable cursor), 3 (batch per-item failures), 8 (queue segregation), 12 (JWT), 13 (events in transactions), 15 (Woo rate limit), 17 (variants), 18 (stock race)
- `.planning/research/FEATURES.md` §A.1-A.4 — supplier sync brief + differentiators + anti-features
- `app/Domain/Sync/Services/WooClient.php` — existing shadow-mode gate (lines 31-94)
- `app/Foundation/Integration/Services/IntegrationLogger.php` — 6-header redaction list
- `app/Foundation/Events/DomainEvent.php` — auto correlation_id + SerializesModels
- `app/Console/Commands/BaseCommand.php` — `perform()` abstract + Context + LogBatch threading
- `config/horizon.php` — `sync-bulk-supervisor` + `sync-woo-push-supervisor` already configured
- [WooCommerce REST API docs — product-variations](https://github.com/woocommerce/woocommerce-rest-api-docs/blob/trunk/source/includes/wp-api-v3/_product-variations.md) — variation structure, `manage_stock` parameter semantics [VERIFIED]
- [Packagist — automattic/woocommerce](https://packagist.org/packages/automattic/woocommerce) — 3.1.1 released 2026-01-30 with PHP 8.5 support [VERIFIED]

### Secondary (MEDIUM confidence)

- [WooCommerce Store API rate limiting](https://developer.woocommerce.com/docs/apis/store-api/rate-limiting/) — `RateLimit-Retry-After` header semantics [VERIFIED via WebSearch]
- [spatie/simple-excel README](https://github.com/spatie/simple-excel/blob/main/README.md) — generator-based writer, flush-on-destruct [VERIFIED via WebSearch]
- [How to handle API rate limits and HTTP 429 errors - DEV](https://dev.to/robertobutti/how-to-handle-api-rate-limits-and-http-429-errors-in-an-easy-and-reliable-way-14e6) — exponential backoff + jitter pattern [VERIFIED]

### Tertiary (LOW confidence — ASSUMED)

- 21stcav.com API shape (`/generate_token.php` + `/api/index.php` JSON REST) — assumed from PROJECT.md external-systems table + legacy plugin memory. **Must be validated at Phase 2 kickoff.** [A1, A2]
- Supplier JWT TTL of 60 min — assumed from common practice; mitigated by reading `expires_in` from first token response. [A2]

## Metadata

**Confidence breakdown:**
- Standard Stack: HIGH — Phase 1 already installed and tested everything except `automattic/woocommerce` + `spatie/simple-excel`; both are well-known stable packages.
- Data Model (§1): HIGH — WooCommerce REST docs explicit on variable-product structure; D-01 expansion is straightforward HasMany.
- Supplier Client (§2): MEDIUM — API shape assumed; cache + retry pattern is standard.
- Woo Iterator (§3): HIGH — `per_page=100` + `?page=N` is documented.
- SyncRun state machine (§4): HIGH — model and migration straightforward.
- Chunked execution (§5): HIGH — Pattern well-established in Horizon docs + Phase 1 already has the supervisors.
- Variable product sync (§6): HIGH — REST endpoints documented.
- Missing handling (§7): HIGH — D-03 explicit; logic is straightforward.
- Tag/meta detection (§8): HIGH — Woo response shape documented.
- CSV report (§9): HIGH — spatie/simple-excel is Phase 5's next-use anyway; pattern is standard Laravel Mailable.
- Rate limit (§10): HIGH — standard exponential-backoff pattern; Woo's Retry-After documented.
- Filament Resources (§11): HIGH — Phase 1 shipped the pattern with SuggestionResource + AlertRecipientResource.
- Architectural test (§12): HIGH — Phase 1 Plan 05 already shipped the DeptracTest pattern; Phase 2 just extends it.
- Pitfalls: HIGH — cross-referenced against PITFALLS.md + Phase 1 deviations.

**Research date:** 2026-04-18
**Valid until:** 2026-05-18 (30 days — stack is stable; if `automattic/woocommerce` ships 3.2 or Woo deprecates `?per_page`, refresh)

---

*Phase: 02-supplier-sync*
*Research completed: 2026-04-18*
