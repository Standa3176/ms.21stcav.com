# Architecture Research

**Domain:** Laravel 12 + Filament 3 ops/integration platform (source-of-truth hub for Woo + Bitrix24 + supplier API + n8n feeds)
**Researched:** 2026-04-18
**Confidence:** HIGH (principles are standard Laravel modular-monolith practice; committed by project brief, validated by 2026 community consensus)

---

## 1. System Overview

The app is a **modular monolith** — a single Laravel codebase with enforced internal boundaries. Five business domains (Products, Pricing, Competitor, CRM, Suggestions) each own their own services, events, models, and Filament resources. They communicate through **domain events on the Laravel event bus** and **shared foundation tables** (audit log, suggestions, sync state) — never by reaching into each other's internals.

```
┌───────────────────────────────────────────────────────────────────────────────┐
│                          INBOUND / TRIGGER LAYER                              │
├───────────────────────────────────────────────────────────────────────────────┤
│  Scheduler   Woo Webhooks   Filesystem Watch   Filament Admin   Artisan CLI   │
│  (daily)     (HMAC-signed)  (storage/csv)      (user actions)   (backfill)    │
└──────┬───────────┬──────────────┬────────────────┬───────────────┬────────────┘
       │           │              │                │               │
       ▼           ▼              ▼                ▼               ▼
┌───────────────────────────────────────────────────────────────────────────────┐
│                          APPLICATION / MODULE LAYER                           │
│                                                                               │
│  ┌────────────────┐  ┌────────────────┐  ┌────────────────┐                  │
│  │  Products      │  │  Pricing       │  │  Competitor    │                  │
│  │  (catalog SoT) │  │  (rules+calc)  │  │  (CSV + diff)  │                  │
│  └────────┬───────┘  └────────┬───────┘  └────────┬───────┘                  │
│           │                   │                   │                          │
│           └───────────┬───────┴───────────────────┘                          │
│                       │                                                      │
│                       ▼                                                      │
│            ┌──────────────────────┐      ┌────────────────┐                 │
│            │  Domain Event Bus    │◄────►│  Suggestions   │ (human-in-loop) │
│            │  (Laravel Events)    │      │  (queue + UI)  │                 │
│            └──────────┬───────────┘      └────────────────┘                 │
│                       │                                                      │
│           ┌───────────┼───────────┐                                          │
│           │           │           │                                          │
│           ▼           ▼           ▼                                          │
│  ┌────────────┐ ┌────────────┐ ┌────────────┐                                │
│  │  Sync/Woo  │ │   CRM/Bx   │ │  Feeds     │ (Phase 8+ — same contract)    │
│  │  (push)    │ │  (push)    │ │  (Merchant)│                                │
│  └─────┬──────┘ └─────┬──────┘ └─────┬──────┘                                │
└────────┼──────────────┼──────────────┼────────────────────────────────────────┘
         │              │              │
         ▼              ▼              ▼
┌───────────────────────────────────────────────────────────────────────────────┐
│                         INTEGRATION / CLIENT LAYER                            │
│                                                                               │
│   SupplierClient    WooClient      BitrixClient    FeedGenerator (iface)     │
│   (JWT, 21stcav)    (REST, key)    (inbound WH)    (Merchant/Meta/Amzn)      │
└──────┬──────────────────┬────────────────┬────────────────┬──────────────────┘
       │                  │                │                │
       ▼                  ▼                ▼                ▼
┌───────────────────────────────────────────────────────────────────────────────┐
│                            PERSISTENCE LAYER                                  │
│                                                                               │
│   MySQL (domain tables)   Redis (Horizon + cache)   Storage (CSV, images)    │
│   + audit_log + suggestions + sync_runs + integration_events                 │
└───────────────────────────────────────────────────────────────────────────────┘
```

### Component Responsibilities

| Component | Owns | Talks to |
|-----------|------|----------|
| **Products module** | `Product`, `Brand`, `Category`, `Supplier` models; SKU lookup; "is this product excluded?" logic | Fires `ProductCreated`, `ProductStockChanged`, `ProductMissingAtSupplier`. Called by Sync module. |
| **Pricing module** | `PricingRule`, `PricingOverride`; most-specific-wins resolver; effective-price calculator | Listens to `SupplierPriceChanged` → recalculates → fires `ProductPriceChanged`. Queried by Sync, Competitor, Feeds. |
| **Competitor module** | `CompetitorPrice` (full history), CSV ingest parser, margin-delta analyser | Listens to CSV-drop event → persists → fires `CompetitorUndercutDetected`. Writes to Suggestions. |
| **Sync module** (Woo push) | Woo REST wrapper, per-SKU push jobs, sync run tracking, failed-push retry | Listens to `ProductPriceChanged`, `ProductStockChanged`, `ProductContentChanged` → pushes to Woo. Writes `audit_log`, `integration_events`. |
| **Webhook module** (Woo inbound) | HMAC verification middleware, raw-payload logging, event dispatch | Receives Woo order/customer webhooks → fires `OrderReceived`, `CustomerRegistered`. Hands off immediately — no processing in controller. |
| **CRM module** (Bitrix push) | `BitrixClient`, field mapping config, entity resolver (Deal/Contact/Company) | Listens to `OrderReceived`, `CustomerRegistered` → pushes to Bitrix24. Writes `audit_log`, `integration_events`. |
| **Suggestions module** | `Suggestion` model, approve/reject/expire workflow, Filament inbox | Written-to by any module that has `auto_apply=false` decisions. Applies via a dispatched `ApplySuggestionJob` on approval. |
| **Audit/Foundation** | `audit_log`, `integration_events`, `sync_runs`, `sync_cursors` | Shared infrastructure. Every module writes here; nothing writes back. |

