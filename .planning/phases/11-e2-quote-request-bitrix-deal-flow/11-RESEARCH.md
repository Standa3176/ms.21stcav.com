# Phase 11: E2 Quote Request → Bitrix Deal Flow — Research

**Researched:** 2026-05-01
**Domain:** B2B quote-flow on top of v1 CRM seam (Phase 4) + Phase 9 trade-pricing decorator
**Confidence:** HIGH (data model + Bitrix surfaces + retry pattern are all v1-precedented)

## Summary

Phase 11 ships a Quote/QuoteLine ULID model with line-price snapshot immutability that survives subsequent PricingRule edits, a Filament `QuoteResource` (Sales nav group), a `spatie/laravel-pdf` v2.8 + DOMPDF driver PDF (UK B2B ex-VAT itemised + 20% VAT block + inc-VAT total), a `QuoteApproved` domain event, and a `PushQuoteToBitrixDealJob` (`crm-bitrix` queue) that idempotently creates a Bitrix Deal of `TYPE_ID=QUOTE` with line items via `crm.deal.productrows.set` (gated by `QUOTE_BITRIX_PUSH_ENABLED` env flag — default false → writes to `sync_diffs` with `provider='bitrix-quote'`). Idempotency is matched on `UF_CRM_WOO_QUOTE_ID` (new Bitrix custom field added by extending `bitrix:bootstrap`).

CONTEXT.md locked 13 D-XX decisions across 4 user-discussed gray areas: dual-mode customer model (nullable user_id FK + denormalised contact fields), single-button Approve workflow (draft → sent atomic transition with separation-of-duties), manual sales acceptance (no public token route in v1), search-and-add line UX with ex-VAT itemised PDF. Plan breakdown is a 5-plan linear chain (11-01..11-05) per CONTEXT.md.

**Primary recommendation:** Reuse Phase 4's PushOrderToBitrixJob shape verbatim for `PushQuoteToBitrixDealJob` (same retry/failed/Suggestions/Alert pattern, same shadow-mode gate idiom). Reuse Phase 9's TradeRuleResolver as the snapshot price source via a new `resolveForQuote(sku, customer_group_id): int` thin method that delegates to the unchanged `resolve()`. Use `crm.deal.productrows.set` for line items (Bitrix-native; SDK already supports it) — DO NOT use Bitrix's separate Quote (Estimate) entity.

## User Constraints (from CONTEXT.md)

### Locked Decisions

| # | Decision | Source |
|---|----------|--------|
| D-01 | **Dual-mode customer model** — nullable `Quote.user_id` FK to `users.id` (cascadeOnDelete=null) + denormalised `customer_email`/`customer_name`/`billing_address` JSON. Anonymous-lead path supports Phase 13 WhatsApp + Phase 14 chatbot + cold sales calls. | CONTEXT.md §Decisions |
| D-02 | **`customer_group_id` snapshotted at creation**, not joined. If `user_id` set, copy `users.customer_group_id`. If null, sales picks manually (default NULL = retail). | CONTEXT.md |
| D-03 | **Filament form Toggle "Existing customer?"** — reveals user picker (search by `users.email`) auto-filling name + customer_group_id; toggle off = free-text fields + manual customer_group_id picker. | CONTEXT.md |
| D-04 | **Single-button Approve transitions `draft → sent` atomically** in one transaction: status flip + `QuoteApproved` event + `PushQuoteToBitrixDealJob` dispatch + email PDF + audit_log row. **Sales CANNOT approve own quotes** (separation-of-duties; admin + pricing_manager only). | CONTEXT.md |
| D-05 | **Enum reserves `pending_approval`/`approved` for v1.x** but v1.0 transitions are `draft → sent → accepted \| rejected \| expired`. Admin-only `sent → draft` revert allowed within 5 min of send. | CONTEXT.md |
| D-06 | **`accepted`/`rejected` are MANUAL sales updates.** `expired` flips automatically. No `withdrawn` status; no quote_history relation. | CONTEXT.md |
| D-07 | **Manual sales acceptance** — sales clicks "Mark as accepted" or "Mark as rejected" Filament row action. Writes audit_log + fires optional `QuoteAccepted`/`QuoteRejected` domain events. | CONTEXT.md |
| D-08 | **Reject capture** — Select reason (`price_too_high`, `wrong_specifications`, `competitor_won`, `delayed_decision`, `other`) + optional note → `Quote.rejection_metadata` JSON. | CONTEXT.md |
| D-09 | **Public accept-link, public chatbot self-service, Bitrix stage mirror-back ALL DEFERRED.** Zero public-facing URLs in v1. | CONTEXT.md |
| D-10 | **Filament line-add: search-and-add Select::searchable() (≥3 chars debounced) + manual SKU text-input fallback.** Bulk paste deferred. Auto-snapshots `unit_price_pence_at_quote` via `TradeRuleResolver::resolveForQuote(sku, customer_group_id)`. | CONTEXT.md |
| D-11 | **PDF VAT layout: ex-VAT itemised + VAT line + inc-VAT total** (UK B2B convention). VAT computed at PDF render time via `PriceCalculator::stripVat()` inverse. Pence integer-only throughout. | CONTEXT.md |
| D-12 | **Quantity is integer** (`quantity_int`, validation `>=1` + `<=9999`). No fractional/weight quantities in v1. | CONTEXT.md |
| D-13 | **Line snapshot immutability** — `unit_price_pence_at_quote` + `line_total_pence_at_quote` + `product_snapshot` set ONCE on creation. Editing draft quote line `quantity_int` recalculates `line_total = unit * qty` but freezes unit_price + product_snapshot. After `status=sent`, line edits are forbidden (UI hides edit; observer throws on `saving`). | CONTEXT.md |

### Claude's Discretion

| Area | CONTEXT.md guidance |
|------|---------------------|
| `spatie/laravel-pdf` driver | DOMPDF (per ROADMAP success criterion 3). Verify in Plan 11-01. |
| PDF storage | Generated on-demand for download + email attachment; NOT persisted by default. Future ops ask → `storage/app/quotes/` + `quotes:prune-pdfs` (90-day retention pattern). |
| PDF branded header | `public/images/meetingstore-logo.png` (operator drops file; placeholder 200×60 px). Hard-coded company details in `config/quote.php`. Footer: page numbers + `Quote #{ulid_short_8} • Generated {YYYY-MM-DD HH:mm}`. |
| Bitrix `TYPE_ID=QUOTE` | Plan 11-04 ships pre-flight check via `crm.dealtype.list` warning if absent. Operator runbook documents creation in Bitrix admin. |
| Bitrix line-item modelling | RESEARCH must compare 3 approaches; lean toward `crm.deal.productrows.set`. |
| Quote ULID format | 26-char ULID PK; UI shows truncated 8-char (`ulid_short_8`). |
| `QuoteApproved` payload | `Quote.id`, `Quote.user_id`, `Quote.customer_email`, `Quote.customer_group_id`, `Quote.status_before`, `Quote.status_after`, `correlation_id`. |
| Listener queue | `crm-bitrix` (Phase 1 FOUND-09; Phase 4 precedent). Email mail dispatch on `default`. |
| Retry policy | Phase 4 D-11 — 3 attempts, 30s/5m/30m backoff, 4xx fail-fast → `quote_push_failed` Suggestion (new kind, parallels `crm_push_failed`) for ops Replay. |
| `expires_at` default | `created_at + 14 days`; configurable via `config('quote.default_expiry_days', 14)`. |
| Customer expiry email | `config('quote.email_on_expiry', false)` — DEFAULT FALSE. |
| Filament navigation group | "Sales" (new top-level group). |
| Deptrac `Quotes` layer | Allow: `Foundation, Products, Pricing, TradePricing, Suggestions, CRM, Webhooks`. Deny: `Agents, Competitor, ProductAutoCreate`. |
| `PushQuoteToBitrix` listener placement | `app/Domain/CRM/Listeners/PushQuoteToBitrix.php` — listener lives in CRM domain so BitrixClient + BitrixEntityMap stay inside CRM. **Quotes domain emits the event; CRM consumes.** Deptrac one-way arrow: CRM → Quotes ALLOWED; Quotes → CRM DENIED. |
| `Quote.customer_group_id` FK | NULLABLE FK with `onDelete('restrict')` + denormalised string `customer_group_name_at_quote`. |
| `UF_CRM_WOO_QUOTE_ID` Bitrix custom field | Plan 11-01 extends `bitrix:bootstrap` (Phase 4) idempotently. |
| `Quote.status` timestamps | `sent_at`, `accepted_at`, `rejected_at`, `expired_at` nullable timestamps alongside enum. |
| PDF customer signature block | `config('quote.pdf_signature_block', false)` — default OFF. |
| **Plan breakdown** | **5 plans (linear waves)** — see CONTEXT.md §Decisions for the 5-plan map. |

### Deferred Ideas (OUT OF SCOPE)

