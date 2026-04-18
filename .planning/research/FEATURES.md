# Feature Research — MeetingStore Ops

**Domain:** Laravel ops app acting as source-of-truth PIM / pricing / CRM-sync hub sitting over a WooCommerce B2B AV store, an external supplier API, Bitrix24 CRM, and n8n competitor scraping.
**Researched:** 2026-04-18
**Confidence:** HIGH on WooCommerce + Bitrix24 + Filament ecosystems (verified multiple sources, official docs). MEDIUM on exact volume/latency thresholds for this specific store (single-source assumptions from brief). LOW on internal ops staff workflow (no user research conducted).

---

## How to read this document

The brief already fixes the 6 feature modules (A–F) and explicitly lists what's in scope per module. This research does **not** re-invent that scope. Instead, every listed feature is categorized:

- **TS** = Table stakes — ops staff expect this, parity with old plugins requires it, or WooCommerce/Bitrix24 integration tooling universally provides it. Missing = revolt / data goes stale / cutover fails.
- **DIFF** = Differentiator — the new app's edge over the two legacy plugins. Directly ties to the Core Value ("one dataset, audited, event-driven").
- **GAP** = Feature the brief missed but every comparable tool in this ecosystem has. Add these to scope.
- **ANTI** = Non-goal — either already in the brief's "Out of Scope" list, or something this research recommends staying out of.

Complexity is S / M / L (small = <2 days, medium = ~1 week, large = multi-week or cross-module).

---

## Module A — Supplier sync (replaces Stock Updater)

### A.1 Brief items categorized

| # | Feature | Category | Complexity | Notes |
|---|---|---|---|---|
| A1 | Daily pull of Woo SKUs from `21stcav.com` (JWT) | TS | M | Parity with current plugin; stock going stale = immediate business impact |
| A2 | Woo writes via REST only, never direct DB | TS | S | Constraint, not a feature — but protects future channel expansion |
| A3 | Missing-SKU handling (`pending`, `custom-ms` exempt) | TS | S | Direct parity — ops team has been relying on this behaviour for years |
| A4 | Preserve `_exclude_from_auto_update` opt-out | TS | S | Parity — if lost, sales' manual price tweaks get steamrolled on next sync |
| A5 | Chunked/resumable sync with last-processed-ID | TS | M | Mandatory at Woo REST scale (100 req/min default rate limit, Source 1) |
| A6 | Emails admin CSV report on completion | TS | S | Parity; current plugin does this; ops expect it |

### A.2 Differentiators to add (beyond brief)

| Feature | Value Proposition | Complexity | Notes |
|---|---|---|---|
| **Dry-run mode** on supplier sync | Ops can preview "what would change" before a real run — standard in modern Woo stock-sync tools (Source 7). Critical during cutover to compare against old plugin output. | M | Reuses the same diff engine as the real sync, just suppresses the REST writes. Write results to `sync_runs` + `sync_run_items` for UI review. |
| **Per-run diff snapshot** persisted | Instead of just logging "updated 412 products", persist the before/after per SKU → powers rollback and audit. | M | Required for the rollback open-item already flagged in brief. |
| **Event emission** (`ProductPriceChanged`, `StockWentToZero`, `ProductOutOfSync`) | Explicit in brief constraints. Lets competitor/agent/feed modules subscribe without touching sync code. | S | Add alongside the sync writer; minimal cost up-front, unlocks Phases 8/10. |

### A.3 Gaps the brief missed (ecosystem standard)

| Gap | Why it matters | Complexity | Confidence |
|---|---|---|---|
| **Rate-limit awareness** — throttle Woo REST to ≤100 req/min (with jitter) | WooCommerce REST has a 100/min rate limit on many endpoints (Source 1, 5). A sync of ~2–5k SKUs hitting in a tight loop *will* 429 without this. | S | HIGH |
| **Idempotency keys per SKU update** | "Bulk operations risk partial failure cascades (5% of cases) — mitigate with per-item idempotency keys" (Source 1). Needed so a retried chunk doesn't double-apply price changes during a flaky run. | S | HIGH |
| **Batch endpoint usage** (`POST /products/batch`) instead of per-SKU PUTs | WooCommerce batch endpoints give ~80–90% throughput gains over single calls (Source 1). Current single-file plugin likely doesn't do this; new app should. | M | HIGH |
| **Partial-failure isolation** — one bad SKU payload shouldn't abort the whole chunk | Woo REST returns per-item errors inside a batch response. Must be parsed and logged per-SKU, not treated as whole-batch fail. | S | HIGH |
| **Supplier API circuit breaker** (N failures → pause, alert) | If `21stcav.com` returns garbage or 500s, current plugin happily pushes bad data to Woo. A circuit breaker stops that. | S | MEDIUM — common pattern, not explicitly in brief |
| **"Stale stock" detector** — flag products not touched by sync in N days | Tells ops "this SKU has silently stopped appearing in supplier feed" — different from "explicitly missing" (which is A3). | S | MEDIUM |
| **Manual sync trigger + scope** (single SKU / brand / category from Filament) | Ops need this during cutover and whenever they fix a bad supplier entry. Old plugin likely only has "sync all". | S | HIGH — Filament pattern is trivial |

### A.4 Anti-features (don't build)