**Seam rule:** modules depend inward on the **Foundation layer** (shared models, event bus, clients) and communicate outward only via **events** or **published service interfaces**. Direct `use App\Modules\CRM\Models\...` from inside the Products module is forbidden (enforce with Deptrac in CI — [source][dep]).

---

## 2. Recommended Project Structure

Flat `app/` with a `Domain/` (or `Modules/`) subfolder per business domain. This is lighter than full DDD but stricter than stock Laravel — the right fit for a 7-phase greenfield where the user has committed to modular discipline but doesn't need CQRS/aggregates.

```
app/
├── Console/
│   └── Commands/
│       ├── BackfillBitrixOrdersCommand.php
│       └── PruneAuditLogCommand.php
├── Domain/                          # Business modules — the seams
│   ├── Products/
│   │   ├── Models/                  # Product, Brand, Category, Supplier
│   │   ├── Services/                # ProductLookupService, SkuResolver
│   │   ├── Events/                  # ProductCreated, StockWentToZero
│   │   ├── Jobs/                    # (few — most jobs live in Sync)
│   │   └── Filament/                # Resources, Pages
│   ├── Pricing/
│   │   ├── Models/                  # PricingRule, PricingOverride
│   │   ├── Services/                # RuleResolver, PriceCalculator
│   │   ├── Events/                  # ProductPriceChanged
│   │   └── Filament/                # RuleResource, PricePreviewPage
│   ├── Competitor/
│   │   ├── Models/                  # CompetitorPrice, CompetitorSource
│   │   ├── Services/                # CsvIngestor, MarginAnalyser
│   │   ├── Jobs/                    # IngestCompetitorCsvJob
│   │   ├── Events/                  # CompetitorUndercutDetected
│   │   └── Filament/                # TrendsPage, DeltasPage
│   ├── Sync/                        # Laravel → Woo (outbound push)
│   │   ├── Services/                # WooClient, WooProductPusher
│   │   ├── Jobs/                    # PushProductToWooJob, RunSupplierSyncJob
│   │   ├── Listeners/               # OnProductPriceChanged → push
│   │   ├── Models/                  # SyncRun, SyncCursor
│   │   └── Filament/                # SyncRunsPage, ImportIssuesPage
│   ├── Webhooks/                    # Woo → Laravel (inbound)
│   │   ├── Http/Controllers/        # WooWebhookController
│   │   ├── Http/Middleware/         # VerifyWooHmacSignature
│   │   ├── Services/                # WebhookDispatcher
│   │   ├── Events/                  # OrderReceived, CustomerRegistered
│   │   └── Models/                  # WebhookReceipt (raw payload log)
│   ├── CRM/                         # Laravel → Bitrix24 (outbound push)
│   │   ├── Services/                # BitrixClient, FieldMapper, DealBuilder
│   │   ├── Jobs/                    # PushDealToBitrixJob, PushContactJob
│   │   ├── Listeners/               # OnOrderReceived → build+push
│   │   ├── Models/                  # BitrixFieldMap
│   │   └── Filament/                # FieldMappingPage, CrmLogPage
│   ├── Suggestions/                 # Human-in-the-loop seam
│   │   ├── Models/                  # Suggestion
│   │   ├── Contracts/               # SuggestionApplier interface
│   │   ├── Jobs/                    # ApplySuggestionJob
│   │   └── Filament/                # SuggestionInbox
│   └── Feeds/                       # Phase 8 seam — empty in v1 except contract
│       └── Contracts/
│           └── FeedGenerator.php    # Interface stub, Merchant/Meta/Amzn later
├── Foundation/                      # Shared infrastructure — no business logic
│   ├── Audit/
│   │   ├── Models/AuditLog.php
│   │   └── Services/Auditor.php     # Auditor::record($action, $context)
│   ├── Integration/
│   │   ├── Models/IntegrationEvent.php  # Every outbound API attempt
│   │   └── Services/IntegrationLogger.php
│   └── Events/                      # Base event class with correlation_id
├── Http/
│   ├── Controllers/                 # Thin — most routes live in Domain/*/Http
│   └── Middleware/
├── Models/                          # Only User + truly cross-domain models
├── Policies/
└── Providers/
    ├── AppServiceProvider.php
    ├── EventServiceProvider.php     # Wires events → listeners across modules
    └── DomainServiceProvider.php    # Loads each module's routes/migrations
```

### Structure Rationale

- **`app/Domain/` over top-level `Modules/`:** Keeps PSR-4 simple (`App\Domain\Pricing\Services\RuleResolver`), avoids needing composer `merge-plugin` or package-style modules. Lighter than [nwidart/laravel-modules][nwidart] but gives the same mental model.
- **Each domain owns its Filament resources in-module:** Don't centralise `app/Filament/` — it becomes a dumping ground. Register each module's resources via its own panel provider contribution.
- **`Foundation/` is the only cross-cutting namespace:** Anything that every module needs (audit, events, base integration logging) lives here. If something feels like it belongs in two modules, it belongs in Foundation — or it's a sign of a leaky abstraction.
- **`Feeds/` as an empty v1 contract:** Creates the seam for Phase 8 without code. A half-built `FeedGenerator` interface costs nothing now and saves a refactor later.
- **Events folder *per module*:** Events are part of the module that produces them. The listener lives with the module that reacts. This keeps event ownership obvious — a Sync listener subscribing to a Pricing event is perfectly fine and expected.

