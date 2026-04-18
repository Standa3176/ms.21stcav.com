# Pitfalls Research

**Domain:** Laravel-based WooCommerce sync + Bitrix24 CRM push, replacing live WordPress plugins on a UK B2B store
**Researched:** 2026-04-18
**Confidence:** HIGH (domain patterns verified across WooCommerce docs, Bitrix24 API reference, Laravel Horizon issue tracker, and production ecommerce post-mortems)

> Scope note: this PITFALLS file is deliberately opinionated about *this rebuild* — WooCommerce REST sync, Bitrix24 one-way push, competitor CSV ingest, Filament admin, and a live-cutover from two production plugins. Generic "write tests / use DI" advice is excluded. Every pitfall below is something that has burned this exact class of system.

---

## Critical Pitfalls

### Pitfall 1: Stuck / non-resumable last-processed-ID on supplier sync

**Severity:** CRITICAL

**What goes wrong:**
The nightly supplier sync iterates N thousand Woo products in chunks, updating each via the Woo REST API. Halfway through, a single malformed product response, a Woo 502, or a PHP memory spike kills the job. The "last processed SKU" pointer was tracked in memory (or in a job payload that Horizon retries from the start), so the retry either (a) starts from zero — double-writes everything and hits the Woo rate limit, or (b) never restarts and silently leaves half the catalogue stale.

**Why it happens:**
Teams treat the sync as a single monolithic job instead of a durable state machine. The `SyncRun` row that should own the pointer doesn't exist; progress lives only in a Horizon tag or log line. When a worker dies, there is no persisted cursor to resume from.

**How to avoid:**
- Persist a `supplier_sync_runs` row with `status`, `last_processed_sku`, `last_processed_id`, `chunk_size`, `started_at`, `finished_at`, `error_payload`.
- Each chunk is its own queued job (`SyncSupplierChunkJob`) that reads from and writes to the `SyncRun` row inside a DB transaction.
- The scheduler command only *starts* a run if the previous one is `finished` or `failed_unresumable`; otherwise it resumes from `last_processed_id`.
- Write a `php artisan supplier:sync --resume={run_id}` command so ops can manually restart.
- Each `Product` row gets `last_synced_at` + `last_sync_run_id` so you can reconcile "what did run 412 actually touch?"

**Warning signs:**
- Horizon "recent jobs" shows `SyncSupplierChunkJob` with 3/3 attempts exhausted and no follow-on chunk
- `products.last_synced_at` distribution is bimodal (some rows updated today, many rows 3+ days stale) — this is the smoking gun
- Sync duration trending upward week-on-week (chunks re-doing work)
- Email CSV report shows "0 updated / 0 failed" — the run never actually executed, just completed the head chunk

**Phase to address:** Phase 2 (Supplier sync). Non-negotiable before Phase 7 cutover.

---

### Pitfall 2: Silent parity gap during parallel-run (both systems writing to Woo)

**Severity:** CRITICAL

**What goes wrong:**
During cutover, the old Stock Updater plugin is still enabled "just to be safe" while the new Laravel sync runs in parallel. Both write to Woo. The old plugin uses direct DB writes; Laravel uses REST. Race conditions produce ghost values: stock goes from 5 → 0 → 5 → 0 within minutes. Worse, the two systems apply different pricing rules (old plugin still using flat-tier margins, Laravel using new brand+category rules) so the visible price on the storefront flaps between sync cycles. Customers see one price at checkout, another at order confirmation.

**Why it happens:**
"Parallel run" is interpreted as "both systems live" instead of "new system live, old system read-only / shadow mode." The old plugin's cron isn't actually disabled, just "expected not to do anything."

**How to avoid:**
- Define parallel-run as **shadow mode only**: Laravel writes to Woo; old plugins have their cron hooks deregistered (`wp_unschedule_event`) but code left in place so it can be re-enabled as rollback.
- Add a `WOO_WRITE_ENABLED` env flag to Laravel — default OFF. During shadow week Laravel *computes* what it would write and logs to `sync_diffs` table; nothing touches Woo. Compare outputs to old-plugin output before flipping the flag.
- Before flipping, run a reconciliation query: "of the last 48h of products the old plugin would have changed, does Laravel agree on the final price?" Acceptable diff ratio before go-live.
- At cutover, the sequence is: (1) disable old cron, (2) run final old-plugin pass, (3) snapshot DB, (4) enable Laravel writes, (5) run first Laravel pass, (6) diff against snapshot.

**Warning signs:**
- Customer service tickets spike with "price changed at checkout" within 24h of cutover
- Woo audit log (if enabled) shows two sources updating the same `_price` meta within minutes
- `products.last_synced_at` jumps forward then backward — indicates two writers racing
- Stock reaches impossible values (negative, or higher than supplier ever shipped)

**Phase to address:** Phase 7 (Cutover). Planning must happen in Phase 1 (the shadow-mode `WOO_WRITE_ENABLED` flag cannot be retrofitted).

---

### Pitfall 3: WooCommerce REST batch endpoint used naively — silent per-item failures

**Severity:** CRITICAL

**What goes wrong:**
The `products/batch` endpoint accepts up to 100 create/update/delete operations per call. Teams call it with 100 items, get an HTTP 200 back, and assume all 100 succeeded. Actually, the response body contains per-item results — and some items can be in an error sub-object while the overall status is 200. Failed items are silently dropped. You find out three weeks later when a customer reports a stale price on a SKU that's "clearly being synced every night."

**Why it happens:**
Devs treat the Woo REST client as a black box — `$client->post('products/batch', $payload)` returns, no exception, move on. The per-item error structure is in WooCommerce's docs but is not surfaced by most PHP clients.

**How to avoid:**
- Never trust batch-endpoint HTTP status alone. Parse the response body: `create[]`, `update[]`, `delete[]` arrays, each with optional `error` sub-object per item.
- Build `parseBatchResponse(array $response): BatchResult` that returns `(succeeded: [...skus], failed: [...['sku' => ..., 'code' => ..., 'message' => ...]])`.
- Log failed items to `sync_errors` table with full request + response payload for re-drive.
- Cap batches at 50 (not 100) — leaves headroom for Woo's own rate limits and avoids timeout on slow shared hosts.
- Write an integration test against a real Woo dev instance that deliberately triggers per-item failures (e.g. send an unknown taxonomy term) and asserts the batch-result parser catches it.

**Warning signs:**
- `sync_errors` table empty despite `sync_runs.updated_count` lower than `sync_runs.attempted_count`
- Individual products are never updated but no job ever failed
- Woo server error logs show validation errors the Laravel side never saw

**Phase to address:** Phase 2 (Supplier sync). The `BatchResult` parser is a Phase 2 deliverable with its own test coverage.

---

### Pitfall 4: Webhook handler is synchronous and not idempotent

**Severity:** CRITICAL

**What goes wrong:**
Woo fires an `order.created` webhook. The Laravel route handler verifies HMAC, creates a Bitrix24 Deal + Contact + Company synchronously, then returns 200. Bitrix24 is slow today (4s). Woo's HTTP timeout (5s default) fires before the response returns, Woo marks the delivery as failed, waits, and retries. Meanwhile Laravel's first handler finished — the Deal was created. Retry arrives, passes HMAC check, creates a second Deal. Sales team now sees duplicate leads for every slow-Bitrix order. Worse: on a 5xx response Woo *also* retries, so any transient Bitrix error doubles up the CRM record.

**Why it happens:**
Three compounding mistakes: (1) synchronous handler instead of queued, (2) no idempotency key / no "have I already processed this event?" check, (3) no distinction between "retry-safe" failures (queue + 200) and "don't retry" failures (400 + log).