| Anti-feature | Why not | Alternative |
|---|---|---|
| Real-time / webhook-driven supplier sync | Supplier DB updates daily; real-time adds complexity with zero business value | Stick to scheduled daily + on-demand manual trigger |
| Multi-supplier support in v1 | Brief is explicit: `21stcav.com` is the only supplier | Design `SupplierClient` as an interface so Phase 11+ can add more, but don't build a second one |
| Auto-retry of supplier API failures inside the same run | Masks supplier-side issues; ops should see the failure and decide | Circuit-break + alert + manual re-run |
| Price-change approval queue before push | Brief is explicit: Laravel is source of truth, Woo gets overwritten. Approval queue would invert that. | Use dry-run + rule explorer for pre-push confidence instead |

---

## Module B — Pricing engine (new capability)

### B.1 Brief items categorized

| # | Feature | Category | Complexity | Notes |
|---|---|---|---|---|
| B1 | `PricingRule` scoped brand / category / brand+category | DIFF | M | Old plugin only has flat tiers — this *is* the product's edge over the legacy plugin |
| B2 | Most-specific wins (no stacking) | DIFF | S | Predictability is the feature; alternative (stacking) would be a usability disaster |
| B3 | Default tiers preserved (<£100, £100–499, £500+) | TS | S | Parity fallback — ensures unconfigured SKUs price identically to today |
| B4 | `final = round(supplier × (1+margin/100) × 1.2, 2)` | TS | S | UK VAT required; rounding rule must match old plugin exactly |
| B5 | Per-product override (`buy_price_percentage_to_add`) | TS | S | Parity; sales have tuned individual SKUs for years |
| B6 | Rule explorer — preview effective price for any SKU | DIFF | M | Explaining *why* a rule fired is the confidence-builder for ops |

### B.2 Differentiators to add

| Feature | Value Proposition | Complexity | Notes |
|---|---|---|---|
| **Pricing rule audit trail** — who/when/what changed | Every rule change persisted with diff. Constraint says "audit everything". | S | `spatie/laravel-activitylog` integrated into Filament (Source 3). |
| **Effective-price calculator shows rule chain** ("brand+cat → brand → cat → default") | Not just "this SKU is £212" — show which rule won and which rules it beat. Kills "why is this price wrong?" tickets. | S | UI work on top of B6; same data. |
| **Simulated price changes before save** — "change Logitech margin from 22% → 20%, show me affected SKUs + revenue impact" | Pricing manager workflow; no old-plugin equivalent. | M | Reuses B6 engine over the whole catalogue. |
| **Minimum-margin floor per rule** | Guards against supplier price going up while margin % drops the retail price below break-even. Standard in repricers (Source 6). | S | HIGH confidence — every competitor tool has this. |

### B.3 Gaps the brief missed

| Gap | Why it matters | Complexity | Confidence |
|---|---|---|---|
| **Rounding/psychological-pricing config** (e.g. always end in `.99` or `.95`) | The current plugin's rounding rule (`round(x, 2)`) is unusual for AV retail — most shops use `.99` endings. Verify with ops what current behaviour is. If they want `.99`, it must go in the formula. | S | MEDIUM — ops-dependent |
| **Rule priority tie-breaker when two equally-specific rules match** | With brand+category rules, edge case: two rules match same brand+cat (e.g. overlapping categories). Need deterministic tiebreaker (most-recently-updated, or explicit priority field). | S | HIGH |
| **Rule validity windows** (start/end date on a rule) | Useful for promotional pricing, Black Friday temporary margin drops, supplier-rebate windows. Not in brief. | S | MEDIUM |
| **What happens when supplier price is zero/null?** | Formula breaks; need explicit skip + alert, not silent £0 push to Woo. | S | HIGH — this is a production landmine |
| **Bulk-recompute command** — "re-apply all pricing rules to all SKUs" (without waiting for next supplier sync) | Needed when a rule is edited and pricing manager wants it reflected now. | S | HIGH |

### B.4 Anti-features

| Anti-feature | Why not | Alternative |
|---|---|---|
| Rule stacking (multiple rules compose) | Already rejected in brief's Key Decisions | Most-specific-wins + explicit override field |
| Per-customer pricing in v1 | Deferred to Phase 12 (B2B tier) | Design `PricingRule` table with a nullable `customer_id` / `customer_group_id` column so v1 always passes `null` and the schema is ready |
| Dynamic / algorithmic pricing (auto-adjust based on competitor) | Phase 10 pricing agent territory | v1 emits suggestions only (the `suggestions` pattern from constraints) |
| Price history on Woo side | Woo doesn't need it; Laravel owns it | Store price history in Laravel (`product_price_history` or via activity log) |

---

## Module C — Competitor analysis

### C.1 Brief items categorized

| # | Feature | Category | Complexity | Notes |
|---|---|---|---|---|
| C1 | Watch `storage/competitors/` for n8n-dropped CSVs | TS | S | Parity with current plugin's ingest mechanism |
| C2 | Auto-detect `sku`/`mpn` + `price` columns | TS | M | Parity; CSV headers vary per competitor |
| C3 | Strip currency symbols, divide by 1.2 (VAT) | TS | S | UK-specific; assumes all competitor sources also include VAT |
| C4 | Full history in `competitor_prices` | DIFF | S | Old plugin truncated daily — keeping history *is* the competitor-module differentiator |
| C5 | Margin delta vs supplier price | DIFF | S | Basic but essential; enables everything in C6 |
| C6 | Suggest new margin % when competitor margin ≥ threshold | DIFF | M | Suggestion only, writes to `suggestions` table per constraints |
| C7 | Dashboard: trend charts, biggest deltas, per-competitor views | DIFF | M | See Module F |