---

## 3. Architectural Patterns

### Pattern 1: Event-Driven Cross-Module Communication

**What:** Modules fire PHP events when domain-significant things happen; other modules listen. No direct service calls between modules except through published interfaces in `app/Domain/*/Contracts/`.

**When to use:** Every cross-module reaction in this codebase. "When price changes, push to Woo" — Pricing fires, Sync listens. "When order arrives, push to Bitrix" — Webhooks fires, CRM listens.

**Trade-offs:**
- Pro: future agents/feeds subscribe to the same events without touching core code (Phase 8-10 requirement)
- Pro: testable — dispatch the event in a unit test, assert listeners ran
- Con: flow harder to trace than direct calls — mitigated by **always logging the event** via a base `DomainEvent` class that writes to `integration_events` with a `correlation_id`

**Example:**

```php
// app/Domain/Pricing/Events/ProductPriceChanged.php
final class ProductPriceChanged extends DomainEvent
{
    public function __construct(
        public readonly int $productId,
        public readonly string $sku,
        public readonly float $oldPrice,
        public readonly float $newPrice,
        public readonly string $reason,   // 'supplier_sync' | 'rule_applied' | 'manual' | 'suggestion_approved'
    ) {
        parent::__construct();   // generates correlation_id, timestamps
    }
}

// app/Domain/Sync/Listeners/PushPriceToWoo.php
final class PushPriceToWoo implements ShouldQueue
{
    public string $queue = 'sync-woo-push';
    public int $tries = 5;
    public array $backoff = [30, 60, 120, 300, 600];

    public function handle(ProductPriceChanged $event): void
    {
        PushProductToWooJob::dispatch($event->productId, ['price'])
            ->onQueue($this->queue);
    }
}
```

### Pattern 2: Suggestions Table (Human-in-the-Loop Write Seam)

**What:** Every feature that mutates data has an `auto_apply` flag. When false, the proposed change is written to `suggestions` instead of being applied. An admin reviews in a Filament inbox; approval fires `ApplySuggestionJob` which executes the change.

**When to use:** From day one for margin changes, auto-created products (draft-first), and any AI-generated change in Phase 10. Starts as a manual review workflow, becomes the agent output surface later — **same table, same UI, different producer**.

**Trade-offs:**
- Pro: Phase 10 agents bolt on with zero refactor — they become another `SuggestionGenerator`
- Pro: gives ops team an "undo/review" safety net during cutover
- Con: more tables/UI to build in v1 (worth it — brief explicitly commits to this)

**Schema:**

```sql
CREATE TABLE suggestions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    type VARCHAR(64) NOT NULL,           -- 'margin_change', 'product_create', 'ad_pause' (Phase 10)
    subject_type VARCHAR(64),            -- MorphTo: Product, PricingRule, etc.
    subject_id BIGINT UNSIGNED,
    source VARCHAR(32) NOT NULL,         -- 'competitor_analyser' | 'agent:pricing' | 'manual'
    payload JSON NOT NULL,               -- proposed change in module-specific shape
    rationale TEXT,                      -- human-readable "why"
    confidence TINYINT UNSIGNED,         -- 0-100, null if N/A
    status ENUM('pending','approved','rejected','expired','applied','failed') DEFAULT 'pending',
    auto_apply BOOLEAN DEFAULT FALSE,
    reviewed_by BIGINT UNSIGNED NULL,    -- FK users
    reviewed_at TIMESTAMP NULL,
    applied_at TIMESTAMP NULL,
    applied_result JSON NULL,            -- what actually happened
    correlation_id UUID,                 -- ties back to originating event
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX (status, type),
    INDEX (subject_type, subject_id)
);
```

**Contract:**

```php
interface SuggestionApplier
{
    public function supports(Suggestion $s): bool;
    public function apply(Suggestion $s): array;   // returns applied_result
}
```

Each module registers its own appliers (Pricing registers `MarginChangeApplier`, Products registers `ProductCreateApplier`). `ApplySuggestionJob` resolves the right one from the container.

### Pattern 3: Outbound Integration Log (`integration_events`)

**What:** Every outbound API call (Woo, Bitrix, supplier, feeds) writes a row to `integration_events` — request body, response, status, duration, retry count, correlation_id. Separate from `audit_log` (which records **what changed in our data**) — `integration_events` records **what we told external systems and what they said back**.

**When to use:** Every call in every client service. Non-negotiable per project constraint ("audit everything"). Critical for Phase 10 agents that need to know which pushes succeeded.

**Schema:**

