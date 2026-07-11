# Phase 15 (expanded) — Marketing Intelligence: Google Ads + GA4 ingestion + advisory agents

**RESEARCH & Scoping** · 2026-07-11 · precedes planning · NO code yet

## Why this expands Phase 15
Phase 15 as originally roadmapped is narrow: an agent reads **won Bitrix Deal** data by
UTM/GCLID and proposes ad-budget shifts. The operator's ask is broader and better-grounded:
**pull live Google Ads + GA4 data into the app** and have specialist Claude-backed agents
review it several times a day and advise on maximising sales/presence. That is a superset —
it adds a new **read-side integration** (Google Ads + GA4) feeding the agent, and widens
"one ad agent" to **a small panel of specialists** (paid-search, organic/SEO, budget
allocation). Pulling directly from Google also **weakens Phase 15's original "wait ≥4 weeks
for data" gate** — Google retains months of history, available on day one.

This all lands natively on existing scaffolding: the C4 agent framework (Phase 8), the
`Suggestions`-first pattern, shadow-mode write gating (`*_WRITE_ENABLED` default false),
encrypted credential store + `IntegrationCredentialKind`, Prism (Claude) + Langfuse, and the
scheduled-pull pattern already used by `supplier:db-sync`. `AgentKind::AdOptimisation` is
already defined but unbuilt.

---

## A. Google Ads API — findings (current as of 2026)

- **SDK/version:** API **v23** (Google ships ~3 versioned releases/yr, sunsets ~annually).
  PHP SDK `googleads/google-ads-php` **v33.x**, **PHP 8.1+** (app is 8.2+ ✓). Deps include
  the **gRPC PECL ext** + protobuf (or REST transport). Treat version bumps as ~annual maintenance.
- **Developer token tiers:** Test (test accts only) → **Explorer** (~2,880 prod ops/day, often
  auto) → **Basic** (15,000 ops/day, ~5 business days approval) → Standard (unlimited, needs
  Basic first, ~10 days + tool review). **Basic is almost certainly enough** for one merchant.
  ⚠️ Google reported an **approval backlog** in early 2026 — apply EARLY; this is the critical path.
- **Auth:** **OAuth2 with an offline refresh token** is the recommended server-to-server path.
  Needs `client_id`, `client_secret`, `refresh_token`, `developer_token`, and `login_customer_id`
  (MCC/manager CID) if going through a manager account. Service accounts only work via Workspace
  domain-wide delegation — **not** the right choice for a single merchant; use OAuth2 refresh token.
- **Read reports (GAQL, cost fields in micros ÷ 1e6):** `campaign` (cost/conversions/conv_value/
  clicks/impr/ctr/avg_cpc → ROAS client-side); `search_term_view` (wasted-spend + converting
  search terms → negative-keyword candidates); `keyword_view` (+ `quality_info.quality_score`,
  `metrics.search_impression_share`); `campaign_budget` (amount_micros). Use **`SearchStream`** =
  1 operation regardless of rows.
- **Quotas:** a few pulls/day is **trivially** within Basic's 15k ops/day. QPS is token-bucket per
  (customer × dev-token); back off on `RESOURCE_TEMPORARILY_EXHAUSTED`.
- **Write side (LATER, money-affecting):** `CampaignBudgetService.MutateCampaignBudgets`
  (amount_micros + update_mask; shared budgets hit all campaigns), bids via
  `AdGroupCriterionService`/`BiddingStrategyService` (target_roas/target_cpa). Build with
  `validate_only` dry-run, caps, audit, human confirm.
- **2026 gotchas:** Consent Mode v2 (EEA-scoped; UK-only merchant is under UK GDPR, but bites if
  serving EEA users — flag to marketing/legal, Feb 2026 removed IP/session-attribute imports,
  15 Jun 2026 `ad_storage` gates ad-data flow); Performance Max has limited granular/search-term
  reporting (`asset_group` no metric selection); version churn is ongoing maintenance.
