# MeetingStore Ops — Project Brief

> Paste this brief into `/gsd-new-project` when initialising this project in Claude Code.

## What it is

Standalone Laravel 12 + Filament 3 application that replaces two legacy WordPress plugins on `meetingstore.co.uk` (WooCommerce):

- **Stock Updater** v1.5 (custom, by Michael Kitt & S M Khirul Alam) — supplier price/stock sync + competitor CSV comparison
- **WooCommerce → Bitrix24 CRM Integration** v1.50.1 by **itgalaxycompany** (Russian vendor — cannot be updated due to sanctions/procurement compliance; stuck on June 2024 version while current is v1.67.2)

The new app becomes the **source of truth** for product data, pricing, competitor intelligence, and CRM sync. WordPress/WooCommerce becomes a pure shop frontend.

## Why rebuild

1. The itgalaxy plugin cannot be legally updated → security and feature risk growing
2. The Stock Updater plugin is a ~1,700-line single-file WP plugin — brittle, hard to extend
3. Pricing logic is tied to flat margin tiers — no way to set rules per brand/category/vendor
4. No unified dashboard — competitor data, stock sync issues, and CRM pushes all sit in different places
5. Product creation is manual — new supplier SKUs need to be published to Woo one at a time

## Hosting

- Same VPS as `meetingstore.co.uk` WooCommerce install
- Likely subdomain: `ops.meetingstore.co.uk`

## External systems

| System | Purpose | Direction | Auth |
|---|---|---|---|
| `21stcav.com/api/index.php` | Central supplier SKU/price DB (updated daily) | Laravel ← API | JWT (token from `/generate_token.php`) |
| WooCommerce REST API | Push products, prices, stock, content, images | Laravel → Woo | Consumer key/secret (to be generated) |
| WooCommerce webhooks | Receive order + customer events | Woo → Laravel | HMAC signature |
| Bitrix24 CRM REST | Push deals/contacts/companies | Laravel → Bitrix24 | Inbound webhook URL |
| n8n competitor scraping | CSV files dropped in storage dir | Laravel reads disk | File-based |

## Feature scope

### A. Supplier sync (replaces Stock Updater plugin)

- Daily pull of all Woo products' SKU+price+stock from `21stcav.com` API
- Updates Woo via REST (not via direct WP DB writes)
- Handles missing SKUs: sets Woo product to `pending` (unless tagged `custom-ms` → stays `publish`)
- Preserves `_exclude_from_auto_update` opt-out flag
- Chunked/resumable sync with last-processed-ID tracking
- Emails admin CSV report on completion

### B. Pricing engine (new capability)

- `PricingRule` model with scope: **brand**, **category**, or **brand + category**
- Most-specific wins: `brand+category` > `category` > `brand` > default tier
- Default tiers kept from old plugin: <£100, £100–499, £500+
- Formula: `final_price = round(supplier_price × (1 + margin%/100) × 1.2, 2)` (×1.2 = UK VAT)
- Per-product override still supported (matches current `buy_price_percentage_to_add` meta)
- Rule explorer in dashboard — preview effective price for any SKU

### C. Competitor analysis (replaces Stock Updater's CSV ingest, adds analytics)

- Watches `storage/competitors/` for CSV files dropped by n8n
- Auto-detects `sku`/`mpn` + `price` columns (same logic as current plugin)
- Strips currency symbols; divides by 1.2 to remove VAT
- Persists to `competitor_prices` with full history (new — old plugin truncated daily)
- Computes margin delta vs our supplier price
- Suggests new margin % when competitor margin ≥ configured threshold (default 8%)
- Dashboard: price trend charts, biggest deltas, competitor-by-competitor views

### D. Product auto-create (new capability)

- Trigger: new SKU appears in supplier DB that doesn't exist in our Woo
- Applies SEO template — mirrors current Meeting Store product page layout
- Fields to populate: title pattern, slug, meta description, long description, brand + category taxonomy, image
- Image sourcing: from supplier DB where available, otherwise placeholder + flag for manual review
- Queue-based (`CreateWooProductJob`) with retry + audit log
- Draft-first review workflow? (TBD during planning)

### E. Bitrix24 CRM sync (replaces itgalaxy plugin — subset)

**One-way only** (Woo → Bitrix24). Explicitly excluded:
- Two-way status sync (no inbound webhook from Bitrix)
- Delayed send via cron (we send immediately)
- Coupon list sync