```sql
CREATE TABLE integration_events (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    channel VARCHAR(32) NOT NULL,        -- 'woo' | 'bitrix' | 'supplier' | 'merchant_center'
    direction ENUM('outbound','inbound') NOT NULL,
    operation VARCHAR(64) NOT NULL,      -- 'product.update' | 'deal.create' | 'token.generate'
    subject_type VARCHAR(64) NULL,
    subject_id BIGINT UNSIGNED NULL,
    correlation_id UUID,
    request_payload JSON,
    response_payload JSON,
    http_status SMALLINT,
    duration_ms INT,
    attempt TINYINT UNSIGNED,
    status ENUM('success','failed','retrying') NOT NULL,
    error_message TEXT,
    created_at TIMESTAMP,
    INDEX (channel, created_at),
    INDEX (subject_type, subject_id),
    INDEX (correlation_id)
);
```

Implemented by a single `IntegrationLogger::log()` call wrapped around every HTTP call in `WooClient`, `BitrixClient`, `SupplierClient`. The Filament CRM Push Log and Supplier Sync Status dashboards read from this table filtered by `channel`.

### Pattern 4: HMAC Webhook Middleware (Inbound Woo)

**What:** A dedicated middleware verifies `X-WC-Webhook-Signature` against a base64-encoded HMAC-SHA256 of the raw request body. Registered on a route group, not globally. Controller body is trivial — log the raw payload, fire a domain event, return 200. No business logic in the controller.

**When to use:** Every inbound Woo webhook route. Same pattern can be reused for Phase 9 back-in-stock customer signals if they ever come via webhook.

**Key gotchas ([source][woohmac]):**
- WooCommerce calls `wp_specialchars_decode()` on the secret before signing — use alphanumeric secrets, no `&<>` characters
- Compute HMAC over the **raw request body**, not a re-encoded one (`$request->getContent()` in Laravel, before any JSON decoding)
- Use `hash_equals()` for timing-safe comparison
- Exclude webhook routes from CSRF (register under `routes/webhooks.php`, not `web.php`)

```php
// app/Domain/Webhooks/Http/Middleware/VerifyWooHmacSignature.php
public function handle(Request $request, Closure $next): Response
{
    $signature = $request->header('X-WC-Webhook-Signature');
    $secret = config('services.woocommerce.webhook_secret');
    $computed = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

    abort_unless($signature && hash_equals($computed, $signature), 401, 'Invalid signature');
    return $next($request);
}
```

### Pattern 5: Queue Segregation by Workload Class (Horizon Supervisors)

**What:** Horizon supervisors are grouped by **failure impact and latency profile**, not by module. Isolating CRM pushes from bulk sync means a failing Bitrix endpoint can't starve the supplier sync, and vice versa.

**When to use:** Always. Horizon docs explicitly recommend defining multiple supervisors when queues have different balancing/process needs ([source][horizon]).

**Supervisor layout:**

| Supervisor | Queues | Why separate |
|------------|--------|--------------|
| `webhook-inbound` | `webhooks` | Must drain near-instantly — blocks Woo retries. 10+ workers. |
| `crm-push` | `crm-bitrix` | Order events are customer-facing. Isolated so a Bitrix outage doesn't starve other work. 3-5 workers. |
| `sync-woo-push` | `sync-woo-push` | Per-SKU push jobs from price/stock events. Moderate priority, 3 workers. |
| `sync-bulk` | `sync-bulk` | Daily full supplier sync (thousands of SKUs). Low priority, 2 workers, long timeout. |
| `competitor-ingest` | `competitor-csv` | CSV parsing. Scheduled batch work, 1-2 workers. |
| `default` | `default` | Everything else. 2 workers. |

Never push CRM jobs onto `default` — one flaky external service should not delay a different one.

---

## 4. Data Flow

### Flow A — Daily Supplier Sync (21stcav API → Laravel → Woo)

```
Scheduler (03:00 UTC)
    ↓
RunSupplierSyncJob → queue: sync-bulk
    ↓
SupplierClient::generateToken()   ──── JWT auth
    ↓
Chunk loop (resume from sync_cursors):
    ├── SupplierClient::fetchChunk(cursor)
    ├── For each SKU:
    │   ├── Products module: resolve Product, check _exclude_from_auto_update
    │   ├── Pricing module: calculate final_price (rule resolver + VAT)
    │   ├── If changed: persist → fire ProductPriceChanged / ProductStockChanged
    │   └── Sync listener enqueues PushProductToWooJob(product, ['price','stock'])
    │       → queue: sync-woo-push
    │       → WooClient::put("products/{id}")
    │       → IntegrationLogger writes integration_events row
    │       → on success: audit_log row; on failure: retry w/ backoff
    └── Advance sync_cursors.last_id
    ↓
SyncRunCompleted event
    ↓
Mail::to(admin)->send(SupplierSyncReportMail)   ──── CSV attached
```

**Key property:** the main loop does NOT block on Woo pushes. It fires events and moves on. Push fan-out happens in parallel on the `sync-woo-push` supervisor. A Woo API slowdown slows pushes, not the fetch loop.

### Flow B — Woo Order Webhook → Bitrix24 Deal