### C.2 Differentiators to add

| Feature | Value Proposition | Complexity | Notes |
|---|---|---|---|
| **Per-competitor CSV schema mapping** saved per competitor | After first successful ingest, remember "Competitor X calls it `part_no`, not `sku`". Standard in every competitive PIM tool. | S | Simple `competitor_csv_mappings` table. |
| **Ingest audit log** — which file, when, how many rows, how many matched to our SKUs, how many orphans | Ops need to know "did yesterday's scrape actually work?" without reading raw CSV. | S | Mirrors sync-run pattern from Module A. |
| **Price-change notifications** — email/Slack when a tracked competitor drops below our price by >X% | Actionable; ops want to know within hours, not next time they check dashboard. Common feature in price-monitoring tools (Source 6). | S | Leverages Spatie failed-job-monitor stack already needed (Source 8). |
| **Product matching confidence score** (exact SKU match vs fuzzy MPN match) | When competitor data doesn't use same SKU, fuzzy-match on MPN/title with a confidence %. Prevents false-match noise. | M | Can start with exact-only (confidence = 1.0) and add fuzzy later. |

### C.3 Gaps the brief missed

| Gap | Why it matters | Complexity | Confidence |
|---|---|---|---|
| **What if CSV has currency column or multi-currency?** | Brief assumes all competitor prices are GBP inc-VAT. Some competitors price excl-VAT or in EUR. Need explicit column detection + per-competitor config. | S | MEDIUM |
| **Orphaned-row handling** — CSV row matches no SKU in our catalogue | Currently a silent drop. Ops should see "Competitor X is tracking 40 SKUs we don't sell — might be product gap" — this is *new product opportunity*, a differentiator. | S | HIGH — turns waste into insight |
| **CSV file retention policy** | Brief flags retention as open item. Recommendation: keep raw CSVs for 90d, parsed rows forever (small data). | S | HIGH |
| **Stale-feed detection** — "Competitor X last scraped 4 days ago" | n8n breaks silently. Dashboard tile showing "last ingest per competitor" catches this. | S | HIGH |
| **MAP (Minimum Advertised Price) monitoring** | For products sold by AV brands with MAP policies (Logitech, Poly, Jabra all have MAP programmes). If *we* breach MAP, vendor can pull distribution. Competitor monitoring should flag MAP breaches on *our* side too. | S | MEDIUM — industry standard (Source 6), depends on whether MS sells MAP-protected brands |
| **Import-preview / validation UI** — "here's what I'd ingest from this file, confirm?" | Protects against a bad n8n scrape corrupting history. | S | MEDIUM — could be deferred if trust in n8n is high |

### C.4 Anti-features

| Anti-feature | Why not | Alternative |
|---|---|---|
| Auto-apply margin suggestions to live prices | Explicit in brief: suggestions only | Suggestion → admin approval → apply (Phase 10 agent pattern) |
| Build a scraper in Laravel | n8n owns scraping; duplicating it in Laravel couples ops to two tech stacks | Let n8n drop CSVs; Laravel ingests. Stay in lane. |
| Competitor real-time price pings | Daily CSV is the data contract; real-time adds complexity with marginal benefit | Faster ingest frequency on n8n side if needed, not app-architecture change |

---

## Module D — Product auto-create

### D.1 Brief items categorized

| # | Feature | Category | Complexity | Notes |
|---|---|---|---|---|
| D1 | Trigger on new SKU in supplier DB with no Woo match | TS | M | Core capability; the whole module's raison d'être |
| D2 | SEO template mirrors current Meeting Store product page layout | TS | M | Consistency across catalogue; SEO juice |
| D3 | Populate title/slug/meta/description/brand/cat/image | TS | M | Parity expectation for any PIM |
| D4 | Image from supplier or placeholder + manual-review flag | TS | M | Supplier image availability unknown — brief flags this as open item |
| D5 | Queue-based with retry + audit log | TS | S | Standard Laravel; Horizon already in stack |
| D6 | Draft-first vs immediate-publish TBD | TS | S | Must be decided before build; recommendation: draft-first for v1, config flag for later |

### D.2 Differentiators to add

| Feature | Value Proposition | Complexity | Notes |
|---|---|---|---|
| **Review inbox in Filament** — "14 new products awaiting approval" | Draft-first workflow needs a single place to approve/edit/reject. Matches the `suggestions` pattern from project constraints. | M | Uses same approval-queue UX that Phase 10 agents will reuse. |
| **Bulk approve + bulk edit** | Brand launches can mean 30+ new SKUs at once. Clicking each is hostile UX. | S | Filament bulk actions built-in. |
| **Title/description templates configurable per brand** | Different brand voices (Logitech vs Jabra vs Poly all have house styles). | M | `brand_content_templates` table. |
| **Duplicate-detection on auto-create** — fuzzy match on MPN before creating | Supplier SKU changes (rebadges, re-releases) often result in "new SKU, same product". Auto-create without dedupe = catalogue bloat. | M | Reuse product-matching scoring from Module C. |