- Public accept-link in PDF (token-signed URL → public route → status flip)
- Bitrix Deal stage mirror-back (inbound webhook → Quote.status update)
- e-Signature integration (DocuSign / Adobe Sign)
- Bulk paste line-add UX
- Per-brand quote PDF templates
- Quote_history relation
- Quote PDF storage / archival (`storage/app/quotes/` + retention)
- Quote analytics dashboard (median time-to-accept, win rate)
- Multi-currency quotes (v1 GBP only)
- Quote line discounts (per-line override on top of TradeRuleResolver)
- Reject reason analytics dashboard
- `QuoteAccepted`/`QuoteRejected` Bitrix Deal stage transitions
- Customer self-service "my quotes" page
- Quote → Order conversion automation
- Per-customer quote count limits
- Customer signature block on PDF (e-signature)

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| QUOT-01 | `Quote` Eloquent model (ULID PK) with `customer_group_id`, `customer_email`, `customer_name`, `billing_address` JSON, `status` enum (`draft`, `pending_approval`, `approved`, `sent`, `accepted`, `rejected`, `expired`), `expires_at` (default today + 14d), timestamps. | §Schema Design — schema columns + status enum table + `expires_at` default + ULID HasUlids trait pattern from Phase 1 / Phase 8 |
| QUOT-02 | `QuoteLine` Eloquent model with `quote_id` FK, `sku`, `quantity_int`, `unit_price_pence_at_quote` (immutable snapshot), `line_total_pence_at_quote`, `product_snapshot` JSON. | §Schema Design + §Snapshot Algorithm + §Line Immutability Observer |
| QUOT-03 | Filament `QuoteResource` (admin + pricing_manager + sales CRUD). Selects customer + customer_group → resolves prices via `TradeRuleResolver` → snapshots each line. Subsequent PricingRule edits don't affect saved quotes. | §Filament UX Pattern + §Snapshot Algorithm — TradeRuleResolver::resolveForQuote thin delegate; Filament Select::searchable() reuses Phase 6 ProductResource SKU search precedent |
| QUOT-04 | `spatie/laravel-pdf` renders quote PDF from `resources/views/pdf/quote.blade.php` with branded header, itemised lines, totals, expiry, optional signature block. PDF reads snapshots, never recomputes. | §PDF Rendering — `spatie/laravel-pdf` v2.8 + DOMPDF driver verified; install command + Blade view skeleton + VAT block math |
| QUOT-05 | On approval, `QuoteApproved` event fires → CRM-domain `PushQuoteToBitrix` listener → `PushQuoteToBitrixDealJob` (`crm-bitrix` queue) creates Bitrix Deal `TYPE_ID=QUOTE` with line items via existing BitrixClient. | §Bitrix Push Pipeline — listener-job-applier pattern verbatim from Phase 4 PushOrderToBitrixJob; line items via `crm.deal.productrows.set` |
| QUOT-06 | `QUOTE_BITRIX_PUSH_ENABLED` env flag default false (shadow-mode → writes payload to `sync_diffs` with `provider='bitrix-quote'`). When true, pushes live. Operator flips manually. | §Shadow-Mode Gate — mirrors Phase 1 WOO_WRITE_ENABLED + Phase 4 services.bitrix.write_enabled idiom; uses existing SyncDiff model |
| QUOT-07 | Quote dedup: re-send is idempotent via `Quote.id` as `UF_CRM_WOO_QUOTE_ID` on Bitrix Deal. Re-approving updates Deal; no duplicate. | §Idempotency — extends BitrixEntityMap with `entity_type='quote_deal'`; matched-update pattern from Phase 4 EntityDeduper.findDealByWooOrderId |
| QUOT-08 | `quotes:expire` scheduled command flips `status=expired` past `expires_at`. Optional config-gated customer email. | §Expiry Command — BaseCommand pattern + Schedule::command() in routes/console.php |

## Standard Stack

### Core (already installed by v1.50.1 / Phase 8)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/framework` | ^12.0 | App framework | v1 baseline [VERIFIED: composer.json:16] |
| `filament/filament` | ^3.3 | Admin panel — QuoteResource | v1 baseline [VERIFIED: composer.json:13] |
| `bitrix24/b24phpsdk` | 1.10.0 | Bitrix REST — `crm.deal.productrows.set` already supported | v1 baseline [VERIFIED: composer.json:12 + vendor/bitrix24/b24phpsdk/src/Services/CRM/Deal/Service/DealProductRows.php] |
| `bezhansalleh/filament-shield` | ^3.3 | RBAC — `quote_*` permissions seeded via `shield:safe-regenerate` (Phase 8) | v1 baseline [VERIFIED: composer.json:11] |
| `spatie/laravel-permission` | ^6.0 | Permissions backing Shield | v1 baseline [VERIFIED: composer.json:28] |
| `spatie/laravel-activitylog` | ^4.12 | Audit trail — Quote model uses `LogsActivity` trait | v1 baseline [VERIFIED: composer.json:27] |
| `laravel/horizon` | ^5.45 | Queue runner — `crm-bitrix` supervisor already configured | v1 baseline [VERIFIED: composer.json:17 + config/horizon.php:190] |

### NEW (Plan 11-04 must add)

