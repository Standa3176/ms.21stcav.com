# Phase 4: Bitrix24 CRM Sync - Research

**Researched:** 2026-04-19
**Domain:** One-way Woo→Bitrix24 push (Deal + Contact + Company) replacing the sanctions-blocked itgalaxy v1.50.1 plugin. Laravel 12 + Filament 3 + Horizon + `bitrix24/b24phpsdk` ^1.10.
**Confidence:** HIGH on legacy parity (extracted plugin source available verbatim) + HIGH on Phase 1/2 contract reuse (SUMMARY files explicit). MEDIUM on `bitrix24/b24phpsdk` 1.x API surface (official SDK exists, namespace + service-builder shape verified, but per-method signatures need sandbox confirmation — flagged as the #1 sandbox-validation deliverable). HIGH on UTM capture mechanism (legacy plugin source + WP convention well-documented).

## Summary

Phase 4 plugs into infrastructure Phase 1 already shipped: HMAC-verified Woo webhooks reach `WooWebhookController` and dispatch `OrderReceived` + `CustomerRegistered` `DomainEvent` subclasses; `crm-bitrix` Horizon supervisor sits idle waiting for queued listeners; `SuggestionApplier` registry has a stub waiting for the first real `crm_push_failed` producer. The work splits cleanly along these seams — five plans, no ambiguity about where things live in `app/Domain/CRM/`.

The single biggest unknown is **`bitrix24/b24phpsdk` 1.10.x exact method signatures**. STATE.md locks the SDK pin, CONTEXT.md fixes the 12 user decisions, but no code in this project has yet imported a single SDK class. The official SDK exposes a `ServiceBuilderFactory::createServiceBuilderFromWebhook($url)` entry point and a `getCRMScope()->deal()/contact()/company()/userfield()` accessor pattern; per-method shapes (filter array structure, `EMAIL` filter for `crm.contact.list`, error class hierarchy, 429/Retry-After honour) are not documented and **must be sandbox-validated as Plan 02 Task 1** before any production code locks the wrapper interface. Fallback `mesilov/bitrix24-php-sdk` ^2.0 is documented (now marked abandoned — original maintainer points users at the official SDK) but provides identical surface area for the wrapper interface.

Everything else is mechanical: port legacy field mappings from `CrmFields.php` into a `crm_field_mappings` seeder, port the status-stage map from `itglx-wcbx24-update-deal-stage.php` into a `crm_status_mappings` table, build the `BitrixEntityMap` ledger (Pitfall 6 mandate), wire `CRM_WRITE_ENABLED` shadow-mode parallel to `WOO_WRITE_ENABLED`, ship the JS snippet for UTM capture as a deploy artefact for the WP team. The legacy plugin's `Crm.php` gives the exact dedup decision tree (find by `_bitrix24_contact_id` user-meta → `crm.duplicate.findbycomm` by phone → by email → create) which this rebuild improves on by replacing WP user-meta with `BitrixEntityMap`.

**Primary recommendation:** Plan 01 ships `BitrixEntityMap` migration + `bitrix:bootstrap` command + the SDK installation + a sandbox-validation script (`bitrix:smoke-test`) that proves every method the wrapper will call actually returns the shape we expect — before any push code is written. This collapses the SDK MEDIUM-confidence risk into the first 30 minutes of Phase 4.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**UTM / GA Client ID capture (CRM-09)**

- **D-01:** **JS snippet + hidden checkout fields.** A small custom JS deployed on `meetingstore.co.uk` reads `utm_*` query params and the `_ga` cookie on landing, writes them to a first-party cookie, and injects hidden `<input>` fields into the WooCommerce checkout form. WooCommerce persists them automatically to order meta (`_bx_wc_utm_fields_data`-style keys). From Laravel's perspective, they arrive as part of the standard `order.created` webhook payload — no extra inbound endpoint, no WP plugin dependency. Rejected alternatives: a dedicated WP plugin (adds WP surface area we're moving away from) and session-cookie → Laravel endpoint (more moving parts, requires joining on email).
- **D-02:** **First-touch attribution, 30-day cookie window.** JS writes UTMs to the cookie on first landing; reuses stored values on subsequent visits if UTMs are absent; overwrites only when a fresh `utm_*` query param is present. Matches B2B buying-journey reality (campaign that *introduced* the lead gets credit for the eventual considered purchase). Checkout always submits the cookie values.
- **D-03:** **Standard 5 UTMs + GA Client ID = 6 Bitrix Deal custom fields.** `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, and `_ga` Client ID parsed from the GA cookie. `gclid` / `fbclid` are explicitly OUT of scope for Phase 4 and deferred to a future Phase 8+ ads-sync item when offline-conversion upload becomes relevant.
- **D-04:** **UTMs ALSO captured on `customer.created`** (not only on order.created). Same cookie values are pushed to the Bitrix Contact as Contact-level custom fields. When a Deal later fires, the Deal copy is also written. This lets sales see attribution on registered-but-not-yet-purchased leads — a genuine extension over the legacy plugin, which only captures UTMs on the Deal.

**Pipeline routing & Deal-stage mapping (CRM-07)**

- **D-05:** **Match legacy — single admin-picked pipeline + landing stage for every new Deal.** No B2B-vs-retail routing in v1. Inspection of the extracted legacy plugin confirmed its "multiple pipeline support" is just an admin choice of *which* pipeline to use — the plugin does NOT dynamically route orders by attribute. CRM-07 is met by letting the admin pick the pipeline in a Filament settings page; zero routing logic.
- **D-06:** **Configurable Woo-order-status → Bitrix-Deal-stage map (new `crm_status_mappings` table).** Admin-only Filament UI with one row per Woo status (`pending`, `processing`, `on-hold`, `completed`, `cancelled`, `refunded`, `failed`, plus any custom statuses detected on the store). Each row maps to a Bitrix Deal `STAGE_ID`. When `order.updated` fires and the status has changed, the handler calls `crm.deal.update` with the mapped `STAGE_ID`. Legacy parity: the itgalaxy plugin ships this via `functions/itglx-wcbx24-update-deal-stage.php`.
- **D-07:** **Pipeline + status-map settings live in dedicated Filament pages, not a config file.** `CrmPipelineSettingsPage` holds `bitrix_pipeline_id` + `landing_stage_id` (plus assigned-user / responsible-person defaults under Claude's Discretion). `CrmStatusMappingResource` manages the status-map rows. Both admin-only via Shield permissions.

**Webhook scope & order-update behaviour (CRM-03, CRM-04, CRM-08)**

- **D-08:** **Four Woo events drive the sync:** `order.created`, `order.updated`, `customer.created`, `customer.updated`. `order.deleted` is explicitly OUT — Woo rarely hard-deletes; cancellations flow via `order.updated` → status-map → stage transition. All four handlers are queued (`crm-bitrix` queue per Phase 1 FOUND-09), respond to the webhook controller within 200ms, and are idempotent on the existing Phase 1 `X-WC-Webhook-Delivery-ID` dedup.
- **D-09:** **On `order.updated`, patch STAGE_ID + OPPORTUNITY (total) + append new notes only.** Full-field re-push is rejected because it would overwrite manual edits a salesperson makes in the Bitrix UI. Stage transition only fires when the status has actually changed (compare against last-pushed status stored on the `BitrixEntityMap` row). Notes are appended as Deal comments (de-duplicated by hashing `note_id`).
- **D-10:** **Race-safe handling when `order.updated` fires before `order.created` has been processed.** Handler checks `BitrixEntityMap` for the order's `deal_id`; if absent, the job re-queues itself with a 30-second delay (up to 5 attempts). If still missing after 5 attempts, writes a `crm_push_failed` suggestion with kind-subtype `update_before_create`. Uses Laravel's `release($delay)` on `ShouldQueue` jobs.
- **D-11:** **Retry policy: 3 attempts, short backoff (30s, 5m, 30m), 4xx fail-fast.** 5xx/network/429 errors retry (honour `Retry-After` on 429 per Bitrix ~2 req/sec ceiling). 4xx validation errors fail immediately. After attempts exhausted: write `suggestions('crm_push_failed')` row with request+response snapshot + correlation_id, fire the `AlertRecipient` distribution, and park the job in the DLQ.
- **D-12:** **First real producer of the Phase 1 suggestions seam is `crm_push_failed`.** Kind string: `crm_push_failed`. `payload`: target entity_type (Deal/Contact/Company), woo_id, last attempt's HTTP status + body snippet, retry count. `evidence`: correlation_id, full request payload (for replay). A Filament "Replay" action calls `ApplySuggestionJob` with a `CrmPushRetryApplier`. Satisfies CRM-11 (push log) + CRM-12 (DLQ surface).

### Claude's Discretion

- **GDPR right-to-erasure workflow (CRM-13).** Default: dual-entry-point (`php artisan gdpr:erase-bitrix-customer --email={addr}` CLI + Filament admin-only action on the CRM push log). PII scrub-in-place (NOT hard delete): Contact fields replaced with `REDACTED-{sha256-of-email}` tokens via `crm.contact.update`; Deal's `OPPORTUNITY` + `UF_CRM_WOO_ORDER_ID` + stage preserved (financial-record requirement). Company is NOT erased (legal entity, not personal data).
- **Contact / Company deduplication keys (CRM-04, CRM-05, Pitfall 6).** Contact: primary `EMAIL` (case-insensitive), fallback `PHONE` (E.164). Company: `UF_CRM_COMPANY_VAT` if present, fallback `TITLE + ADDRESS_POSTAL_CODE`. Deal: `UF_CRM_WOO_ORDER_ID` exact. `BitrixEntityMap` table with unique index on `(entity_type, woo_id)`. Email change on existing customer → update existing Bitrix Contact (matched via map's `(entity_type='contact', woo_id=customer_id)` row).
- **Field mapping storage + UX (CRM-02, CRM-06).** New `crm_field_mappings` table. Filament Resource per entity (Deal/Contact/Company) with select-dropdown of available Bitrix fields (cached 24h). "Refresh from Bitrix" button invalidates cache. On save, validate each mapped bitrix_field exists in the fresh schema.
- **`php artisan bitrix:bootstrap`** (CRM-01) creates the `UF_CRM_WOO_ORDER_ID` Deal custom field via `crm.deal.userfield.add` if absent. Idempotent. Fails hard if Bitrix auth is broken.
- **`php artisan bitrix:backfill-orders --since={date}` (CRM-10).** Dry-run default; `--live` opt-in; `--since` required; chunk 50 orders per batch; sleep 600ms between chunks.
- **Shadow-mode gate for Bitrix writes.** Introduce `CRM_WRITE_ENABLED` env (default `false`). When false, the push job serialises payload into `sync_diffs` (or new `crm_push_shadow` table — planner decides; this research recommends `sync_diffs` with `provider='bitrix'` column).
- **Listener queue routing.** `order.*` + `customer.*` handlers run on `crm-bitrix`. Backfill runs on `sync-bulk` (Pitfall 7).
- **B24 SDK lock with fallback.** Pin `bitrix24/b24phpsdk` ^1.10 (NOT ^3.x — that line requires PHP 8.4+, project is on 8.2/8.3). Fallback `mesilov/bitrix24-php-sdk` ^2.0 if sandbox blocks.

### Deferred Ideas (OUT OF SCOPE)

- B2B-vs-retail dynamic pipeline routing (legacy plugin doesn't do this; ops haven't asked)
- Full rule-driven pipeline routing
- `gclid` + `fbclid` offline-conversion capture (Phase 8+)
- Two-way status sync (Bitrix → Woo) — explicit Out of Scope in REQUIREMENTS.md
- Inbound Bitrix webhook — explicit Out of Scope
- Coupon list sync — explicit Out of Scope
- Read-only mirror of Bitrix Deal status back into Laravel for dashboard
- `order.deleted` webhook handling — rare; cancellations flow via `order.updated`
- Bitrix Lead entity mode (legacy supports Lead-only; we are fixed to Deal+Contact+Company)
- Custom Woo order statuses auto-discovery
- Full-field re-push on `order.updated`
- Pipeline routing settings in env vars (rejected D-07 in favour of Filament UI)
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| CRM-01 | `php artisan bitrix:bootstrap` creates `UF_CRM_WOO_ORDER_ID` integer custom field on Deal before any push code runs | §"Custom-field bootstrap" — exact `crm.deal.userfield.add` payload + idempotency check via `crm.deal.userfield.list`. Plan 01 deliverable. |
| CRM-02 | Bitrix field schemas cached 24h with manual "Refresh from Bitrix" button + push-time validation reports stale mappings | §"Field-schema cache" — `bitrix_schema_cache` table or Cache::remember pattern + `crm.deal.fields` + `crm.contact.fields` + `crm.company.fields` API. Pitfall 11 mandate. |
| CRM-03 | Woo order creation → Deal + Contact + Company pushed via official SDK | §"SDK API surface" + §"Push job orchestration" — `BitrixClient::dealAdd/contactAdd/companyAdd` calls in `PushOrderToBitrixJob`, sequence Company → Contact → Deal (Deal needs Contact ID + Company ID). |
| CRM-04 | Woo customer registration → Contact upserted (find-or-create by email, never duplicate insert) | §"Contact dedup" — `crm.contact.list` with EMAIL filter, `BitrixEntityMap.entity_type='contact'` ledger row, mb_strtolower normalisation. |
| CRM-05 | Deals found by `UF_CRM_WOO_ORDER_ID` before create so retries never produce duplicates | §"Deal dedup" — `BitrixEntityMap.entity_type='deal'` lookup first; SDK `crm.deal.list` filter `[UF_CRM_WOO_ORDER_ID => $wooOrderId]` as the safety net if map row missing. |
| CRM-06 | Admins map Woo order/customer fields to Bitrix Deal/Contact/Company fields via Filament UI — no code edits | §"Field-mapping storage + UX" — `crm_field_mappings` table + `CrmFieldMappingResource` (one Resource per entity type). Seeded from legacy `CrmFields.php`. |
| CRM-07 | Multiple Bitrix deal pipelines supported; pipeline routing rules admin-configurable | §"Pipeline routing" — single pipeline + landing-stage admin-picked in `CrmPipelineSettingsPage` (D-05/D-07 lock). NOT rule-driven routing. |
| CRM-08 | Order notes synced into Deal's comments | §"Order-note sync" — `crm.deal.update` with `COMMENTS` field append on `order.updated` when new notes present; dedup by note hash. |
| CRM-09 | UTM + GA Client ID captured on checkout pushed to configured Bitrix custom fields | §"JS snippet + WP integration points" — 30-line snippet design, cookie schema, `woocommerce_checkout_create_order` hook, 6 UF_CRM_WOO_* Deal/Contact custom fields. |
| CRM-10 | `php artisan bitrix:backfill-orders --since={date} --dry-run` replays historical orders idempotently via `BitrixEntityMap` | §"Backfill command" — 50/chunk + 600ms sleep + dry-run default; reuses `PushOrderToBitrixJob` so idempotency is identical to webhook path. |
| CRM-11 | Every CRM push attempt persisted to push log with Filament replay action | §"Push log" — reuse `integration_events` (Phase 1) filtered by `channel='bitrix'`; OR new `crm_push_logs` table. Recommendation: reuse, add lightweight `CrmPushLogResource` as a filtered view. |
| CRM-12 | Failed CRM pushes after N retries land in DLQ + surface as `suggestions('crm_push_failed')` for human review | §"DLQ + suggestion producer" — first real Phase 1 SuggestionApplier producer; `CrmPushRetryApplier` registered in `AppServiceProvider::boot`. |
| CRM-13 | GDPR right-to-erasure command scrubs customer's Bitrix Contact + related Deal PII on request | §"GDPR erasure" — exact PII-bearing field list for Contact + Deal scrub-in-place; audit-log entry per erasure; CLI + Filament dual entry-point. |
</phase_requirements>

## Standard Stack

### Core (already shipped — Phase 1 & 2)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/framework` | ^12.0 | Framework | Already pinned, all phases use [VERIFIED: composer.json] |
| `filament/filament` | ^3.3 | Admin UI | Already pinned [VERIFIED: composer.json] |
| `laravel/horizon` | ^5.45 | Queues | `crm-bitrix` supervisor already configured per FOUND-09 [VERIFIED: 01-05-SUMMARY.md] |
| `automattic/woocommerce` | ^3.1 (3.1.0 installed) | Woo REST | Used for the `wc-api/v3` order/customer reads in backfill; webhook intake doesn't depend on it [VERIFIED: 02-02-SUMMARY.md] |
| `spatie/laravel-permission` | ^6.0 | RBAC | Phase 1 [VERIFIED: composer.json] |
| `bezhansalleh/filament-shield` | ^3.3 | Filament RBAC glue | Phase 1 [VERIFIED: composer.json] |
| `spatie/laravel-activitylog` | ^4.12 | Audit log | Phase 1 [VERIFIED: composer.json] |