### D.3 Gaps the brief missed

| Gap | Why it matters | Complexity | Confidence |
|---|---|---|---|
| **Required-field completeness score per draft** | Before a draft can go live it needs image, description, category, brand, price. UI should show "72% complete — missing image, category". Standard PIM feature (Source 2). | S | HIGH — every PIM has this |
| **Rejection reason persisted** | If ops rejects a draft, record why (not a real product / duplicate / discontinued). Feeds back into auto-create filter logic. | S | MEDIUM |
| **Auto-skip rules** — never auto-create SKUs from brand/category X, or under price Y | Some supplier SKUs are kits, spares, or test products that shouldn't go on the store at all. | S | HIGH — cuts manual-review burden |
| **Image handling** — resize, strip EXIF, convert WebP, upload to Woo media library via REST | Auto-create with a 4MB JPEG straight from supplier tanks page-load speed. Woo REST `/media` endpoint + `intervention/image` processing. | M | HIGH — confirmed gap (brief marks as open item) |
| **Generated-slug uniqueness check** | Slug collisions with existing Woo products will 409. Check before POST or handle the retry gracefully. | S | HIGH |
| **Preview the Woo product page** (rendered mockup) before publishing | Lets ops catch ugly auto-generated content before the customer does. | M | Nice-to-have — defer if scope tight |

### D.4 Anti-features

| Anti-feature | Why not | Alternative |
|---|---|---|
| AI-generated descriptions in v1 | Phase 10 content agent territory; also: hallucination risk on technical AV specs. The Rams2 CLAUDE.md explicitly constrains "AI is ONLY allowed for formatting…never for inventing scope, equipment". Same principle. | Template-driven content from supplier data fields only. Agent comes later to *suggest* enrichment. |
| Auto-publish without any review in v1 | Too risky with unknown supplier data quality | Draft-first workflow; add "auto-publish for whitelisted brands" in v1.x once trust established |
| Rich product variations (sizes/colours) auto-created | AV products are mostly simple; variations add modelling complexity | Handle simple products only in v1; variations deferred or manual |

---

## Module E — Bitrix24 CRM sync

### E.1 Brief items categorized

| # | Feature | Category | Complexity | Notes |
|---|---|---|---|---|
| E1 | Order created → Deal + Contact + Company | TS | M | Parity with itgalaxy plugin — core of module |
| E2 | Customer registration → create/update Contact | TS | S | Parity |
| E3 | Multiple deal pipelines | TS | S | Already supported by itgalaxy (Source 4); ops configure via UI |
| E4 | Dynamic field mapping via Filament UI | DIFF | L | itgalaxy does this via code customisation (Source 4); doing it via UI is the edge |
| E5 | Order notes → Deal comments | TS | S | Parity |
| E6 | Artisan command for historical backfill | TS | M | Needed for cutover so all past orders land in Bitrix |
| E7 | UTM + GA Client ID capture → Bitrix custom fields | TS | M | itgalaxy supports UTM + GA + roistat + Yandex + Facebook (Source 4); brief drops the Russian ones correctly |
| E8 | Audit log every CRM push (request/response/retry) | DIFF | S | itgalaxy has basic logs; full audit is the differentiator |

### E.2 Differentiators to add

| Feature | Value Proposition | Complexity | Notes |
|---|---|---|---|
| **Replay UI** — re-send a failed CRM push from the audit log with one click | Without this, a missed order = ops has to re-trigger via artisan or manual recreate. | S | Leverages E8's log. |
| **Pipeline routing rules** — route to different pipelines by order value / product category / customer type | Beyond just "multiple pipelines supported" — *which* pipeline a deal goes to should be rule-driven. | M | Similar pattern to Module B pricing rules. |
| **Contact dedupe by email + phone** before create | Prevents duplicate Bitrix contacts when the same customer places orders as guest vs logged-in vs different email casing. | M | Bitrix has `crm.duplicate.findbycomm` endpoint. |
| **Sync dry-run** for backfill | Running the artisan backfill blind against a live Bitrix is scary. Dry-run produces "what would happen" report first. | S | Same pattern as Module A dry-run. |

### E.3 Gaps the brief missed

