# MeetingStore Ops

## What This Is

Standalone Laravel 12 + Filament 3 application that replaces two legacy WordPress plugins on `meetingstore.co.uk` (WooCommerce): the in-house **Stock Updater** (supplier price/stock sync + competitor CSV comparison) and the third-party **WooCommerce → Bitrix24 CRM Integration** by itgalaxycompany (stuck on v1.50.1 because sanctions block updates). The new app becomes the source of truth for product data, pricing, competitor intelligence and CRM sync — WordPress/WooCommerce is reduced to a pure shop frontend.

## Core Value

**One Laravel app owns product data, pricing rules, competitor intelligence and CRM sync — Woo is the display layer, nothing more.** If this fails, the business loses its daily supplier sync (stock goes stale) and its CRM pipeline (leads don't land in Bitrix24). Everything else — dashboards, auto-created products, pricing UX — is secondary to that source-of-truth guarantee.

## Requirements

### Validated

<!-- Shipped and confirmed valuable. -->

(None yet — greenfield build; ship to validate)

### Active

<!-- Current scope. Building toward these. -->

**A. Supplier sync** (replaces Stock Updater plugin)
- [ ] Daily pull of all Woo products' SKU+price+stock from `21stcav.com` API (JWT-authed)
- [ ] Writes to Woo via REST only — never direct WP DB writes
- [ ] Missing-SKU handling: set Woo product to `pending` unless tagged `custom-ms` (stays `publish`)
- [ ] Preserve `_exclude_from_auto_update` opt-out flag
- [ ] Chunked/resumable sync with last-processed-ID tracking
- [ ] Emails admin CSV report on completion

**B. Pricing engine** (new capability)
- [ ] `PricingRule` model scoped by brand / category / brand+category
- [ ] Most-specific wins: brand+category > category > brand > default tier (no stacking)
- [ ] Default tiers kept from old plugin: <£100, £100–499, £500+
- [ ] Formula: `final_price = round(supplier_price × (1 + margin%/100) × 1.2, 2)` (×1.2 = UK VAT)
- [ ] Per-product override preserved (matches current `buy_price_percentage_to_add` meta)
- [ ] Rule explorer in dashboard — preview effective price for any SKU

**C. Competitor analysis** (replaces Stock Updater CSV ingest, adds analytics)
- [ ] Watches `storage/competitors/` for n8n-dropped CSV files
- [ ] Auto-detects `sku`/`mpn` + `price` columns; strips currency symbols; divides by 1.2 to remove VAT
- [ ] Persists to `competitor_prices` with full history (old plugin truncated daily — we keep everything)
- [ ] Computes margin delta vs supplier price; suggests new margin % when competitor margin ≥ threshold (default 8%)
- [ ] Dashboard: price trend charts, biggest deltas, per-competitor views

**D. Product auto-create** (new capability)
- [ ] Trigger: new SKU in supplier DB with no matching Woo product
- [ ] SEO template mirrors current Meeting Store product page layout
- [ ] Populates: title pattern, slug, meta description, long description, brand + category taxonomy, image
- [ ] Image sourcing: supplier DB where available, otherwise placeholder + manual-review flag
- [ ] Queue-based (`CreateWooProductJob`) with retry + audit log
- [ ] Draft-first vs immediate-publish workflow TBD in planning

**E. Bitrix24 CRM sync** (replaces itgalaxy plugin — one-way subset)
- [ ] On order creation → create Deal + Contact + Company in Bitrix24
- [ ] On customer registration → create/update Contact
- [ ] Multiple deal pipelines supported
- [ ] Dynamic field mapping — fetch `crm.deal.fields` / `crm.contact.fields` / `crm.company.fields`, map via Filament UI
- [ ] Order notes synced to Deal comments
- [ ] Bulk historical-order backfill via artisan command
- [ ] Capture UTM parameters + GA Client ID on checkout → push to Bitrix custom fields
- [ ] Audit log of every CRM push attempt (request + response + retry history)

**F. Dashboard** (Filament admin)
- [ ] Supplier sync status — last run, duration, updated/failed counts, per-SKU drill-down
- [ ] Import issues — SKUs missing at supplier, pending products, products with missing cost/price
- [ ] Competitor analysis — current prices per SKU, margin history chart, top deltas
- [ ] Pricing rules — CRUD + effective-price preview
- [ ] CRM push log — order/customer sync attempts, failures, retries
- [ ] Failed jobs — Horizon integration

**G. Cutover**
- [ ] Parallel running with existing plugins for a monitored window
- [ ] Disable Stock Updater + itgalaxy plugins only after Laravel sync proves stable
- [ ] Handover docs for ops team

### Out of Scope

<!-- Explicit boundaries. Reasoning captured to prevent re-adding. -->

- **Two-way CRM sync (Bitrix24 → Woo)** — itgalaxy plugin supports it but we're dropping that direction; no inbound Bitrix webhook
- **Coupon list sync** — itgalaxy feature we don't use
- **Delayed CRM send via cron** — we push immediately on order/customer events
- **WooCommerce checkout/cart/frontend replacement** — Woo stays as shop frontend
- **Customer-facing features** — this is an internal ops tool
- **Multi-store support** — single store (meetingstore.co.uk) only for v1
- **Manual overrides in Woo admin** — Laravel is source of truth; Woo admin changes will be overwritten on next sync (documented behaviour, not a bug)
- **Roistat / Yandex Metrika analytics** — Russian analytics stack, unused
- **Post-v1 tier features** (Merchant Center feeds, GA4 integration, AI agents, stock forecasting, B2B pricing) — documented in brief but explicitly deferred to Phases 8+

## Context

**Why now:**
1. itgalaxy plugin cannot be legally updated (procurement blocked by sanctions) — stuck on June 2024 v1.50.1 while upstream is v1.67.2; security + feature risk growing
2. Stock Updater is a ~1,700-line single-file WP plugin — brittle, hard to extend
3. Pricing tied to flat margin tiers — no rules per brand/category/vendor
4. No unified dashboard — competitor data, sync issues, CRM pushes all live in different places
5. Product creation is manual — new supplier SKUs published to Woo one at a time

**External systems:**

| System | Purpose | Direction | Auth |
|---|---|---|---|
| `21stcav.com/api/index.php` | Central supplier SKU/price DB (updated daily) | Laravel ← API | JWT (`/generate_token.php`) |
| WooCommerce REST API | Push products, prices, stock, content, images | Laravel → Woo | Consumer key/secret (to be generated) |
| WooCommerce webhooks | Receive order + customer events | Woo → Laravel | HMAC signature |
| Bitrix24 CRM REST | Push deals/contacts/companies | Laravel → Bitrix24 | Inbound webhook URL |
| n8n competitor scraping | CSV files dropped in storage dir | Laravel reads disk | File-based |

**Hosting:** Same VPS as `meetingstore.co.uk` WooCommerce install. Likely subdomain `ops.meetingstore.co.uk`.

**Relationship to existing 21CAV Rams2 platform:** Separate app, separate DB. No runtime coupling in v1. Future Phase 12 may add a RAMS integration hook for Meeting Store customers who are also 21CAV installation clients.

## Constraints

- **Tech stack**: Laravel 12, PHP 8.2+, Filament 3, Horizon + Redis queues, MySQL, Blade/Livewire via Filament, PHPUnit + Pest — fixed by user decision
- **WooCommerce integration**: REST only, never direct WP DB writes — future channel expansion (Shopify, Amazon) depends on this discipline
- **Event-driven sync**: Emit domain events from day one (`ProductPriceChanged`, `StockWentToZero`, `OrderCreated`) — future agents/feeds subscribe without touching core
- **Audit everything**: Every sync, push, rule application stored with full context — required for future AI-agent phases
- **Suggestions pattern**: Any data-changing feature writes to a `suggestions` table first when `auto_apply=false` — makes future agents trivial to bolt on
- **Feed abstraction**: Build future Merchant Center feed as generic `FeedGenerator` interface so Meta/Bing/Amazon slot in
- **Compliance**: itgalaxy plugin cannot be updated due to procurement/sanctions rules — the migration is partly compliance-driven, not just tech-debt cleanup
- **Parity first**: Cutover must run in parallel with old plugins before they're disabled — data-loss risk is unacceptable

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Laravel owns all product/pricing/CRM data; Woo is display layer only | Enables future multi-channel expansion without core rewrite | — Pending |
| One-way CRM sync (Woo → Bitrix24); no inbound webhook | itgalaxy's two-way sync is the feature we're most willing to drop; reduces complexity | — Pending |
| Deal + Contact + Company entity mode (fixed, not configurable for v1) | Covers 100% of current usage; configurability adds UI burden for zero real benefit | — Pending |
| Most-specific pricing rule wins (no stacking) | Predictable, auditable, matches how the pricing manager thinks about rules | — Pending |
| Capture UTM + GA Client ID; skip Roistat/Yandex Metrika | UK market; Russian analytics stack unused | — Pending |
| Same VPS as WooCommerce install, `ops.meetingstore.co.uk` subdomain | Keeps latency to Woo API low; reuses existing infra | — Pending |
| Laravel 12 + Filament 3 + Horizon stack (fixed) | User preference; matches existing 21CAV Laravel ecosystem | — Pending |
| Domain events + audit log + suggestions pattern from day one | Non-negotiable if Phase 10 AI agents are to compound on v1 data | — Pending |

## Open Items (for phase-level planning)

Flagged in PROJECT-BRIEF.md — resolve at `/gsd-discuss-phase` or `/gsd-plan-phase` for the relevant phase, not here:

- Woo → Laravel webhook security (HMAC secret management)
- Image handling for auto-created products (what does the supplier DB actually return?)
- Draft vs immediate-publish for new products
- Rollback strategy if sync misbehaves in production
- Retention policy for audit logs and competitor history
- User roles (admin only, or separate sales/ops roles?)
- Email notification distribution list (keep current, or change?)

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-04-18 after initialization*