- Sources: google-ads-php GitHub/Packagist; developers.google.com/google-ads/api docs
  (access-levels, developer-token, rest/auth, query/overview, best-practices/quotas,
  deprecations, performance-max/reporting); v23 announcement (ads-developers.googleblog.com).

## B. GA4 Data API — findings (current as of 2026)

- **SDK/version:** Data API v1; stable client exposed under **`V1beta`** namespace (production-
  supported despite the label — long-standing GA4 quirk). PHP client `google/analytics-data`
  **v0.24.0** (2026-05-04), **PHP 8.1+** ✓. REST transport works without gRPC ext (pragmatic default).
  Main call: `BetaAnalyticsDataClient::runReport()`.
- **Auth:** **Service account (JSON key)** is the recommended path for unattended jobs (no browser
  consent, no refresh-token dance). Scope `analytics.readonly`. Setup: GCP project → enable Data API
  → create SA + JSON key → add the SA `client_email` as **Viewer** on the **GA4 property** (least
  privilege) → point client at key via `GOOGLE_APPLICATION_CREDENTIALS`. (Note the asymmetry: Ads
  uses OAuth refresh token, GA4 uses a service account.)
- **Quotas:** **200,000 tokens/property/day**, 10 concurrent — a few reports/day is negligible.
  Send `returnPropertyQuota:true` to monitor consumption.