```
WooCommerce (order created)
    ↓ POST /webhooks/woo/order  (X-WC-Webhook-Signature header)
VerifyWooHmacSignature middleware   ──── 401 if invalid
    ↓
WooWebhookController::order()
    ├── Persist raw payload to webhook_receipts (dedupe key: X-WC-Webhook-Delivery-Id)
    ├── Fire OrderReceived event with correlation_id = delivery_id
    └── Return 200 OK  ──── < 500ms total
    ↓
OrderReceived event
    ↓
CRM module listener: BuildAndPushDeal
    ↓
PushDealToBitrixJob → queue: crm-bitrix
    ├── DealBuilder: normalize Woo order → Bitrix Deal shape using BitrixFieldMap
    ├── Resolve/create Contact (by email)
    ├── Resolve/create Company (if B2B fields present)
    ├── BitrixClient::call('crm.deal.add')
    ├── IntegrationLogger writes (channel=bitrix, op=deal.create, correlation_id=…)
    └── On failure: retry w/ backoff; after max attempts → suggestions('crm_push_failed')
                                                         for manual intervention
```

### Flow C — Competitor CSV → Suggestion → Human Approval → Pricing Update

```
n8n drops file in storage/competitors/tonergeeks_2026-04-18.csv
    ↓
Scheduler (every 5 min): IngestCompetitorCsvJob → queue: competitor-csv
    ├── CsvIngestor: auto-detect sku/mpn + price columns
    ├── Strip £, divide by 1.2 (VAT)
    ├── Insert rows into competitor_prices (full history — never truncate)
    └── MarginAnalyser::analyse() for each SKU
        ├── Compute margin delta vs supplier cost
        └── If competitor margin ≥ threshold (default 8%):
            └── Fire CompetitorUndercutDetected
    ↓
Suggestions module listener: CreateMarginChangeSuggestion
    ├── Build Suggestion(type='margin_change', source='competitor_analyser',
    │                    payload={new_margin, evidence}, rationale, confidence)
    ├── auto_apply = false (v1 default)
    └── Insert into suggestions
    ↓
Filament Suggestion Inbox (admin reviews)
    ├── Approve → ApplySuggestionJob → MarginChangeApplier::apply()
    │   → updates PricingRule → fires ProductPriceChanged
    │   → cascades through Flow A's Woo push path
    └── Reject → status=rejected, reason logged
```

**Phase 10 replacement:** when the Pricing Agent ships, it runs the same analysis with richer context (GA conversion data) and writes to the same `suggestions` table with `source='agent:pricing'`. The inbox UI, apply path, and audit trail don't change.

### Flow D — Event Correlation Through a Full Chain

Every `DomainEvent` carries a `correlation_id` (UUID) generated at the trigger point. Every `audit_log`, `integration_events`, and `suggestions` row records it. This lets a dashboard show:

> "Supplier price changed for SKU-1234 at 03:04:12 → margin rule applied → Woo push succeeded at 03:04:15 → competitor undercut detected at 09:00 → margin-change suggestion created → approved by sonny@21cav.com at 11:14 → Woo push succeeded at 11:14:03"

— all by joining on one UUID. Essential for the Phase 10 agents which need to reason about cause-and-effect chains.

---

## 5. Domain Event Catalogue

Events that must exist from day one (v1), plus v1-ready events that have no listeners yet but are "free to fire" because the infrastructure is in place.

### Products module

| Event | Trigger | v1 Listeners | Phase 8+ Listeners |
|-------|---------|--------------|--------------------|
| `ProductCreated` | Auto-create or manual admin create | `PushProductToWooJob` (Sync) | `AddToMerchantFeed` (Feeds), `NotifyBackInStockSubscribers` (Phase 9) |
| `ProductMissingAtSupplier` | SKU not returned in supplier sync | `SetWooStatusPending` (Sync), log to audit | `PauseGoogleAds` (Phase 8) |
| `ProductContentChanged` | Title/description/image updated | `PushProductToWooJob` content fields | `RegenerateMerchantFeed` (Phase 8), `SeoAgentReview` (Phase 10) |
| `StockWentToZero` | Stock crossed from >0 to 0 | `PushProductToWooJob` stock fields | `NotifyBackInStockSubscribers` prep (Phase 9), `PauseGoogleAds` (Phase 8) |
| `StockReturned` | Stock crossed from 0 to >0 | `PushProductToWooJob` stock fields | `FireBackInStockEmails` (Phase 9), `ResumeGoogleAds` (Phase 8) |

### Pricing module

| Event | Trigger | v1 Listeners |
|-------|---------|--------------|
| `SupplierPriceChanged` | Supplier sync detected new cost | `RecalculateFinalPrice` (Pricing self-listener) |
| `ProductPriceChanged` | Final price recalculated | `PushProductToWooJob` price fields (Sync), audit_log |
| `PricingRuleChanged` | Admin edits rule in Filament | `RecalculateAllAffectedProducts` (batch job) |

### Competitor module

| Event | Trigger | v1 Listeners |
|-------|---------|--------------|
| `CompetitorCsvIngested` | CSV file processed | `RunMarginAnalysis` |
| `CompetitorUndercutDetected` | Margin delta ≥ threshold | `CreateMarginChangeSuggestion` |

### Webhooks / CRM module

| Event | Trigger | v1 Listeners |
|-------|---------|--------------|
| `OrderReceived` | Woo order webhook verified | `BuildAndPushDealToBitrix` |
| `CustomerRegistered` | Woo customer webhook verified | `UpsertBitrixContact` |
| `OrderRefunded` | Woo refund webhook | `UpdateBitrixDealStage` (v1 or phase-flex) |

### Foundation (cross-cutting)