**Entity mode:** Deal + Contact + Company (fixed, not configurable for v1)

**Included:**
- On order creation → create Deal + Contact + Company in Bitrix24
- On customer registration → create/update Contact
- Multiple deal pipelines supported
- Custom Bitrix24 field mapping (dynamic — fetch `crm.deal.fields` / `crm.contact.fields` / `crm.company.fields` and let admin map Woo data to them via Filament)
- Order notes synced to Deal comments
- Bulk historical-order backfill (artisan command)
- Track UTM parameters and GA Client ID on checkout, push to Bitrix custom fields
- Audit log of every CRM push attempt (request + response + retry history)

### F. Dashboard (new capability)

Filament admin pages:
- **Supplier sync status** — last run, duration, updated/failed counts, per-SKU drill-down
- **Import issues** — SKUs not found at supplier, pending products, products with missing cost/price
- **Competitor analysis** — current competitor prices per SKU, margin history chart, top deltas
- **Pricing rules** — CRUD + effective-price preview
- **CRM push log** — order/customer sync attempts, failures, retries
- **Failed jobs** — Horizon integration

## Technology stack

| Layer | Choice |
|---|---|
| Framework | Laravel 12 |
| PHP | 8.2+ |
| Admin/Dashboard | Filament 3 |
| Queues | Laravel Horizon + Redis |
| DB | MySQL (separate DB from Woo) |
| Scheduling | Laravel scheduler (cron entry) |
| Woo API | `automattic/woocommerce` PHP client |
| Bitrix24 | Custom `BitrixClient` service (REST) |
| Frontend | Blade + Livewire (via Filament) |
| Testing | PHPUnit + Pest |

## Non-goals (v1)

- No two-way CRM sync
- No replacement for WooCommerce checkout/cart/frontend
- No replacement for the WooCommerce-Bitrix24 status sync direction (CRM → Woo)
- No coupon sync
- No customer-facing features
- No multi-store support (single store: meetingstore.co.uk)
- No manual override of Woo data in Woo admin (Laravel is source of truth; changes in Woo admin will be overwritten on next sync)

## Build phases (proposed)

1. **Foundation** — Laravel + Filament scaffold, auth, base models (`Product`, `Brand`, `Category`, `Supplier`), Woo REST client, Horizon
2. **Supplier sync** — replaces Stock Updater daily cron (lowest risk, immediate value)
3. **Pricing engine** — brand/category rules applied during sync
4. **Competitor module** — CSV ingest + analytics dashboard
5. **Product auto-create** — SEO template + Woo REST create
6. **Bitrix24 sync** — order + customer + bulk backfill + field mapping UI
7. **Cutover** — disable Stock Updater + itgalaxy plugins, monitor parallel running, hand over

## Decisions already made

- Laravel owns all product/pricing/CRM data; Woo is display layer only
- One-way CRM sync; no inbound webhook from Bitrix
- Deal + Contact + Company entity mode (fixed for v1)
- UTM + GA Client ID captured; roistat/Yandex Metrika ignored (Russian analytics, not used)
- Most-specific pricing rule wins (no stacking)
- Same VPS as WooCommerce install
- Hosted at `ops.meetingstore.co.uk` (subdomain)

## Open items for planning phase

- Woo → Laravel webhook security (HMAC secret management)
- Image handling for auto-created products (what does supplier DB return?)
- Draft vs immediate publish for new products
- Rollback strategy if sync misbehaves in production
- Retention policy for audit logs and competitor history
- User roles (admin only, or separate sales/ops roles?)
- Email notifications — keep same distribution list as current plugin?

---

## Future phases (post-v1 roadmap candidates)

> v1 (Phases 1–7) ships parity rebuild + the pricing/competitor/dashboard capabilities. These future phases compound on top of the data foundation v1 creates. Structure v1 code so these can bolt on cleanly.

### Phase 8 — Channel feeds & ad integration (Tier 1)

Revenue / saving play using data you'll already own.

| Feature | Description |
|---|---|
| **Google Merchant Center feed** | Auto-generated product feed from Laravel source of truth — always in sync with prices, stock, content. Eliminates manual feed maintenance. Opens Google Shopping ads. |
| **Google Ads sync** | Push product feed, auto-pause ads on stock-out, adjust bids on high-margin movers. Reuses `BitrixClient`-style service pattern but for Google Ads API. |
| **GA4 integration** | Pull revenue, conversion rate, bounce rate, click data per SKU/category/brand into the dashboard. Enables profit-per-click, not just margin analysis. |
| **Meta / Facebook catalog feed** | Same dataset, Meta Ads channel. |
| **Google Search Console** | Pull impressions/clicks/CTR per product URL for SEO agent (Phase 10). |