- **Useful fields (e-commerce):** metrics `sessions`, `keyEvents`/`conversions`, `purchaseRevenue`,
  `transactions`, `ecommercePurchases`, `engagementRate`; dimensions
  `sessionDefaultChannelGroup`, `sessionSourceMedium`, `sessionCampaignName`,
  `landingPagePlusQueryString`, `date`, product-scoped `itemName`/`itemBrand`. Pivot revenue +
  transactions against channel/source/campaign/landing-page = the standard acquisition→revenue view.
  (Session- vs item-scoped dims don't always combine — build per-scope reports.)
- **Attribution / GCLID — definitive:** the **GA4 Data API cannot return click-level GCLID** (no
  `gclid` dimension; aggregated rows only). UTM = self-tagged links parsed into `session*` dims;
  GA client_id = cookie device id (not exposed); GCLID = Google Ads click id (used internally, not
  queryable). **True "this click → this sale" closed loop is NOT GA4** — it's **Google Ads offline
  conversion import keyed on GCLID** (`ConversionUploadService`, **90-day** window), or **Enhanced
  Conversions for Leads** (hashed email/phone, 63-day) as fallback. GA4 gives aggregate channel/
  campaign performance, which is plenty to start.
- **2026 gotchas:** `conversions`→`keyEvents` rename (prefer `keyEvents`); Consent Mode modeling
  means non-consenting data is ML-estimated (thresholds ~1k daily events) → API totals may under/
  mis-count in consent regions; **data retention defaults to 2 months** (set to 14 for YoY — though
  aggregated channel/revenue-by-day reports are retained regardless); thresholding can silently
  suppress low-count rows when demographics/Signals are involved.
- Sources: developers.google.com/analytics Data API (quotas, client-libraries); Packagist
  google/analytics-data; support.google.com (offline-conversions GCLID 90-day, behavioral modeling,
  data retention).

---

## C. Proposed design (mapped to app patterns)

**Credentials** — add `IntegrationCredentialKind::GoogleAds` (`google_ads`) + `GoogleAnalytics`
(`google_analytics`) to the encrypted credential store + Filament Integration Credentials UI, with
a "Test connection" action (mirrors the Supplier DB / Icecat kinds). Ads stores OAuth refresh-token
bundle + dev token + login-customer-id; GA4 stores the service-account JSON + property id.

**Ingestion (read-only, shadow-safe)** — scheduled commands mirroring `supplier:db-sync`:
`google:pull-ads` (SearchStream GAQL → `ad_metrics_daily` / `search_term_metrics` snapshot tables)
and `google:pull-ga4` (`runReport` → `ga_channel_metrics_daily` snapshot). Full audit rows, chunked,
idempotent upsert by (date × entity). Admin viewer pages under a new **Marketing** nav area. This
half is fully shadow-safe (no external writes) and blocked ONLY by token approval.

**Advisory agents (C4 framework, `AgentKind`)** — run several times/day via Horizon cron with a
per-run cost cap (reuse the Phase 12-E budget-race guard), Langfuse-traced:
  - **AdOptimisationAgent** (`ad_optimisation`, already enum-defined) — reads the ingested Ads+GA4
    snapshots **plus the app's own margin / competitor / stock data** (the unfair advantage Google
    can't see) and emits prioritised **Suggestions**: pause wasted keywords, bid up converting search
    terms, shift budget A→B, "high-margin in-demand SKUs with no ad coverage".
  - **Organic-presence angle** — reuse the existing Phase 12 SEO/content agent pointed at GA4
    landing-page/query data.
  - (optional) **budget-allocation** reasoning across paid + organic.
A **Marketing Intelligence dashboard** surfaces the latest advice + trend tiles.

**Money safety (non-negotiable, native to the app):** agents **advise**; humans approve. Every
recommendation is a `Suggestion`. Any *write* back to Google (budget/bid mutate, offline conversion
upload) is a **later phase**, shadow-mode-gated (`GOOGLE_ADS_WRITE_ENABLED` default false), with
`validate_only` dry-run + change caps + audit. Recommend keeping ad-spend writes **manual for a long
time** — the daily analysis is ~90% of the value at ~0% of the "agent burned £2k overnight" risk.

**GCLID / closed loop (distinct sub-project, later):** today the app captures UTM + GA client id
(`UtmExtractor`) but **not GCLID**. Aggregate GA4 + Ads reporting works fine without it, so start
there. For airtight click→sale attribution later: capture GCLID on the landing page → persist on the
Woo order meta → on a won Bitrix deal, upload an offline conversion to Google Ads (90-day window).

---

## D. Recommended phasing

- **15a — Google data ingestion (read-only):** credential kinds, Ads OAuth2 + GA4 service account,
  pull commands + snapshot tables + admin viewers. Shadow-safe. Gated only by token approval.
- **15b — Advisory agents + Marketing dashboard:** AdOptimisationAgent + SEO-agent reuse, scheduled
  several times/day, Suggestions + dashboard. All advice, no writes.
- **15c — Closed-loop (optional, later):** GCLID capture → offline conversion import → gated
  budget/bid write-back.

## E. Operator prerequisites (CRITICAL PATH — only the operator can do these; start NOW)

1. **Apply for a Google Ads API developer token (Basic)** in the Google Ads **API Center** (from the
   MCC/manager account). ⚠️ Do this FIRST — approval backlog reported in 2026.
2. **Create a Google Cloud project**; enable **Google Ads API** + **Google Analytics Data API**.
3. **Ads OAuth2:** create a Web-app OAuth client, run a one-time consent, capture the **refresh
   token**; note the **login-customer-id** (MCC) if you use a manager account.
4. **GA4 service account:** create a service account + JSON key; grant its `client_email`
   **Viewer** on the GA4 **property**; set GA4 **data retention to 14 months** (Admin → Data Settings).
5. **Confirm the identifiers:** the Google Ads customer ID(s) and the GA4 property ID to target.
6. **Consent-mode / GDPR note** for marketing/legal if you serve EEA users (UK-only is UK GDPR).

## F. Open decisions for the operator
- Scope 15a + 15b now, defer 15c (writes/closed-loop)? (Recommended.)
- Start GA4-only (easier service-account auth, no token wait) while the Ads token clears, then add Ads?
- Keep Google-Ads writes permanently manual, or plan a gated write-back later?