### Phase 4 additions

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `bitrix24/b24phpsdk` | **^1.10** (1.10.1 latest 1.x patch as of 2026-04-14) | Bitrix24 REST | Official Bitrix-org SDK with inbound-webhook auth, typed responses, generator-based bulk ops, full CRM fields API. **DO NOT pin ^3.x** — that line requires PHP 8.4+; project is PHP 8.2/8.3. [VERIFIED: Packagist 2026-04-19, https://packagist.org/packages/bitrix24/b24phpsdk] [CITED: STACK.md §2] |

### Fallback

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `bitrix24/b24phpsdk` ^1.10 | `mesilov/bitrix24-php-sdk` ^2.0 (PHP 8.2/8.3) | Veteran community SDK, same webhook-auth surface. **Now marked abandoned on Packagist** — original maintainer points users at the official SDK. Use only if sandbox-validation reveals official SDK blockers. The wrapper interface (`App\Domain\CRM\Services\BitrixClient`) is SDK-agnostic so swap is a constructor-injection change. [VERIFIED: Packagist 2026-04-19, https://packagist.org/packages/mesilov/bitrix24-php-sdk] |

**Installation:**
```bash
composer require bitrix24/b24phpsdk:"^1.10"
```

**Version verification command (run in Plan 01):**
```bash
composer show bitrix24/b24phpsdk
# Expect: versions : * 1.10.1 (or later 1.10.x patch)
# Expect: requires php >=8.2 (no 8.4 hard floor)
```

If `composer require` resolves to a 1.x version below 1.10.0, the `^1.10` constraint forces upgrade. If it resolves to 3.x (because you typed `^1.0` instead of `^1.10`), you'll get a PHP version conflict — the constraint pin is load-bearing.

## Architecture Patterns

### Recommended Domain Structure

```
app/Domain/CRM/
├── Models/
│   ├── BitrixEntityMap.php           # Pitfall 6 mandate — dedup ledger
│   ├── CrmFieldMapping.php           # CRM-06 — Filament-editable field map
│   ├── CrmStatusMapping.php          # CRM-07 — Woo-status → Deal-stage map
│   ├── CrmPipelineSettings.php       # singleton — pipeline_id + landing_stage_id
│   └── BitrixSchemaCache.php         # CRM-02 — 24h cache row per entity-type
├── Services/
│   ├── BitrixClient.php              # SDK-agnostic wrapper around b24phpsdk
│   ├── BitrixFieldSchemaCache.php    # cache.put/get + refresh from crm.*.fields
│   ├── EntityDeduper.php             # find-or-create logic for Contact + Company
│   ├── DealPayloadBuilder.php        # builds dealAdd payload from order + mappings
│   ├── ContactPayloadBuilder.php     # builds contactAdd/update payload
│   └── GdprEraser.php                # PII scrub orchestration
├── Jobs/
│   ├── PushOrderToBitrixJob.php      # ShouldQueue + crm-bitrix queue
│   ├── PushCustomerToBitrixJob.php   # ShouldQueue + crm-bitrix queue
│   ├── BackfillOrdersChunkJob.php    # ShouldQueue + sync-bulk queue (Pitfall 7)
│   └── EraseBitrixContactJob.php     # ShouldQueue + default queue (low frequency)
├── Listeners/
│   ├── HandleOrderReceived.php       # subscribes to Phase 1 OrderReceived event
│   └── HandleCustomerRegistered.php  # subscribes to CustomerRegistered event
├── Console/Commands/
│   ├── BitrixBootstrapCommand.php    # CRM-01 — extends BaseCommand
│   ├── BitrixBackfillOrdersCommand.php  # CRM-10 — extends BaseCommand
│   ├── BitrixSchemaRefreshCommand.php   # optional cron for stale-cache prevention
│   └── GdprEraseBitrixCustomerCommand.php  # CRM-13 — extends BaseCommand
├── Filament/
│   ├── Resources/
│   │   ├── CrmFieldMappingResource.php       # one Resource handles all 3 entity_types
│   │   ├── CrmStatusMappingResource.php
│   │   └── CrmPushLogResource.php            # read-only filtered view of integration_events
│   └── Pages/
│       └── CrmPipelineSettingsPage.php       # custom Filament Page (singleton settings)
├── Appliers/
│   └── CrmPushRetryApplier.php       # registered for kind='crm_push_failed' (Phase 1 seam)
├── Policies/
│   ├── CrmFieldMappingPolicy.php
│   ├── CrmStatusMappingPolicy.php
│   ├── CrmPipelineSettingsPolicy.php
│   ├── CrmPushLogPolicy.php
│   └── BitrixEntityMapPolicy.php
└── Events/
    └── BitrixDealPushed.php          # optional — emit if downstream phases want this
```

### Pattern 1: SDK-agnostic wrapper

**What:** `App\Domain\CRM\Services\BitrixClient` exposes typed methods (`dealAdd`, `dealUpdate`, `dealList`, `contactAdd`, `contactUpdate`, `contactList`, `companyAdd`, `companyUpdate`, `companyList`, `dealUserfieldAdd`, `dealUserfieldList`, `dealFields`, `contactFields`, `companyFields`). The class takes a `Bitrix24\SDK\Core\ApiClient` (or the higher-level service builder result) as a constructor dependency. No call site imports SDK classes directly.

**When to use:** Always. This is the contract Phase 4 builds — every other class consumes `BitrixClient`, never the SDK directly. Makes the `mesilov` fallback a constructor swap, not a refactor.

**Example:**
```php
// Source: official SDK README + Packagist 1.10 constraint
// File: app/Domain/CRM/Services/BitrixClient.php
namespace App\Domain\CRM\Services;

use App\Foundation\Integration\Services\IntegrationLogger;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Bitrix24\SDK\Services\ServiceBuilder;

final class BitrixClient
{
    private ServiceBuilder $sdk;

    public function __construct(
        private readonly IntegrationLogger $logger,
    ) {
        $this->sdk = ServiceBuilderFactory::createServiceBuilderFromWebhook(
            config('services.bitrix.webhook_url')
        );
    }

    public function dealAdd(array $fields): string
    {
        // Wrap every call in IntegrationLogger.log() with channel='bitrix'
        // Returns Bitrix Deal ID as string (Bitrix uses string IDs even for numerics).
        // Concrete SDK call: $this->sdk->getCRMScope()->deal()->add($fields)->getId()
        // Sandbox-validate the exact return-type chain in Plan 02 Task 1.
    }

    public function contactList(array $filter, array $select = ['ID', 'EMAIL']): array
    {
        // Sandbox-validate filter shape — particularly EMAIL filter (Pitfall 6 + legacy
        // plugin uses crm.duplicate.findbycomm; native crm.contact.list filter shape
        // for multi-field EMAIL is non-obvious).
        // Concrete SDK call: $this->sdk->getCRMScope()->contact()->list([], $filter, $select)
    }

    // ... + 12 more methods
}
```

### Pattern 2: Listener + Job split (Phase 1 contract)

**What:** Webhook handlers in `app/Domain/Webhooks/` already dispatch `OrderReceived(webhookReceiptId, deliveryId)` and `CustomerRegistered(webhookReceiptId, deliveryId)` events. Phase 4 ships LISTENERS that subscribe to those events — no new webhook routes, no HMAC middleware changes.

**When to use:** All four event handlers (`order.created`, `order.updated`, `customer.created`, `customer.updated`). The Phase 1 webhook controller routes by topic header but always dispatches the same two event types — the listener inspects the WebhookReceipt's headers/body to determine which sub-handler runs.

**Example:**
```php
// Source: 01-04-SUMMARY.md "Phase 4 (CRM push) can assume" section
// File: app/Domain/CRM/Listeners/HandleOrderReceived.php
namespace App\Domain\CRM\Listeners;

use App\Domain\Webhooks\Events\OrderReceived;
use App\Domain\Webhooks\Models\WebhookReceipt;
use App\Domain\CRM\Jobs\PushOrderToBitrixJob;
use Illuminate\Contracts\Queue\ShouldQueue;

final class HandleOrderReceived implements ShouldQueue
{
    public string $queue = 'crm-bitrix';

    public function handle(OrderReceived $event): void
    {
        $receipt = WebhookReceipt::findOrFail($event->webhookReceiptId);
        $payload = json_decode($receipt->raw_body, associative: true);
        $topic = $receipt->headers['x-wc-webhook-topic'][0] ?? null;

        // dispatch the actual push job; topic determines created vs updated path
        PushOrderToBitrixJob::dispatch($payload, $topic);
    }
}
```

### Pattern 3: BitrixEntityMap-first dedup

**What:** Before ANY `crm.*.add` call, check `BitrixEntityMap` for an existing `(entity_type, woo_id)` row. If present, the mapped `bitrix_id` is the source of truth — call `update`, not `add`. If absent, fall back to SDK-side dedup (`crm.duplicate.findbycomm` or `crm.contact.list` filter), then create-and-record.

**When to use:** Every CRM write. Pitfall 6 is CRITICAL severity — duplicate Deals/Contacts are the #1 reason cited for the legacy plugin's complaints, and silent re-deliveries during the cutover window WILL hit this if the map is missing.

**Example:**
```php
// Source: PITFALLS.md §Pitfall 6 + legacy Crm.php lines 226-323 (find-by-customer-id → phone → email → create cascade)
$mapRow = BitrixEntityMap::where(['entity_type' => 'contact', 'woo_id' => $wooCustomerId])->first();
if ($mapRow) {
    $bitrix->contactUpdate($mapRow->bitrix_id, $contactPayload);
    $mapRow->update(['last_pushed_at' => now(), 'last_payload_hash' => hash('sha256', json_encode($contactPayload))]);
    return $mapRow->bitrix_id;
}

// Not in map — try SDK dedup before create
$existing = $bitrix->contactList(filter: ['EMAIL' => mb_strtolower($email)], select: ['ID']);
if (!empty($existing)) {
    $bitrixId = $existing[0]['ID'];
    BitrixEntityMap::create(['entity_type' => 'contact', 'woo_id' => $wooCustomerId, 'bitrix_id' => $bitrixId, ...]);
    $bitrix->contactUpdate($bitrixId, $contactPayload);
    return $bitrixId;
}

// Truly new — create + record
$bitrixId = $bitrix->contactAdd($contactPayload);
BitrixEntityMap::create([...]);
return $bitrixId;
```

### Anti-Patterns to Avoid

- **Synchronous Bitrix calls in the webhook controller.** Phase 1 already ships the `WooWebhookController::handle()` ≤200ms contract. Any Bitrix call from inside the controller (or any controller) is wrong — it MUST go through a queued listener on `crm-bitrix`. Pitfall 4.
- **Importing SDK classes outside `BitrixClient`.** Couples every consumer to the SDK choice. Use the wrapper.
- **Calling `crm.contact.add` without consulting `BitrixEntityMap` AND `crm.contact.list`/`duplicate.findbycomm`.** This is Pitfall 6 — the failure mode is duplicate Contacts that ops sees on day one of cutover.
- **Using `crm.contact.update` for GDPR erasure with hard-delete intent.** Bitrix has `crm.contact.delete` but D-13 / Claude's Discretion explicitly chose **scrub-in-place** (UK GDPR allows anonymisation as alternative to deletion when business has legitimate retention interest — financial records). Hard delete loses the financial audit trail.
- **Hand-rolling a Guzzle HTTP client for Bitrix.** SDK exists; STACK.md §2 explicitly warns against this. Reach for `BitrixClient` (which wraps the SDK), not Guzzle.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Bitrix REST HTTP transport | Custom Guzzle client | `bitrix24/b24phpsdk` ^1.10 via `BitrixClient` wrapper | SDK handles auth, payload encoding, response parsing, batch operations, generator-based iteration |
| Inbound HMAC verification for Woo webhooks | New middleware | `App\Domain\Webhooks\Http\Middleware\VerifyWooHmacSignature` (Phase 1) | Already shipped, tested, sub-200ms. No new webhook endpoints in Phase 4. |
| Webhook dedup by delivery ID | Custom unique-key check | `webhook_receipts` table UNIQUE(source, delivery_id) (Phase 1) | Already shipped. Phase 1 D-07 contract. |
| Correlation-ID threading | Custom request middleware + queue payload injection | `Context::add('correlation_id', ...)` + `Context::hydrated` callback (Phase 1) | Already global. Every Bitrix `IntegrationLogger::log()` row picks up the same CID as the inbound webhook. |
| Failed-job alerting | Custom exception handler | `ThrottledFailedJobNotifier` listener + `AlertRecipient` model (Phase 1) | Already shipped. Phase 4 just dispatches onto crm-bitrix queue and any failure auto-fires (5-min dedup). |
| Suggestions inbox UI | Custom Filament Resource | `SuggestionResource` (Phase 1) — Phase 4 just registers `CrmPushRetryApplier` for kind='crm_push_failed' | Already shipped. The "Replay" action calls `ApplySuggestionJob` which routes via the registered applier. |
| Audit logging for Filament Resource changes | Custom observers | `spatie/activitylog` `LogsActivity` trait + `Auditor` (Phase 1) | `CrmFieldMapping` / `CrmStatusMapping` / `CrmPipelineSettings` get `LogsActivity`; GDPR erasure uses `Auditor::record('gdpr_erasure', ...)` |
| WooCommerce REST client for backfill | Wrap Guzzle around Woo | `automattic/woocommerce` ^3.1 via `WooClient::get()` (Phase 2) | Already shipped, has retry-once-on-401 + 429 backoff |
| Shadow-mode write gate | New env flag + new table | Reuse `sync_diffs` table with `provider='bitrix'` distinguisher OR add `crm_push_shadow` (planner decides; this research recommends `sync_diffs` extension) | Phase 1 D-08 pattern; conditional prune already in PruneSyncDiffsCommand |
| Deptrac module-boundary enforcement | Custom architecture test | `depfile.yaml` extension + `DeptracCrmLayerTest` (Phase 2 P05 pattern) | Already shipped pattern; just add CRM allow-list |

**Key insight:** Phase 4 is the LIGHTEST infrastructure phase because Phase 1 anticipated every cross-cutting concern and Phase 2 proved the patterns. Plan-time effort goes into business logic (field mappings, status map, dedup, GDPR scrub list, JS snippet design) — not foundation.

## Runtime State Inventory

> Phase 4 is greenfield code (no rename, no migration of existing Bitrix data — the legacy itgalaxy plugin's `_wc_bitrix24_deal_id` post_meta on Woo orders is left in place, but Phase 4 does NOT read or rely on it). However, two pre-existing runtime states matter:

| Category | Items Found | Action Required |
|----------|-------------|-----------------|
| Stored data | **Existing Bitrix Contacts/Deals from itgalaxy plugin runs** — every order placed before Phase 4 cutover already has a Bitrix Deal (created by the legacy plugin) but NO `UF_CRM_WOO_ORDER_ID` on it (legacy plugin used WP `_wc_bitrix24_deal_id` post_meta as the dedup key, not a Bitrix custom field) | **One-off Plan 05 task:** before flipping `CRM_WRITE_ENABLED=true`, run a back-population script that walks Woo orders with `_wc_bitrix24_deal_id` post_meta, calls `BitrixClient::dealUpdate($legacyDealId, ['UF_CRM_WOO_ORDER_ID' => $wooOrderId])`, and writes a `BitrixEntityMap` row. WITHOUT this, the first `order.updated` after cutover will fail to find the existing Deal and create a duplicate. Backfill command can dual-purpose this if it accepts an `--adopt-legacy-deal-ids` flag. |
| Live service config | **Bitrix24 inbound-webhook URL** is held in WordPress `wp_options.itgalaxy_wcbx24_options.webhook` field. Read it once during cutover (operator copy-paste) and put in `.env` as `BITRIX_WEBHOOK_URL`. Once Phase 4 is live, the legacy plugin can be deactivated and the URL is project-owned. | Operator action during Phase 7 cutover runbook. **Plan 04 should ship a Filament settings field** to display the configured URL (read-only, masked) so ops can verify connection before flipping the gate. |
| OS-registered state | None — neither `pm2` nor Windows Task Scheduler nor systemd is involved on the Laravel side. Horizon is supervised by `supervisord` per Phase 1 user-setup; supervisor config is generic (`php artisan horizon`), no Bitrix-specific entries needed. | None |
| Secrets / env vars | `BITRIX_WEBHOOK_URL` (new), `CRM_WRITE_ENABLED` (new, default `false`). No SOPS rotation impact — these are new keys, not renames. | Add to `.env.example` in Plan 01. Document in Phase 7 cutover runbook. |
| Build artifacts | None — no installed packages reference the legacy plugin. `composer require bitrix24/b24phpsdk:"^1.10"` is the sole new artefact (auto-installed in `vendor/`, `composer.lock` updates). | None |

**The canonical question:** *After every file in the repo is updated, what runtime systems still have the old string cached, stored, or registered?*
**Answer:** The Bitrix Deal records themselves carry the legacy dedup key (`_wc_bitrix24_deal_id` Woo post_meta, NOT a Bitrix-side field). Pre-cutover backfill is the migration step.

## Common Pitfalls

### Pitfall 1: SDK 3.x accidentally pulled in (PHP version conflict)

**What goes wrong:** Developer types `composer require bitrix24/b24phpsdk:"^1.0"` (or worse, no constraint at all), Composer resolves to 3.1.0, install fails with `requires php ^8.4` against the project's PHP 8.2/8.3.
**Why it happens:** SDK 3.x dropped on 2026-04 with breaking changes + PHP 8.4 floor; constraint `^1.0` includes 1.x but Composer also considers 3.x within the same `^1` semver wildcard if you typo. The author maintains both branches.
**How to avoid:** Pin exactly `"^1.10"` (or stricter `"~1.10.0"`). Document in Plan 01 README. If it ever resolves to 3.x in CI, the install fails immediately — but locally on a Mac with PHP 8.4 (rare on Windows-Herd dev), you'd silently get 3.x.
**Warning signs:** `composer show bitrix24/b24phpsdk` outputs version `3.x`; SDK class names live in `Bitrix24\SDK\Services\*` but with v3 signatures; runtime errors about missing methods on the service builder.

### Pitfall 2: Bitrix EMAIL filter on `crm.contact.list` returns empty for emails that exist

**What goes wrong:** `crm.contact.list` with naive filter `['EMAIL' => 'foo@bar.com']` returns empty array — but `crm.duplicate.findbycomm` with `type=EMAIL, values=['foo@bar.com']` finds the same contact.
**Why it happens:** Bitrix stores `EMAIL` as a multi-value typed field (`[{VALUE: 'foo@bar.com', VALUE_TYPE: 'WORK'}]`); the simple-string filter on `crm.contact.list` doesn't match. PITFALLS.md §Pitfall 6 calls this out: "filter on the multi-field EMAIL as a nested value type, not a plain string".
**How to avoid:** Two paths — (a) use `crm.duplicate.findbycomm` (the legacy plugin does this — `Crm.php:621-641`); (b) use `crm.contact.list` with `['EMAIL' => 'foo@bar.com']` BUT verify in sandbox whether the SDK normalises this. Recommendation: prefer `crm.duplicate.findbycomm` because it handles email-and-phone in one call AND is what legacy ops are used to behaviourally.
**Warning signs:** `BitrixEntityMap` has multiple rows pointing at different `bitrix_id`s for the same `email_hash`; sales reports duplicate Contacts.

### Pitfall 3: Bitrix returns Deal ID as string, not integer

**What goes wrong:** Code stores `(int) $dealId` in `BitrixEntityMap.bitrix_id`. Some Bitrix instances return IDs > PHP_INT_MAX on 32-bit systems, OR use prefixed string IDs (`"D_1234"`) for certain entity types in some Bitrix configurations.
**Why it happens:** Bitrix's API returns IDs as strings consistently — JSON `"123"` not `123`. PHP's loose typing usually works but specific edge cases (very large IDs, custom-field IDs) bite.
**How to avoid:** `BitrixEntityMap.bitrix_id` is **VARCHAR(64)** not BIGINT. Cast nothing. Compare as strings.
**Warning signs:** `BitrixEntityMap` has `bitrix_id=0` rows after a successful push (cast-to-int truncated); duplicate-detection misses because `'12345' !== 12345` in strict mode.

### Pitfall 4: Field-mapping cache returns stale field IDs after Bitrix admin edit

**What goes wrong:** Admin in Bitrix UI deletes custom field `UF_CRM_DEAL_REGION` and recreates it — same name, new internal ID. Cached schema in Laravel still references the old ID. Every `crm.deal.add` fails with "unknown field" or silently drops the value.
**Why it happens:** PITFALLS.md §Pitfall 11. Field schemas mutate in Bitrix and the cache TTL might outlive the change.
**How to avoid:** (a) Cache by `FIELD_NAME` not internal ID (the SDK should already do this — verify in sandbox); (b) "Refresh from Bitrix" button on the field-mapping Filament page — manual cache bust; (c) on every push attempt, validate mapped fields exist in cached schema, and on missing-field error, force-refresh once and retry. CRM-02 acceptance criterion explicitly requires this.
**Warning signs:** Spike of `crm.deal.add` errors with "Unknown field"; UTM/GA fields appearing as `null` in Bitrix despite being captured client-side.

### Pitfall 5: Legacy Bitrix Deals adopted into `BitrixEntityMap` without `UF_CRM_WOO_ORDER_ID` populated

**What goes wrong:** First `order.updated` after cutover for a pre-cutover order → handler queries `BitrixEntityMap`, finds no row, queries `crm.deal.list` with `UF_CRM_WOO_ORDER_ID` filter, finds no Deal (because the legacy plugin never wrote that field), creates a duplicate.
**Why it happens:** Phase 4 introduces `UF_CRM_WOO_ORDER_ID` as the dedup key, but legacy Deals predate it.
**How to avoid:** Pre-cutover backfill — see Runtime State Inventory. Plan 05 task: walk all Woo orders with `_wc_bitrix24_deal_id` post_meta, call `dealUpdate(legacyDealId, ['UF_CRM_WOO_ORDER_ID' => $wooOrderId])`, write `BitrixEntityMap` row. The `bitrix:backfill-orders` command should accept `--adopt-legacy-deal-ids` flag to do this in one pass.
**Warning signs:** Bitrix Deal count grows by ~1 per cutover-day order; sales sees duplicate Deals for the same order number.

### Pitfall 6: 4xx error retried needlessly (Bitrix expense)

**What goes wrong:** Retry policy treats every error as transient — 400 "invalid field" gets retried 3 times, each retry burns Bitrix's 2 req/sec budget AND the validation error never goes away.
**Why it happens:** Naive retry middleware doesn't inspect HTTP status codes. D-11 specifically calls out 4xx fail-fast — this is a guard against it.
**How to avoid:** `BitrixClient` catches SDK exceptions, inspects HTTP status code (the SDK should expose this — sandbox-validate), and rethrows as either `BitrixTransientException` (5xx, network, 429) or `BitrixPermanentException` (4xx). The job's `failed()` hook treats permanent as immediate-DLQ; transient retries with backoff. D-11 explicit.
**Warning signs:** `integration_events.attempts=3` rows for the same `endpoint=crm.deal.add` with `response_status=400`; alert volume spikes during a Bitrix schema change.

### Pitfall 7: GDPR erasure also wipes the financial audit trail

**What goes wrong:** Implementer reads "scrub PII" too broadly, clears `OPPORTUNITY` (deal value), `STAGE_ID` (won/lost outcome), `BEGINDATE` (when the order happened). UK financial-records retention requires the *non-PII* parts of historical sales to survive customer erasure requests.
**Why it happens:** UK GDPR doesn't override financial-record retention obligations. The bias should be "retain non-PII business data, scrub identifying data."
**How to avoid:** Explicit allow-list (NOT block-list) in `GdprEraser`. See §GDPR Erasure for the field list. PII-bearing Deal fields are limited (mostly the Deal's TITLE if it embeds the customer name — replace with `"Order #{order_number}"`); the rest live on the linked Contact.
**Warning signs:** Post-erasure, the linked Contact has scrubbed name + email but the Deal's COMMENTS field still contains the customer's billing address copied from the order note (legacy plugin does this — `OrderToBitrix24.php:647 generateNote()`).

## Code Examples

### SDK installation + initialisation (Plan 01)

```php
// Source: official b24phpsdk README — https://github.com/bitrix24/b24phpsdk
// File: app/Domain/CRM/Services/BitrixClient.php (constructor)
use Bitrix24\SDK\Services\ServiceBuilderFactory;

$serviceBuilder = ServiceBuilderFactory::createServiceBuilderFromWebhook(
    config('services.bitrix.webhook_url')
);
// $serviceBuilder is a \Bitrix24\SDK\Services\ServiceBuilder
// Access points:
//   $serviceBuilder->getCRMScope()->deal()->add($fields)
//   $serviceBuilder->getCRMScope()->contact()->list($order, $filter, $select)
//   $serviceBuilder->getCRMScope()->company()->add($fields)
//   $serviceBuilder->getCRMScope()->userfield()->...
// Concrete signatures: see src/Services/CRM/Deal/Service/Deal.php in vendor/ once installed.
```

### bitrix:bootstrap — create UF_CRM_WOO_ORDER_ID custom field (CRM-01)

```php
// Source: legacy CrmFields.php $breakFields shows UF fields are managed via crm.deal.userfield.* methods
// File: app/Domain/CRM/Console/Commands/BitrixBootstrapCommand.php
namespace App\Domain\CRM\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\CRM\Services\BitrixClient;

final class BitrixBootstrapCommand extends BaseCommand
{
    protected $signature = 'bitrix:bootstrap';
    protected $description = 'Create Bitrix custom fields required by the Phase 4 sync. Idempotent; safe to run on every deploy.';

    public function __construct(private readonly BitrixClient $bitrix) { parent::__construct(); }

    protected function perform(): int
    {
        $this->info('Checking UF_CRM_WOO_ORDER_ID on Deal entity...');

        $existing = $this->bitrix->dealUserfieldList(
            filter: ['FIELD_NAME' => 'UF_CRM_WOO_ORDER_ID']
        );

        if (!empty($existing)) {
            $this->info('UF_CRM_WOO_ORDER_ID already exists, skipping.');
            return self::SUCCESS;
        }

        $this->bitrix->dealUserfieldAdd([
            'FIELD_NAME'    => 'UF_CRM_WOO_ORDER_ID',
            'USER_TYPE_ID'  => 'integer',          // [VERIFIED: Bitrix REST docs — integer is a built-in user-type]
            'XML_ID'        => 'WOO_ORDER_ID',
            'EDIT_FORM_LABEL' => ['en' => 'WooCommerce Order ID'],
            'LIST_COLUMN_LABEL' => ['en' => 'Woo Order #'],
            'SHOW_FILTER'   => 'I',                // show in filter
            'SHOW_IN_LIST'  => 'Y',                // show in list view
            'IS_SEARCHABLE' => 'Y',                // allows global search
        ]);

        // Repeat for the 6 UTM fields on Deal AND the 6 UTM fields on Contact (D-03/D-04)
        // Plus UF_CRM_WOO_CUSTOMER_ID on Contact for cross-reference.

        $this->info('Bootstrap complete. Idempotent — safe to re-run.');
        return self::SUCCESS;
    }
}
```

### Find-or-create Contact dedup cascade (CRM-04)

```php
// Source: legacy Crm.php lines 226-326 — exact decision tree the legacy plugin uses
// Modernised: replace WP user-meta `_bitrix24_contact_id` with BitrixEntityMap
// File: app/Domain/CRM/Services/EntityDeduper.php

public function findOrCreateContact(int $wooCustomerId, array $contactPayload): string
{
    // 1. Map-first lookup (fastest, definitive)
    $mapRow = BitrixEntityMap::firstWhere(['entity_type' => 'contact', 'woo_id' => $wooCustomerId]);
    if ($mapRow) {
        $this->bitrix->contactUpdate($mapRow->bitrix_id, $contactPayload);
        return $mapRow->bitrix_id;
    }

    // 2. SDK-side dedup by phone (legacy uses crm.duplicate.findbycomm)
    if (!empty($contactPayload['PHONE'])) {
        $found = $this->bitrix->duplicateFindByComm('PHONE', 'contact', [$contactPayload['PHONE']]);
        if ($found) {
            return $this->adopt($wooCustomerId, $found, $contactPayload);
        }
    }

    // 3. SDK-side dedup by email
    if (!empty($contactPayload['EMAIL'])) {
        $found = $this->bitrix->duplicateFindByComm('EMAIL', 'contact', [mb_strtolower($contactPayload['EMAIL'])]);
        if ($found) {
            return $this->adopt($wooCustomerId, $found, $contactPayload);
        }
    }

    // 4. Truly new — create + record
    $bitrixId = $this->bitrix->contactAdd($contactPayload);
    BitrixEntityMap::create([
        'entity_type'       => 'contact',
        'woo_id'            => $wooCustomerId,
        'bitrix_id'         => $bitrixId,
        'last_pushed_at'    => now(),
        'last_payload_hash' => hash('sha256', json_encode($contactPayload)),
    ]);
    return $bitrixId;
}

private function adopt(int $wooCustomerId, string $bitrixContactId, array $contactPayload): string
{
    BitrixEntityMap::create([
        'entity_type'       => 'contact',
        'woo_id'            => $wooCustomerId,
        'bitrix_id'         => $bitrixContactId,
        'last_pushed_at'    => now(),
        'last_payload_hash' => hash('sha256', json_encode($contactPayload)),
    ]);
    $this->bitrix->contactUpdate($bitrixContactId, $contactPayload);
    return $bitrixContactId;
}
```

### Phase 1 SuggestionApplier registration (Plan 04)

```php
// Source: 01-04-SUMMARY.md Applier Registry Matrix — kind='crm_push_failed' is the first real producer
// File: app/Providers/AppServiceProvider.php (boot method)

use App\Domain\Suggestions\Services\SuggestionApplierResolver;
use App\Domain\CRM\Appliers\CrmPushRetryApplier;

$this->callAfterResolving(SuggestionApplierResolver::class, function ($resolver) {
    $resolver->register('crm_push_failed', CrmPushRetryApplier::class);
});
```

### JS snippet for UTM capture (Plan 04 deliverable for WP team)

```javascript
// Source: D-01..D-04 + WordPress hook reference + legacy plugin AnalyticsHelper
// Deploy as a mu-plugin OR as a wp_footer-hooked snippet on meetingstore.co.uk
// File: ms-utm-capture.js (~30 lines as CONTEXT specifies)

(function () {
    var COOKIE_NAME = 'ms_utm_first_touch';
    var TTL_DAYS = 30;
    var KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    function readGaClientId() {
        // GA cookie format: GA1.2.<client_id>.<timestamp>
        var match = document.cookie.match(/(?:^|;\s*)_ga=([^;]+)/);
        if (!match) return '';
        var parts = match[1].split('.');
        return parts.length >= 4 ? parts[2] + '.' + parts[3] : '';
    }

    function getCookie(name) {
        var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]+)'));
        return m ? decodeURIComponent(m[1]) : null;
    }

    function setCookie(name, value, days) {
        var d = new Date(); d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
    }

    // Build the touch object from current URL (if UTMs present) — first-touch only
    var url = new URLSearchParams(window.location.search);
    var hasNewUtm = KEYS.some(function (k) { return url.get(k); });

    var existing = getCookie(COOKIE_NAME);
    var touch = existing ? JSON.parse(existing) : null;

    if (hasNewUtm || !touch) {
        touch = {};
        KEYS.forEach(function (k) { touch[k] = url.get(k) || ''; });
        touch._ga = readGaClientId();
        setCookie(COOKIE_NAME, JSON.stringify(touch), TTL_DAYS);
    }

    // On checkout page, inject hidden inputs (works whether or not jQuery is present)
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form.woocommerce-checkout, form.checkout');
        if (!form || !touch) return;
        Object.keys(touch).forEach(function (k) {
            if (form.querySelector('input[name="ms_utm_' + k + '"]')) return; // idempotent
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ms_utm_' + k;
            input.value = touch[k] || '';
            form.appendChild(input);
        });
    });
})();
```

### WP-side hook to persist injected fields to order meta (Plan 04 deliverable)

```php
// Source: D-01 + WP woocommerce_checkout_create_order action hook standard pattern
// Deploy as mu-plugin alongside the JS snippet
// File: wp-content/mu-plugins/ms-utm-persist.php

add_action('woocommerce_checkout_create_order', function ($order, $data) {
    $keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', '_ga'];
    foreach ($keys as $k) {
        $field = 'ms_utm_' . $k;
        if (isset($_POST[$field]) && $_POST[$field] !== '') {
            $order->update_meta_data('_ms_' . $field, sanitize_text_field($_POST[$field]));
        }
    }
}, 10, 2);
```

The Laravel side then reads `meta_data[]` from the `order.created` webhook payload, looking for keys `_ms_ms_utm_utm_source` etc. (or simpler key naming if the WP team prefers — coordinate during deploy).

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `mesilov/bitrix24-php-sdk` (community SDK, 2013-2024) | `bitrix24/b24phpsdk` (official, 2024+) | Mid-2024 | Community SDK now marked **abandoned on Packagist**; original maintainer points users at the official SDK. Both still functional on PHP 8.2/8.3 line. [VERIFIED: Packagist 2026-04-19] |
| WP `_wc_bitrix24_deal_id` post_meta as dedup key (legacy plugin) | `BitrixEntityMap` Laravel-side ledger + `UF_CRM_WOO_ORDER_ID` Bitrix-side custom field | Phase 4 (this rebuild) | Decouples dedup from WP — survives WP uninstall + makes backfill idempotent. Pitfall 6 mandate. |
| Synchronous `wp_remote_post` Bitrix calls (legacy `Crm::sendApiRequest` blocks the WP request) | Async queued listeners on `crm-bitrix` Horizon supervisor | Phase 4 | Webhook controller returns 200 within 200ms; Bitrix slowness no longer triggers Woo retry storms (Pitfall 4). |
| `crm.contact.list` filter shape varies by Bitrix version (multi-value EMAIL gotcha) | `crm.duplicate.findbycomm` (the legacy plugin uses this) | N/A — current best practice | Avoids the Pitfall 6 Phase-2 trap. Confirm with sandbox in Plan 02 Task 1. |

**Deprecated/outdated:**
- `mesilov/bitrix24-php-sdk` ^2.0 — abandoned label on Packagist; use only as a documented fallback if `bitrix24/b24phpsdk` ^1.10 has blockers
- WP `wp_options.itgalaxy_wcbx24_options` — read once during cutover for the webhook URL, then discarded
- Roistat / Yandex / Facebook pixel cookie capture (legacy plugin supports all four) — Phase 4 explicitly drops these (D-03 + Russian-market constraint from PROJECT.md)

## Assumptions Log

> The planner and discuss-phase use this section to identify decisions that need user confirmation before execution.

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The official `bitrix24/b24phpsdk` 1.10.x exposes `getCRMScope()->deal()->add()` returning an object with `->getId()` | §Pattern 1 + §Code Examples | LOW — Plan 01 sandbox-validation test catches this in 30 minutes. Fallback to `mesilov` is documented. |
| A2 | `crm.duplicate.findbycomm` is the right primitive for email/phone dedup (vs `crm.contact.list`) | §Pitfall 2 + §Code Examples | LOW — both work; legacy uses `findbycomm`; sandbox can confirm SDK exposes either. Choice doesn't affect the wrapper interface. |
| A3 | Bitrix returns Deal/Contact/Company IDs as strings consistently | §Pitfall 3 | LOW — VARCHAR(64) on `BitrixEntityMap.bitrix_id` is the safe default regardless. |
| A4 | The 6 UTM Deal custom fields are `string` user-type (not `integer` or `enumeration`) | §"Custom-field bootstrap" | LOW — string is the right default for free-text UTM values; only `UF_CRM_WOO_ORDER_ID` is `integer`. |
| A5 | WordPress mu-plugin is an acceptable deploy artefact for the JS snippet + meta-persist hook | §"JS snippet" | MEDIUM — coordinate with the WP team before Plan 04 ships. Alternative: drop the snippet into the active theme's `wp_footer` action. |
| A6 | The `_ga` cookie always exists on meetingstore.co.uk before checkout (because GA tracking is installed) | §"JS snippet" | MEDIUM — graceful degradation: if `_ga` is empty, the GA Client ID field is empty in Bitrix. No error. |
| A7 | Bitrix's 2 req/sec rate limit applies to inbound-webhook auth specifically (not just OAuth apps) | §"Bitrix rate-limiting" | MEDIUM — STACK.md asserts this; sandbox-validate by hammering the dev tenant in Plan 02. |
| A8 | The official SDK does NOT include built-in rate limiting (issue #76 still open) | §"Bitrix rate-limiting" | LOW — confirmed via WebFetch on 2026-04-19; we ship our own throttle as a Guzzle middleware on the SDK's HTTP client. |
| A9 | The legacy `_wc_bitrix24_deal_id` post_meta still exists on pre-cutover Woo orders | §Pitfall 5 + §"Runtime State Inventory" | MEDIUM — verify with ops via a Woo SQL query during Phase 7 cutover prep: `SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='_wc_bitrix24_deal_id'`. If the meta is missing for older orders, those orders simply can't be backfilled to existing Bitrix Deals — they'd create duplicates. Document the recovery path (one-off manual mapping in Filament). |
| A10 | The legacy itgalaxy plugin's "delay via WP cron" mode is NOT enabled on production (immediate-send mode is the default) | §"Webhook handlers" | LOW — even if it is, Phase 4 doesn't depend on legacy behaviour during the parallel-run window. Worst case: Bitrix Deals appear from both systems for a few minutes per order; `BitrixEntityMap` adoption catches this. |
| A11 | All Phase 4 custom field names use the `UF_CRM_WOO_*` prefix; this prefix is currently unused in the customer's Bitrix tenant | §"Custom-field bootstrap" | LOW — if it IS used, the bootstrap command's `dealUserfieldList` filter will detect existing fields and skip safely (idempotent). Manual tenant audit is wise but not blocking. |

**If this table is empty:** All claims in this research were verified or cited — no user confirmation needed.

This table is **not empty** — A5, A6, A7, A9 are MEDIUM-risk assumptions worth surfacing to the user via discuss-phase or ops contact before Plan 04 ships.

## Open Questions

1. **Does the operator already have a Bitrix dev/sandbox tenant for Plan 02 Task 1 sandbox validation?**
   - What we know: Production tenant exists (legacy plugin runs against it). PROJECT.md says one-way Woo→Bitrix.
   - What's unclear: Whether a sandbox tenant exists or whether the production tenant is the only environment.
   - Recommendation: Plan 02 Task 1 should accept either. If only production exists, gate the smoke-test command behind `BITRIX_SMOKE_TEST_ALLOWED=true` env (default false) so it can't be run accidentally; document the expected side effect (creates one Deal + one Contact in prod, manually delete after).

2. **Which Bitrix pipeline ID does ops want as the default landing pipeline?**
   - What we know: D-05 says single admin-picked pipeline.
   - What's unclear: The pipeline ID itself.
   - Recommendation: Phase 7 cutover runbook task — operator opens Filament `CrmPipelineSettingsPage`, picks from the dropdown (populated via `crm.dealcategory.list`). No coding needed.

3. **Does the WP team (the people who own meetingstore.co.uk) prefer the JS snippet as a mu-plugin, an active-theme footer hook, or a Google Tag Manager tag?**
   - What we know: The snippet is ~30 lines. It's the on-ramp for ALL UTM data.
   - What's unclear: Their preferred deploy mechanism.
   - Recommendation: Plan 04 ships the snippet + the WP-side meta-persist hook as code in `docs/wordpress-snippets/` in this repo, with a README explaining all three deploy options. Coordinate during Phase 7 cutover.

4. **Are there custom Woo order statuses on meetingstore.co.uk beyond the standard 7?**
   - What we know: D-06 says seed the standard 7 + admin can add more in the Filament UI.
   - What's unclear: How many custom statuses exist (likely zero — standard B2B AV stores rarely customise this).
   - Recommendation: Plan 04 seed covers 7. Plan 04 also exposes Filament UI to add more rows on demand — no migration needed.

5. **Should `crm_push_logs` be a separate table or a filtered view of `integration_events`?**
   - What we know: CRM-11 requires per-attempt request/response/latency persistence. `integration_events` (Phase 1) already captures this with 90-day retention.
   - What's unclear: Whether ops wants a longer retention for CRM specifically.
   - Recommendation: Reuse `integration_events` filtered by `channel='bitrix'`. Add `CrmPushLogResource` as a thin Filament Resource that scopes the query. Saves a table + leverages Phase 1 prune. If ops later wants 365-day CRM retention, override the prune for `channel='bitrix'` rows.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | All Phase 4 code | ✓ | 8.4.x (Herd) — local; 8.2/8.3 prod target | — |
| Composer | SDK install | ✓ (assumed; project already has composer.lock) | — | — |
| MySQL 8 | Migrations | ✓ | 8.x | — |
| Redis 7 | Horizon (crm-bitrix queue) | ✓ | 7.x — already running per Phase 1 | — |
| `bitrix24/b24phpsdk` ^1.10 | All CRM code | ✗ — not yet installed | — | `mesilov/bitrix24-php-sdk` ^2.0 (now abandoned label, but functional) |
| Bitrix24 tenant + inbound webhook URL | Sandbox + prod | Partially — production tenant exists per legacy plugin; sandbox unknown | — | See Open Question #1 |
| WordPress + WooCommerce on meetingstore.co.uk | Webhook source + UTM JS snippet deploy | ✓ — assumed live | — | — |
| WC webhook secret already configured (`WC_WEBHOOK_SECRET` env) | HMAC verification | ✓ — Phase 1 D-07 | — | — |

**Missing dependencies with no fallback:**
- Bitrix sandbox tenant access — if absent, sandbox-validation has to happen against production with the `BITRIX_SMOKE_TEST_ALLOWED=true` gate. Noted as Open Question #1.

**Missing dependencies with fallback:**
- `bitrix24/b24phpsdk` ^1.10 — install via Plan 01. Fallback `mesilov` documented in STACK.md.

## Project Constraints (from CLAUDE.md)

CLAUDE.md exists at the project root and contains comprehensive constraints. Phase 4-relevant directives:

**Coding & quality (Project section):**
- AI is ONLY allowed for formatting and method statement structuring — never for inventing scope, equipment, or design. **Phase 4 implication:** No AI-driven field-mapping suggestions; the field map is admin-curated.
- Architecture: Laravel service-based, thin controllers, shared data services, safe migrations, queue-compatible. **Phase 4 implication:** All Bitrix calls go through `BitrixClient` service; controllers (none new in Phase 4) stay thin; migrations follow timestamp pattern `2026_04_20_XXXXXX`.

**Stack (already-shipped, do not change):**
- Laravel ^12.0, PHP ^8.2, Filament 3, Horizon 5.45, Redis 7, MySQL 8 — all locked. Phase 4 conforms.
- `automattic/woocommerce` ^3.1 — used in Plan 05 backfill. Reuse, don't add new HTTP client.
- `bezhansalleh/filament-shield` ^3.3 — RBAC pattern. Phase 4 ships 5 new policies; shield:generate audit required (will regenerate stub-stripped policies — Phase 1/2 documented mitigation pattern).
- Pest 3 + PHPUnit 11 — testing framework. Phase 4 tests follow this.
- Laravel Pint ^1.24 — PSR-12 formatter. Phase 4 code formatted at task end.

**Conventions (from CLAUDE.md "Naming Patterns"):**
- Controllers: PascalCase + `Controller` suffix — N/A in Phase 4 (no new controllers).
- Services: PascalCase + `Service` suffix — `BitrixClient` is a Service-pattern class but doesn't carry the suffix (precedent: `WooClient`, `SupplierClient` from Phase 2). Acceptable.
- Models: PascalCase singular — `BitrixEntityMap`, `CrmFieldMapping`, etc.
- Jobs: PascalCase + `Job` suffix, verb-first — `PushOrderToBitrixJob`, `PushCustomerToBitrixJob`, `BackfillOrdersChunkJob`.
- Form Requests: PascalCase + `Request` suffix — N/A.
- Policies: PascalCase + `Policy` suffix matching model — `BitrixEntityMapPolicy`, `CrmFieldMappingPolicy`, etc.
- Migration timestamps: phase-stride pattern. Phase 3 ended at `2026_04_19_*`; Phase 4 uses `2026_04_20_XXXXXX`.

**Logging (CLAUDE.md):**
- Always prefix log messages with class name. **Phase 4:** `'BitrixClient: deal push succeeded'` etc.
- Include structured context array with relevant IDs.

**Error handling:**
- Custom exceptions for domain errors. **Phase 4:** `App\Exceptions\BitrixTransientException`, `App\Exceptions\BitrixPermanentException` (or under `App\Domain\CRM\Exceptions\`).

**GSD Workflow Enforcement (CLAUDE.md):**
- Use `/gsd-execute-phase` for planned phase work. Phase 4 IS planned phase work — agents follow the GSD flow, not direct edits.

## Webhook Payload Shape

> Required by focus_areas item 3 + the JS snippet design — Phase 4 parsing code reads specific fields.

WooCommerce emits webhooks as POST requests with `Content-Type: application/json` and these load-bearing headers:

| Header | Value | Used for |
|--------|-------|----------|
| `X-WC-Webhook-Topic` | `order.created`, `order.updated`, `customer.created`, `customer.updated` | Router — `HandleOrderReceived` vs `HandleCustomerRegistered` |
| `X-WC-Webhook-Delivery-ID` | UUID-shape; reused across Woo retries | Phase 1 `webhook_receipts.delivery_id` (UNIQUE on (source, delivery_id)) |
| `X-WC-Webhook-Signature` | base64(hmac_sha256(raw_body, WC_WEBHOOK_SECRET)) | Phase 1 `VerifyWooHmacSignature` middleware |
| `X-WC-Webhook-Source` | Store base URL | Sanity check |
| `X-WC-Webhook-Event` | `created` / `updated` / `deleted` | Redundant with topic — use topic |
| `X-WC-Webhook-Resource` | `order` / `customer` / `product` | Redundant with topic — use topic |

### Order payload (order.created / order.updated) — load-bearing fields for Phase 4

```json
{
  "id": 12345,
  "number": "12345",
  "status": "processing",
  "currency": "GBP",
  "date_created": "2026-04-19T14:23:00",
  "date_modified": "2026-04-19T14:23:00",
  "total": "1199.00",
  "customer_id": 678,
  "customer_note": "Please deliver between 9-12",
  "billing": {
    "first_name": "Jane", "last_name": "Smith", "company": "ACME Ltd",
    "address_1": "1 High St", "address_2": "",
    "city": "London", "state": "Greater London", "postcode": "EC1A 1AA",
    "country": "GB",
    "email": "jane@acme.example", "phone": "+447700900123"
  },
  "shipping": { "/* same shape minus email + phone */": "" },
  "line_items": [
    { "id": 1, "name": "8-Port Switch", "product_id": 501, "variation_id": 0,
      "quantity": 2, "subtotal": "998.00", "total": "998.00", "sku": "SW-8P-01",
      "meta_data": [] }
  ],
  "shipping_lines": [],
  "fee_lines": [],
  "coupon_lines": [],
  "refunds": [],
  "meta_data": [
    { "id": 9001, "key": "_ms_utm_source",   "value": "google" },
    { "id": 9002, "key": "_ms_utm_medium",   "value": "cpc" },
    { "id": 9003, "key": "_ms_utm_campaign", "value": "spring-sale" },
    { "id": 9004, "key": "_ms_utm_term",     "value": "av-installer" },
    { "id": 9005, "key": "_ms_utm_content",  "value": "ad-variant-a" },
    { "id": 9006, "key": "_ms_utm_ga_cid",   "value": "1234567890.1618425600" }
  ]
}
```

**Parsing pattern (Phase 4 `HandleOrderReceived` listener):**

```php
$payload = json_decode($receipt->raw_body, associative: true);
$utmMeta = collect($payload['meta_data'] ?? [])
    ->keyBy('key')
    ->map(fn($m) => $m['value']);

$utmFields = [
    'UF_CRM_WOO_UTM_SOURCE'   => $utmMeta['_ms_utm_source']   ?? '',
    'UF_CRM_WOO_UTM_MEDIUM'   => $utmMeta['_ms_utm_medium']   ?? '',
    'UF_CRM_WOO_UTM_CAMPAIGN' => $utmMeta['_ms_utm_campaign'] ?? '',
    'UF_CRM_WOO_UTM_TERM'     => $utmMeta['_ms_utm_term']     ?? '',
    'UF_CRM_WOO_UTM_CONTENT'  => $utmMeta['_ms_utm_content']  ?? '',
    'UF_CRM_WOO_GA_CID'       => $utmMeta['_ms_utm_ga_cid']   ?? '',
];
```

### Customer payload (customer.created / customer.updated)

Shape: similar to the `billing` block above, plus `id`, `date_created`, `date_modified`, `email`, `first_name`, `last_name`, `role`, `username`, `billing: {...}`, `shipping: {...}`, `meta_data: [...]`. The UTM keys live in the customer's meta_data only if the customer registers via the checkout flow (per D-04); for admin-created customers the keys are absent.

**The meta_data `key` naming is coordinated in Plan 04 JS snippet.** Recommendation: `_ms_utm_source` / `_ms_utm_medium` / etc. (single underscore prefix per WP convention for internal meta). Snippet + PHP hook + Laravel parser MUST agree on the prefix — document the contract in the Plan 04 README.

## Custom-Field Bootstrap (CRM-01)

> Plan 01 deliverable — MUST ship before any push code.

### Fields to create via `crm.deal.userfield.add`

| FIELD_NAME | USER_TYPE_ID | Purpose | SHOW_IN_LIST | IS_SEARCHABLE |
|------------|--------------|---------|--------------|---------------|
| `UF_CRM_WOO_ORDER_ID` | `integer` | **Pitfall 6 dedup key — CRITICAL** | Y | Y |
| `UF_CRM_WOO_UTM_SOURCE` | `string` | D-03 | N | N |
| `UF_CRM_WOO_UTM_MEDIUM` | `string` | D-03 | N | N |
| `UF_CRM_WOO_UTM_CAMPAIGN` | `string` | D-03 | N | N |
| `UF_CRM_WOO_UTM_TERM` | `string` | D-03 | N | N |
| `UF_CRM_WOO_UTM_CONTENT` | `string` | D-03 | N | N |
| `UF_CRM_WOO_GA_CID` | `string` | D-03 | N | N |

### Fields to create via `crm.contact.userfield.add`

| FIELD_NAME | USER_TYPE_ID | Purpose |
|------------|--------------|---------|
| `UF_CRM_WOO_CUSTOMER_ID` | `integer` | Cross-reference with Laravel `BitrixEntityMap.woo_id` — helpful for manual sales lookups |
| `UF_CRM_WOO_UTM_SOURCE` | `string` | D-04 — Contact-level UTM capture |
| `UF_CRM_WOO_UTM_MEDIUM` | `string` | D-04 |
| `UF_CRM_WOO_UTM_CAMPAIGN` | `string` | D-04 |
| `UF_CRM_WOO_UTM_TERM` | `string` | D-04 |
| `UF_CRM_WOO_UTM_CONTENT` | `string` | D-04 |
| `UF_CRM_WOO_GA_CID` | `string` | D-04 |

### Example `crm.deal.userfield.add` payload

```php
// Source: Bitrix REST docs — crm.deal.userfield.add
// [CITED: https://apidocs.bitrix24.com/api-reference/crm/deals/userfield/crm-deal-userfield-add.html]
// [ASSUMED] USER_TYPE_ID='integer' is the right type for an Order ID; sandbox-validate whether 'string'
//           is preferable for future-proofing. 'integer' is the default choice.
[
    'FIELD_NAME'    => 'UF_CRM_WOO_ORDER_ID',
    'USER_TYPE_ID'  => 'integer',
    'XML_ID'        => 'WOO_ORDER_ID',
    'EDIT_FORM_LABEL'   => ['en' => 'WooCommerce Order ID', 'ru' => 'ID заказа WooCommerce'],
    'LIST_COLUMN_LABEL' => ['en' => 'Woo Order #'],
    'LIST_FILTER_LABEL' => ['en' => 'Woo Order #'],
    'SHOW_FILTER'   => 'I',
    'SHOW_IN_LIST'  => 'Y',
    'IS_SEARCHABLE' => 'Y',
    'MANDATORY'     => 'N',
    'MULTIPLE'      => 'N',
]
```

### Idempotency

Bootstrap MUST be safe to run on every deploy. Pattern:

```php
$existing = $bitrix->dealUserfieldList(filter: ['FIELD_NAME' => 'UF_CRM_WOO_ORDER_ID']);
if (empty($existing)) {
    $bitrix->dealUserfieldAdd([...]);
    $this->info("Created UF_CRM_WOO_ORDER_ID");
} else {
    $this->info("UF_CRM_WOO_ORDER_ID already exists (id={$existing[0]['ID']}); skipping");
}
```

### Fail-hard-on-auth-broken

If `dealUserfieldList` throws (auth failure, network), the command exits with non-zero status and does NOT silently fall through to "try creating it anyway" — that's how duplicate fields get created. CLAUDE.md-style error handling: throw custom exception, log with class prefix, exit 1.

## Status → Stage Map — Realistic Seed (D-06, CRM-07)

> The actual Bitrix `STAGE_ID` values depend on the admin-picked pipeline. The seeder ships **labels**; the bootstrap command or Filament UI resolves labels against `crm.dealcategory.stage.list` for the picked pipeline.

### Seed shape

```php
// File: database/seeders/CrmStatusMappingSeeder.php
// Seeded once during initial Phase 4 deploy; admin edits via Filament UI after.

return [
    // Woo status    => Bitrix stage label (resolved against the admin-picked pipeline)
    'pending'        => 'NEW',
    'processing'     => 'PREPAYMENT_INVOICE',
    'on-hold'        => 'EXECUTING',
    'completed'      => 'WON',
    'cancelled'      => 'LOSE',
    'refunded'       => 'LOSE',
    'failed'         => 'LOSE',
];
```

**Bitrix default pipeline stage labels (standard tenant):**
- `NEW` — Deal just arrived
- `PREPARATION` — Preparing docs
- `PREPAYMENT_INVOICE` — Invoice sent
- `EXECUTING` — Performing the work
- `FINAL_INVOICE` — Final invoice
- `WON` — Deal closed successfully (final semantic-green stage)
- `LOSE` — Deal lost (final semantic-red stage)

**Note:** Custom pipelines have custom stage IDs like `C5:NEW` or `C12:EXECUTING` — the legacy plugin parses these as `CATEGORY_ID:STAGE_ID` via `explode(':')` or `explode('||||')` (`Crm.php:146-157`). The bootstrap should handle both formats. Phase 4 seed uses **bare stage labels** (no `C5:` prefix); the seeder's UX during deploy resolves them against the picked pipeline.

### Resolution flow on first deploy

1. Admin runs `php artisan bitrix:bootstrap` (creates custom fields).
2. Admin opens `/admin/crm-pipeline-settings`, picks pipeline from `crm.dealcategory.list` dropdown, saves → `crm_pipeline_settings` singleton row has `bitrix_pipeline_id`.
3. Admin opens `/admin/crm-status-mappings`, sees seeded rows with placeholder `STAGE_ID`s; for each, picks actual stage from `crm.dealcategory.stage.list` dropdown filtered by `bitrix_pipeline_id`, saves.
4. `CRM_WRITE_ENABLED=true` is the final flip.

**Validation on save:** Each `CrmStatusMapping` row's `bitrix_stage_id` must exist in the cached `crm.dealcategory.stage.list` for the configured pipeline. If stale → error banner "Refresh Bitrix schema".

## Bitrix Rate-Limiting + Retry Strategy

### Ceiling

Bitrix24 cloud tenants enforce ~2 requests per second per inbound-webhook credential. [CITED: STACK.md §2 + https://apidocs.bitrix24.com/limits.html]. Self-hosted tenants may have different limits but none of our customers use self-hosted.

### SDK behaviour (confirmed 2026-04-19)

The official `bitrix24/b24phpsdk` **does NOT include built-in rate limiting** — see [Issue #76](https://github.com/bitrix24/b24phpsdk/issues/76) (open, November 2024 → April 2026+). On 429 responses the SDK currently returns/throws the error without auto-delay. `Retry-After` header honour is NOT automatic.

### Our implementation — Guzzle middleware in `BitrixClient`

The SDK takes a Guzzle `ClientInterface` on construction (or via `HttpClientFactory`). Inject a client with two middlewares:

1. **Token-bucket throttle** — max 2 requests / second; burst capacity 5. Use `spatie/laravel-rate-limited-job-middleware`-style pattern OR a simple `usleep(500000)` between consecutive calls (same-process throttling is fine because our Horizon crm-bitrix-supervisor runs 1-2 workers — per Phase 1 Plan 05 config).
2. **Retry on 429 + Retry-After honour** — if response 429, read `Retry-After` header (seconds), sleep, retry ONCE from inside the middleware. Further retries escalate to the job-level retry policy.

### Job-level retry (D-11 lock)

| Attempt | Delay | Notes |
|---------|-------|-------|
| 1 | immediate | initial push |
| 2 | 30s | first retry — transient network/5xx/429 |
| 3 | 5m | second retry — sustained outage |
| 4 | 30m | third retry — last chance |
| (exhausted) | — | write `crm_push_failed` suggestion, fire `AlertRecipient` alert, park in DLQ |

4xx errors (400, 401 auth, 403, 404 missing field) **fail-fast** — job goes straight to DLQ after attempt 1 with no retries. The `BitrixClient` distinguishes via:

```php
// pseudo — actual exception classes depend on SDK sandbox validation (A1 above)
try {
    return $this->sdk->getCRMScope()->deal()->add($fields)->getId();
} catch (\Bitrix24\SDK\Core\Exceptions\TransportException $e) {
    // 5xx / network — RETRY
    throw new BitrixTransientException($e->getMessage(), previous: $e);
} catch (\Bitrix24\SDK\Core\Exceptions\BaseException $e) {
    // 4xx / validation — DO NOT RETRY
    throw new BitrixPermanentException($e->getMessage(), previous: $e);
}
```

Job's `failed()` hook checks the exception type via Laravel's `$event->exception` and decides DLQ vs continue-retry. **Sandbox-validation flag:** the exact exception classes the SDK throws need confirmation in Plan 02 Task 1.

## GDPR Erasure — Exact Bitrix Field List to Scrub (CRM-13)

> Default per Claude's Discretion: **scrub-in-place**, NOT hard delete. UK GDPR permits anonymisation as an alternative to deletion when the business has legitimate retention interest (here: financial/sales records).

### Contact fields — PII-bearing (MUST scrub)

Replace values with `REDACTED-{sha256(email)[0:12]}` tokens (deterministic — lets ops cross-reference after erasure without storing the email):

| Field | Type | Scrub value |
|-------|------|-------------|
| `NAME` | string | `REDACTED-{hash}` |
| `LAST_NAME` | string | `REDACTED-{hash}` |
| `SECOND_NAME` | string | `REDACTED-{hash}` |
| `PHONE` | multi-value | `[]` (empty array removes all phones) |
| `EMAIL` | multi-value | `[]` |
| `WEB` | multi-value | `[]` |
| `IM` | multi-value | `[]` (instant messenger handles) |
| `ADDRESS` | string | `REDACTED` |
| `ADDRESS_2` | string | `REDACTED` |
| `ADDRESS_CITY` | string | `REDACTED` |
| `ADDRESS_POSTAL_CODE` | string | `REDACTED` |
| `ADDRESS_REGION` | string | `REDACTED` |
| `ADDRESS_PROVINCE` | string | `REDACTED` |
| `ADDRESS_COUNTRY` | string | — (country alone is not PII — leave for analytics) |
| `POST` | string (job title) | `REDACTED` |
| `BIRTHDATE` | date | `null` |
| `COMMENTS` | text | `REDACTED` |
| `SOURCE_DESCRIPTION` | text | `REDACTED` |
| `PHOTO` | file | `null` |

### Deal fields — PII-bearing (MUST scrub)

Most PII is on the linked Contact; the Deal carries limited PII. Scrub:

| Field | Type | Scrub value |
|-------|------|-------------|
| `TITLE` | string | `Order #{UF_CRM_WOO_ORDER_ID}` — replace any name-embedded title with the order number |
| `COMMENTS` | text | `REDACTED — order notes removed per GDPR erasure` |
| `SOURCE_DESCRIPTION` | text | `REDACTED` |
| `ADDITIONAL_INFO` | text | `REDACTED` |

### Deal fields — MUST PRESERVE (business record, non-PII)

| Field | Why preserved |
|-------|---------------|
| `ID` | — |
| `UF_CRM_WOO_ORDER_ID` | Links to the Woo order — business audit |
| `OPPORTUNITY` (deal value) | Financial record — HMRC retention |
| `CURRENCY_ID` | Financial record |
| `STAGE_ID` | Sales outcome (won/lost) — business audit |
| `CATEGORY_ID` (pipeline) | — |
| `BEGINDATE` / `CLOSEDATE` | Financial record timing |
| `UF_CRM_WOO_UTM_*` | UTM parameters — NOT PII per GDPR guidance (attribution labels, not identity) |
| `UF_CRM_WOO_GA_CID` | GA Client ID — borderline; scrub if ops wants full paranoia. **Recommendation:** preserve (it's a non-cookie-linked number after the original `_ga` cookie is cleared). Document the choice in ops runbook. |
| `COMPANY_ID` / `CONTACT_ID` | References; the linked Contact itself is scrubbed (above). Do not nullify — preserve referential integrity. |

### Company — DO NOT scrub

Company is a legal entity, not personal data. Preserved entirely. [CITED: UK GDPR Article 4(1) — personal data is "information relating to an identified or identifiable natural person"; registered legal entities are out of scope.]

### Audit-log entry

Every erasure writes ONE audit row via `Auditor::record('gdpr_erasure', [...])`:

```php
Auditor::record('gdpr_erasure', [
    'actor_id'      => auth()->id(),
    'actor_email'   => auth()->user()?->email,
    'subject_email' => $email,                    // PLAINTEXT — allowed in audit log for regulator compliance
    'contact_id'    => $bitrixContactId,
    'deal_ids'      => $dealIds,                  // array of preserved Deal IDs
    'entity_map_rows_updated' => $mapRowsAffected,
    'correlation_id' => Context::get('correlation_id'),
    'retention_note' => 'Financial records preserved per HMRC retention; PII scrubbed per UK GDPR Article 17(3)(b).',
]);
```

**Retention override:** `audit_log` rows where `log_name='gdpr_erasure'` must survive the default 365-day retention. Either bump retention via `PruneActivityLogCommand --exclude-log-name=gdpr_erasure` flag, OR spin off a dedicated `gdpr_erasure_log` table. **Recommendation:** dedicated table — keeps retention policy explicit. Single-column migration + Eloquent model. Prune never touches it.

### Dual-entry-point implementation

- **CLI:** `php artisan gdpr:erase-bitrix-customer --email=jane@acme.example` — prompts for confirmation, requires typing `ERASE` literal; logs before dispatching `EraseBitrixContactJob`.
- **Filament:** Admin-only action on the CRM Push Log row OR on a dedicated `/admin/gdpr-erasure` page where admin pastes email. `->authorize(hasRole('admin'))` + confirmation modal with 10-character confirmation phrase.

## Shadow-Mode Gate — Implementation Recommendation

> Reuse `sync_diffs` OR add `crm_push_shadow`? **Recommendation: reuse `sync_diffs` with a `provider` column extension.**

### Option A (RECOMMENDED) — Reuse `sync_diffs` with `provider` discriminator

**Schema change** (additive migration in Plan 01):

```php
Schema::table('sync_diffs', function (Blueprint $table) {
    $table->string('provider', 20)->default('woo')->after('id')->index();
    // existing Phase 1 sync_diffs rows get backfilled to 'woo' via default
});
```

**Write path in `BitrixClient`:**

```php
if (!config('services.bitrix.write_enabled')) {
    SyncDiff::create([
        'provider'       => 'bitrix',
        'channel'        => 'bitrix',
        'method'         => 'POST',
        'endpoint'       => $method,           // e.g. 'crm.deal.add'
        'woo_id'         => $wooEntityId,
        'payload'        => $fields,
        'correlation_id' => $correlationId,
    ]);
    IntegrationLogger::log([...]);  // dry-run attempt still logs
    return;  // no real call
}
```

**Retention:** `PruneSyncDiffsCommand` (Phase 1 D-08) already skips pruning when `WOO_WRITE_ENABLED=false`. Update the command to ALSO check `CRM_WRITE_ENABLED` — if EITHER is false, skip the prune for the relevant `provider` rows. Phase 7 cutover flips both, prune resumes for both.

**Pros:** One table + one prune logic + one Filament surface (SyncDiff Resource extended with provider filter). Parity-check dashboard in Phase 7 can compare Laravel's expected `bitrix` diffs against real Bitrix state.
**Cons:** Payload column stores both Woo and Bitrix shapes — JSON column is agnostic so this is a naming concern only.

### Option B — New `crm_push_shadow` table

Separate table, separate model, separate prune. Duplicates infrastructure. Only worth it if the payload shape divergence makes reuse awkward; it doesn't.

**Decision:** Option A. Plan 01 ships the additive migration. Planner can override if they see a reason the research missed.

## `BitrixEntityMap` Schema Design

> Pitfall 6 mandate. This is the dedup ledger — Phase 4's most important table.

```php
// File: database/migrations/2026_04_20_XXXXXX_create_bitrix_entity_map_table.php
Schema::create('bitrix_entity_map', function (Blueprint $table) {
    $table->id();

    $table->enum('entity_type', ['deal', 'contact', 'company']);
    $table->unsignedBigInteger('woo_id');              // Woo order_id OR customer_id OR 0 for company sentinel
    $table->string('bitrix_id', 64);                   // Pitfall 3 — NOT bigInt

    // Dedup-metadata
    $table->string('email_hash', 64)->nullable();      // sha256(mb_strtolower(email)) — indexed for reverse lookup during GDPR erasure
    $table->string('last_payload_hash', 64)->nullable(); // sha256(json_encode(pushed_payload)) — drift detection
    $table->string('last_status_snapshot', 30)->nullable(); // D-09 — for order.updated status-change detection
    $table->timestamp('last_pushed_at')->useCurrent();

    // Observability
    $table->string('last_correlation_id', 36)->nullable()->index();
    $table->string('created_via', 30)->default('push'); // 'push' | 'backfill' | 'adopted_legacy' | 'manual'

    $table->timestamps();

    // Indexes
    $table->unique(['entity_type', 'woo_id'], 'bitrix_entity_map_type_woo_id_unique');  // Pitfall 6 guarantee
    $table->index(['entity_type', 'bitrix_id']);        // reverse lookup — who's woo_id for bitrix deal 12345?
    $table->index('last_pushed_at');                    // TTL pruning if ever needed
    $table->index('email_hash');                        // GDPR erasure lookup
});
```

### Why use `woo_id = 0` sentinel for companies?

Companies match by VAT / name+postcode, not by Woo ID (Woo's concept of "company" is the billing_company string on an order — not a first-class entity). For Phase 4 companies: use `woo_id = 0` (sentinel) + `TITLE + POSTAL_CODE` stored in `last_payload_hash` as the dedup key. OR add a separate `bitrix_company_dedup` table. **Recommendation:** Use `(entity_type='company', woo_id=0)` sentinel rows with a `dedup_key` string column if the billing_company varies across orders. Planner decides final shape.

### Read/write access pattern

- Every push reads the map FIRST (O(1) lookup via unique index).
- On successful push: update `last_pushed_at` + `last_payload_hash` + `last_status_snapshot`.
- On GDPR erasure: lookup by `email_hash` → scrub linked Bitrix Contact + Deal.

## Deptrac Layer Additions (depfile.yaml)

> Current `depfile.yaml` has `CRM: [Foundation]` allow-list placeholder. Phase 4 extends.

```yaml
# File: depfile.yaml (Phase 4 additions)
ruleset:
  # ... existing rules ...

  # Phase 4 (Plans 04-01..04-05): CRM layer cross-domain allow-list.
  #   - Foundation: DomainEvent, Auditor, IntegrationLogger, BaseCommand, Context
  #   - Webhooks: subscribes to OrderReceived + CustomerRegistered events (Phase 1 Plan 04)
  #   - Suggestions: first real producer of the SuggestionApplier seam — CrmPushRetryApplier
  #   - Sync: reuses sync_diffs + SyncDiff model for CRM_WRITE_ENABLED=false shadow mode
  #   - Alerting: AlertRecipient lookup for DLQ alert distribution
  # Explicitly NOT allowed: Pricing, Products, Competitor, Feeds
  CRM: [Foundation, Webhooks, Suggestions, Sync, Alerting]
```

### Architecture test

```php
// File: tests/Architecture/DeptracCrmLayerTest.php
// Pattern from Phase 2 P05 + Phase 3 P05
test('CRM layer does not violate Deptrac ruleset', function () {
    $process = Process::fromShellCommandline('vendor/bin/deptrac analyse --no-progress');
    $process->run();
    expect($process->getExitCode())->toBe(0);
});

test('CRM layer cannot import from Pricing, Products, Competitor, or Feeds', function () {
    $violatorPath = 'app/Domain/CRM/Services/__DeptracViolator.php';
    file_put_contents($violatorPath, '<?php
namespace App\Domain\CRM\Services;
use App\Domain\Pricing\Services\PriceCalculator;
class __DeptracViolator {}');

    try {
        $process = Process::fromShellCommandline('vendor/bin/deptrac analyse --no-progress');
        $process->run();
        expect($process->getExitCode())->not->toBe(0);
    } finally {
        @unlink($violatorPath);
    }
});
```

## Plan Breakdown Proposal

> Given 13 requirements + legacy parity mandate, recommend 5 plans mirroring Phase 2/3 shape.

| Plan | Scope | Requirements | Key deliverables |
|------|-------|--------------|------------------|
| **04-01-data-model-bootstrap** | Data schema + SDK install + `bitrix:bootstrap` | CRM-01 | `bitrix24/b24phpsdk` ^1.10 composer require + `BitrixEntityMap` + `CrmFieldMapping` + `CrmStatusMapping` + `CrmPipelineSettings` + `BitrixSchemaCache` migrations + Eloquent models + 5 policies + factories + `BitrixClient` skeleton (method signatures only) + `BitrixBootstrapCommand` + `bitrix:smoke-test` sandbox-validation command (A1-A3 collapse). Sync_diffs `provider` column additive migration. |
| **04-02-bitrix-client-wrapper** | Real SDK wrapping + dedup + schema cache | — (enables CRM-02, CRM-04, CRM-05) | `BitrixClient` full implementation (all 15+ methods) + rate-limit middleware + 4xx/5xx exception classification + `BitrixFieldSchemaCache` + `EntityDeduper` + `BitrixSchemaRefreshCommand`. Tests cover dedup cascade, cache TTL, retry classification. |
| **04-03-webhook-listeners-push-jobs** | The actual sync | CRM-03, CRM-04, CRM-05, CRM-08, CRM-09, CRM-12 | `HandleOrderReceived` + `HandleCustomerRegistered` listeners + `PushOrderToBitrixJob` + `PushCustomerToBitrixJob` + `DealPayloadBuilder` + `ContactPayloadBuilder` + `CrmPushRetryApplier` registered in AppServiceProvider + D-09 status-change detection + D-10 race-safe re-queue + D-11 retry policy + D-12 DLQ suggestion producer. UTM meta-data parsing from webhook payload. |
| **04-04-filament-ui** | Admin-facing config + push log | CRM-02, CRM-06, CRM-07, CRM-11 | `CrmFieldMappingResource` + `CrmStatusMappingResource` + `CrmPushLogResource` (read-only, filters `integration_events` by channel='bitrix') + `CrmPipelineSettingsPage` custom Filament Page + Replay action on `SuggestionResource` for kind='crm_push_failed' + "Refresh from Bitrix" button. Shield audit post-regenerate. Seed legacy field mappings from `CrmFields.php`. |
| **04-05-backfill-gdpr-guardrails** | Cutover tooling + compliance + architecture | CRM-10, CRM-13 + Deptrac | `BitrixBackfillOrdersCommand` (+ `--adopt-legacy-deal-ids` flag per Pitfall 5) + `BackfillOrdersChunkJob` + `bitrix_backfill_runs` table + `GdprEraseBitrixCustomerCommand` + `EraseBitrixContactJob` + `GdprEraser` service + `gdpr_erasure_log` table + `DeptracCrmLayerTest` + `04-VERIFICATION.md` ship verdict. JS-snippet + WP-hook artefacts in `docs/wordpress-snippets/`. |

### Inter-plan dependencies

- 04-01 → 04-02 (client wrapper needs migrations + skeleton)
- 04-02 → 04-03 (push jobs need the real client)
- 04-03 → 04-04 (Filament needs pushed data to display; SuggestionResource needs CrmPushRetryApplier registered)
- 04-04 → 04-05 (backfill needs the full pipeline + UI to triage issues)

### Rough complexity

- 04-01: M (data + bootstrap — ~25 files, similar to Phase 2 Plan 01)
- 04-02: L (SDK wrapping is the sandbox-unknown; 15 methods + 3 service classes + tests — ~30 files)
- 04-03: L (listeners + jobs + payload builders + applier + race-safe retry logic — ~25 files)
- 04-04: M (4 Resources + 1 Page + Replay action — ~20 files)
- 04-05: M (backfill + GDPR + Deptrac + verification — ~20 files)

### Shield regeneration audits

Every plan that creates a new Policy MUST run `shield:generate` then audit ALL policies for `{{ Placeholder }}` leaks and `hasRole('admin')` regressions on SuggestionPolicy / AlertRecipientPolicy / existing CRM policies. Phase 1/2 pattern documented.


## Validation Architecture

> **SKIPPED** — `.planning/config.json` has `"workflow": { "nyquist_validation": false }`. Validation Architecture section omitted per researcher protocol.

## Security Domain

> Required because `security_enforcement` is not explicitly disabled in config.json — treat as enabled.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Bitrix inbound-webhook URL is the auth secret — store in `.env`, never log it (`IntegrationLogger::SENSITIVE_HEADERS` already redacts; the URL itself appears in `endpoint` so prefer `endpoint='crm.deal.add'` not `endpoint='https://b24-xxx.bitrix24.com/rest/USER_ID/SECRET/crm.deal.add'`). Worth verifying the SDK's URL composition lets us log the method name only. |
| V3 Session Management | no | Phase 4 has no user sessions on its own surface. Filament Resources are gated by Shield + the existing admin guard. |
| V4 Access Control | yes | All 5 Filament Resources + 1 settings Page admin-only via Shield + hardcoded `hasRole('admin')` (Phase 1 SuggestionPolicy pattern — Pitfall K belt-and-braces). `->authorize()` mandatory on every Filament Action (Phase 1 Warning 9). |
| V5 Input Validation | yes | Field-mapping save validates that mapped Bitrix field names exist in cached schema (CRM-02). Status-map save validates STAGE_ID against `crm.status.list` for the picked pipeline. UTM cookie values from the JS snippet are sanitised on the WP side (`sanitize_text_field`) — Phase 4 trusts that and re-validates length only (defensive). |
| V6 Cryptography | partial | HMAC verification on inbound Woo webhooks already shipped (Phase 1 — `hash_equals()` constant-time). Phase 4 doesn't add new crypto. GDPR scrub uses SHA-256 of email as the redaction token (one-way — no reversal needed). |
| V7 Error Handling & Logging | yes | `IntegrationLogger` redacts `Authorization`, `X-Bitrix-Signature`, `X-Api-Key`, etc. Phase 4 must NOT log full webhook URL (contains the user-id + secret) — log method name only. Bitrix error responses sometimes include input payload echoes; redact email/phone before persisting if those appear in error message strings (defensive — sandbox-validate). |
| V8 Data Protection | yes | GDPR right-to-erasure (CRM-13). PII fields list defined below. Audit-log entry per erasure with actor + correlation_id (Phase 1 `Auditor::record('gdpr_erasure', ...)`). Erasure entries themselves retained beyond the audit_log 365-day retention — bump retention for `log_name='gdpr_erasure'` rows OR spin off `gdpr_erasure_log` table. Specifics in §"GDPR Erasure" below. |
| V9 Communications | yes | All Bitrix HTTPS (the inbound webhook URL is HTTPS). Verify SSL cert in production (per [SDK Issue #87](https://github.com/bitrix24/b24phpsdk/issues/87) — known intermittent self-signed cert issue on some Bitrix self-hosted instances; cloud Bitrix is fine). |
| V10 Malicious Code | no | Phase 4 ships no eval/dynamic-class-loading. SDK is pinned to a known maintained version. Composer.lock controls integrity. |
| V11 Business Logic | yes | Backfill command's `--live` flag is the kill-switch (D-04 pattern from Phase 2); `CRM_WRITE_ENABLED=false` is the upstream guard. Both must be true to write to Bitrix. |
| V12 Files & Resources | no | No file uploads in Phase 4. |
| V13 API Security | yes | Bitrix outbound calls are rate-limited (≤2 req/sec — manual throttle in `BitrixClient` since SDK has no built-in limiter per [Issue #76](https://github.com/bitrix24/b24phpsdk/issues/76)). |
| V14 Configuration | yes | Bitrix webhook URL in `.env` only; never committed; never logged. `.env.example` carries placeholder + comment. |

### Known Threat Patterns for Bitrix CRM Sync

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Webhook URL leak via log files (`endpoint` field exposes USER_ID/SECRET) | Information Disclosure | Log method name only (`crm.deal.add`), not full URL. Verify SDK exposes method-name access cleanly. |
| Replay of captured webhook (Bitrix → no, but Woo→ yes) | Spoofing | Phase 1 `webhook_receipts` UNIQUE(source, delivery_id) catches replays at the Laravel boundary; HMAC catches forged ones. |
| Malicious admin uploads field-mapping that points at sensitive Bitrix fields | Tampering | Field-mapping Resource is admin-only (Shield + hasRole). Audit-log every change via `LogsActivity`. No mitigation against intentional admin abuse — out of scope. |
| Backfill command runs with `--live` accidentally | Tampering | Dry-run default (D-04 Phase 2 pattern). `--live` opt-in. `CRM_WRITE_ENABLED` env gate is a second layer. |
| GDPR erasure with wrong email scrubs the wrong customer | Repudiation | Confirmation prompt on CLI; Filament action requires explicit confirm modal; audit-log entry shows actor + email + correlation_id; scrub is reversible only via `BitrixEntityMap` lookup → which still has the woo_id mapping (the woo_id itself is a number, not PII). |
| Bitrix sends back response with PII echo that gets logged | Information Disclosure | Defensive: `IntegrationLogger` should hash email/phone in response bodies if length suggests PII. Sandbox-validate what Bitrix actually returns on error. |

## Sources

### Primary (HIGH confidence)
- [`bitrix24/b24phpsdk` on Packagist](https://packagist.org/packages/bitrix24/b24phpsdk) — version 1.10.1 latest 1.x (verified 2026-04-19); 3.1.0 latest 3.x requires PHP 8.4+
- [`bitrix24/b24phpsdk` GitHub README](https://github.com/bitrix24/b24phpsdk) — installation pattern, ServiceBuilderFactory, getCRMScope() entry
- [`mesilov/bitrix24-php-sdk` on Packagist](https://packagist.org/packages/mesilov/bitrix24-php-sdk) — 2.0 (2024-08-28) marked abandoned; documented fallback
- [Bitrix24 SDK installation docs](https://apidocs.bitrix24.com/sdk/b24phpsdk/index.html) — webhook initialisation example
- [SDK Issue #76 — rate limiter](https://github.com/bitrix24/b24phpsdk/issues/76) — confirms no built-in rate limiting; we ship our own throttle
- [SDK Issue #87 — SSL cert](https://github.com/bitrix24/b24phpsdk/issues/87) — known intermittent self-signed cert issue on self-hosted Bitrix
- [WooCommerce REST API webhook docs](https://github.com/woocommerce/woocommerce-rest-api-docs/blob/trunk/source/includes/wp-api-v1/_webhooks.md) — webhook payload + headers + HMAC format
- [WooCommerce orders REST schema](https://github.com/woocommerce/woocommerce-rest-api-docs/blob/trunk/source/includes/wp-api-v3/_orders.md) — order JSON shape including `meta_data: [{id, key, value}]`
- Legacy plugin source (extracted): `C:/Users/sonny.tanda/Documents/1 - Laravel Projects/woo project/bitrix24-extracted/woocommerce-bitrix24-integration/` — definitive parity reference (read in this research session: readme.txt, OrderToBitrix24.php, CustomerToBitrix24.php, Crm.php, CrmFields.php, itglx-wcbx24-update-deal-stage.php)
- Phase 1 SUMMARY files (read in this session: 01-03, 01-04, 01-05) — confirms WooWebhookController / OrderReceived / CustomerRegistered / SuggestionApplier / AlertRecipient / ThrottledFailedJobNotifier all shipped

### Secondary (MEDIUM confidence)
- [Loading and Using B24PhpSDK — apidocs.bitrix24.com](https://apidocs.bitrix24.com/sdk/b24phpsdk/index.html) — corroborates README initialisation example
- [Save UTM Parameters to WooCommerce Orders — seresa.io](https://seresa.io/blog/woocommerce-tracking/save-utm-parameters-to-woocommerce-orders-first-and-last-touch) — first-touch attribution pattern reference
- [WooCommerce Track and Capture UTM Parameters — appfromlab](https://www.appfromlab.com/posts/how-to-capture-utm-parameters-in-woocommerce/) — JS snippet + checkout hidden field pattern reference

### Tertiary (LOW confidence — flagged for sandbox validation)
- Concrete SDK method signatures for `getCRMScope()->deal()->add($fields)->getId()` — verified via search-result snippet but not run; **Plan 02 Task 1 sandbox-validation deliverable**
- `crm.contact.list` filter shape with `EMAIL` field — Bitrix-version-dependent; legacy uses `crm.duplicate.findbycomm` workaround; **sandbox-validate which path the SDK exposes cleanly**
- 4xx vs 5xx exception class hierarchy in the SDK — not documented in README; **sandbox-validate before D-11 retry policy lock**

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — every package version verified against Packagist 2026-04-19; Phase 1/2 contracts confirmed via direct file read
- Architecture (domain layout, listener pattern, dedup): HIGH — Phase 1 contract is explicit; legacy plugin source confirms business logic
- Pitfalls: HIGH — Pitfalls 4/6/7 have direct mitigation in the design; Pitfalls 11 (schema cache), 16 (Redis persistence — already mitigated), 13 (sync events — already mitigated) are addressed
- SDK API surface: MEDIUM — entry pattern verified, per-method signatures need Plan 02 Task 1 sandbox validation
- UTM JS snippet design: HIGH — pattern is well-established; 30-line target achievable
- GDPR erasure design: HIGH — UK GDPR's anonymisation-as-alternative-to-deletion is well-documented; field list derived from legacy plugin's PII fields

**Research date:** 2026-04-19
**Valid until:** 2026-05-19 (30 days — stable ecosystem; SDK 1.10.x shouldn't churn before then. Re-verify if SDK 1.11 ships and changes the service builder API.)