| Event | Trigger | Listeners |
|-------|---------|-----------|
| `IntegrationCallFailed` | Any outbound client call failed max attempts | `CreateCrmFailureSuggestion` or `AlertAdmin` |
| `SyncRunCompleted` | Daily sync finished | `MailSupplierSyncReport` |
| `SuggestionApplied` | Suggestion approved and executed | audit_log |

**Event-design rule:** events are **past tense and domain-meaningful**, never `BeforeDoX` / `AfterDoX`. Events describe what happened, not what a listener should do. [source][events]

---

## 6. Integration Points

### External Services

| Service | Direction | Client | Queue | Failure behaviour |
|---------|-----------|--------|-------|-------------------|
| `21stcav.com/api/index.php` | Laravel ← | `SupplierClient` (JWT cached 1h) | `sync-bulk` | Retry sync from last cursor; on token failure, refresh then retry |
| WooCommerce REST | Laravel → | `WooClient` (`automattic/woocommerce`) | `sync-woo-push` | 5 retries w/ exponential backoff; on final fail, audit + alert |
| WooCommerce webhooks | Woo → Laravel | `WooWebhookController` + HMAC middleware | — | Respond 200 after persisting raw payload; process async |
| Bitrix24 inbound webhook | Laravel → | `BitrixClient` | `crm-bitrix` | 5 retries; on final fail, create `crm_push_failed` suggestion for manual resend |
| n8n CSV drop | n8n → disk → Laravel | `storage/competitors/` watcher | `competitor-csv` | Move processed files to `processed/`; failed parses go to `failed/` with error sidecar |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| Products ↔ Pricing | Events (`SupplierPriceChanged`) + direct service call for sync price lookup | Pricing exposes `PriceCalculator::effectivePriceFor(Product)` as a public contract |
| Pricing ↔ Sync | Events only (`ProductPriceChanged`) | Sync never calls Pricing directly — it only reacts |
| Competitor ↔ Pricing | Events + Suggestions table | No direct writes — always goes through the suggestion seam |
| Webhooks ↔ CRM | Events only (`OrderReceived`) | Webhook controller MUST NOT touch Bitrix directly (latency + coupling) |
| CRM ↔ Products | Read-only queries via `ProductLookupService` interface | CRM resolves line items to product rows for Deal-line creation |
| Any module ↔ Suggestions | `Suggestions::propose()` facade-style | Suggestion module is write-only from producers; read/apply is its own concern |
| Any module ↔ Foundation/Audit | `Auditor::record()`, `IntegrationLogger::log()` | Singleton-style services bound in container |

---

## 7. Suggested Build Order (Phases 1–7)

Dependency-first ordering. Each phase's foundation is in place before the next phase needs it. Phase numbers match `PROJECT-BRIEF.md`.

```
Phase 1 — Foundation
├── Laravel 12 + Filament 3 + Horizon + Redis
├── Base models: Product, Brand, Category, Supplier (empty data)
├── app/Foundation/ — Auditor, IntegrationLogger, DomainEvent base class
├── app/Domain/Suggestions/ — Suggestion model, inbox scaffold, SuggestionApplier contract
├── WooClient + SupplierClient skeletons (auth works, no business logic)
├── Horizon supervisor config (all queues defined, empty)
└── CI: Deptrac rules enforcing module boundaries
    │
    ├── SEAMS ESTABLISHED: audit_log, integration_events, suggestions, event bus
    │
    ▼
Phase 2 — Supplier sync (replaces Stock Updater — highest business value, lowest risk)
├── Products module: Product CRUD, SKU lookup, exclusion flag
├── Sync module: RunSupplierSyncJob, PushProductToWooJob, SyncRun tracking
├── Events wired: SupplierPriceChanged, StockWentToZero, ProductMissingAtSupplier
├── Scheduler entry + email report
└── Filament: Supplier Sync Status page, Import Issues page
    │
    ▼
Phase 3 — Pricing engine
├── Pricing module: PricingRule, PricingOverride, RuleResolver, PriceCalculator
├── Listener: SupplierPriceChanged → recalc → ProductPriceChanged
├── Filament: Rule CRUD, Price Preview page
└── At this point sync respects rules automatically — no Sync changes needed
    │
    ▼
Phase 4 — Competitor module
├── Competitor module: CsvIngestor, CompetitorPrice (history), MarginAnalyser
├── File watcher (scheduler) + IngestCompetitorCsvJob
├── Event: CompetitorUndercutDetected → suggestion creation
├── MarginChangeApplier (completes the Suggestions seam for first real use case)
└── Filament: Trends, Deltas, Per-competitor pages
    │
    │ ── First non-trivial use of suggestions table — validates the pattern
    │
    ▼
Phase 5 — Product auto-create
├── Trigger: SKU in supplier sync with no Product match
├── Products module: SEO template applier, image sourcing (supplier DB or placeholder)
├── CreateWooProductJob + ProductCreateApplier (suggestion-first for safety)
├── Admin review inbox reuses Suggestions UI
└── Filament: Draft products queue
    │
    ▼
Phase 6 — Bitrix24 CRM sync
├── Webhooks module: HMAC middleware, controller, raw payload logger
├── CRM module: BitrixClient, FieldMapper, DealBuilder, Contact/Company resolvers
├── Listeners: OrderReceived → PushDealToBitrixJob, CustomerRegistered → UpsertContact
├── Filament: FieldMappingPage (dynamic from Bitrix crm.deal.fields), CRM Push Log
├── Artisan: BackfillBitrixOrdersCommand for historical orders
└── UTM/GA Client ID capture on checkout → Deal custom fields
    │
    ▼
Phase 7 — Cutover
├── Parallel-run monitoring dashboards
├── Disable Stock Updater plugin
├── Disable itgalaxy plugin
├── Ops handover docs
└── Phase 8+ seams verified: Feeds interface, Suggestions extensibility, event catalogue
```

