---
quick_id: 260524-qqn
description: "AI product-page creation: generate-drafts (Claude content), source-images (Icecat+Serper+vision), assign-taxonomy, preview page; extends Phase 6 auto-create"
date: 2026-05-24
commit: 9b7f094
status: completed
retroactive: true
---

# Quick Task 260524-qqn — Summary

> Retroactive GSD record of operator-driven work shipped to `origin/main` and
> deployed live on 2026-05-24. Code was committed during the session (commit
> range below); this task captures it in GSD planning.

## What changed

AI-powered extension of the v1 **Phase 6 Product Auto-Create** feature — three
new Artisan commands, three new domain services, a preview page, two migrations,
two integration-credential kinds, and a Langfuse-noise fix.

### 1. `products:generate-drafts` — AI product content
- `GenerateProductDraftsCommand`: reads supplier_db facts → `ClaudeClient::generate`
  → upserts a local draft Product. System prompt rewritten to the canonical
  meetingstore.co.uk page structure (studied 6 live pages): title `Brand Model
  Descriptor`, 4-bullet `<ul>` short description, long description with six
  `<h3>` sections — Product Overview / Key Features / Use Cases / Compatibility /
  What's in the Box / Why Buy from MeetingStore?. maxTokens 1600→2500.

### 2. `products:source-images` — image sourcing + Claude-vision validation
- `SourceProductImagesCommand`: candidates = Icecat ∪ auto-detected supplier
  image columns ∪ Serper web search; each fetched → ≤1200px WebP → vision-validated
  → up to `--max` stored (`image_url` + `gallery_image_urls`), manual-review
  flag cleared. `--dry-run`, `--candidates`, `--max-spend-pence` guards.
- `IcecatClient` (NEW): JSON Product Request by GTIN/EAN then Brand+ProductCode;
  Full-Icecat `app_key` query param; `api-token`/`content-token` sent only when
  valid UUIDs; returns ordered high-res gallery URLs; degrades to [] gracefully.
- `WebImageSearchClient` (NEW): Serper.dev `/images`; drops blocked (competitor)
  domains, prefers brand-in-domain (official) then largest image.
- `ProductImageVisionValidator` (NEW): attaches processed WebP to a `UserMessage`
  → Claude vision verdict JSON; accepts only correct product with no watermark /
  overlay text / competitor branding (physical product text + on-screen UI OK);
  fails closed.
- `ProductImageFetcher`: now sends a browser User-Agent + Accept and treats HEAD
  as advisory (GET + size + decode is the gate) — recovers manufacturer-CDN images.

### 3. `products:assign-taxonomy` — brand + category auto-mapping
- `AssignProductTaxonomyCommand` (NEW): brand = supplier manufacturer fuzzy match;
  category = Claude picks best-fit name verbatim from the live Woo list →
  `categoryIdByName`. Flips `needs_brand_or_category_assignment` → `draft` when
  both resolve. forceFill+saveQuietly (content/images untouched). `--dry-run`.
- `TaxonomyResolver` v2: fuzzy match (token-overlap + similar_text, threshold
  0.55) over the full live term list; `allCategories()`/`allBrands()`/
  `categoryIdByName()`; brand lookup resolves the `pa_brand` attribute id (was
  using the slug) with a native `/products/brands` fallback; 1h cache.

### 4. `/preview/product/{id}` — rendered draft preview
- `ProductPreviewController` + `resources/views/preview/product.blade.php` +
  auth-gated route: renders a draft as a customer-facing product page (gallery,
  bullets, 6 sections) with a sticky "DRAFT PREVIEW — not live" banner. Local
  data only; never touches Woo.

### 5. Schema + integrations + noise
- Migration `2026_05_24_090000`: `products.woo_product_id` → nullable (fixes the
  SQLSTATE 1364 that blocked all review-first inserts, incl. CreateWooProductJob's
  needs-assignment short-circuit). UNIQUE index preserved.
- Migration `2026_05_24_100000`: `products.gallery_image_urls` JSON column (+ Product
  fillable/cast).
- `IntegrationCredentialKind::Icecat` (username + optional app_key/content_token/
  api_token) and `::ImageSearch` (api_key); new `optionalFields()` rendered in the
  credentials form; resolver env-fallback + `config/services.php` icecat + image_search
  blocks; Test-connection wired for both.
- `config/langfuse.php` (NEW): app-level override defaulting `tracing_enabled` /
  `otel_enabled` / `prism.auto_trace` to FALSE — kills the per-call OTel
  "Unauthorized" stack-trace noise without a `.env` edit.

## Outcome (live run)

6 draft products (#5633–#5638: Huddly S1, Sony FW-50EZ20L, Sony FW-43EZ20L,
Huddly IQ, Barco ClickShare Tray, ViewSonic IFP5551) generated with the new
content structure (12p Claude) and imaged via web search (5×3 + 1×2 validated
images, 33p Claude vision). Vision correctly rejected competitor-branded,
overlay-text, wrong-model, and rear-port-only candidates. Taxonomy assignment +
Woo push remain (the latter gated by `WOO_WRITE_ENABLED=false`).

## Commits (work shipped during session, on `main`)

`703b83d` woo_product_id nullable · `1869d55` Langfuse env doc ·
`1d58fa6` source-images + vision validator · `e947cea` Icecat test probe ·
`b96e195` Icecat error logging · `f922364` Icecat app_key + UUID-guard ·
`0d1d5f5` Serper web image-search source · `77c66b8` Langfuse config + fetcher UA ·
`d498dbc` meetingstore page-structure content · `2bb094f` assign-taxonomy ·
`9b7f094` preview page

## Follow-ups / notes
- Icecat account is Open (free) tier; Full Icecat (paid) would give app_key +
  cleaner licensed images for these B2B AV brands. Web search is the working source.
- Exposed GitHub PAT from an earlier session and the placeholder admin password
  should still be rotated (carried forward).
- Not yet done for the 6: brand/category assignment (command ready), then Woo push.