**How to avoid:**
- Webhook route does **only four things** in ≤200ms: verify HMAC, store the raw body + headers into `webhook_events` with unique index on `(topic, delivery_id)` or `(topic, order_id, event_hash)`, dispatch a queued job, return 200.
- `webhook_events` row has `processed_at` and `status`. The processing job checks for existing `processed_at` and no-ops if set (idempotency).
- Include Woo's `X-WC-Webhook-Delivery-ID` header as the dedup key. Woo reuses this ID across retries of the same event.
- HMAC verification uses `hash_equals()` (constant-time) with the base64-encoded SHA-256 of the raw body — compute *before* any JSON decode, since decode-then-reencode changes bytes.
- Any validation failure returns 400 (Woo will not retry); any internal/downstream failure returns 200 with event stored for the queue to retry (avoids Woo's own retry storm).
- For Bitrix push specifically: every `crm.deal.add` call includes a Laravel-generated `UF_CRM_WOO_ORDER_ID` custom field. Before creating, search by this field — if found, update instead of insert.

**Warning signs:**
- `webhook_events` table shows multiple rows with same `delivery_id` (unique index should prevent this — if it ever fires as a DB error, that's your early warning)
- Bitrix24 deal list shows duplicate Deal.TITLE entries for the same order number
- Webhook response time p95 > 500ms (means handler is doing real work synchronously)
- Woo admin → Webhooks → Delivery log shows repeated 5xx/timeout failures

**Phase to address:** Phase 6 (Bitrix24 sync) for the Bitrix side; Phase 1 (Foundation) for the generic webhook-events infrastructure — dedup table, HMAC middleware, queued dispatcher.

---

### Pitfall 5: VAT rounding applied mid-calculation — pricing drifts by pennies

**Severity:** CRITICAL

**What goes wrong:**
The formula is `round(supplier_price × (1 + margin%/100) × 1.2, 2)`. Team implements it with `round()` calls at each step: `round(supplier × 1.18, 2)` then `round(prev × 1.2, 2)`. Compound rounding accumulates. A £847.33 supplier price with 18% margin + 20% VAT should compute to £1,199.25; intermediate rounding produces £1,199.24 or £1,199.26. Over 3,000 products this means dozens of pennies of under/over-pricing. For a B2B AV store, a single 8-port switch mispriced by 1p shows as £0.01 in the Ads spend calculation — but compounds in competitor-margin suggestions ("competitor is 0.0008% under, raise margin") that are pure noise.

On top of that: PHP `round()` defaults to `PHP_ROUND_HALF_UP`, but HMRC permits half-even (banker's rounding) as well. Mixing calculations between the two produces results that drift from what the old plugin showed — users will call it a "bug."

**Why it happens:**
- Teams use PHP `float` for money (precision loss at large values)
- Rounding applied at every arithmetic step instead of only at display/storage boundary
- VAT divide-by-1.2 in competitor CSV ingest uses `price / 1.2` (float) instead of a decimal-safe operation

**How to avoid:**
- Represent money as integer pennies or as `string`-backed decimals using BCMath (PHP 8.4 has a native `BcMath\Number` class; for 8.2/8.3 use `brick/money` or `moneyphp/money`).
- Single pure function: `FinalPriceCalculator::compute(int $supplierPennies, int $marginBasisPoints, int $vatBasisPoints = 2000): int` — all integer math, rounding applied **once** at return.
- VAT removal in competitor CSV: `ex_vat_pennies = round((raw_pennies * 10000) / 12000)` — integer division with explicit rounding mode.
- Lock rounding mode at one place: `config/pricing.php => 'rounding_mode' => PHP_ROUND_HALF_UP` (match old plugin behaviour; document the choice).
- Golden-test fixture: import 50 known (supplier_price, margin, expected_final_price) triples from the old plugin's current live output and assert the new engine matches to the penny. This is the parity gate for Phase 3.

**Warning signs:**
- QA reports "price off by 1p" on spot-checks during cutover
- Competitor margin-suggestion dashboard is full of near-zero deltas (sub-penny noise)
- Unit tests use float assertions (`assertEquals(1199.25, $price)`) — a structural warning sign; float compare is never safe for money
- `final_price` column typed as `decimal(10,2)` but PHP side is `float` — data drift guaranteed

**Phase to address:** Phase 3 (Pricing engine). Golden-fixture parity test is a Phase 3 success criterion. Competitor-CSV VAT stripping is Phase 4 and must reuse the same calculator.

---

### Pitfall 6: Bitrix24 duplicate contacts / deals from naïve "create on every order"

**Severity:** CRITICAL

**What goes wrong:**
Every Woo order triggers `crm.contact.add` + `crm.company.add` + `crm.deal.add`. A repeat customer now has 4 Contact records in Bitrix; the sales team has no idea which one is "the real one." Auto-email sequences fire four times. Worse: the itgalaxy plugin we're replacing *did* handle this (it searches by email before creating), so the rebuild is a regression users notice on day one.

**Why it happens:**
- Bitrix24 doesn't deduplicate on insert — it cheerfully creates duplicate records by design
- Devs assume "the CRM will figure it out" or use the built-in "Duplicate Control" feature which only deduplicates on *manual* entry, not on REST-API inserts
- Email-based search (`crm.contact.list?filter[EMAIL]=...`) is non-obvious: you must filter on the multi-field `EMAIL` as a nested value type, not a plain string

**How to avoid:**
- Every CRM push is "find-or-create," never "create":
  - Contact: search by email (primary) and phone (fallback), `filter[EMAIL]=x@y.com` with `type=WORK` nested
  - Company: search by VAT number / registration number if present, fallback company-name + postcode exact match
  - Deal: search by the custom field `UF_CRM_WOO_ORDER_ID` — if found, *update*, don't insert
- Add a Laravel-side `BitrixEntityMap` table: `(entity_type, woo_id, bitrix_id, last_pushed_at, last_payload_hash)`. Before any API call, consult the map. This also makes the bulk backfill idempotent — re-running it is a no-op.
- Custom field `UF_CRM_WOO_ORDER_ID` (integer) created during Phase 6 setup via artisan command; fail fast if it's missing from Bitrix (don't silently fall back to insert-by-title which causes the duplicate explosion).
- For the historical backfill artisan command: chunk by 50 orders, sleep between chunks, log progress to `bitrix_backfill_runs`. Never run the backfill twice without checking the map.

**Warning signs:**
- Bitrix Contact count grows faster than unique-customer count in Woo (ratio > 1.05 is a red flag)
- `BitrixEntityMap` has multiple rows per `woo_customer_id` (should be uniquely indexed — if the unique constraint ever fires during insert, log it and investigate)
- Sales team reports "already emailed this lead" complaints
- `crm.contact.list` filter returns 0 results for emails you *know* exist — usually means the filter shape is wrong and you're about to create duplicates

**Phase to address:** Phase 6 (Bitrix24 sync). The `BitrixEntityMap` table + custom-field bootstrap is the first Phase 6 deliverable, before any push code.

---

### Pitfall 7: Source-of-truth discipline breaks — team "fixes" in Woo admin, gets overwritten

**Severity:** CRITICAL (operational/political)

**What goes wrong:**
Project decision is "Laravel is source of truth; Woo admin changes will be overwritten." In practice, a marketing team member spots a typo in a product description and fixes it in Woo admin at 4pm. At 02:00 Laravel's sync runs and overwrites the fix because the description is generated from supplier data + SEO template. Marketer complains loudly next morning. Either (a) the team caves and pauses the sync, or (b) the overwrite is silent and nobody knows why fixes don't stick. Trust in the new system collapses.

**Why it happens:**
"Documented behaviour, not a bug" is not a shield against users with real needs. People need a way to make *persistent* changes without touching Laravel code.

**How to avoid:**
- Preserve the `_exclude_from_auto_update` Woo meta flag (already in scope per PROJECT.md) and surface it prominently in both Woo admin *and* Filament. Users should be able to flip it on a product in one click.
- Add a `ProductOverride` model in Laravel that stores per-field overrides (`description_override`, `title_override`, `price_override`). The sync pipeline consults overrides before writing. The Filament product detail page shows what's generated vs overridden, with a per-field "pin" button.
- Before flipping `WOO_WRITE_ENABLED` at cutover, run a one-off scan of Woo products and auto-populate `ProductOverride` for any product where current Woo content diverges from what the new template would produce. Flag those for manual review. This prevents the "first sync wipes out 18 months of marketing edits" disaster.
- Write a human-readable commit note into each Woo update (custom meta `_last_sync_run_id`) so when a user complains "something changed my page," you can trace it back.

**Warning signs:**
- Slack/email complaints of "my edit disappeared" in the first week post-cutover
- `_exclude_from_auto_update`-tagged product count is 0 — nobody's using the escape hatch (either they don't know about it or the workflow is unusable)
- Git blame on `ProductOverride` model has increasingly defensive conditionals — a signal someone's trying to patch around users not respecting source-of-truth

**Phase to address:** Phase 5 (Product auto-create) for the override model; Phase 7 (Cutover) for the pre-cutover divergence scan. Filament UI for overrides in Phase 5.

---

### Pitfall 8: Long-running supplier sync job blocks the Horizon queue

**Severity:** CRITICAL

**What goes wrong:**
`SyncSupplierJob` takes 45 minutes to process 3,000 products. It's dispatched to the `default` queue. During those 45 minutes, an urgent Woo order webhook fires, queues a `PushOrderToBitrixJob`, and the job waits behind the sync. Customer sits in "processing" for 45 minutes with no CRM record. In extreme cases, the supplier sync times out, gets retried by Horizon, and *two* copies run in parallel — duplicate API calls, Woo rate limits, doubled Bitrix pushes.

**Why it happens:**
- Single queue for all job types
- No `withoutOverlapping()` mutex on long-running scheduled jobs
- `--timeout=0` used on the worker (per the RAMS project's own convention) — which means Horizon never kills a stuck worker

**How to avoid:**
- Split queues by SLA: `critical` (webhooks, CRM push, user-facing), `default` (product operations), `sync` (long-running batch), `low` (reports, emails). Horizon config allocates dedicated supervisors per queue.
- Dispatch webhook-triggered Bitrix jobs to `critical`; supplier sync chunks to `sync`. Critical queue has its own worker pool that never blocks.
- Scheduled commands use `->withoutOverlapping(30)` — mutex prevents a slow run + a new cron from overlapping.
- Long-running jobs explicitly: `public int $timeout = 600; public int $tries = 2;` per-job, not per-worker. Pair with Horizon's per-queue `timeout` config.
- Supervisor auto-restart: `--max-jobs=1000 --max-time=3600` so workers recycle (prevents memory bloat from long-running PHP).
- Never use `queue:listen` in production (per Horizon docs) — use `horizon` with proper supervision.

**Warning signs:**
- Horizon's "Current Workload" shows `critical` queue with wait-time > 5 seconds
- Bitrix push p95 latency jumps during 02:00-03:00 UTC (supplier sync window)
- Failed-jobs table has `SyncSupplierJob` entries with "maximum attempts reached" — usually means two instances tried to run and one lost the race
- Redis memory grows unboundedly during sync runs (job payloads not being released)

**Phase to address:** Phase 1 (Foundation) — queue segmentation is infrastructure, must be right from day one. Phase 2 (Supplier sync) for `withoutOverlapping`. Phase 7 (Cutover) for load-test under real traffic.

---

## Major Pitfalls

### Pitfall 9: Competitor CSV ingest fails silently on BOM / encoding / partial files

**Severity:** MAJOR

**What goes wrong:**
n8n drops a CSV in `storage/competitors/`. The file has a UTF-8 BOM (0xEF 0xBB 0xBF) at the start. PHP's `fgetcsv` doesn't strip the BOM, so the first column header becomes `"\xEF\xBB\xBFsku"` instead of `"sku"`. Your auto-detect logic looks for `sku`, doesn't find it, skips the file. Competitor data stops updating. Nobody notices for two weeks because the dashboard shows *old* data, not empty data.

Variants of the same class: file uses Windows-1252 encoding with £ sign, `mb_convert_encoding` never called, price parses as garbage. Or n8n writes the file in-place while Laravel reads it mid-write, CSV is truncated, last row is half a line. Or competitor uses comma-as-decimal (`1.234,56` European style) and `floatval()` reads it as `1.234`.

**Why it happens:**
CSV looks simple; it isn't. Standard `fgetcsv` doesn't handle BOM, doesn't detect encoding, doesn't lock files. n8n doesn't guarantee atomic writes by default.

**How to avoid:**
- Use `league/csv` not raw `fgetcsv`. Enable BOM stripping: `Reader::createFromPath(...)->setHeaderOffset(0)` + `Reader::setInputBOM(Reader::BOM_UTF8)`.
- Detect encoding: `mb_detect_encoding($raw, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true)` and convert to UTF-8 before parsing.
- Atomic file ingest pattern: n8n writes to `storage/competitors/incoming/name.csv.tmp`, renames to `name.csv` when done. Laravel only picks up files not ending in `.tmp` and older than 30 seconds (belt-and-braces against partial writes).
- On ingest, move file to `storage/competitors/processed/{date}/` with its parse result in a sidecar `.json`. Never process from the same location twice.
- Parser is defensive: every row produces either a `CompetitorPrice` row or a row in `csv_parse_errors`. Never silently drop.
- Price parsing goes through a dedicated `PriceParser::fromString(string $raw, string $locale = 'en_GB'): ?int` that handles `£`, `GBP`, `1,234.56`, `1.234,56`, trailing spaces, and returns `null` on failure (caller logs the error).

**Warning signs:**
- `competitor_prices` table row count flat for > 48h when n8n is known to be running
- `csv_parse_errors` is empty but so is `competitor_prices` — silent-skip in action
- File sizes in `storage/competitors/` growing but `last_processed_at` in `competitor_runs` unchanged
- Competitor-delta dashboard shows "no data" in the UI despite known recent activity

**Phase to address:** Phase 4 (Competitor module). Atomic-rename convention coordinated with n8n in Phase 4 kickoff.

---

### Pitfall 10: Filament dashboard N+1 on audit-log / sync-log heavy tables

**Severity:** MAJOR

**What goes wrong:**
The "CRM push log" Filament resource lists the last 1,000 Bitrix push attempts. Each row shows related order number, customer name, and retry count. Devs didn't `->with(['order', 'customer'])` so each row fires 2-3 lazy queries. Page load for 50 rows = 150 queries. Admin-page load time hits 14-18s. On 500 rows, browser tabs crash. Ops stop using the dashboard, which kills the "single pane of glass" value prop.

**Why it happens:**
- Filament generates resource table scaffolds without eager-loading hints
- Audit tables grow unboundedly (one row per sync attempt × 3,000 products × nightly = millions of rows within months)
- Default page size of 50 is too large for join-heavy views
- Counts in table summaries (`->counts('failed_pushes')`) run real SQL per row unless `withCount` is set at query builder level

**How to avoid:**
- Every Filament Resource: override `getEloquentQuery()` with `->with([...relations used in columns])` and `->withCount([...relations used in counts])`. Treat this as boilerplate.
- Install Laravel Telescope (dev) or Debugbar in local/staging; add a CI check that fails if any Filament page exceeds N queries.
- Cap default page size at 25 for audit-heavy resources; use server-side filters (indexed date range + status) not full-scan sorts.
- Partition / prune strategy for audit tables: `sync_attempts` older than 90 days moves to `sync_attempts_archive` (daily command). Keeps the "hot" table small.
- Dashboard widgets (totals, error counts) read from pre-aggregated `sync_run_stats` rows, not raw audit data. A cheap `SELECT` on `sync_run_stats` replaces a 10M-row aggregation.
- Never show raw JSON request/response blobs in the table — lazy-load them in the detail view only.

**Warning signs:**
- Chrome DevTools Network tab shows Filament page loads > 3s in staging
- Laravel Debugbar query count > 50 on any Filament index page
- MySQL slow-query log features Filament-generated SELECTs
- `sync_attempts` table size > 1GB (inspection is overdue)
- Ops complain "the dashboard is slow" — once they say this, they stop opening it

**Phase to address:** Phase 1 (Foundation) for the eager-load convention + audit-log table structure with proper indexes. Phase 6 (Bitrix push log) and Phase 7 (full dashboard) for verification under real data volumes.

---

### Pitfall 11: Bitrix24 custom field mapping breaks when an admin changes a field in Bitrix

**Severity:** MAJOR

**What goes wrong:**
The dynamic field mapping UI (per PROJECT.md spec) fetches `crm.deal.fields` once, caches the list, and admins map Woo data to Bitrix fields. Someone in Bitrix deletes custom field `UF_CRM_UTM_SOURCE` and recreates it with the same name but a new internal ID. Every `crm.deal.add` now fails with "unknown field" — or worse, Bitrix silently accepts but drops the value. The mapping UI in Filament still shows the old field as selected because the cached list is stale.

Similar: a pipeline stage is renamed in Bitrix. The new system pushes deals to the old stage ID which no longer exists — Bitrix silently assigns a default stage, and deals land in the wrong pipeline.

**Why it happens:**
- Field mapping treats Bitrix schema as static; it's not
- No "re-sync fields" button; caches forever
- No validation at push time that the mapped field still exists

**How to avoid:**
- Cache `crm.deal.fields` / `crm.contact.fields` / `crm.company.fields` / `crm.dealcategory.list` (pipelines) in a `bitrix_schema_cache` table with `last_refreshed_at`. TTL max 24h.
- Filament mapping UI has a "Refresh from Bitrix" button — ops can force-refresh after schema changes.
- Before each push job, validate: do all fields in the current mapping still exist in cached schema? Is the target pipeline stage ID still in `crm.dealcategory.stage.list`? If no → mark job as `blocked_on_schema`, alert admin, don't retry indefinitely.
- Health-check command `php artisan bitrix:schema-check` runs hourly, alerts on drift.
- Store field references by `ENTITY_ID` + `FIELD_NAME` not internal row ID — Bitrix recreate-with-same-name is then transparent.

**Warning signs:**
- Spike of `crm.deal.add` errors with "Unknown field" in `bitrix_push_attempts.error_message`
- Deals appearing in pipeline "default" / stage "NEW" when they shouldn't
- `bitrix_schema_cache.last_refreshed_at` > 24h (health-check should alert before users notice)
- UTM/GA fields show as `null` in Bitrix despite Woo definitely capturing them

**Phase to address:** Phase 6 (Bitrix24 sync). Schema-cache table is foundational to the mapping UI.

---

### Pitfall 12: JWT token expiry on supplier API ignored — sync dies at midnight

**Severity:** MAJOR

**What goes wrong:**
21stcav.com supplier API uses JWT. Token is fetched at app boot, cached in memory for the life of the sync. A sync that runs from 02:00 to 02:47 — JWT expired at 02:30 (30-min TTL). Second half of the sync gets 401s on every call. Horizon retries with the same expired token. Sync fails. No products updated.

**Why it happens:**
- JWT lifecycle not factored in — treated as a static secret
- No 401 → refresh-and-retry logic in the HTTP client
- Token refresh endpoint (`/generate_token.php`) is called at boot only

**How to avoid:**
- `SupplierApiClient` wraps Guzzle with a middleware that catches 401, refreshes the token via `/generate_token.php`, retries the original request once. If second call also 401, surface the real auth failure.
- Cache token in Redis with TTL = (token_expiry - 60s) so app-side eviction fires before API-side expiry.
- Every `SyncSupplierChunkJob` re-acquires token via the cached service, not via constructor param — so chunks that run after a cache miss get a fresh token automatically.
- Unit-test the 401-retry middleware against a mocked supplier that returns 401 once then 200.

**Warning signs:**
- Sync failures cluster around multiples of token TTL (e.g. always at 30-minute marks)
- HTTP 401 rate in `supplier_api_attempts` spikes during long syncs
- Short syncs succeed; long ones fail — textbook expiry signature

**Phase to address:** Phase 2 (Supplier sync). Client is built in Phase 1 (Foundation) but middleware discipline established here.

---

### Pitfall 13: Event listeners run synchronously during Woo REST write

**Severity:** MAJOR

**What goes wrong:**
Per the "event-driven from day one" constraint, `ProductPriceChanged` fires inside the REST-write transaction. Listeners push to competitor-analysis recompute, log to audit, and (in a future phase) call a Google Ads API. One listener is synchronous and slow. A 3,000-product sync that should take 5 minutes now takes 40 because each write blocks on listener chain.

**Why it happens:**
- Laravel events default to synchronous execution
- "Emit events from day one" is a good design principle but implementation detail (sync vs async) is skipped

**How to avoid:**
- Every domain event listener implements `ShouldQueue` by default — async on the `low` queue.
- Only truly synchronous listeners (e.g. "update the `products.last_synced_at` timestamp") run inline, and those go through a repository write rather than an event.
- Audit-log listener is async. Competitor-recompute is async. Any future Google Ads / Merchant Center listener is async.
- Dispatched-from-transaction gotcha: events fired inside a DB transaction that later rolls back still queue the listener. Use `DB::afterCommit(fn() => event(...))` or Laravel 12's event-after-commit config.

**Warning signs:**
- Supplier sync duration grew dramatically between phases (listeners accumulating)
- `default` or `low` queue backlog spikes 10x during sync windows
- Profiling shows listener chain in the hot path of the write loop

**Phase to address:** Phase 1 (Foundation). The `ShouldQueue` default and `afterCommit` pattern must be set as project convention.

---

### Pitfall 14: Competitor auto-suggestion threshold creates noise

**Severity:** MAJOR

**What goes wrong:**
The competitor suggester fires any time competitor margin is ≥ 8% better. With 500 competitors scraped × 3,000 products × daily, this produces thousands of suggestions per week. Ops drown, start ignoring the dashboard. The one *actually* important suggestion (a loss-leader scenario) is buried.

**Why it happens:**
- Threshold applied at the raw signal level instead of at the "this is actually worth acting on" level
- No time-weighted smoothing — yesterday's flash-sale competitor price becomes a "suggestion" today
- No volume/revenue weighting — a SKU that sells 3/year gets same weight as one that sells 300/year

**How to avoid:**
- Store every signal (this is the "keep everything" design) but only surface suggestions that meet higher bar: 8% margin delta AND sustained for ≥ 3 consecutive scrapes AND SKU has ≥ N sales in last 90 days.
- Rank the suggestions dashboard by `potential_revenue_impact = (suggested_margin_delta × avg_daily_units_sold × 30)` so the £/month impact is the sort key.
- Throttle: one active suggestion per SKU at a time; if not actioned in 14 days, auto-expire.
- Every suggestion has a "why" text: "Competitor X at £Y for 4/5 of last 5 days; at 14% margin you'd match; this SKU sold 28 units in the last 30d; estimated £420/mo impact if matched."

**Warning signs:**
- Suggestions-dashboard "unread" count grows monotonically — nobody's acting
- Ratio of dismissed-without-action to accepted > 80% — noise dominates signal
- Ops complain "there's too much to look at"
- Same SKU appears in the list every day for weeks — auto-expire isn't working

**Phase to address:** Phase 4 (Competitor module).

---

### Pitfall 15: Woo REST rate limit hit during initial backfill / catch-up sync

**Severity:** MAJOR

**What goes wrong:**
First production run needs to touch all 3,000 products + do a one-off CRM backfill of historical orders. Hammering Woo's REST API at full speed trips its rate limiter (when enabled) or its PHP worker pool (when not) — customer-facing requests slow to a crawl, storefront feels broken. In extreme cases WAF / Cloudflare throttles Laravel's IP and legitimate syncs fail for an hour.

**Why it happens:**
- WooCommerce REST has no universal hard limit, but PHP-FPM worker count + MySQL row-locking on the `_postmeta` table do
- Batch endpoints are more polite than single-product endpoints but teams sometimes fall back to single calls for "safer" error handling
- Backfill not adaptive — runs at constant rate regardless of response times

**How to avoid:**
- Use Woo's batch endpoint (50 items per call, per Pitfall 3).
- Add a Guzzle middleware that tracks response time rolling average; if p95 > 1s, slow down (exponential back-off). Self-regulating.
- Run catch-up sync outside peak hours (Woo peak traffic is ~10am-2pm UK for B2B).
- Same-VPS hosting helps (no internet latency) but doesn't help MySQL lock contention — monitor `SHOW PROCESSLIST` during the first heavy run.
- Backfill commands accept `--rate=N` per-second cap and a `--dry-run` mode. Run dry-run against production a week before cutover.

**Warning signs:**
- Storefront TTFB jumps during sync windows
- Woo admin page loads slow down noticeably while Laravel sync runs
- MySQL connection count spikes (Woo creates a connection per REST request in many setups)
- 429 responses in `supplier_api_attempts` or `woo_api_attempts` (if Woo responds with these)

**Phase to address:** Phase 2 (Supplier sync) for adaptive rate control; Phase 6 (Bitrix sync) for backfill-command-specific rate limits; Phase 7 (Cutover) for real-traffic validation.

---

### Pitfall 16: Horizon config not aligned with Redis persistence — jobs lost on server restart

**Severity:** MAJOR

**What goes wrong:**
Ops reboots the VPS for a kernel patch. Redis loses everything in the queue (default config: in-memory only, no AOF). Mid-flight sync chunks vanish. Horizon "recent failed" shows nothing because there were no failures — jobs just ceased to exist.

**Why it happens:**
- Default Redis install has `appendonly no`
- "Redis is a queue" mental model ignores persistence
- Horizon has no own persistence — it's a UI over Redis

**How to avoid:**
- Redis config: `appendonly yes`, `appendfsync everysec` (Redis data surviving reboot is worth the small I/O cost).
- Or: for jobs that absolutely cannot be lost (webhook processing, CRM pushes), use the `database` queue driver instead of Redis. Horizon doesn't manage DB queues — that's fine, just use standard workers for those specific queues.
- Every critical sync is idempotent-by-design (Pitfall 1, 4, 6) so a job loss can be recovered by re-running the scheduler — but don't rely on this as primary protection.
- Runbook: after any server restart, run `php artisan horizon:status` + `php artisan supplier:sync:reconcile` (compares `products.last_synced_at` to expected and re-queues anything > 25h stale).

**Warning signs:**
- No "failed" jobs after a restart, but a sync that was clearly in-flight just... stopped
- `sync_runs` table has a row with `status=running` but no recent activity
- Redis `INFO persistence` shows `aof_enabled:0`

**Phase to address:** Phase 1 (Foundation) — Redis config baked into deployment. Phase 7 (Cutover) — restart drill as part of go-live checklist.

---

### Pitfall 17: Product-variant handling ignored or assumed flat

**Severity:** MAJOR

**What goes wrong:**
Woo products have variants (sizes, colours). Each variant has its own SKU, price, and stock. The sync is written assuming one SKU per product row. A product with 5 variants gets synced once at the parent level — variant prices/stock go stale. Customer sees "in stock" on the product page, clicks the specific variant at checkout, gets "out of stock." Or inversely, variant-level supplier data arrives but the sync writes it to the parent, ignoring variants.

**Why it happens:**
- WooCommerce's variant model is different from simple products — separate `product_variation` post type, separate REST endpoint (`products/{id}/variations`)
- Meeting Store is primarily B2B AV kit (usually single-SKU products) so the team may not realise some products are variable
- Current Stock Updater plugin may or may not handle variants — needs verification, not assumption

**How to avoid:**
- **First Phase 2 task:** audit the current Woo catalogue: `wp wc product list --type=variable` count. If zero — document "v1 assumes no variable products" and put a guard in place (`SyncSupplierChunkJob` skips + logs any `type=variable` it encounters).
- If non-zero, model explicitly: `ProductVariant` table, separate sync path via `products/{parent}/variations/batch` endpoint.
- Woo's data model gotcha: variant stock can be managed at parent *or* variant level (`manage_stock` flag at each level). Sync must respect the flag.
- Never assume "stock = sum of variant stock" — Woo's display logic is more complex than that.

**Warning signs:**
- Customer reports "said in stock but couldn't check out"
- `products.type` distribution shows variants but no corresponding rows in `product_variants`
- `sync_errors` show "product_id X is type=variable, skipped" — expected only if you decided to skip; alarming otherwise

**Phase to address:** Phase 2 (Supplier sync). Audit is a Phase 2 day-one task.

---

### Pitfall 18: Stock-goes-to-zero race condition between sync and live orders

**Severity:** MAJOR

**What goes wrong:**
02:30: supplier sync reads product X, stock = 5. Sync computes new stock (still 5 at supplier). In the 20 seconds between read and write, a customer orders 3 units; Woo decrements to 2. Sync writes back 5. Customer now has an oversold condition — Woo says 5, supplier only has 2 because they just shipped elsewhere.

**Why it happens:**
- Naive "read-modify-write" cycle, no optimistic concurrency
- Woo's stock management is its own source of truth for *reservations* (cart holds, checkout flows) — Laravel overwriting it loses that state
- Sync is framed as "mirror supplier → Woo" when it should be "mirror supplier → Woo, preserving Woo's delta since last sync"

**How to avoid:**
- Only write stock when it changed at supplier. Track `last_known_supplier_stock` in Laravel; only push a Woo update when `current_supplier_stock != last_known_supplier_stock`.
- When pushing, use Woo's "manage stock" endpoint with `stock_quantity_change` style semantics rather than absolute overwrite. (Woo REST v3 accepts absolute `stock_quantity` — there is no delta endpoint — so the pattern is: GET Woo stock, compute delta from last sync, apply delta to current.)
- During the sync window, consider putting the affected SKUs in "stock sync in progress" state and deferring any conflicting order reservations. For B2B with low order frequency during 02:00-03:00 UTC this is probably overkill — but document the assumption.
- Capture a domain event `StockWentToZero` with before/after/source so forensics is possible weeks later.

**Warning signs:**
- Oversold orders — customer checks out, cannot be fulfilled
- Stock level jumps that don't match supplier changes (Laravel wrote stock that wasn't in supplier feed)
- Reported gap between Woo "available stock" and what the warehouse actually has

**Phase to address:** Phase 2 (Supplier sync). Explicit design review during Phase 2 planning.

---

## Minor Pitfalls

### Pitfall 19: "Email admin on completion" silently breaks when SMTP config drifts

**Severity:** MINOR

**What goes wrong:**
Mailer config falls back to `log` driver. Sync completion emails go to `storage/logs/laravel.log` instead of admin inbox. Nobody notices for a month. Meanwhile, sync failures also "email admin" — same silent log-only behaviour.

**How to avoid:**
- CI test: swap mailer to `array` driver, dispatch notification, assert it was sent with right recipient. Catches regression if `.env` drifts.
- Health-check command: send a weekly "sync system alive" email; if ops doesn't receive it, alert on the *missing* email (separate channel — Slack webhook as backup).

**Phase to address:** Phase 2 (Supplier sync) + Phase 1 (Foundation) for the notification infrastructure.

---

### Pitfall 20: Competitor CSV directory grows unbounded, eats disk

**Severity:** MINOR

**What goes wrong:**
n8n drops a 5MB CSV daily. After 2 years, `storage/competitors/` is 3.6GB. Backups fail. No-one planned retention.

**How to avoid:**
- `storage/competitors/processed/` gets pruned after 90 days (configurable in `config/competitors.php`).
- Pruning command runs weekly, logged.
- Same for `storage/logs/`, `failed_jobs`, `sync_errors` — define retention policy per table in Phase 1, not Phase 7.

**Phase to address:** Phase 1 (Foundation) for the policy; Phase 4 (Competitor module) for the CSV prune command specifically.

---

### Pitfall 21: Migration adds `nullable()` to stale data; queries forget to handle null

**Severity:** MINOR

**What goes wrong:**
A Phase 3 migration adds `pricing_rule_id` to `products`, nullable because legacy products don't have one yet. Phase 3 code assumes every price calculation has a rule — throws on null. Takes a week to surface because affected products are the "long tail" rarely updated.

**How to avoid:**
- Every `nullable()` column gets a backfill in the same migration (for existing rows) + a not-null pathway in code + a test for the null case.
- When truly nullable, wrap access in `$product->pricing_rule_id ?? DefaultRule::id()` or similar — never direct access.

**Phase to address:** All phases — general discipline. Enforced via code-review checklist.

---

### Pitfall 22: Filament admin has no role-based access — junior sales user nukes pricing rules

**Severity:** MINOR (pre-launch) / MAJOR (post-launch)

**What goes wrong:**
Filament Shield or similar not installed. All authenticated users see all resources. Someone without pricing authority deletes a rule that affects 200 SKUs. Audit log exists but the fix is manual.

**How to avoid:**
- Install `filament-shield` in Phase 1. Define roles: `admin`, `pricing_manager`, `sales`, `read_only` — even if there's only one user at launch.
- Pricing rule changes require confirmation + reason text, written to `pricing_rule_audit`.
- Destructive actions (delete pricing rule, delete pricing rule group) gated behind a Filament "Require Password" modal.

**Phase to address:** Phase 1 (Foundation) for roles; Phase 3 (Pricing engine) for per-resource policies.

---

### Pitfall 23: No "replay webhook" UI — debugging production is pasting JSON into Tinker

**Severity:** MINOR

**What goes wrong:**
A Bitrix push fails. To debug, a dev SSHes in, opens `tinker`, copies a webhook payload from the log, dispatches the job manually. Works, but is painful, error-prone, and the fix often isn't reproducible.

**How to avoid:**
- Filament "Webhook Events" resource has a "Replay" action that re-dispatches the processing job from the stored raw payload.
- Same for `bitrix_push_attempts` — "Retry" action.
- Test environment has a "Simulate Woo webhook" Filament action that dispatches a webhook with a test payload.

**Phase to address:** Phase 6 (Bitrix24 sync) for the retry UI; Phase 1 (Foundation) for the webhook-event replay infrastructure.

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| Skip the `ProductOverride` model; rely on `_exclude_from_auto_update` flag only | Saves 2-3 days in Phase 5 | First user complaint becomes a full re-architecture — flag is all-or-nothing, can't pin one field | Never — the flag alone was insufficient in the old plugin; that's why we're rebuilding |
| Single queue, no segmentation | Simpler config, faster Phase 1 | Pitfall 8 bites within first month of real traffic | Never — the whole point of Horizon is supervisor separation |
| Store prices as `float` in DB | Faster to write | Pitfall 5 — pennies drift, impossible to correct retrospectively | Never — `decimal(12,4)` or integer pennies from day one |
| Synchronous webhook handler "because Bitrix is usually fast" | 1 day saved in Phase 6 | One slow Bitrix morning = duplicate deals; recovery requires Bitrix admin privileges | Never for production |
| Skip BOM / encoding handling ("our n8n always writes UTF-8") | 1 day saved in Phase 4 | Silent data loss when n8n changes, competitor source changes, or encoding rules drift | Only with a CI test asserting real competitor files parse correctly, and an alert when parse errors happen |
| Single Bitrix API call per Deal (no find-or-create) | Simpler code in Phase 6 | Sales team loses trust after first duplicate-lead incident; rebuilding deduplication retroactively requires scrubbing Bitrix | Never — find-or-create is a ~1 day investment |
| Use `queue:listen` instead of Horizon | Works on smaller hosts; one less thing to configure | No retry dashboard, no auto-restart, no queue separation — every Phase 8+ feature gets harder | Acceptable only in local dev |
| "Fix it in Woo admin just this once" during cutover | User gets what they want at 3pm on Tuesday | Established precedent destroys source-of-truth discipline within a month | Never post-cutover. Use `ProductOverride` instead. |
| Skip bulk backfill idempotency ("we'll only run it once") | 2 days saved | Backfill inevitably gets re-run — explosion of duplicates | Never — `BitrixEntityMap` pays for itself the first re-run |
| Cache `crm.deal.fields` forever | Faster mapping UI | Breaks silently when Bitrix admin changes a field | Never — 24h TTL + manual refresh button is cheap |

---

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| **WooCommerce `products/batch`** | Treat HTTP 200 as "all 100 succeeded" | Parse per-item `error` objects; log failures to `sync_errors`; cap at 50/batch |
| **WooCommerce variants** | Sync writes to parent product; variants go stale | Query `products/{id}/variations`; use `product_variations/batch` endpoint; respect `manage_stock` flag per variant |
| **WooCommerce webhook HMAC** | Verify against `json_decode → json_encode`d body | Verify against raw request body bytes *before* any decode; use `hash_equals` |
| **WooCommerce stock** | Absolute overwrite on every sync | Only push when supplier value changed; preserve Woo's in-flight reservations |
| **Bitrix24 `crm.contact.add`** | Direct add on every order — duplicates | `crm.contact.list` by email first; `BitrixEntityMap` for idempotency |
| **Bitrix24 custom fields** | Reference by internal numeric ID | Reference by `FIELD_NAME` (`UF_CRM_...`); re-resolve via `*.fields` on schema refresh |
| **Bitrix24 deal pipelines** | Hard-code stage IDs | Look up via `crm.dealcategory.stage.list`; cache with TTL; health-check |
| **Bitrix24 email filter** | `filter[EMAIL]=x@y.com` as plain string | Use `filter[EMAIL]=x@y.com` at the multi-field level — Bitrix will match any email type |
| **Bitrix24 close-date preservation on backfill** | System close date overwritten with upload date | Use a custom date field + automation to set system close date |
| **21stcav.com JWT** | Token fetched at boot, cached for life of request | Refresh on 401; TTL in Redis < token TTL; middleware-level retry |
| **n8n CSV drop** | Read file immediately, encounter half-written file | Atomic rename convention (`.tmp` → final); require mtime > 30s before processing |
| **Filament table queries** | Default query, lazy-loaded relations | Override `getEloquentQuery()` with `with()` + `withCount()`; cap page size; index filter columns |
| **Laravel Horizon** | `queue:listen` in production; single queue | Horizon supervisor per queue class; `critical` / `default` / `sync` / `low` segregation |
| **Redis persistence** | Default in-memory config | `appendonly yes`, `appendfsync everysec`; or use DB queue for critical jobs |
| **Laravel events** | Synchronous listener fires in hot path | `ShouldQueue` by default; `DB::afterCommit` when dispatched from transaction |

---

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| Single-threaded sync over 3,000+ products | Sync duration grows linearly, eventually > 1h | Chunked jobs on `sync` queue with multiple supervisors; batch endpoint | At ~1,000 products on shared hosting, ~5,000 on VPS |
| Audit tables without pruning | Filament page loads > 10s; MySQL slow queries on `sync_attempts` | 90-day retention + archive table + pre-aggregated stats | ~2-3 months of nightly syncs |
| Filament N+1 on related counts | Admin dashboard slow, complaints from ops | Eager load + `withCount`; debugbar query-count CI check | First Filament resource with >3 related columns |
| Redis unbounded payload growth during sync | Redis memory warnings | Keep job payloads small (IDs not full objects); `max-jobs` worker recycling | At ~1M+ queued jobs lifetime |
| Bitrix push in webhook-response path | Webhook timeouts → Woo retries → duplicate deals | Queue the push; verify + store + dispatch + 200, in that order | First time Bitrix is slow for > 5s |
| CSV ingest with no streaming | OOM on files > 50MB | `league/csv` stream reader; process row-by-row, not load-all | Competitor expands scrape range or adds a new source |
| Eager load everything in Filament | Memory spikes on list pages | Only eager-load what's in *table columns*; detail-view can load more | First resource with many-to-many |
| No index on `products.sku` unique lookup | Supplier sync slows as catalogue grows | Unique index on `products.sku`; composite index `(supplier_id, sku)` | ~2,000 products, linearly |
| No index on `bitrix_push_attempts.order_id` + `created_at` | Push-log filtering gets slow | Composite index on the filter+sort columns | ~100k push attempts |

---

## Security Mistakes

| Mistake | Risk | Prevention |
|---------|------|------------|
| Woo consumer key/secret committed to repo or stored plaintext in DB | Store takeover via Woo REST; can create products, refund orders | `.env` only; rotate on every ex-employee departure; use read-scope key where possible (but supplier sync needs write) |
| Webhook endpoint accepts requests without HMAC | Attacker forges orders → creates Bitrix deals → social-engineers sales | Middleware verifies HMAC with `hash_equals`; reject all requests without valid signature |
| HMAC check uses `==` / `===` instead of `hash_equals` | Timing attack reveals signature one byte at a time | Always `hash_equals($expected, $received)` |
| Bitrix inbound webhook URL in Git, logs, or Filament error pages | URL *is* the credential for Bitrix — leak = full CRM write access | Store in `.env`; never log full URL; redact in audit payloads |
| Competitor CSVs dropped in `storage/app/public/` or `storage/competitors/` exposed via web server | Competitor pricing + internal margin data exposed | `storage/competitors/` lives outside any publicly-served dir; `storage/app/private/competitors/` + check web server config |
| Filament admin on same domain as public site without separate auth | Admin session cookie leaks across if Woo has XSS | Dedicated subdomain (`ops.meetingstore.co.uk` as planned); separate auth; optional: IP allowlist or SSO |
| UTM / GA parameters echoed unsanitized into Bitrix note bodies | XSS inside CRM — Bitrix admins open a deal, JS runs in their session | Strip HTML / escape before writing to Bitrix; treat UTM values as untrusted |
| JWT token logged | Token = supplier API access; log aggregation tool breach = data exposure | Redact `Authorization: Bearer ...` in all log formatters; use Laravel's log-processor middleware |
| CSRF on Filament actions that trigger CRM push | Attacker with valid admin session can spam Bitrix | Filament handles this by default — but custom controllers don't; audit any custom route |
| No IP allowlist on `ops.` subdomain | Credential stuffing against Filament admin | Cloudflare rules or basic auth in front of Filament for extra layer |

---

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---------|-------------|-----------------|
| "Sync failed" shown without actionable error text | Admin has to SSH / log-dive to find out which SKU failed | Surface first 5 failures with SKU + error message + "retry this SKU" button |
| Pricing rule preview hidden behind multi-click flow | Pricing manager doesn't verify before saving | Inline "effective price for SKU X" preview in the rule-edit form |
| Competitor suggestions without "why" explanation | Ops blindly accept or reject; destroys trust when a rejection turns out right | Every suggestion has rationale: competitor name, observed price, sustained period, sales volume, £/mo impact |
| Bulk backfill command prints no progress | Dev thinks it hung, kills it, restarts, duplicates fire | Tick per chunk; ETA; resumable; stored `bitrix_backfill_runs` row visible in Filament |
| Dashboard widgets with stale data and no refresh indicator | Ops act on yesterday's data thinking it's live | Every widget shows `last_updated_at`; auto-refresh every 60s for critical ones |
| No "dry run" for pricing changes | Manager can't preview impact of changing a margin rule | "Preview impact" before save: shows "245 SKUs would change price; avg delta +£2.14; total revenue impact £X/mo" |
| Mapping UI shows raw Bitrix field names (`UF_CRM_1234567890`) | Admin guesses which one is "UTM Source" | Fetch field labels via `*.fields`; show label + internal name |
| Sync runs at 02:00 UTC with no "running now" indicator | Admin opens dashboard at 02:30, sees "last sync 24h ago," panics | In-progress sync visible in dashboard with current-chunk status |
| "Audit log" is one giant table with no filters | Unusable for finding a specific event | Filters: date range, entity type, action, status, user |
| Pricing rule conflict warnings buried | Manager creates a rule that's shadowed by an existing one without knowing | On save: "Rule Y (more specific) already covers 180 of these SKUs" warning |

---

## "Looks Done But Isn't" Checklist

- [ ] **Supplier sync:** resumable after mid-run failure — verify by `kill -9` on a running chunk and confirming next run picks up from `last_processed_id`
- [ ] **Supplier sync:** batch-endpoint per-item errors surfaced — verify by sending a known-bad payload and checking `sync_errors` has the row
- [ ] **Supplier sync:** JWT refresh-on-401 — verify by setting token TTL to 60s and running a 3-minute sync
- [ ] **Supplier sync:** variable products handled or explicitly skipped — verify with `wp wc product list --type=variable` check in a test
- [ ] **Pricing engine:** golden-fixture parity with old plugin — 50 SKU × supplier_price × margin triples match to the penny
- [ ] **Pricing engine:** VAT calculated with integer-pennies math — verify by property-based test on random inputs; no float anywhere
- [ ] **Pricing engine:** conflict detection on rule save — verify by creating overlapping rules and checking the warning
- [ ] **Competitor CSV:** BOM / UTF-8 / Windows-1252 handled — verify with three fixture files
- [ ] **Competitor CSV:** partial file protection — verify by writing a file and reading mid-write; should skip
- [ ] **Competitor CSV:** price-parse errors logged per-row, not per-file — verify with a file containing one bad row
- [ ] **Webhooks:** HMAC verification uses raw bytes + `hash_equals` — verify with a test that tampers with one byte and expects rejection
- [ ] **Webhooks:** idempotent on `delivery_id` — verify by sending the same webhook twice; second one is no-op
- [ ] **Webhooks:** response time p95 < 200ms — verify via integration test with clock assertions
- [ ] **Bitrix:** find-or-create for Contact, Company, Deal — verify by running the same order through twice; no duplicates
- [ ] **Bitrix:** schema refresh button + 24h TTL — verify by renaming a field in Bitrix and checking the validation catches it
- [ ] **Bitrix:** historical backfill idempotent — verify by running it twice; second run = zero new Bitrix records
- [ ] **Bitrix:** custom-field `UF_CRM_WOO_ORDER_ID` exists at Phase 6 start — verify via bootstrap command + fail-fast if absent
- [ ] **Filament:** every resource eager-loads table columns — verify via Debugbar query count < 20 on each index page
- [ ] **Filament:** role-based access — verify by logging in as each role and confirming resource visibility
- [ ] **Filament:** destructive actions gated behind confirmation — verify on pricing rule delete
- [ ] **Horizon:** `critical` queue isolated from `sync` queue — verify by queueing a long `sync` job and confirming a `critical` job runs immediately
- [ ] **Horizon:** `withoutOverlapping` on scheduled sync — verify by manually triggering while one is running
- [ ] **Horizon:** worker recycle (`--max-jobs`, `--max-time`) configured — verify by inspecting `supervisor` config
- [ ] **Horizon:** failed-jobs auto-pruned and alert on accumulation — verify with a deliberately failing test job
- [ ] **Redis:** `appendonly yes`, `appendfsync everysec` — verify via `redis-cli CONFIG GET appendonly`
- [ ] **Rollback:** old plugins can be re-enabled in < 5 minutes — verify via dry-run of the rollback runbook
- [ ] **Rollback:** `WOO_WRITE_ENABLED=false` stops Laravel writes immediately — verify in staging
- [ ] **Audit log retention:** prune command runs + pre-aggregate stats table populated — verify by seeding a year of fake attempts and checking dashboard performance
- [ ] **Parity during parallel run:** `sync_diffs` table populated during shadow mode — verify Laravel vs old-plugin output diff is < agreed threshold
- [ ] **Email alerts:** admin receives weekly "system alive" ping — verify SMTP config hasn't drifted to log driver
- [ ] **Events:** domain events emitted + listeners are `ShouldQueue` — verify by running sync and checking listener latency isn't in the write path

---

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| Sync left half the catalogue stale | LOW | `php artisan supplier:sync --resume={run_id}` — if designed correctly, just pick up. If not, delete the `last_processed_id` and let next scheduled run do the lot (takes 45min). |
| Duplicate Bitrix contacts | MEDIUM | Bitrix admin manually merges via Bitrix UI (their dedupe tool works for existing records). Fix root cause in Laravel before re-running backfill. |
| Duplicate Bitrix deals | MEDIUM-HIGH | Scripted merge via `crm.deal.delete` on duplicates identified by `UF_CRM_WOO_ORDER_ID`. Keep oldest; delete newer. Back up first. |
| Overwritten marketing edits in Woo | HIGH | If `ProductOverride` wasn't built yet: restore from last DB snapshot, page by page. Lesson learned → build `ProductOverride`. |
| Pricing penny drift | HIGH | Once incorrect prices are in Woo + captured in orders, you cannot retroactively refund pennies. Fix calculator, run one-off "reconcile prices" command, accept historical inaccuracy. |
| Webhook signature verification disabled | LOW | Re-enable middleware. Audit logs for any suspicious `webhook_events` during the gap. |
| Stock oversold | HIGH (revenue, trust) | Cancel customer order + apologise + compensate. Change sync to push stock *decrements* not *overwrites* for the affected SKUs going forward. |
| Horizon queue backed up | LOW-MEDIUM | `horizon:pause`, drain, investigate the slow job, fix, `horizon:continue`. If jobs stuck reserved: `horizon:terminate` and let supervisor restart. |
| CSV competitor parser silently dropping files | LOW | Replay files from `storage/competitors/processed/` through the new parser. Files are retained → no data lost, just not ingested yet. |
| Bitrix schema change broke pushes | LOW-MEDIUM | Hit "Refresh from Bitrix" in Filament; fix mapping; retry queued jobs via Filament replay action. |
| JWT token expiry mid-sync | LOW | If middleware is in place, just re-run sync. If not, add middleware (Pitfall 12) and re-run. |
| Competitor prices wrong due to BOM | LOW | Re-process files from `storage/competitors/processed/` after fixing encoding handling. |
| Filament slow, ops stopped using it | MEDIUM | Add eager loads + indexes; prune audit table; pre-aggregate widget data. Takes 1-2 days. Credibility loss harder to recover. |
| Lost jobs after Redis restart | LOW (if reconcile command exists) | `php artisan supplier:sync:reconcile` re-queues work for any SKU stale > 25h. |
| Old-plugin + new-app both writing | MEDIUM | Flip `WOO_WRITE_ENABLED=false`, disable old plugin cron, snapshot DB state, re-enable Laravel. Accept a few hours of stale data. |
| Team starts "fixing in Woo admin" habitually | MEDIUM (political) | Ship `ProductOverride` UI urgently; retrain team; document with examples; make the Filament workflow faster than the Woo-admin workaround. |

---

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| 1. Stuck last-processed-ID | Phase 2 | Kill -9 mid-sync → next run resumes from correct ID |
| 2. Silent parity gap at cutover | Phase 7 (design in Phase 1) | Shadow-mode diff < agreed threshold for 7+ days |
| 3. Batch endpoint silent per-item fails | Phase 2 | Integration test with deliberately bad payload surfaces in `sync_errors` |
| 4. Non-idempotent webhook handler | Phase 1 (infra) + Phase 6 (Bitrix specifics) | Double-send same webhook → single Bitrix record |
| 5. VAT rounding drift | Phase 3 | Golden-fixture parity test passes to the penny; no float in the calculator |
| 6. Bitrix duplicate contacts/deals | Phase 6 | Re-run backfill twice → zero new CRM records on second run |
| 7. Source-of-truth discipline breaks | Phase 5 (override model) + Phase 7 (pre-cutover scan) | `ProductOverride` shipped; first user complaint resolved via pin, not via code change |
| 8. Long sync blocks queue | Phase 1 (queue segmentation) + Phase 2 (`withoutOverlapping`) | Load test: critical job dispatched during sync completes in < 5s |
| 9. CSV ingest silent failures | Phase 4 | Three-fixture test (BOM, Windows-1252, partial file) all handled |
| 10. Filament N+1 perf | Phase 1 (convention) + Phase 6/7 (verification) | Debugbar < 20 queries per Filament index page |
| 11. Bitrix schema drift | Phase 6 | Manually rename a field in Bitrix → validation catches; refresh button updates |
| 12. JWT expiry mid-sync | Phase 2 | Set token TTL to 60s; run 3-min sync; completes successfully |
| 13. Sync listeners in hot path | Phase 1 | Profile shows listeners on queue, not in write loop |
| 14. Competitor suggestion noise | Phase 4 | Dismissed-to-accepted ratio < 50% after first month |
| 15. Woo REST rate limit on backfill | Phase 2 (adaptive rate) + Phase 7 (real-traffic test) | Storefront TTFB unchanged during sync windows |
| 16. Redis persistence not configured | Phase 1 | `CONFIG GET appendonly` returns `yes` in production |
| 17. Product variants ignored | Phase 2 | Variable-product audit done day 1; explicit handle-or-skip decision documented |
| 18. Stock race condition | Phase 2 | Only pushes stock when supplier value changed; audit-logged |
| 19. Silent email alert failure | Phase 2 | Weekly "system alive" ping received by admin |
| 20. CSV dir unbounded growth | Phase 1 (policy) + Phase 4 (prune command) | Disk usage flat over a month of runs |
| 21. Null column bugs | All phases (process) | Code review checklist includes nullable-column check |
| 22. Filament RBAC absent | Phase 1 (roles) + Phase 3 (resource policies) | Non-admin user blocked from pricing rule delete |
| 23. No webhook replay UI | Phase 1 (infra) + Phase 6 (Bitrix retry) | Replay action reproduces failing job deterministically |

---

## Sources

- [WooCommerce REST API — Batch endpoint](https://woocommerce.github.io/woocommerce-rest-api-docs/) — 100-item batch cap, per-item result structure
- [WooCommerce API rate limiting](https://developer.woocommerce.com/docs/apis/store-api/rate-limiting/) — Store API rate limits (optional, disabled by default)
- [WooCommerce Webhooks guide (Hookdeck)](https://hookdeck.com/webhooks/platforms/guide-to-woocommerce-webhooks-features-and-best-practices) — HMAC verification, retry semantics, idempotency
- [Securing Laravel Webhooks (Medium)](https://medium.com/appfoster/securing-laravel-webhooks-signature-verification-best-practices-2f0e69f03c31) — `hash_equals`, raw-body verification
- [Handling Payment Webhooks Reliably (Medium)](https://medium.com/@sohail_saifii/handling-payment-webhooks-reliably-idempotency-retries-validation-69b762720bf5) — Idempotency patterns, delivery-id dedup
- [Bitrix24 REST API docs — CRM Contacts](https://apidocs.bitrix24.com/api-reference/crm/contacts/index.html) — `crm.contact.list` filter shape
- [Bitrix24 REST API docs — Deal custom fields](https://apidocs.bitrix24.com/api-reference/crm/deals/user-defined-fields/index.html) — `crm.deal.userfield.*` methods
- [Bitrix24 Helpdesk — Import gotchas](https://helpdesk.bitrix24.com/open/25766211/) — Messenger ID dedup, close-date preservation
- [Laravel Horizon docs (12.x)](https://laravel.com/docs/12.x/horizon) — supervisor config, timeout handling, queue separation
- [Horizon stuck-job issues](https://github.com/laravel/horizon/issues/612) — reserved/pending state pathologies
- [Horizon timeout edge cases](https://github.com/laravel/horizon/issues/833) — 15/30-minute hang patterns
- [Laravel queue workers die (pola5h)](https://pola5h.github.io/blog/laravel-queues-jobs-redis-horizon/) — `--max-time`/`--max-jobs` discipline
- [Filament large-table perf issue #9304](https://github.com/filamentphp/filament/issues/9304) — 14-18s load times on page size ≥ 50
- [Filament auditing patterns (christalks.dev)](https://christalks.dev/post/integrating-audit-logs-into-your-filamentphp-admin-panel-2e964ae3) — audit-table pagination
- [Money pattern in PHP (DEV)](https://dev.to/rubenrubiob/money-pattern-in-php-the-problem-334a) — float-is-evil for currency
- [PHP 8.4 BCMath + Laravel (DEV)](https://dev.to/takeshiyu/handling-decimal-calculations-in-php-84-with-the-new-bcmath-object-api-442j) — decimal-safe math
- [UK VAT rounding (Pakk Academy)](https://academy.pakk.io/the-maths-of-ecommerce/vat-rounding) — HMRC rounding rules, per-unit vs per-line
- [League CSV — BOM handling](https://csv.thephpleague.com/9.0/interoperability/encoding/) — BOM detection, encoding conversion
- [n8n CSV encoding issues](https://community.n8n.io/t/how-to-convert-the-csv-file-from-utf-8-bom-format-to-utf-8/48063) — BOM round-tripping in n8n outputs
- Project context: `PROJECT.md`, `PROJECT-BRIEF.md` — Phase numbering, feature scope, stated constraints
- Experience: itgalaxy plugin v1.50.1 behaviour notes, Stock Updater plugin structure (per PROJECT-BRIEF.md context)

---
*Pitfalls research for: Laravel 12 + Filament 3 WooCommerce/Bitrix24 sync rebuild on live UK B2B store*
*Researched: 2026-04-18*