**Ordering rationale:**

1. **Foundation first** — audit/suggestions/events have to exist before phase 2 because phase 2 writes to them. Skipping this = rewrite later.
2. **Supplier sync before pricing** — supplier sync works fine with default margin tiers in v1 code. Adding pricing rules in phase 3 is a pure enhancement with no sync-code changes.
3. **Pricing before competitor** — competitor analysis suggests margin changes, which requires `PricingRule` to exist.
4. **Competitor before auto-create** — validates the Suggestions pattern on a simpler case (margin change) before applying it to a bigger one (create-a-product).
5. **CRM last before cutover** — it's the most independent module (no dependency on products/pricing/competitor) and the highest-risk integration (Bitrix quirks, HMAC edge cases). Saving it for last lets the supplier sync cutover proceed independently if CRM needs extra time.

---

## 8. Scaling Considerations

Realistic — this is an internal ops tool, not a public SaaS. The external APIs (Bitrix rate limits, Woo REST throughput) are the real ceiling, not Laravel.

| Scale | Adjustments |
|-------|-------------|
| v1 launch (~20k Woo products, <100 orders/day) | Default Horizon config, single MySQL, single Redis. Supplier sync runs in <30min. Woo push throughput limited by Woo itself. |
| Phase 8-9 (+GA4, +customer notifications) | Add `notifications` queue supervisor. GA4 ingest runs hourly on `analytics-ingest` supervisor. Consider read replica if dashboard queries get heavy. |
| Phase 10+ (AI agents, multi-feed) | `ai-agent` queue supervisor with long timeout (5-10min per agent run). `feeds` supervisor for channel regeneration. `integration_events` table hits 10M+ rows — partition by month or add `PruneIntegrationEventsCommand` with retention policy (decide in planning). |
| Phase 12 (B2B, chatbot) | Still single app — don't split to microservices. If customer-facing chatbot needs lower latency, that's a Livewire/stateless read path, not a separate service. |

### First Bottleneck Predictions

1. **Woo REST throughput during initial bulk sync** — push rate-limited by Woo plugin ecosystem. Mitigate: chunked push, low worker count on `sync-bulk`, respect 429s.
2. **`integration_events` write volume** — 20k products × 2-3 pushes/day = 60k rows/day, 20M/year. Mitigate: retention policy, consider archiving to cold storage in Phase 11.
3. **Bitrix24 rate limits** — Bitrix webhook limits are lenient but exist. Retry w/ backoff handles this; isolated supervisor prevents cross-impact.

---

## 9. Anti-Patterns

### Anti-Pattern 1: Eloquent Relationships Across Modules

**What people do:** `Order::belongsTo(\App\Domain\Products\Models\Product::class)`.
**Why it's wrong:** Couples Webhooks module to Products module internals. One module's schema change breaks the other. Tests have to boot both modules even for isolated scenarios.
**Do this instead:** Store the SKU string (or a `product_ref_id`) on the order row, and resolve it through a published interface: `ProductLookupService::findBySku($sku)`. The relationship is logical, not Eloquent-wired. [source][modules]

### Anti-Pattern 2: Controller with Business Logic for Webhooks

**What people do:** `WooWebhookController::order()` decodes payload, builds a Bitrix deal, calls Bitrix, writes audit row — all inline.
**Why it's wrong:** Webhook takes >5s, Woo retries, you get duplicate deals. Also blocks the HTTP worker.
**Do this instead:** Controller persists raw payload, fires event, returns 200. All work happens in listeners on queues. Controller body should be under 20 lines.

### Anti-Pattern 3: Direct Woo DB Writes

**What people do:** `DB::connection('wordpress')->table('wp_posts')->update(...)`.
**Why it's wrong:** Breaks Woo plugin hooks (stock notifications, cache invalidation, webhook firing). Creates invisible side-effect bugs. Explicitly forbidden by brief.
**Do this instead:** Always go through `WooClient` (REST). If it's slow, batch endpoint. If the endpoint doesn't exist, add a custom Woo REST controller on the Woo side — never reach into the DB.

### Anti-Pattern 4: Sharing a Single Queue for All Work

**What people do:** Everything runs on `default`.
**Why it's wrong:** A Bitrix outage fills the queue with retries, starving supplier sync. A stuck competitor CSV job blocks webhook handling.
**Do this instead:** Supervisor-per-workload-class (see Pattern 5). At minimum separate: inbound-webhooks, external-API-push, bulk-sync, default. [source][horizon]

### Anti-Pattern 5: Audit Log Used as Event Bus

**What people do:** Listeners read `audit_log` rows to decide what to do next.
**Why it's wrong:** Audit log is a record, not a trigger. Coupling reactions to log rows makes retention/archival a minefield.
**Do this instead:** Fire events for reactions. Use `audit_log` / `integration_events` only for reads (dashboards, debugging, agent inputs).

### Anti-Pattern 6: Writing to Woo from Multiple Places