| Gap | Why it matters | Complexity | Confidence |
|---|---|---|---|
| **Webhook-security / HMAC verification for Woo → Laravel webhooks** | Brief lists as open item but critical. WooCommerce signs webhooks with HMAC-SHA256 in `X-WC-Webhook-Signature` (Source 8). Must verify with constant-time comparison before processing. | S | HIGH — Source 8 explicit |
| **Idempotency** — process each Woo order webhook once, even if delivered twice | "WooCommerce fires webhooks once, and if the receiving endpoint returns anything other than a 200, the event is logged as failed with no automatic retry" (Source 8). Counter-intuitive: Woo doesn't retry reliably, so if it *does* retry (from Woo's log) you'll double-push to Bitrix. Store `order_id + event` as idempotency key. | S | HIGH — Source 8 |
| **Dead-letter queue for Bitrix failures** | After N retries (5–10 per Source 8), put failed pushes in a DLQ table, alert, and require manual review. Don't just silently fail. | S | HIGH — ecosystem standard |
| **Webhook delivery receipt** — Laravel returns 200 *fast*, then processes async | Woo fails the webhook if response is slow. Always: queue + return 200, process in job. | S | HIGH — Source 8 |
| **Bitrix API rate-limit handling** | Bitrix24 has per-method rate limits (around 2 req/sec for cloud). Backfill of 10k orders needs throttling. | S | HIGH |
| **Field-mapping validation** — can't save a mapping that references a Bitrix field that doesn't exist anymore | Bitrix admins add/remove custom fields. Stale mappings break silently. On save + nightly, re-fetch `crm.deal.fields` and validate. | S | MEDIUM |
| **Order status sync — out-of-scope in brief, but…** | Brief correctly excludes two-way status sync. But *read-only* mirror of Bitrix deal status back into Laravel (for dashboard only, not pushing to Woo) is cheap and useful for ops. Consider. | M | LOW — borderline scope creep, mention but don't insist |
| **UTM capture on the Woo side** | The brief says "Capture UTM parameters…on checkout". But Woo doesn't capture UTMs by default — needs either a JS snippet on Woo or webhook enrichment. Must confirm how UTMs actually reach the Laravel app. | S | HIGH — implementation detail the brief glosses over |
| **GDPR: right-to-erasure** — ability to delete a customer from Bitrix on request | UK GDPR; customers can request deletion. Without this, ops has to do it manually in Bitrix UI. | S | MEDIUM |

### E.4 Anti-features

| Anti-feature | Why not | Alternative |
|---|---|---|
| Two-way status sync (Bitrix → Woo) | Explicit non-goal | Ops update Woo status manually if needed; they already have Woo admin |
| Coupon list sync | Explicit non-goal | — |
| Inbound Bitrix webhook | Explicit non-goal (one-way) | — |
| Delayed send via cron (itgalaxy feature) | Explicit non-goal; we push on event | Queue job is fast enough |
| Roistat / Yandex Metrika / FB pixel cookie capture | Not used in UK market | Drop silently; just capture UTM + GA CID |
| Configurable entity mode (Lead-only, Contact-only, etc.) | Explicit Key Decision — fixed to Deal+Contact+Company | — |

---

## Module F — Filament dashboard

### F.1 Brief items categorized

| # | Feature | Category | Complexity | Notes |
|---|---|---|---|---|
| F1 | Supplier sync status widget | TS | S | Ops check this first every morning |
| F2 | Import issues view | TS | M | Missing SKUs, pending products, missing cost — the "what needs my attention" list |
| F3 | Competitor analysis pages | TS | M | See Module C differentiators |
| F4 | Pricing rules CRUD + preview | TS | M | See Module B |
| F5 | CRM push log | TS | S | See Module E |
| F6 | Failed jobs (Horizon) | TS | S | Horizon ships this; just embed |

### F.2 Differentiators to add

| Feature | Value Proposition | Complexity | Notes |
|---|---|---|---|
| **Unified activity log viewer** (global, filterable by model/user/action) | Constraint says "audit everything". One UI surfacing all writes is the payoff. | S | `rmsramos/activitylog` or `pxlrbt/filament-activity-log` both ship this (Source 3). |
| **Home dashboard with health tiles** — one screen answers "is the system OK?" | Prevents ops from hunting across 6 pages each morning. | M | Filament widgets. |
| **Notification centre** — queued failures, MAP breaches, stale feeds, sync-paused alerts | Centralised inbox beats scattered emails. | M | Filament has notifications built-in; back with a DB table. |
| **Role-based access** (admin / ops / sales / read-only) | Brief flags as open item. Standard in admin panels. | M | Filament has policies + roles; `spatie/laravel-permission` integrates cleanly. |
| **Horizon embedded link + auth-shared** | One login, not two | S | Horizon auth-gate via same user guard. |

### F.3 Gaps the brief missed

| Gap | Why it matters | Complexity | Confidence |
|---|---|---|---|
| **Global search across products / SKUs / orders / deals** | Ops frequently ask "where did SKU X end up?" — needs cross-module search. Filament 3 has global search built-in per resource; needs configuration. | M | HIGH |
| **Export-to-CSV on every table** | Ops live in Excel. Every list view should export. | S | Filament has bulk export action. |
| **Saved filters / views** per user | "Show me all pending products from Logitech" — if every ops user has to rebuild the filter daily, they'll live in SQL instead. | M | MEDIUM — power-user feature |
| **Scheduled-report emails** (weekly competitor digest, monthly sync summary) | Complements real-time dashboard; gets info to people who don't log in daily (directors, sales). | M | MEDIUM |
| **Dark mode + mobile-responsive** | Filament 3 ships both; just needs theme configured. Ops sometimes check on phone. | S | LOW priority but free |
| **Impersonation for support** — admin "log in as user X" to debug their view | Useful once roles exist. | S | MEDIUM |

### F.4 Anti-features

