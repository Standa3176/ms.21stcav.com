---
quick_id: 260524-qqn
description: "AI product-page creation: generate-drafts (Claude content), source-images (Icecat+Serper+vision), assign-taxonomy, preview page; extends Phase 6 auto-create"
date: 2026-05-24
retroactive: true
must_haves:
  truths:
    - products.woo_product_id was NOT NULL no-default — blocked ALL review-first draft creation (CreateWooProductJob needs_brand_or_category_assignment short-circuit AND the new generator); made nullable (UNIQUE index preserved, MySQL NULLs distinct)
    - ClaudeClient::generate() is the sole sanctioned Anthropic entry point and is vision-capable — image validation attaches a Prism Media\Image to a UserMessage, no new client needed
    - IntegrationCredentialKind matches are exhaustive (enum requiredFields/label/urlFields/color + resolver resolveFromEnv) — every new kind needs a branch in all five or UnhandledMatchError
    - Icecat account is Open (free) tier — Sony/Barco/ViewSonic/Huddly need Full Icecat (app_key from My Profile, query param); pivoted to Serper.dev web image search as the working source
    - Langfuse OTel exporter stack-traced "Unauthorized" on every Claude call (LANGFUSE_HOST set, placeholder keys); app-level config/langfuse.php wins over package mergeConfigFrom → defaults tracing OFF
  artifacts:
    - app/Console/Commands/GenerateProductDraftsCommand.php (products:generate-drafts — Claude content in meetingstore.co.uk 6-section structure)
    - app/Console/Commands/SourceProductImagesCommand.php (products:source-images — Icecat+supplier+web → vision-validate → store ≤3)
    - app/Console/Commands/AssignProductTaxonomyCommand.php (products:assign-taxonomy — fuzzy brand + Claude category pick)
    - app/Domain/ProductAutoCreate/Services/IcecatClient.php (NEW — GTIN/Brand lookup, app_key + UUID-guarded token headers)
    - app/Domain/ProductAutoCreate/Services/WebImageSearchClient.php (NEW — Serper.dev images, manufacturer-domain bias, blocked-domain filter)
    - app/Domain/ProductAutoCreate/Services/ProductImageVisionValidator.php (NEW — Claude vision verdict, fail-closed)
    - app/Domain/ProductAutoCreate/Services/TaxonomyResolver.php (v2 — fuzzy match, allCategories/allBrands, brand attribute-id fix)
    - app/Domain/ProductAutoCreate/Services/ProductImageFetcher.php (browser UA + advisory HEAD)
    - app/Domain/Integrations/Enums/IntegrationCredentialKind.php (+Icecat +ImageSearch +optionalFields())
    - app/Http/Controllers/ProductPreviewController.php + resources/views/preview/product.blade.php + routes/web.php (/preview/product/{id})
    - database/migrations/2026_05_24_090000_make_woo_product_id_nullable_on_products.php
    - database/migrations/2026_05_24_100000_add_gallery_image_urls_to_products_table.php
    - config/langfuse.php (NEW — OTel/tracing default OFF) + config/services.php (icecat + image_search blocks)
  key_links:
    - .planning/milestones/v1.50.1-ROADMAP.md Phase 6 Product Auto-Create (the foundation this extends)
    - .planning/milestones/v1.50.1-MILESTONE-AUDIT.md (Phase 6 FLAG — MySQL Feature-tier operator carry-forward)
---

# Quick Task 260524-qqn: AI Product-Page Creation

> **Retroactive record.** This documents work that was built, committed, and
> deployed to `origin/main` during an operator-driven session on 2026-05-24,
> then recorded in GSD after the fact. No code was written by the quick-task
> executor — the commit hashes are listed in the SUMMARY.

## Goal

Turn the v1 **Phase 6 Product Auto-Create** scaffolding (SEO-template content +
supplier-URL image pipeline + review inbox) into an operator-driven, AI-powered
pipeline that produces publish-ready draft products — content, images, and
taxonomy — for SKUs missing from meetingstore.co.uk. Review-first throughout:
nothing is pushed to Woo (still behind `WOO_WRITE_ENABLED=false`).

This also exercised the **Phase 6 audit FLAG** — the MySQL Feature-tier gates
that were carried forward to the operator because Phase 6 verified against
SQLite. The `woo_product_id` NOT-NULL bug only surfaced on live MySQL.

## Tasks

### Task 1 — AI product content (`products:generate-drafts`)
Read supplier_db facts (brand/title/mpn/ean/price) → `ClaudeClient::generate`
writes a clean title (with model number), a 4-bullet short description, and a
long description in the canonical meetingstore.co.uk structure (six `<h3>`
sections: Product Overview, Key Features, Use Cases, Compatibility, What's in
the Box, Why Buy from MeetingStore?). Upserts a local draft Product. Fixed the
NOT-NULL `woo_product_id` schema bug that blocked all review-first inserts.

### Task 2 — Image sourcing + AI validation (`products:source-images`)
Candidate sources: Icecat (by GTIN/EAN then Brand+ProductCode) → supplier-feed
image columns (auto-detected) → Serper.dev web image search (manufacturer-domain
biased). Each candidate is downloaded (browser-UA, advisory-HEAD fetcher),
normalised to ≤1200px WebP, then **Claude-vision validated** — accept only the
correct product, free of watermarks / overlaid promo text / competitor branding
(physical product branding + on-screen UI allowed). Stores up to `--max` images
(primary → `image_url`, all → new `gallery_image_urls` JSON) and clears the
manual-image-review flag. Spend-guarded; never posts to Woo.

### Task 3 — Taxonomy auto-assignment (`products:assign-taxonomy`)
`TaxonomyResolver` v2 fetches the full live Woo category + brand term lists
(fuzzy matching, brand attribute-id fix). Brand = supplier manufacturer fuzzy
match; category = Claude picks the best-fit name verbatim from the real Woo list
(handles use-case taxonomies). Flips `needs_brand_or_category_assignment` →
`draft` when both resolve. Writes via forceFill+saveQuietly (content/images
untouched).

### Task 4 — Draft preview page (`GET /preview/product/{id}`)
Auth-gated route + Blade view rendering a draft as a customer-facing product
page (image gallery, 4-bullet short desc, the 6 sections) with a "DRAFT
PREVIEW — not live" banner, so operators can sign off before any Woo push.

### Task 5 — Integrations + noise
New `IntegrationCredentialKind::Icecat` (app_key + UUID token headers) and
`::ImageSearch` (Serper api_key), both with env-fallback + Filament form
support (new `optionalFields()`), Test-connection wired. Silenced the Langfuse
OpenTelemetry "Unauthorized" stack-trace noise via an app-level `config/langfuse.php`.