**What people do:** Sync module pushes price; Pricing module also pushes price directly when a rule changes.
**Why it's wrong:** Race conditions, duplicate pushes, inconsistent audit trail.
**Do this instead:** **Only the Sync module writes to Woo.** Other modules fire events; Sync listens. Single writer, single integration log source.

### Anti-Pattern 7: Skipping the Suggestions Table "Just for Now"

**What people do:** "V1 is all auto-apply, we'll add suggestions in Phase 10."
**Why it's wrong:** Retrofitting a suggestion seam into production code paths is the exact refactor the project brief says to avoid. Cost of the seam in v1 is a few hours per module; cost of retrofit later is weeks.
**Do this instead:** Build the Suggestions table in Phase 1. Every mutation checks `auto_apply` (which can default to true in v1 if desired — the seam is still there). Phase 10 agents then become trivial producers.

---

## 10. Where the Seams Go (Summary)

| Seam | Location | First real use | Phase 8+ reuse |
|------|----------|----------------|----------------|
| **Event bus** | `app/Domain/*/Events/` + `EventServiceProvider` | Phase 2 (supplier sync fires `SupplierPriceChanged`) | Every feed, agent, notification subscribes here |
| **Suggestions table** | `app/Domain/Suggestions/` | Phase 4 (competitor margin suggestions) | Phase 5 (product create), Phase 10 (all agents) |
| **Audit log** | `app/Foundation/Audit/` | Phase 1 (scaffold writes on every model touch) | Phase 10 agents read history for context |
| **Integration events** | `app/Foundation/Integration/` | Phase 2 (every Woo push logged) | Phase 8 (feed-gen logs), Phase 10 (agent reasoning input) |
| **Feed abstraction** | `app/Domain/Feeds/Contracts/FeedGenerator` | Empty v1 stub | Phase 8 (Merchant/Meta/Amazon implement it) |
| **Agent contract** | `app/Domain/Suggestions/Contracts/` (precursor) | Contracts stubbed | Phase 10 (`AgentContract extends SuggestionGenerator`) |
| **Horizon supervisors** | `config/horizon.php` | Phase 1 (defined, empty queues) | Phase 8+ add `feeds`, `analytics-ingest`, `ai-agent` supervisors |
| **Webhook HMAC middleware** | `app/Domain/Webhooks/Http/Middleware/` | Phase 6 (Woo orders) | Phase 9 (any customer-signal webhooks) |

---

## Sources

- [Modular Monolithic Architecture in Laravel 12 — 200OK Solutions][modules] — module boundaries, cross-module communication via events/contracts (HIGH)
- [Exploring Modular Monolithic Architecture: A Laravel Developer's Guide — Medium, Feb 2026][modules2] — 2026 e-commerce example confirming `app/Domain/<BoundedContext>` layout (HIGH)
- [Laravel Horizon — Laravel 12.x official docs][horizon] — supervisor naming, queue balancing, process-per-queue recommendations (HIGH)
- [Laravel Events and Listeners: Building Decoupled Applications — DEV][events] — event-first cross-module communication pattern (MEDIUM)
- [Secure Your Webhooks in Laravel: Preventing Data Spoofing — christalks.dev][woohmac] — HMAC middleware pattern, `hash_equals`, `wp_specialchars_decode` gotcha (MEDIUM)
- [WooCommerce Webhooks Verification in PHP — Pakainfo][woohmac2] — X-WC-Webhook-Signature format, raw body requirement (MEDIUM)
- [Laravel Auditing — Filament plugin (TappNetwork)][audit] — confirms `AuditsRelationManager` available to embed audit trails into Filament resources if Spatie/OwenIt is adopted (MEDIUM)
- [Guide to WooCommerce Webhooks Features and Best Practices — Hookdeck][woohook] — retry semantics, delivery IDs for dedupe (MEDIUM)
- [Laravel AI SDK: Building Database Tools for Agents — laravel.com blog][aisdk] — Phase 10 precedent for agent-produced suggestions flowing through a queued apply path (LOW — Phase 10 relevance only)
- `meetingstore-ops/PROJECT-BRIEF.md` and `.planning/PROJECT.md` — committed architectural principles (SOURCE OF TRUTH)

[modules]: https://www.200oksolutions.com/blog/modular-monolithic-architecture-in-laravel-12/
[modules2]: https://medium.com/@harryespant/exploring-modular-monolithic-architecture-a-laravel-developers-guide-with-an-e-commerce-example-0548668ec222
[horizon]: https://laravel.com/docs/12.x/horizon
[events]: https://dev.to/arasosman/laravel-events-and-listeners-building-decoupled-applications-e91
[woohmac]: https://christalks.dev/post/secure-your-webhooks-in-laravel-preventing-data-spoofing-fe25a70e
[woohmac2]: https://www.pakainfo.com/woocommerce-webhooks-verification-in-php/amp/
[audit]: https://filamentphp.com/plugins/tapp-network-laravel-auditing
[woohook]: https://hookdeck.com/webhooks/platforms/guide-to-woocommerce-webhooks-features-and-best-practices
[aisdk]: https://laravel.com/blog/laravel-ai-sdk-building-production-safe-database-tools-for-agents
[dep]: https://github.com/qossmic/deptrac

---
*Architecture research for: MeetingStore Ops — Laravel 12 + Filament 3 modular monolith*
*Researched: 2026-04-18*