| Anti-feature | Why not | Alternative |
|---|---|---|
| Custom React/Vue SPA dashboard | Filament is fixed in stack; breaks the "fast-to-build" value prop | Use Filament widgets / Livewire |
| Customer-facing portal on the same app | Explicit non-goal (internal tool) | Separate app if ever needed |
| Deep customisation of Horizon UI | Horizon ships its own dashboard; re-skinning it costs time for zero ops value | Link out to `/horizon` |
| Embedded BI / pivot-table builder | Scope creep; every "dashboard" project grows into this. | Export-to-CSV → Excel, or integrate with Metabase later |

---

## Ecosystem-wide gaps (cross-module)

These are table-stakes for *this class of Laravel app* but aren't in any specific module.

| Gap | Why critical | Module it primarily touches | Complexity | Confidence |
|---|---|---|---|---|
| **Global activity log / audit trail** via `spatie/laravel-activitylog` + Filament viewer | Constraint is explicit: "audit everything". Shouldn't be per-module. | Foundation / all | S | HIGH (Source 3) |
| **Failed-job notifications to Slack/email** via `spatie/laravel-failed-job-monitor` | Horizon shows failures but doesn't push alerts by default (Source 9). Ops won't know until they check. | Foundation | S | HIGH (Source 9) |
| **Webhook HMAC middleware** shared between Woo and any future inbound webhook | See Module E gap; should be reusable, not per-endpoint | Foundation | S | HIGH (Source 8) |
| **Rate-limit-aware HTTP client** for Woo + Bitrix | Both have limits (Woo 100/min, Bitrix ~2/sec). Shared middleware that respects `Retry-After` + honours 429s. | Foundation | M | HIGH (Source 1, 8) |
| **`suggestions` table + Filament inbox UI** | Explicit in constraints. Needs to land in v1 (even if only competitor-margin suggestions populate it at first) or Phase 10 agents can't bolt on without a retrofit. | Foundation / Module C | M | HIGH — derived from brief constraints |
| **Domain events** (`ProductPriceChanged`, `StockWentToZero`, `OrderCreated`) | Explicit in constraints. Emit from Module A/E; Modules C/F subscribe; Phase 8+ feeds subscribe. | All | S | HIGH |
| **Feature flags / config kill-switches** per sync job (can ops disable Bitrix push without deploying?) | Cutover safety. Without this, a buggy CRM sync means emergency deploy. | Foundation | S | HIGH |
| **Retention policies** for competitor CSVs, audit logs, CRM push logs | Brief lists as open item; if unresolved, DB grows unbounded. Ship with sensible defaults + configurable via env. | Foundation | S | HIGH |
| **Backup strategy for MySQL** | Source of truth for pricing + CRM mappings — loss = business outage. Automate daily dumps off-VPS. | Infra | S | HIGH |
| **Secrets management** (JWT, Woo consumer secret, Bitrix webhook URL, HMAC secrets) out of `.env` in prod | `.env` on shared VPS is a leakage risk. Use Laravel's encrypted env or a secrets manager. | Infra | S | MEDIUM |
| **Healthcheck endpoint** `/up` returning sync recency, queue depth, DB connectivity | For external monitoring (UptimeRobot, BetterUptime). Catches "everything looks fine but sync hasn't run in 3 days". | Foundation | S | HIGH |

---

## Feature dependencies (drives phase ordering)

```
Foundation (auth, base models, events, audit, webhook security, HTTP middleware, suggestions table)
   └── Module A (Supplier sync) — FIRST functional phase
         ├── requires Foundation's HTTP middleware + events
         ├── emits ProductPriceChanged, StockWentToZero
         └── writes supplier_price into Product model
   │
   ├── Module B (Pricing engine)
   │     ├── requires Module A's supplier_price write to exist
   │     ├── consumed by Module A's sync (rule applied before Woo push)
   │     └── independent of C/D/E
   │
   ├── Module D (Product auto-create)
   │     ├── requires Module A (discovers new SKUs via sync diff)
   │     ├── requires Module B (needs pricing engine to set initial price)
   │     └── requires suggestions inbox pattern
   │
   ├── Module C (Competitor analysis)
   │     ├── requires Module A/B (needs supplier_price + margin to compute delta)
   │     ├── emits suggestions (writes to suggestions table)
   │     └── independent of D/E
   │
   ├── Module E (Bitrix24 sync)
   │     ├── requires Foundation's webhook HMAC + idempotency
   │     ├── independent of A/B/C/D (orders don't need pricing rules; Woo already priced)
   │     └── CAN ship in parallel with B/C/D
   │
   └── Module F (Dashboard)
         ├── grows throughout — each module contributes pages
         ├── home widgets need Modules A+B+C+E data
         └── final polish phase

Cutover (G)
   └── requires all modules stable + parallel-run proven
```

### Phase-ordering implications

1. **Foundation MUST ship before anything else.** Audit, events, webhook middleware, suggestions table, domain base models (`Product`, `Brand`, `Category`, `Supplier`, `CompetitorSource`, `SyncRun`). The brief's Phase 1 is correct.
2. **Module A (supplier sync) is the highest-risk, highest-value first functional phase.** Stale stock = immediate business hit. Ship it with just *default-tier* pricing (no rules yet) so cutover can happen fast; Module B can retrofit.
3. **Module B (pricing) must land before Module D (auto-create)** — auto-created products need a price, and that price must obey rules.
4. **Module E (Bitrix) can ship parallel to B/C/D** — the only shared dependency is Foundation. This is important for the cutover timeline: once Bitrix sync works, the itgalaxy plugin can be disabled independently.
5. **Module C (competitor) is the lowest-urgency functional module** — current plugin does it badly, so even a v1 with history + basic deltas is a win. Push to later.
6. **Module F (dashboard) is built *continuously*, not as a single phase** — each functional phase adds its own Filament pages. A "dashboard phase" at the end is for polish, home widgets, and cross-cutting features (global search, notifications, role setup).