### Phase 9 — Customer-facing automation (Tier 1)

Direct revenue lift from signals already captured.

| Feature | Description |
|---|---|
| **Abandoned cart recovery** | Bitrix24 Deal `stale` trigger → email sequence. Already have the deal in CRM from v1. |
| **Back-in-stock alerts** | Customer subscribes on out-of-stock PDP → Laravel fires email on next supplier sync where stock > 0. |
| **Price-drop alerts** | Wishlist subscribers get notified when price drops below their threshold. |
| **Review aggregation** | Pull Trustpilot / Google reviews, expose to Woo frontend and Merchant Center feed. |

### Phase 10 — AI agent framework + first agents (Tier 2)

Agents write to `suggestions` table, admin reviews in Filament inbox, one-click approve → Laravel executes.

Pattern: each agent = `AgentContract` + `SuggestionGenerator` + `SuggestionApplier`. Build once, reuse.

| Agent | Reads | Suggests |
|---|---|---|
| **Pricing agent** | Competitor deltas + GA conversion rate + current margin + stock level | Margin adjustments with rationale (e.g. "drop from 18% → 14% — competitor £40 under, converting at half category avg") |
| **Ad optimisation agent** | GA4 + Google Ads spend + product margin | Pause underperforming keywords, raise bids on profitable ones, flag products that shouldn't be advertised (negative margin after ad spend) |
| **SEO / content agent** | GA organic traffic + Search Console impressions/CTR + current product content | Title/meta/description rewrites for pages with impressions but low CTR |

### Phase 11 — Forecasting & operational intelligence (Tier 3)

| Feature | Description |
|---|---|
| **Stock planning agent** | Sales velocity + supplier lead time + historical stockouts → reorder-point suggestions |
| **Sales forecasting** | Time-series model on order history, drives stock planning and cash flow projection |
| **Supplier performance dashboard** | Lead time variance, stockout frequency, price stability, delivery accuracy — reveals which suppliers are actually costing you |
| **True profitability per SKU** | Margin minus ad spend minus fulfilment cost — flag loss-making "profitable-looking" products |

### Phase 12 — B2B & channel expansion (Tier 4)

| Feature | Description |
|---|---|
| **B2B account pricing** | Logged-in trade customers see negotiated prices. Pricing engine already supports customer-scoped rules conceptually. |
| **Quote request flow** | "Request quote" CTA on big-ticket items → Bitrix24 Deal → sales follow-up. Reuses v1 CRM push. |
| **WhatsApp Business integration** | Order notifications + quote follow-ups pushed via Bitrix24. |
| **AI product-finder chatbot** | Trained on catalogue, qualifies leads, hands off to Bitrix24 sales. |
| **RAMS platform hook** | If a Meeting Store customer is also a 21CAV installation client, pre-populate their RAMS project data on purchase (integration with existing 21CAV RAMS Laravel app). |

### Design principles for v1 to enable future phases

- **Laravel is source of truth** — never write to Woo DB directly; always via REST. Keeps future channels (Shopify, Amazon, eBay) a config change, not a rewrite.
- **Event-driven sync** — emit domain events (`ProductPriceChanged`, `StockWentToZero`, `OrderCreated`, etc.) from day one. Future agents/feeds subscribe without touching core sync code.
- **Audit everything** — every sync, push, rule application stored with full context. Agents need historical data to suggest anything useful.
- **Suggestions pattern** — any feature that changes data writes to a `suggestions` table first when `auto_apply=false`. Makes agents trivial to add later (they just populate the same table).
- **Feed abstraction** — build Merchant Center feed as a generic `FeedGenerator` interface so Meta / Bing / Amazon feeds slot in without duplication.

---

## How to use this brief

1. Open Claude Code in this directory (`C:\Users\sonny.tanda\Documents\1 - Laravel Projects\meetingstore-ops\`)
2. Run `/gsd-new-project`
3. When it asks for project context, point it at this file (`PROJECT-BRIEF.md`)
4. It will spawn research agents, then draft `PROJECT.md` + `ROADMAP.md` covering the 7 build phases