| Library | Version | Purpose | Why |
|---------|---------|---------|-----|
| `spatie/laravel-pdf` | ^2.8 (latest 2026-04-27) | Quote PDF rendering via Blade + DOMPDF | Decided in CONTEXT.md (Claude's Discretion); QUOT-04 explicit. Latest is 2.8.0; pin `^2.7` per CONTEXT.md OR `^2.8` to take latest — both compatible. [VERIFIED: packagist.org/packages/spatie/laravel-pdf — PHP ^8.2, Laravel ^11\|^12\|^13] |
| `dompdf/dompdf` | ^3.x | Pure-PHP PDF renderer (driver for spatie/laravel-pdf) | DOMPDF driver per ROADMAP success criterion 3. NO external binaries, NO Node.js, NO Docker. [CITED: spatie.be/docs/laravel-pdf/v2/installation-setup] |

**Verification before Plan 11-04 ships:**
```bash
composer require spatie/laravel-pdf:^2.8 dompdf/dompdf:^3.0
# Then in .env:
LARAVEL_PDF_DRIVER=dompdf
```

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `spatie/laravel-pdf` + DOMPDF | `barryvdh/laravel-dompdf` (used in 21CAV Rams2) | Direct dompdf wrapper — simpler API but less driver-agnostic. spatie/laravel-pdf chosen by ROADMAP for the v2/driver-architecture future-proofing (Browsershot upgrade path). |
| DOMPDF driver | Browsershot (Chromium) | Browsershot has full CSS3 support but adds Puppeteer/Node binary requirement on the VPS. Out of scope per CONTEXT.md driver decision. |
| `crm.deal.productrows.set` for line items | Bitrix Estimate entity (`crm.quote.add`) | See §Bitrix Line-Item Modelling — Estimate adds a separate entity surface we don't currently track in v1; productrows.set is the v1-precedent pattern. |
| ULID PK | UUID v7 | ULID is the v2 standard (Phase 1 D-16, Phase 8 AGNT-03 ULID precedent). Eloquent `HasUlids` trait already used by `Suggestion`, `AgentRun`. |
| Direct Filament action approve | Suggestions seam (`quote_approval` kind) | Suggestions seam is for **AI-generated proposals**; quote approval is **human-driven**. Direct Filament action chosen — see §Approval Applier Seam. |

## Architecture Patterns

### Recommended Project Structure (Plan 11-01 creates from zero)

```
app/Domain/Quotes/                                # NEW domain
├── Models/
│   ├── Quote.php                                 # ULID PK, HasUlids, LogsActivity
│   └── QuoteLine.php                             # FK to Quote, snapshot columns
├── Events/
│   ├── QuoteApproved.php                         # extends DomainEvent + ShouldDispatchAfterCommit
│   ├── QuoteAccepted.php                         # optional D-07
│   └── QuoteRejected.php                         # optional D-07
├── Filament/
│   └── Resources/
│       └── QuoteResource.php                     # admin/pricing_manager/sales CRUD; "Sales" nav group
│       └── QuoteResource/
│           ├── Pages/{ListQuotes,CreateQuote,EditQuote,ViewQuote}.php
│           └── RelationManagers/QuoteLinesRelationManager.php
├── Console/Commands/
│   └── QuotesExpireCommand.php                   # extends BaseCommand
├── Policies/
│   ├── QuotePolicy.php                           # 5 std + approve/markAccepted/markRejected
│   └── QuoteLinePolicy.php
├── Observers/
│   └── QuoteLineImmutabilityObserver.php         # D-13 invariant — throws on saving when status != draft
├── Services/
│   ├── PriceSnapshotter.php                      # builds QuoteLine row from sku + qty + customer_group_id
│   └── QuotePdfRenderer.php                      # wraps spatie/laravel-pdf::view('pdf.quote', ...)
├── Mail/
│   └── QuoteSentMail.php                         # Mailable with PDF attachment
└── Notifications/
    └── QuoteExpiredNotification.php              # optional QUOT-08 customer email

app/Domain/CRM/Listeners/
└── PushQuoteToBitrix.php                         # NEW — subscribes QuoteApproved (CRM-domain placement)

app/Domain/CRM/Jobs/
└── PushQuoteToBitrixDealJob.php                  # NEW — clones PushOrderToBitrixJob shape

app/Domain/CRM/Appliers/
└── QuotePushRetryApplier.php                     # NEW — kind='quote_push_failed' (parallels CrmPushRetryApplier)

app/Domain/TradePricing/Services/
└── TradeRuleResolver.php                         # MODIFIED — add resolveForQuote(sku, customer_group_id): int delegating to existing resolve()

resources/views/pdf/
└── quote.blade.php                               # NEW — branded header + ex-VAT itemised + VAT block + footer

config/
└── quote.php                                     # NEW — company details + default_expiry_days + email_on_expiry + pdf_signature_block

database/migrations/
├── 2026_05_01_000001_create_quotes_table.php     # ULID PK + 14 cols
├── 2026_05_01_000002_create_quote_lines_table.php # FK + 7 cols
├── 2026_05_01_000003_extend_bitrix_entity_map_for_quote_deal.php # NO-OP if VARCHAR entity_type already accepts new value
└── 2026_05_01_000004_add_receives_quote_alerts_to_alert_recipients.php # boolean default false

depfile.yaml + deptrac.yaml                       # MODIFIED — Quotes layer + CRM allow-list extension
```

### Pattern 1: Decorator-extension over byte-identical v1 service (B-03 invariant)

**What:** TradeRuleResolver::resolveForQuote is added as a thin delegate; the existing `resolve()` body is UNCHANGED.
**When to use:** Every Phase 11 caller of TradeRuleResolver MUST go through `resolveForQuote()` so the line-snapshot intent is explicit at call-sites.
**Example:**
```php
// app/Domain/TradePricing/Services/TradeRuleResolver.php — additive
final class TradeRuleResolver
{
    // ... existing resolve(...) UNCHANGED — sha256 baseline locked by Phase 9 B-03

    /**
     * Phase 11 D-13 — explicit "snapshot at quote-creation" entry point.
     * Returns the integer-pennies VAT-INCLUSIVE retail price (calls
     * PriceCalculator::compute with margin_bps + VAT_bps). Phase 11
     * QuoteLine writer stores both the result AND the resolution chain
     * (matchedRuleId + chain[]) into product_snapshot for auditability.
     */
    public function resolveForQuote(string $sku, ?int $customerGroupId): PricingResolution
    {
        $product = Product::query()->where('sku', $sku)->firstOrFail();
        return $this->resolve($product, $customerGroupId);
    }
}
```

### Pattern 2: Line-snapshot at QuoteLine creation (D-13 invariant)

**What:** Plan 11-02 ships `PriceSnapshotter` service that builds a fully-frozen QuoteLine row.
**When to use:** Filament create/edit forms for QuoteLine; `ImportQuoteAction` (Phase 14 forward-compat helper) — every code path that creates a QuoteLine row.
**Example:**
```php
// app/Domain/Quotes/Services/PriceSnapshotter.php
final class PriceSnapshotter
{
    public function __construct(
        private readonly TradeRuleResolver $resolver,
        private readonly PriceCalculator $calculator,
    ) {}

    public function buildLine(Quote $quote, string $sku, int $quantity): array
    {
        $resolution = $this->resolver->resolveForQuote($sku, $quote->customer_group_id);
        $product = Product::where('sku', $sku)->firstOrFail();

        // Resolution already returns VAT-inclusive retail (Phase 3 PriceCalculator::compute).
        // For ex-VAT itemisation on PDF, stripVat is applied at render time, NOT stored.
        $unitPriceIncVat = $resolution->finalPrice;  // integer pennies, inc-VAT

        return [
            'quote_id' => $quote->id,
            'sku' => $sku,
            'quantity_int' => $quantity,
            'unit_price_pence_at_quote' => $unitPriceIncVat,
            'line_total_pence_at_quote' => $unitPriceIncVat * $quantity,
            'product_snapshot' => [
                'name' => $product->name,
                'brand' => $product->brand?->name,
                'category' => $product->category?->name,
                'matched_rule_id' => $resolution->matchedRuleId,
                'resolution_chain' => $resolution->chain,
                'snapshot_at' => now()->toIso8601String(),
            ],
        ];
    }
}
```

**Critical:** D-11 says ex-VAT itemisation in PDF — but storage is **VAT-inclusive** because that's what `PriceCalculator::compute()` returns. The PDF renderer applies `stripVat()` at render time. Verify this with the planner before locking — alternative is to store ex-VAT pennies and add VAT at render. Either is consistent if applied consistently. **Recommendation: store inc-VAT (matches v1 retail pricing convention), strip-at-render. Document this decision explicitly in Plan 11-02.**

### Pattern 3: Quote status state machine (D-04, D-05, D-06)

**What:** v1.0 transitions enforced by Filament action visibility + a guard method on the Quote model.

```
draft ──Approve──▶ sent ──MarkAccepted──▶ accepted
                    │   ──MarkRejected──▶ rejected
                    │   ──(scheduler)────▶ expired (when expires_at < now())
                    └──Revert (5min)────▶ draft
```

| Transition | Actor | Mechanism |
|------------|-------|-----------|
| `draft → sent` | admin / pricing_manager (NOT sales) | Filament Approve action; D-04 atomic transaction |
| `sent → draft` | admin only, within 5min | Filament Revert action (hidden after 5min) |
| `sent → accepted` | sales / pricing_manager / admin | Filament "Mark accepted" row action |
| `sent → rejected` | sales / pricing_manager / admin | Filament "Mark rejected" row action with reason form |
| `sent → expired` | scheduler (`quotes:expire`) | Auto, no human |
| ANY → ANY (else) | nobody | Validation throws; UI hides action |

**Reserved-but-unused enum values:** `pending_approval`, `approved` — present in DB enum so v1.x can use them without migration. v1.0 ignores them.

### Pattern 4: Listener-based extension of v1 (Phase 4 verbatim — CRM-domain placement)

**What:** `QuoteApproved` event fires from Quotes domain; `PushQuoteToBitrix` listener lives in `app/Domain/CRM/Listeners/`.
**Why:** Keeps Quotes domain ignorant of Bitrix; Deptrac one-way arrow `CRM → Quotes` allowed, `Quotes → CRM` denied.

```php
// app/Domain/CRM/Listeners/PushQuoteToBitrix.php
namespace App\Domain\CRM\Listeners;

use App\Domain\CRM\Jobs\PushQuoteToBitrixDealJob;
use App\Domain\Quotes\Events\QuoteApproved;

final class PushQuoteToBitrix
{
    public function handle(QuoteApproved $event): void
    {
        PushQuoteToBitrixDealJob::dispatch($event->quoteId);
    }
}
```

Wired in `EventServiceProvider::$listen` array.

### Anti-Patterns to Avoid

- **Don't recompute prices in the PDF renderer.** D-13 invariant — PDF reads `unit_price_pence_at_quote` snapshot column ONLY. Calling `TradeRuleResolver` from `quote.blade.php` defeats the snapshot guarantee.
- **Don't put `BitrixClient` import in `app/Domain/Quotes/`.** Deptrac one-way arrow violation. The CRM-domain listener is the seam.
- **Don't create a separate `entity_type='quote'` in BitrixEntityMap that has the same `woo_id` shape as orders.** Use `entity_type='quote_deal'` so Phase 4 order-dedup query (`->where('entity_type', 'deal')`) doesn't accidentally match a quote.
- **Don't reuse `UF_CRM_WOO_ORDER_ID` for quotes.** Add a NEW custom field `UF_CRM_WOO_QUOTE_ID`. Reuse would conflate orders + quotes in Bitrix.
- **Don't fire `QuoteApproved` synchronously inside a model `saving` observer.** Use `ShouldDispatchAfterCommit` so the listener runs only after the DB transaction commits (matches Phase 1 + Phase 4 pattern).
- **Don't allow sales to approve their own quote.** D-04 separation-of-duties — Filament action `->visible(fn() => $user->hasAnyRole(['admin', 'pricing_manager']))`.
- **Don't make customer_group_id a JOIN-at-resolve-time field on Quote.** D-02 — snapshotted as a column at quote creation. JOIN-time resolution would mean future role changes to the user could change displayed group on the quote.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| ULID generation | Custom 26-char generator | Eloquent `HasUlids` trait | Matches Phase 1 D-16 / Phase 8 AGNT-03 ULID precedent. Already used by Suggestion, AgentRun. |
| PDF rendering | Custom DomPDF wrapper | `spatie/laravel-pdf` + DOMPDF driver | Driver-agnostic; future-proofs to Browsershot if CSS gets fancy. |
| Bitrix line-items | Hand-build JSON for custom-field strategy | `crm.deal.productrows.set` (SDK already supports) | Bitrix-native; renders in Bitrix UI; supports up to ~250 rows per call (well above 30-line quote ceiling). |
| Penny math | Float prices anywhere | `PriceCalculator` integer pennies (Phase 3 D-03) | Float drift kills audit. Phase 3 ships the canonical converter. |
| VAT calculation | Hand-rolled `* 1.2` | `PriceCalculator::stripVat()` for ex-VAT or `* (10000 + 2000) / 10000` style | Single source of VAT math (D-05 lock). |
| Retry on Bitrix push | Custom retry loop | Phase 4 `PushOrderToBitrixJob` shape (tries=3, backoff=[30,300,1800], BitrixTransientException retryable, BitrixPermanentException fail-fast) | Battle-tested across order pushes. Phase 11 inherits the entire retry/failed/Suggestions/Alert pipeline. |
| Suggestion DLQ on push fail | Custom failed-job handler | Suggestions seam: `kind='quote_push_failed'` → `QuotePushRetryApplier` (clone CrmPushRetryApplier shape) | Phase 1 D-14 + D-17 invariant — every failure has a Suggestion + Replay path. |
| Email Mailable | Raw SwiftMailer / PHPMailer | Laravel `Mail::send(new QuoteSentMail($quote))` with PDF attachment | Standard. |
| Audit trail on Quote model | Manual Auditor::record() calls | `LogsActivity` trait with explicit `logOnly([...])` (Phase 1 pattern; Activity log viewer Phase 1 already shipped) | spatie/laravel-activitylog auto-captures status transitions. |
| Filament SKU search | Custom AJAX endpoint | `Select::searchable()` reusing Phase 6 ProductResource search query | Phase 6 ships the SKU autocomplete pattern. |
| Customer signature integration | Custom e-signature flow | Static signature line on PDF (CONTEXT.md `pdf_signature_block` config) | Real e-signature deferred to v2.x. |
| Public accept-link / token rotation | Custom signed URL + rate limit | DEFERRED per D-09 | Manual sales acceptance is v1 — not building public surface. |

**Key insight:** Phase 11 is 95% composition of v1+v2 primitives. The only non-precedented work is the snapshot-immutability observer (~30 LOC) and the quote.blade.php template (~150 LOC). Everything else is "clone Phase 4 shape but for quotes."

## Common Pitfalls

### Pitfall 1: Snapshot drift via float pence

**What goes wrong:** A line is created with `unit_price_pence_at_quote = 1999`. A subsequent edit accidentally stores `19.99` as a float, then re-reads as `(int) 19.99 = 19` pence — line drops 99% of value.
**Why it happens:** Mixing `decimal:2` Eloquent cast with integer-pennies discipline.
**How to avoid:** Cast `unit_price_pence_at_quote` and `line_total_pence_at_quote` as `'integer'` in `$casts`; never use `decimal:2`. Pest test asserts via `expect($quote->lines->first()->unit_price_pence_at_quote)->toBeInt()->toBe(1999)`.
**Warning signs:** Any line total under £0.10. Any non-integer in the column on `php artisan tinker` inspection.

### Pitfall 2: `crm.dealtype.list` returns no `QUOTE` system code

**What goes wrong:** `BitrixClient::dealAdd(['TYPE_ID' => 'QUOTE', ...])` succeeds but Bitrix silently coerces to default deal type. Operator sees Quotes appearing as ordinary Sales deals.
**Why it happens:** Bitrix Deal types are configurable per-instance; `QUOTE` is NOT a built-in (search confirmed: standard examples use `COMPLEX`, `GIG`, `GOODS`, `SALE`). Operator must create the type in Bitrix admin first OR map to an existing type.
**How to avoid:** Plan 11-04 ships pre-flight check that calls `crm.dealtype.list` (NOT in current BitrixClient — needs to be added; or use `crm.status.list` with `ENTITY_ID=DEAL_TYPE`). Warns on missing `QUOTE`. Operator runbook documents creation.
**Warning signs:** `dealtype.list` returns no row with system code `QUOTE`. Bitrix UI shows quote-Deals under "All Deals" without the QUOTE filter.

### Pitfall 3: Phase 4 entity_type collision in BitrixEntityMap

**What goes wrong:** Phase 11 inserts BitrixEntityMap row with `entity_type='deal'` and `woo_id=42`. Phase 4 already has a row with the same key for Order #42. The UNIQUE(entity_type, woo_id) index trips OR worse — silently overwrites.
**Why it happens:** Reusing `entity_type='deal'` for both order-Deals and quote-Deals.
**How to avoid:** Use `entity_type='quote_deal'` (already documented in CONTEXT.md "Specific Ideas"). Verify the existing `bitrix_entity_map.entity_type` column type accepts the new value (VARCHAR — should be fine; check the migration source). Update `BitrixEntityMap` model with `ENTITY_QUOTE_DEAL = 'quote_deal'` constant + scope.
**Warning signs:** Quote push silently updates an existing Order's Bitrix Deal ID. UNIQUE-violation on `bitrix_entity_map_entity_type_woo_id_unique` for a `woo_id` that's both a quote and an order.

### Pitfall 4: Snapshot integrity test passes locally but fails in CI

**What goes wrong:** `QuotePdfPriceImmunityTest` creates a quote, edits a PricingRule, regenerates the PDF, asserts unchanged. Passes locally. Fails in CI because PriceCalculator's `round()` mode differs (env-specific).
**Why it happens:** `config('pricing.rounding_mode')` defaults to `PHP_ROUND_HALF_UP` but tests don't pin it explicitly. CI may have different `.env.testing` defaults.
**How to avoid:** `QuotePdfPriceImmunityTest::beforeEach` calls `config()->set('pricing.rounding_mode', PHP_ROUND_HALF_UP)`. Phase 3 golden-fixture test does this — match the pattern.
**Warning signs:** Penny-off-by-one assertion failures only on CI; local always passes.

### Pitfall 5: `QuoteApproved` event fires before transaction commits

**What goes wrong:** Filament Approve action does `DB::transaction(fn() => [save status=sent + dispatch event])`. Listener fires synchronously, dispatches PushQuoteToBitrixDealJob, which loads `Quote::find($id)` and finds it in pre-commit state (or fails to load). Bitrix Deal creates with stale data.
**Why it happens:** Plain `event()` calls fire immediately, even inside a transaction.
**How to avoid:** `QuoteApproved` MUST implement `Illuminate\Contracts\Events\ShouldDispatchAfterCommit`. Phase 1 + Phase 4 precedent — every cross-domain DomainEvent uses this trait.
**Warning signs:** Bitrix Deal has `STAGE_ID=NEW` but Quote.status='accepted' locally — the listener saw an older snapshot.

### Pitfall 6: Sales reads `customer_group_id` via JOIN at PDF time

**What goes wrong:** PDF template does `$quote->customer->customer_group->name` — JOIN at render time. User's role changed since the quote was created → wrong group displayed on the regenerated PDF.
**Why it happens:** Forgetting D-02 — customer_group_id is a snapshotted column on Quote, not a JOIN.
**How to avoid:** PDF reads `$quote->customer_group_name_at_quote` (denormalised string column — CONTEXT.md). Pest test creates quote, changes user's role, re-renders PDF, asserts label matches original.
**Warning signs:** PDF "trade" label changes when customer_group_id is reassigned upstream.

### Pitfall 7: Filament Action visibility doesn't enforce server-side authorization

**What goes wrong:** `Approve` action is hidden via `->visible(...)` for sales role, but a determined user POSTs to the action URL directly. Server-side `->authorize()` was never set; action runs.
**Why it happens:** `visible()` is UI-only — controls rendering. `->authorize()` enforces at handler-time.
**How to avoid:** EVERY Filament action MUST have `->authorize('approve_quote')` (mandatory per CONTEXT.md `<code_context>`). Pest test asserts approval action 403s for sales-role user even when calling action handler directly.
**Warning signs:** Sales-role user successfully approves quote; activity_log shows their causer_id on a sent_at transition.

## Code Examples

### Quote model with HasUlids + LogsActivity

```php
// app/Domain/Quotes/Models/Quote.php
namespace App\Domain\Quotes\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

final class Quote extends Model
{
    use HasUlids, LogsActivity;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';  // RESERVED v1.x
    public const STATUS_APPROVED = 'approved';                  // RESERVED v1.x
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'user_id', 'customer_group_id', 'customer_group_name_at_quote',
        'customer_email', 'customer_name', 'billing_address',
        'status', 'expires_at', 'rejection_metadata',
        'sent_at', 'accepted_at', 'rejected_at', 'expired_at',
    ];

    protected $casts = [
        'billing_address' => 'array',
        'rejection_metadata' => 'array',
        'expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'sent_at', 'accepted_at', 'rejected_at', 'expired_at'])
            ->logOnlyDirty();
    }

    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(QuoteLine::class);
    }

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function ulidShort(): string
    {
        return substr($this->id, 0, 8);
    }
}
```

### QuoteApproved domain event (after-commit dispatch)

```php
// app/Domain/Quotes/Events/QuoteApproved.php
namespace App\Domain\Quotes\Events;

use App\Foundation\Events\DomainEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class QuoteApproved extends DomainEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $quoteId,
        public readonly ?int $userId,
        public readonly string $customerEmail,
        public readonly ?int $customerGroupId,
        public readonly string $statusBefore,
        public readonly string $statusAfter,
        public readonly string $correlationId,
    ) {}
}
```

### QuoteLineImmutabilityObserver (D-13 invariant)

```php
// app/Domain/Quotes/Observers/QuoteLineImmutabilityObserver.php
namespace App\Domain\Quotes\Observers;

use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteLine;
use RuntimeException;

final class QuoteLineImmutabilityObserver
{
    public function saving(QuoteLine $line): void
    {
        if (! $line->exists) {
            return; // creation OK
        }

        $quote = $line->quote;
        if ($quote->status !== Quote::STATUS_DRAFT) {
            // After draft: only quantity_int may change (D-13)
            // unit_price_pence_at_quote + product_snapshot frozen forever
            $forbidden = ['unit_price_pence_at_quote', 'product_snapshot', 'sku', 'quote_id'];
            foreach ($forbidden as $col) {
                if ($line->isDirty($col)) {
                    throw new RuntimeException(sprintf(
                        'QuoteLine %s.%s is immutable after Quote.status=sent (got %s → %s)',
                        $line->id, $col,
                        json_encode($line->getOriginal($col)),
                        json_encode($line->getAttribute($col)),
                    ));
                }
            }
            // Recalc line_total if quantity changed (allowed in draft only)
            if ($line->isDirty('quantity_int')) {
                throw new RuntimeException(sprintf(
                    'QuoteLine %s quantity_int cannot change after Quote.status=sent',
                    $line->id,
                ));
            }
        } else {
            // In draft: recalc line_total when quantity changes
            if ($line->isDirty('quantity_int')) {
                $line->line_total_pence_at_quote = $line->unit_price_pence_at_quote * $line->quantity_int;
            }
        }
    }
}
```

### PushQuoteToBitrixDealJob skeleton (clones PushOrderToBitrixJob)

```php
// app/Domain/CRM/Jobs/PushQuoteToBitrixDealJob.php
namespace App\Domain\CRM\Jobs;

use App\Domain\CRM\Exceptions\BitrixPermanentException;
use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class PushQuoteToBitrixDealJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 300, 1800];  // Phase 4 D-11
    public int $timeout = 120;

    public function __construct(public readonly string $quoteId)
    {
        $this->onQueue('crm-bitrix');
    }

    public function handle(BitrixClient $client): void
    {
        $quote = Quote::with('lines')->findOrFail($this->quoteId);
        $correlationId = (string) ($quote->correlation_id ?? Str::ulid());

        try {
            $map = BitrixEntityMap::query()
                ->where('entity_type', 'quote_deal')
                ->where('woo_id', 0)            // sentinel — quotes use ULID not int
                ->where('bitrix_id', $quote->id) // store quote ULID in bitrix_id col? See Pitfall — needs migration extension
                ->first();
            // ALTERNATIVELY: extend BitrixEntityMap with a `quote_id` ULID column.
            // Plan 11-01 must decide between (a) reusing woo_id as int hash of ULID
            // (b) adding a new nullable quote_id ULID column.

            $payload = [
                'TYPE_ID' => 'QUOTE',  // Pitfall 2 — verify via bitrix:bootstrap
                'TITLE' => sprintf('Quote %s for %s', $quote->ulidShort(), $quote->customer_email),
                'OPPORTUNITY' => number_format($this->quoteTotalIncVat($quote) / 100, 2, '.', ''),
                'CURRENCY_ID' => 'GBP',
                'UF_CRM_WOO_QUOTE_ID' => $quote->id,
                // ...other CRM mapping (assigned_by_id, contact_id resolution, etc.)
            ];

            if ($map === null) {
                $dealId = $client->dealAdd($payload, $correlationId);
                BitrixEntityMap::create([
                    'entity_type' => 'quote_deal',
                    'woo_id' => 0,
                    'bitrix_id' => $dealId,
                    'last_payload_hash' => hash('sha256', (string) json_encode($payload)),
                    'last_correlation_id' => $correlationId,
                    'last_pushed_at' => now(),
                    'created_via' => BitrixEntityMap::VIA_PUSH,
                    // 'quote_id' => $quote->id,  // if column added
                ]);
            } else {
                $client->dealUpdate($map->bitrix_id, $payload, $correlationId);
                $map->update([
                    'last_payload_hash' => hash('sha256', (string) json_encode($payload)),
                    'last_correlation_id' => $correlationId,
                    'last_pushed_at' => now(),
                ]);
                $dealId = $map->bitrix_id;
            }

            // Line items via crm.deal.productrows.set
            $rows = $quote->lines->map(fn($line) => [
                'PRODUCT_NAME' => $line->product_snapshot['name'] ?? $line->sku,
                'PRICE' => number_format($line->unit_price_pence_at_quote / 100, 2, '.', ''),
                'QUANTITY' => (string) $line->quantity_int,
                'TAX_RATE' => '20',
                'TAX_INCLUDED' => 'Y',  // matches snapshot store-format
            ])->all();

            // BitrixClient needs a new `dealProductRowsSet` method (Plan 11-04 adds it)
            $client->dealProductRowsSet((int) $dealId, $rows, $correlationId);
        } catch (BitrixPermanentException $e) {
            $this->emitFailedSuggestion('permanent_validation', $quote->id, $e->getMessage(), $correlationId);
            $this->fail($e);
        }
    }

    public function failed(Throwable $e): void
    {
        if (! $e instanceof BitrixPermanentException) {
            $this->emitFailedSuggestion('push_exhausted', $this->quoteId, $e->getMessage(), null);
        }
        // Notify receives_quote_alerts recipients (clone Phase 4 notifyCrmAlerts shape)
    }

    private function emitFailedSuggestion(string $subKind, string $quoteId, string $err, ?string $cid): void
    {
        Suggestion::create([
            'kind' => 'quote_push_failed',  // NEW kind — register QuotePushRetryApplier
            'status' => Suggestion::STATUS_PENDING,
            'correlation_id' => $cid,
            'payload' => [
                'sub_kind' => $subKind,
                'quote_id' => $quoteId,
                'error_message' => $err,
            ],
            'evidence' => [
                'quote_id' => $quoteId,
                'retry_count' => $this->attempts(),
            ],
            'proposed_at' => now(),
        ]);
    }

    private function quoteTotalIncVat(Quote $quote): int
    {
        return $quote->lines->sum('line_total_pence_at_quote');
    }
}
```

### Bitrix line-item shape for `crm.deal.productrows.set`

```php
// Verified against vendor/bitrix24/b24phpsdk/src/Services/CRM/Deal/Service/DealProductRows.php:75-95
[
    'PRODUCT_ID' => 0,                       // 0 = ad-hoc product (not in Bitrix catalog)
    'PRODUCT_NAME' => 'Sony XAV-AX5650',     // from product_snapshot.name
    'PRICE' => '199.99',                     // string, GBP units (not pence)
    'PRICE_EXCLUSIVE' => '166.66',           // ex-VAT (computed via PriceCalculator::stripVat)
    'PRICE_NETTO' => '166.66',
    'PRICE_BRUTTO' => '199.99',
    'QUANTITY' => '2',                       // string
    'TAX_RATE' => '20',                      // UK VAT
    'TAX_INCLUDED' => 'Y',                   // PRICE includes VAT
    'CUSTOMIZED' => 'Y',                     // marks as ad-hoc (not catalog-linked)
    'MEASURE_CODE' => 796,                   // Bitrix unit code: 796 = piece (default)
    'MEASURE_NAME' => 'pcs',
    'SORT' => 10,                            // line ordering
]
```

### `crm.deal.productrows.set` — extending BitrixClient

```php
// app/Domain/CRM/Services/BitrixClient.php — additive method
public function dealProductRowsSet(int $dealId, array $rows, ?string $correlationId = null): void
{
    $shadow = $this->shadowIfDisabled('crm.deal.productrows.set', null,
        ['dealId' => $dealId, 'rows' => $rows], $correlationId);
    if ($shadow !== null) {
        return;
    }

    $this->withSdk(
        'crm.deal.productrows.set',
        ['dealId' => $dealId, 'rows' => $rows],
        fn () => $this->sdk()->getCRMScope()->dealProductRows()->set($dealId, $rows)->isSuccess(),
        $correlationId,
    );
}
```

### spatie/laravel-pdf invocation

```php
// app/Domain/Quotes/Services/QuotePdfRenderer.php
use Spatie\LaravelPdf\Facades\Pdf;

final class QuotePdfRenderer
{
    public function render(Quote $quote): string  // returns PDF bytes
    {
        return Pdf::view('pdf.quote', ['quote' => $quote->load('lines')])
            ->name(sprintf('quote-%s.pdf', $quote->ulidShort()))
            ->base64();  // or ->save() / ->stream()
    }
}
```

### Bitrix Deal type pre-flight (Plan 11-04)

```php
// app/Domain/CRM/Console/Commands/BitrixBootstrapCommand.php — extension
// Runs after the existing field-bootstrap loop:
$dealTypes = $this->client->dealStatusList(); // NEW method on BitrixClient
$hasQuoteType = collect($dealTypes)->contains(fn ($t) => ($t['STATUS_ID'] ?? '') === 'QUOTE');

if (! $hasQuoteType) {
    $this->warn(
        'BitrixBootstrap: Bitrix instance has no Deal Type with STATUS_ID="QUOTE". '.
        'Quote pushes will fall back to default deal type. '.
        'Create the type in Bitrix admin: CRM → Settings → Deal Types → Add → set System Code to "QUOTE".'
    );
    // NOT a fatal error — operator can run with default type until they configure
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `barryvdh/laravel-dompdf` direct wrapper | `spatie/laravel-pdf` v2 with driver architecture | spatie/laravel-pdf v2.0 (2024) | Driver-agnostic; future-proofs to Browsershot if PDF design needs CSS3 grid |
| Bitrix custom-fields JSON for line items | `crm.deal.productrows.set` (native productrows entity) | Bitrix REST stable since 2018; b24phpsdk supports as of v1.0 | Renders in Bitrix UI; supports up to ~250 rows; no parse hacks |
| Per-action retry loops | Laravel `$tries` + `$backoff` array on ShouldQueue jobs | Laravel 9+ | Standard. Phase 4 D-11 establishes the [30s, 5m, 30m] cadence for Bitrix tier-1. |
| Float price arithmetic | Integer-pennies + basis points (Phase 3 D-03) | v1.50.1 Phase 3 | Eliminates rounding drift; locked by golden fixture. |
| Polling for state transitions | Domain events + listener-based extension | Phase 1 baseline | Cross-domain coupling without import edges. |

**Deprecated/outdated:**
- `bitrix24/b24phpsdk` v3.x line — requires PHP 8.4 floor (we're on 8.2 floor). v1.10.0 is the right pin per Phase 4 decisions.
- `Z3d0X/filament-logger` — unmaintained; we use `rmsramos/activitylog` (v1.50.1 baseline).

## Bitrix Line-Item Modelling Comparison (ROADMAP research flag)

ROADMAP flagged: "Bitrix Deal line-item modelling for 30-line quotes (custom-fields strategy vs Bitrix Estimate API surface)." CONTEXT.md leans toward `crm.deal.productrows.set`. RESEARCH verifies:

| Approach | Mechanism | Pros | Cons | Verdict |
|----------|-----------|------|------|---------|
| **A. `crm.deal.productrows.set`** | Bitrix-native deal-attached product rows; SDK ships `DealProductRows::set` | Renders in Bitrix UI as line items; supports up to ~250 rows; standard Bitrix shape; minimal v1 deviation; SDK already supports per [VERIFIED: vendor/bitrix24/b24phpsdk/src/Services/CRM/Deal/Service/DealProductRows.php] | Two API calls per push (`crm.deal.add` + `crm.deal.productrows.set`) — adds latency under 2req/s rate limit | **RECOMMENDED — implement this** |
| **B. Custom-field JSON on Deal** | Stuff line items as JSON into a `UF_CRM_WOO_QUOTE_LINES_JSON` custom field | Single API call; simpler retry semantics | Bitrix UI does NOT render JSON; reps see opaque blob; no native search/filter on line content; fragile (UF max length is 65536 chars — limits rich payloads) | NOT RECOMMENDED — UI invisibility kills sales-rep workflow |
| **C. Bitrix Estimate (`crm.quote.add`)** | Separate Bitrix entity for quotes/estimates; `crm.quote.productrows.set` for lines | Conceptually-correct entity model (Estimates are quotes); native lifecycle | Adds entity surface we don't track in v1 (Estimates are separate from Deals — sales pipeline doesn't surface them by default); requires duplicate field-mapping seam; **CONTEXT.md explicitly rejects** ("adds entity surface we don't currently track") | NOT RECOMMENDED — out of scope per CONTEXT.md |

**Recommendation:** **Approach A (`crm.deal.productrows.set`).** Confidence HIGH.
- SDK support: VERIFIED in `vendor/bitrix24/b24phpsdk/src/Services/CRM/Deal/Service/DealProductRows.php` lines 75-95.
- Two-call pattern: `dealAdd` returns Deal ID → `dealProductRowsSet(dealId, rows)` populates line items.
- Rate limit impact: 2 req/s ÷ 2 calls per quote = 1 quote-push/sec sustained. Well within crm-bitrix supervisor maxProcesses=2 budget.
- Idempotency: `productrows.set` REPLACES all rows on the deal — re-pushing the same Deal ID with the same rows is safe.

## Approval Applier Seam (Should approval be a SuggestionApplier?)

**Question:** Should quote approval be a SuggestionApplier (kind=`quote_approval` produces a suggestion → admin approves → applier pushes to Bitrix), or a direct Filament action that dispatches the push job?

**Recommendation:** **Direct Filament action that dispatches the push job.** Rationale:

1. **Suggestions seam semantic:** Suggestions are for **AI-generated proposals** that need human approval before they touch data (Phase 1 D-14, Phase 5/8/10 producers). Quote approval is **human-driven** — a sales/pricing_manager has already decided the customer should receive this quote. Wrapping that in a Suggestion adds a meaningless approval step ("admin approves the human's decision").

2. **CrmPushRetryApplier as DLQ-only seam:** The Suggestions seam is correctly used for DLQ recovery (`crm_push_failed` kind → `CrmPushRetryApplier`). Phase 11 mirrors this: `quote_push_failed` kind → `QuotePushRetryApplier` for failed pushes. But the *initial* push is a direct dispatch.

3. **Phase 4 precedent:** `HandleOrderReceived` (webhook listener) → `PushOrderToBitrixJob::dispatch(...)`. Direct dispatch from listener. Suggestions only enter the picture on failure. Phase 11 follows this verbatim.

4. **UI clarity:** Filament Approve button → user sees "Quote sent to customer" feedback in 1 click. Routing through Suggestions adds 2 extra clicks (approve quote → see Suggestion appear → approve Suggestion → push runs).

**Documented decision for Plan 11-04:**
- Filament Approve action → `DB::transaction(fn() => [status flip + event::dispatch(QuoteApproved)])`
- Listener (`PushQuoteToBitrix` in CRM domain) catches event, dispatches `PushQuoteToBitrixDealJob`
- On job failure (after 3 retries OR fail-fast 4xx), `quote_push_failed` Suggestion is written
- Admin approves the Suggestion → `QuotePushRetryApplier` re-dispatches `PushQuoteToBitrixDealJob`

## Plan Breakdown Recommendation

CONTEXT.md proposes 5 plans (linear waves). Research VALIDATES this breakdown:

| Plan | Scope | Why this granularity |
|------|-------|----------------------|
| **11-01** | Data model + `bitrix:bootstrap` UF_CRM_WOO_QUOTE_ID extension + `config/quote.php` + Deptrac `Quotes` layer (dual-YAML) + `BitrixEntityMap` quote_deal extension | Foundation — must land first; no circular dependencies on later plans. ~6-8 files. |
| **11-02** | `TradeRuleResolver::resolveForQuote()` + `PriceSnapshotter` service + `QuoteLine` writer + `QuoteLineImmutabilityObserver` + `QuotePdfPriceImmunityTest` (the SHIP GATE per ROADMAP success criterion 1) | Snapshot-integrity is the headline; isolating it in one plan makes the regression test the focus. |
| **11-03** | Filament `QuoteResource` + Pages + Policy + Approve/Revert/MarkAccepted/MarkRejected actions + line picker (Select::searchable reuse Phase 6) + `RolePermissionSeeder` extension + `shield:safe-regenerate` | Operator surface; depends on 11-02 for `PriceSnapshotter`. |
| **11-04** | PDF rendering (`spatie/laravel-pdf` install + DOMPDF + `quote.blade.php` + VAT block) + `QuoteSentMail` Mailable + `QuoteApproved` event + `PushQuoteToBitrix` listener (CRM domain) + `PushQuoteToBitrixDealJob` (clone Phase 4 shape) + `BitrixClient::dealProductRowsSet` extension + sandbox test | Push pipeline; depends on 11-03 for the Approve action that fires the event. |
| **11-05** | `quotes:expire` command + `QuotePushRetryApplier` (kind='quote_push_failed') + `AlertRecipient::receives_quote_alerts` migration + `ImportQuoteAction` service for Phase 14 handoff + Deptrac dual-config sync + DeptracQuotesLayerTest + `11-VERIFICATION.md` ship verdict | Cleanup + verification + Phase 14 forward-compat helper. |

**Estimated file count per plan (based on Phase 9 + Phase 10 averages):**
- 11-01: ~8 files (4 migrations + Quote/QuoteLine models + config + Deptrac edit)
- 11-02: ~6 files (TradeRuleResolver edit + PriceSnapshotter + Observer + 2 Pest tests + ImpactImmunity test)
- 11-03: ~12 files (Resource + 4 Pages + Policy + Form/Table + 4 Action classes + seeder edit)
- 11-04: ~10 files (composer changes + PdfRenderer + Mailable + Blade view + Event + Listener + Job + BitrixClient edit + sandbox test)
- 11-05: ~6 files (Command + Applier + AlertRecipient migration + ImportQuoteAction + Deptrac edit + Architecture test)

**Total estimate: ~42 files, similar to Phase 9 (38 files across 6 plans).**

## Quote-from-Chat Handoff (Phase 14 Forward-Compat)

Phase 14 will add a `propose_quote(customer_email, line_items)` agent tool. Phase 11 ships an `ImportQuoteAction` artisan command + service that takes a structured payload + creates a draft Quote. Phase 14 wraps this.

```php
// app/Domain/Quotes/Services/ImportQuoteAction.php
final class ImportQuoteAction
{
    public function __construct(
        private readonly PriceSnapshotter $snapshotter,
    ) {}

    /**
     * @param array{
     *   customer_email: string,
     *   customer_name?: string,
     *   user_id?: ?int,
     *   customer_group_id?: ?int,
     *   billing_address?: ?array,
     *   line_items: array<int, array{sku: string, quantity_int: int}>,
     *   notes?: ?string,
     * } $input
     */
    public function execute(array $input): Quote
    {
        return DB::transaction(function () use ($input) {
            $quote = Quote::create([
                'user_id' => $input['user_id'] ?? null,
                'customer_email' => $input['customer_email'],
                'customer_name' => $input['customer_name'] ?? null,
                'customer_group_id' => $input['customer_group_id'] ?? null,
                'customer_group_name_at_quote' => /* resolved via CustomerGroup::find */,
                'billing_address' => $input['billing_address'] ?? null,
                'status' => Quote::STATUS_DRAFT,
                'expires_at' => now()->addDays(config('quote.default_expiry_days', 14)),
            ]);

            foreach ($input['line_items'] as $line) {
                QuoteLine::create($this->snapshotter->buildLine(
                    $quote, $line['sku'], $line['quantity_int']
                ));
            }

            return $quote->fresh('lines');
        });
    }
}
```

Phase 14 chatbot agent tool wraps this:
```php
// Phase 14 (future) — propose_quote tool
$action = app(\App\Domain\Quotes\Services\ImportQuoteAction::class);
$quote = $action->execute([
    'customer_email' => $email,
    'line_items' => collect($skus)->map(fn($sku) => ['sku' => $sku, 'quantity_int' => 1])->all(),
]);
return ['quote_id' => $quote->id, 'quote_url_short' => $quote->ulidShort()];
```

## Quote Totals + Line Math (Pence Discipline)

Phase 3 D-03 invariant: integer-pennies throughout.

| Field | Storage | Math |
|-------|---------|------|
| `quote_lines.unit_price_pence_at_quote` | INTEGER (pennies, VAT-INCLUSIVE per recommendation) | Set ONCE by PriceSnapshotter via `TradeRuleResolver::resolveForQuote(...)->finalPrice` |
| `quote_lines.line_total_pence_at_quote` | INTEGER (pennies, inc-VAT) | `unit_price_pence_at_quote * quantity_int` |
| `quotes.total_pence_at_quote` (NEW column — recommend adding) | INTEGER (pennies, inc-VAT) | `SUM(quote_lines.line_total_pence_at_quote)` cached on save (or computed view) |
| PDF Subtotal (ex-VAT) | render-time computed | `PriceCalculator::stripVat($total_pence_at_quote, 2000)` |
| PDF VAT 20% | render-time computed | `$total_pence_at_quote - stripVat($total_pence_at_quote, 2000)` |
| PDF Total inc-VAT | direct read | `$total_pence_at_quote` |

**Validation:**
- Pest test: `expect($quote->lines->sum('line_total_pence_at_quote'))->toBe($quote->total_pence_at_quote)`
- Pest test: `expect($lineTotal)->toBe($unitPrice * $quantity)`
- Pest test: `expect(stripVat($total) + (total - stripVat($total)))->toBe($total)` — VAT round-trip

**Open question:** CONTEXT.md doesn't explicitly add a `quotes.total_pence_at_quote` column. Recommendation to planner: ADD this column + Quote::saving observer that recomputes `SUM(lines.line_total_pence_at_quote)` on draft saves. After status=sent, the column is locked alongside the lines.

## Pre-Cutover Bitrix Sandbox Probe

Phase 4 D-20 establishes the pattern: live Bitrix sandbox probe before cutover. Phase 11 needs an analogous gate.

**Probe sequence (Plan 11-04 sandbox test):**
1. With `QUOTE_BITRIX_PUSH_ENABLED=true` against sandbox webhook URL: create test Quote → approve → assert Bitrix Deal appears with `TYPE_ID=QUOTE` and matching `UF_CRM_WOO_QUOTE_ID`.
2. Re-approve same Quote: assert no duplicate Bitrix Deal (BitrixEntityMap matched on `quote_deal` entity_type).
3. Verify `crm.deal.productrows.set` line items render in Bitrix UI.
4. `cutover:checklist` integration: add Phase 11 sandbox-probe gate that exits 1 unless the test deal exists in sandbox tenant.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | All Laravel code | ✓ | ^8.2 (CONTEXT.md baseline) | — |
| Composer | Plan 11-04 install | ✓ (assumed dev/CI present) | — | — |
| MySQL `meetingstore_ops` | All migrations + Eloquent | ✓ (per v1.50.1 cutover Gate 3 — currently blocked on online DB; tests defer to MySQL-online run) | 8.0+ | SQLite for unit tests only (per Phase 9 precedent) |
| Redis | Horizon `crm-bitrix` queue | ✓ (per v1.50.1 baseline) | 7.x | — |
| `spatie/laravel-pdf` | PDF rendering (QUOT-04) | ✗ | — | NONE — Plan 11-04 MUST install |
| `dompdf/dompdf` | DOMPDF driver | ✗ | — | NONE — Plan 11-04 MUST install |
| Bitrix sandbox tenant | Plan 11-04 sandbox test | unknown — operator must confirm | — | Test against `Bitrix24\SDK\Core\BulkItemsReader` mock if no sandbox |
| Bitrix `TYPE_ID=QUOTE` deal type | All Bitrix Deal pushes (live mode) | unknown — Plan 11-04 ships pre-flight check | — | Falls back to default deal type with warning if absent |
| Mail driver | `QuoteSentMail` (D-04 email PDF on approve) | ✓ (Laravel built-in `mail` driver) | — | `log` driver in test env |

**Missing dependencies with no fallback:**
- `spatie/laravel-pdf` + `dompdf/dompdf` — must be installed by Plan 11-04 first task

**Missing dependencies with fallback:**
- Bitrix `TYPE_ID=QUOTE` — operator runbook documents creating it; pre-flight warns; degrades to default type
- Bitrix sandbox — HTTP-fake test path is acceptable per Phase 4 precedent

## Validation Architecture

Skipped — `workflow.nyquist_validation` is `false` in `.planning/config.json`.

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Existing Breeze + Laravel auth (no v2 changes); Quote requires authenticated Filament user with `quote_*` permissions |
| V3 Session Management | yes | Filament session middleware (already configured) |
| V4 Access Control | yes | `QuotePolicy` + `QuoteLinePolicy` + Filament Action `->authorize()` mandatory; D-04 separation-of-duties (sales cannot approve own quotes) |
| V5 Input Validation | yes | Filament Form components (TextInput, Select, Number) with built-in validators; SKU validation via `Rule::exists('products', 'sku')`; quantity_int 1..9999 range; customer_email `email` validator; status enum restricted via `Rule::in(...)` |
| V6 Cryptography | partial | No new crypto in Phase 11 (PDF is not signed; e-signature deferred); existing Laravel session encryption applies |
| V8 Data Protection | yes | `customer_email`/`customer_name`/`billing_address` are PII — covered by existing GDPR `gdpr:erase-bitrix-customer` command (extend to scrub Quotes alongside Bitrix erasure in a follow-up; OUT OF SCOPE per CONTEXT.md deferred) |
| V13 API & Web Service | yes | No new public API in Phase 11 (D-09 — zero public-facing URLs); QuoteResource is admin-only |

### Known Threat Patterns for Quote Flow

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Sales approves own quote (privilege escalation) | Elevation | D-04 separation-of-duties; Filament action `->visible(...)` UI gate + `->authorize('approve_quote')` server-side gate; QuotePolicy::approve checks role NOT user_id |
| Quote price snapshot tampering (changing line price after `sent`) | Tampering | D-13 immutability observer throws on saving; Pest `QuoteLineImmutabilityTest` locks invariant |
| Bitrix Deal duplicate creation on approval re-fire | Tampering | QUOT-07 idempotency via `UF_CRM_WOO_QUOTE_ID` + BitrixEntityMap `quote_deal` lookup |
| Forged quote_push_failed Suggestion approval triggers unauthorized push | Elevation | SuggestionPolicy gates approval to admin; ApplySuggestionJob is internal-only (no public webhook trigger) |
| PDF leak of internal margin / supplier price | Information Disclosure | PDF reads only `unit_price_pence_at_quote` (final retail) + `product_snapshot` (name/brand/category — NOT supplier_price); no margin or competitor data exposed |
| Customer signature block exposes quote to forgery | Repudiation | v1 = printed signature line only; e-signature deferred. Audit trail covers status transitions via activity_log. |
| Mass-assignment on quote.user_id (account-link spoofing) | Spoofing | `Quote::$fillable` includes `user_id` only when set by the Filament form; the form's user-picker is permission-gated; non-admin sales cannot pick arbitrary users |
| Email PDF to wrong customer | Information Disclosure | `QuoteSentMail::to($quote->customer_email)` — verify customer_email matches a real address; Filament form validates `email` rule |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `unit_price_pence_at_quote` is stored VAT-INCLUSIVE; PDF strips VAT at render time | §Pattern 2 + §Quote Totals | Storage convention mismatch — alternative is store ex-VAT and add at render. Either is consistent if applied consistently. **Planner must lock this in Plan 11-02.** |
| A2 | `BitrixEntityMap.entity_type='quote_deal'` requires no migration (VARCHAR column accepts new value) | §Pitfall 3 | If column has CHECK constraint or ENUM, Plan 11-01 needs an additional migration. Verify by reading `bitrix_entity_map` table schema first. |
| A3 | Bitrix `TYPE_ID=QUOTE` is creatable in any Bitrix tenant via admin UI (CRM → Settings → Deal Types) | §Pitfall 2 + §Pre-Cutover Probe | Some Bitrix licenses (Free / Self-hosted Express) may restrict deal type creation. Operator must verify with Bitrix24 docs for their tier. |
| A4 | `crm.deal.productrows.set` accepts up to ~250 rows per call | §Bitrix Line-Item Comparison | Quotes >250 lines would fail — but D-12 caps quantity at 9999 per LINE, and quotes are typically 5-30 lines per ops report. Risk: low. |
| A5 | `BitrixEntityMap` schema can store quote ULIDs (CHAR(26)) — likely needs `quote_id` nullable column added in Plan 11-01 OR the `bitrix_id` column is already wide enough | §Idempotency | If `bitrix_id` is VARCHAR(64), it fits ULID (26 chars) but the existing `woo_id` INT semantics break for quotes. Recommendation: add nullable `quote_id` ULID column. **Planner decides in Plan 11-01.** |
| A6 | spatie/laravel-pdf v2.7 OR v2.8 are both compatible | §Standard Stack | Latest is 2.8.0 (released 2026-04-27, 4 days before this research). CONTEXT.md says v2.7 — pinning `^2.8` takes latest; pinning `^2.7` accepts 2.7.x but not 2.8.x. Either works with PHP ^8.2 + Laravel ^12. |
| A7 | `LARAVEL_PDF_DRIVER=dompdf` is the env var name | §Standard Stack | Verified against spatie docs [CITED: spatie.be/docs/laravel-pdf/v2/installation-setup]. |
| A8 | The `crm.dealtype.list` REST endpoint exists OR equivalent (`crm.status.list` with `ENTITY_ID=DEAL_TYPE`) | §Pitfall 2 | Bitrix uses `crm.status.list` for status enums; deal types may surface via `crm.dealcategory.list` (which IS in the SDK at vendor/.../DealCategory.php). Plan 11-04 must verify the exact endpoint. |
| A9 | `customer_group_name_at_quote` is needed as a denormalised string column | §Schema Design + §Pitfall 6 | If renaming a CustomerGroup is rare, the FK + JOIN may be acceptable. CONTEXT.md "Claude's Discretion" recommends denormalisation; planner can drop if rename is provably never going to happen. |

## Open Questions (RESOLVED)

1. **Quote total column placement.**
   - What we know: D-13 mandates line snapshot immutability; CONTEXT.md doesn't explicitly add a `quotes.total_pence_at_quote` column.
   - What's unclear: Do we cache total on Quote, or always SUM at read time?
   - Recommendation: Add `total_pence_at_quote` column + Quote model observer that recomputes on save (draft only). After `status=sent` it's frozen alongside lines. Avoids N+1 SUM queries on the QuoteResource list view.
   - RESOLVED: Plan 11-01 ships quotes.total_pence_at_quote cached integer column with draft-only recompute observer (Plan 11-02).

2. **`BitrixEntityMap` schema extension shape.**
   - What we know: Phase 4's existing schema uses `entity_type` + `woo_id` (INT) as the dedup key.
   - What's unclear: Quote.id is a ULID (CHAR(26)), not an INT. How do we wedge ULID into the INT column? Options: (a) add nullable `quote_id` CHAR(26) column with composite `(entity_type, quote_id)` unique key; (b) use a hash of ULID as the INT woo_id (collision-prone); (c) reuse `bitrix_id` column as the lookup key (semantic mess).
   - Recommendation: **Option (a) — add nullable `quote_id` ULID column** in Plan 11-01 migration. The existing `(entity_type, woo_id)` UNIQUE stays for orders; new `(entity_type, quote_id)` UNIQUE is added for quote_deal rows. Cleanest separation; minimal v1 disruption.
   - RESOLVED: Plan 11-01 Option (a) — added nullable quote_id CHAR(26) ULID column + composite UNIQUE(entity_type, quote_id) coexists with existing UNIQUE(entity_type, woo_id) for orders.

3. **PDF storage on push retry.**
   - What we know: D-04 emails the rendered PDF on approve; CONTEXT.md says PDF is generated on-demand (not stored).
   - What's unclear: If the email fails (SMTP outage), do we re-render the PDF on retry? Or store it once + reuse?
   - Recommendation: Re-render on retry (deterministic — snapshot integrity guarantees identical output). Storage adds disk lifecycle complexity for a problem that doesn't exist in v1.
   - RESOLVED: Plan 11-04 QuotePdfRenderer re-renders deterministically per snapshot guarantee (no PDF persistence — regenerate on every push attempt).

4. **Bitrix Deal contact_id resolution.**
   - What we know: Phase 4 PushOrderToBitrixJob resolves Contact via `EntityDeduper::findOrCreateContact(wooCustomerId, ...)` from order's billing email.
   - What's unclear: Quote may have NO `user_id` (anonymous lead per D-01) — what Contact is attached to the Bitrix Deal?
   - Recommendation: Plan 11-04 calls `EntityDeduper::findOrCreateContact(0, [email => $quote->customer_email, ...])` with `wooCustomerId=0` sentinel. Phase 4 EntityDeduper already handles missing wooCustomerId by deduping on email. Verify in plan.
   - RESOLVED: Plan 11-04 PushQuoteToBitrixDealJob resolves contact via EntityDeduper::findOrCreateContact(0, [email => quote.customer_email]) — anonymous lead path with sentinel woo_customer_id=0.

5. **`quote_push_failed` Suggestion auto-retry policy.**
   - What we know: `quote_push_failed` Suggestion is written on push exhaustion / 4xx fail-fast.
   - What's unclear: Should `QuotePushRetryApplier` retry once and then escalate, or unlimited retries until admin intervention?
   - Recommendation: Match `CrmPushRetryApplier` shape — admin clicks Approve on the Suggestion → applier dispatches a fresh `PushQuoteToBitrixDealJob`. No auto-retry from the Suggestion itself. Operator-driven recovery loop.
   - RESOLVED: Plan 11-05 ships QuotePushRetryApplier (clones Phase 4 CrmPushRetryApplier shape) — operator-driven Replay action only; no auto-retry from Suggestion itself.

## Project Constraints (from CLAUDE.md)

`./CLAUDE.md` is symlinked to the RAMS Platform project (different app). The actual MeetingStore Ops constraints come from `.planning/PROJECT.md` (loaded above). Relevant constraints applied to Phase 11:

- **AI usage:** None in Phase 11 (no AI tools; quote pricing is fully deterministic via TradeRuleResolver). Phase 14 will add the `propose_quote` agent tool.
- **Data integrity:** D-13 line snapshot immutability is the headline constraint.
- **Existing pipeline:** Phase 4 BitrixClient + Phase 9 TradeRuleResolver byte-identical (B-03 sha256-baseline tests).
- **Architecture:** Laravel domain layout, thin Filament Resources delegating to services, queue-based push job.
- **SQL security:** No new SQL; all reads via Eloquent. No frontend exposure of internal SKU codes (PDF shows brand+name+SKU, not supplier_price).
- **Output formats:** PDF via spatie/laravel-pdf + DOMPDF.

## Sources

### Primary (HIGH confidence)
- CONTEXT.md (locked decisions D-01..D-13 + Claude's Discretion + Deferred Ideas) — `.planning/phases/11-e2-quote-request-bitrix-deal-flow/11-CONTEXT.md`
- REQUIREMENTS.md QUOT-01..08 — `.planning/REQUIREMENTS.md:64-73`
- ROADMAP.md Phase 11 — `.planning/ROADMAP.md:109-122`
- Phase 4 BitrixClient — `app/Domain/CRM/Services/BitrixClient.php` (full file read; pattern verified)
- Phase 4 PushOrderToBitrixJob — `app/Domain/CRM/Jobs/PushOrderToBitrixJob.php` (full file read; clone target verified)
- Phase 4 BitrixEntityMap — `app/Domain/CRM/Models/BitrixEntityMap.php` (schema verified)
- Phase 4 BitrixBootstrapCommand — `app/Domain/CRM/Console/Commands/BitrixBootstrapCommand.php` (extension target verified)
- Phase 4 CrmPushRetryApplier — `app/Domain/CRM/Appliers/CrmPushRetryApplier.php` (clone shape verified)
- Phase 9 TradeRuleResolver — `app/Domain/TradePricing/Services/TradeRuleResolver.php` (decorator extension target)
- Phase 9 09-02-SUMMARY.md / 09-04-SUMMARY.md — TradeRuleResolver shape + customer_group sync pipeline
- Phase 3 PriceCalculator — `app/Domain/Pricing/Services/PriceCalculator.php` (compute + stripVat verified)
- Phase 1 SuggestionApplier contract — `app/Domain/Suggestions/Contracts/SuggestionApplier.php`
- Phase 1 Suggestion model — `app/Domain/Suggestions/Models/Suggestion.php` (HasUlids + STATUS_* constants)
- Bitrix24 SDK DealProductRows — `vendor/bitrix24/b24phpsdk/src/Services/CRM/Deal/Service/DealProductRows.php` (line-item shape verified)
- Bitrix24 SDK DealCategory — `vendor/bitrix24/b24phpsdk/src/Services/CRM/Deal/Service/DealCategory.php` (deal-type endpoints exist)
- Horizon config — `config/horizon.php:190` (`crm-bitrix-supervisor` already configured tries=5/timeout=120)
- Composer.json — version pins for v1 baseline + missing spatie/laravel-pdf confirmed
- depfile.yaml + deptrac.yaml — existing Deptrac layer structure

### Secondary (MEDIUM confidence)
- spatie/laravel-pdf v2.8 release date 2026-04-27 — Packagist [WebFetch verified]
- DOMPDF driver requirements (composer require dompdf/dompdf, no binaries) — spatie docs [CITED: spatie.be/docs/laravel-pdf/v2/installation-setup]
- spatie/laravel-pdf PHP ^8.2 + Laravel ^11|^12|^13 compat — Packagist [WebFetch verified]

### Tertiary (LOW confidence — flagged for verification)
- Bitrix `TYPE_ID=QUOTE` is NOT a built-in system code (per WebSearch results showing examples use `COMPLEX`, `GIG`, `GOODS`, `SALE`) — needs operator verification on the actual Bitrix tenant via `crm.dealcategory.list` or `crm.status.list?ENTITY_ID=DEAL_TYPE` probe. **Plan 11-04 pre-flight check resolves this.**
- `BitrixEntityMap.entity_type` column accepts new VARCHAR value `quote_deal` without migration — assumed VARCHAR not ENUM; verify by reading the actual table schema before Plan 11-01 lands.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — composer pins verified against composer.json; spatie/laravel-pdf installation verified via WebFetch
- Architecture: HIGH — pattern clone targets (PushOrderToBitrixJob, CrmPushRetryApplier, BitrixClient) all read in full; Phase 4 + Phase 9 SUMMARY files anchor the decisions
- Pitfalls: MEDIUM-HIGH — 7 pitfalls documented with concrete avoidance steps; A2/A3/A8 are flagged ASSUMPTIONS that need Plan-level verification
- Bitrix line-item modelling: HIGH — SDK source verified; ROADMAP research flag CLOSED with recommendation Approach A
- Plan breakdown: HIGH — matches CONTEXT.md proposal; file-count estimates calibrated against Phase 9

**Research date:** 2026-05-01
**Valid until:** 2026-05-31 (30 days — stack stable; only flag is whether spatie/laravel-pdf releases v2.9 with breaking changes)