### Suggested phase sequence (diverges from brief's 7-phase list)

| # | Phase | Primary modules | Rationale |
|---|---|---|---|
| 1 | Foundation + Supplier sync (minimum viable) | Foundation, A (default tiers only) | De-risks #1 business dependency fastest; proves the Woo REST write path |
| 2 | Pricing engine | B | Unlocks rule-driven margins; retrofits onto A's sync |
| 3 | Bitrix24 sync | E | Unblocks cutover for the itgalaxy plugin (sanctions-risk compliance driver) |
| 4 | Competitor analysis | C | Lower urgency but high differentiator value |
| 5 | Product auto-create | D | Needs B complete + suggestions pattern proven by C |
| 6 | Dashboard polish + ops UX | F | Pull together cross-cutting widgets, notifications, roles |
| 7 | Cutover + parallel-run + handover | — | Brief's Phase 7 unchanged |

**Note:** Brief orders C before D, which this research also recommends (C proves the suggestions inbox pattern that D then reuses). Brief orders B before C, which also holds. Only real re-ordering vs brief: **move Module E (Bitrix) earlier** because itgalaxy-plugin sanctions risk is the compliance driver behind the whole project — cutting that dependency first reduces legal exposure.

---

## MVP definition

### Launch with (v1, Phases 1–7)

Per the brief's scope — ship all 6 modules. MVP of each module is the TS + DIFF rows above. The GAP items should be scoped into the relevant phase during `/gsd-plan-phase`.

**Hard MVP requirements** (product is broken without these):

- [ ] Supplier sync runs daily, pushes to Woo via REST, handles missing SKUs
- [ ] Pricing rules apply with default-tier fallback (brand+cat > cat > brand > tier)
- [ ] Competitor CSV ingest with full history
- [ ] Woo order webhook → Bitrix deal/contact/company (with HMAC verification + idempotency)
- [ ] Filament admin with sync status, pricing rules, CRM log, Horizon embed
- [ ] Audit log on every mutation (sync, rule change, CRM push)
- [ ] Dry-run mode on both supplier sync and Bitrix backfill (for cutover safety)
- [ ] Failed-job Slack/email alerts (Spatie failed-job-monitor)
- [ ] Webhook HMAC middleware + 200-fast queue-then-process pattern

### Add after validation (v1.x, post-cutover)

- [ ] Image processing pipeline for auto-create (resize, WebP, EXIF strip)
- [ ] MAP monitoring + vendor breach alerts
- [ ] Fuzzy product matching for competitor rows
- [ ] Bulk approve + brand-templated content in auto-create review
- [ ] Saved filters / scheduled reports
- [ ] Rule simulation (impact analysis before save)

### Future consideration (v2+ / Phases 8–12, already in brief)

- [ ] Merchant Center / Meta feeds (Phase 8)
- [ ] GA4 integration (Phase 8)
- [ ] AI agents writing to suggestions (Phase 10)
- [ ] B2B customer-scoped pricing (Phase 12)
- [ ] RAMS platform hook (Phase 12)

---

## Feature prioritization matrix

| Feature | User Value | Impl Cost | Priority | Rationale |
|---|---|---|---|---|
| Supplier sync (A) | HIGH | MEDIUM | P1 | Business outage if missing |
| Bitrix24 sync (E) | HIGH | MEDIUM | P1 | Sanctions/compliance driver |
| Pricing engine (B) | HIGH | MEDIUM | P1 | The actual "new capability" edge |
| Webhook HMAC + idempotency | HIGH | LOW | P1 | Security + data-integrity foundation |
| Dry-run mode | HIGH | LOW | P1 | Cutover safety |
| Failed-job Slack alerts | HIGH | LOW | P1 | Ops can't act on what they can't see |
| Competitor analytics (C) | MEDIUM | MEDIUM | P1 | Differentiator but not cutover-blocker |
| Product auto-create (D) | MEDIUM | HIGH | P1 | Net-new capability; biggest UX risk |
| Dashboard widgets (F) | MEDIUM | MEDIUM | P1 | Ops productivity + cutover confidence |
| Role-based access | MEDIUM | LOW | P1 | Multi-user ops team needs this |
| Activity-log viewer | MEDIUM | LOW | P1 | Constraint-driven (audit everything) |
| Suggestions inbox | LOW (v1) | LOW | P1 | Small cost now, huge unlock for Phase 10 |
| Pipeline routing rules | MEDIUM | MEDIUM | P2 | Once basic Bitrix push works |
| MAP monitoring | MEDIUM | LOW | P2 | Brand-dependent; defer until confirmed |
| Fuzzy product matching | MEDIUM | MEDIUM | P2 | After exact-match proves insufficient |
| Scheduled reports | LOW | MEDIUM | P3 | Nice-to-have |
| Impersonation | LOW | LOW | P3 | Only needed once user base grows |
| AI-generated content | LOW (v1) | HIGH | P3 | Phase 10 agent territory |

---

## Comparable-tools analysis

| Feature | itgalaxy plugin (legacy) | Prisync / Priceva (competitor tools) | Woo Stock Sync Sheets | MeetingStore Ops (planned) |
|---|---|---|---|---|
| Supplier stock pull | Stock Updater plugin, flat tier pricing | N/A | Has it (Google Sheets source) | Daily pull, rule-driven pricing, event-emitting |
| Dry-run / preview | No | N/A | Yes (dry-run mode) | **Yes — adds (gap in brief)** |
| Per-SKU idempotency | Implied, not explicit | N/A | Yes | **Yes — adds (gap in brief)** |
| Competitor history | Truncated daily | Full history + trends | N/A | Full history + trends (DIFF) |
| Margin suggestions | No | Auto-reprice rules | N/A | Suggestions, no auto-apply (DIFF) |
| MAP monitoring | No | Yes | N/A | **Consider adding (gap)** |
| CRM UTM tracking | Yes (UTM+GA+Yandex+Roistat+FB) | N/A | N/A | UTM+GA only (drops Russian) |
| CRM field mapping | Code customisation | N/A | N/A | **UI-driven (DIFF)** |
| Activity audit log | Basic | N/A | N/A | Full Spatie activitylog (DIFF) |
| Dead-letter queue | No | N/A | N/A | **Yes — adds (gap in brief)** |
| Role-based access | WP roles (crude) | Yes | Yes | **Filament + Spatie permissions (gap)** |

---

## Confidence & open questions

**HIGH confidence findings** (verified across multiple sources):
- WooCommerce REST 100/min rate limit + need for batch endpoints + idempotency (Source 1, 5)
- WebSocket-equivalent reality: Woo webhooks don't retry reliably, HMAC verification required, DLQ pattern standard (Source 8)
- Filament 3 + Spatie activitylog is the canonical audit trail stack (Source 3)
- Horizon does *not* notify on failed jobs by default; need Spatie failed-job-monitor (Source 9)
- PIM completeness scoring, schema mapping, channel mapping are industry-standard (Source 2)

**MEDIUM confidence**:
- Whether Meeting Store actually sells MAP-policy brands (Logitech/Poly/Jabra do enforce MAP in UK but enforcement varies — verify with ops)
- Current rounding behaviour in legacy plugin (`.99` vs plain `round(x,2)`) — brief says the latter; worth confirming
- Whether all competitor CSVs are GBP inc-VAT or mixed

**LOW confidence** (needs user research):
- Ops team daily workflow — this document assumes patterns from comparable tools, not observed usage
- Which emails in the current "admin CSV report" distribution — brief flags as open item
- Rollback expectations — how far back, how fast

**Open questions for `/gsd-plan-phase` discussions:**
1. Confirm rounding rule expected by ops (pure `round(x,2)` or `.99`/`.95` endings)
2. Confirm whether supplier DB returns images (drives Module D complexity)
3. Confirm MAP brands (drives Module C scope)
4. Confirm UTM capture mechanism on Woo side (cookie vs JS vs webhook enrichment)
5. Confirm retention windows (audit, competitor history, CRM push log)
6. Confirm user roles needed (admin-only vs ops/sales/read-only split)
7. Confirm webhook-delivery SLA expectations (is a 30-second processing delay acceptable?)

---

## Sources

1. [WooCommerce REST Python: Bulk Operations Guide 2026](https://johal.in/woocommerce-rest-python-bulk-operations-with-wp-api-client-library-2026/) — rate limits, idempotency, bulk endpoints
2. [Ecommerce PIM Guide — BigCommerce](https://www.bigcommerce.com/articles/business-management/pim/) — PIM must-have features
3. [Filament Activity Logs: Three Packages Compared — LaravelDaily](https://laraveldaily.com/post/filament-activity-logs-three-packages-comparison-review) — Spatie activitylog Filament integrations
4. [WooCommerce — Bitrix24 CRM Integration — itgalaxy on Medium](https://medium.com/@info_66265/woocommerce-bitrix24-crm-integration-de346cc2a086) — legacy plugin feature set
5. [WooCommerce REST API Documentation](https://woocommerce.com/document/woocommerce-rest-api/) — official rate-limit and bulk docs
6. [Prisync — Competitor Price Tracking](https://prisync.com/) — repricer feature baseline, MAP monitoring
7. [Stock Sync Sheets Documentation — WooCommerce Marketplace](https://woocommerce.com/document/stock-sync-sheets/) — dry-run + rollback patterns
8. [Guide to WooCommerce Webhooks — Hookdeck](https://hookdeck.com/webhooks/platforms/guide-to-woocommerce-webhooks-features-and-best-practices) — HMAC, retry, DLQ
9. [Spatie laravel-failed-job-monitor on GitHub](https://github.com/spatie/laravel-failed-job-monitor) — failed-job alerting
10. [PIM Guide — Pimcore](https://pimcore.com/en/resources/insights/what-is-pim) — PIM data governance, completeness scoring

---
*Feature research for: MeetingStore Ops (Laravel 12 + Filament 3 source-of-truth over WooCommerce + Bitrix24)*
*Researched: 2026-04-18*
