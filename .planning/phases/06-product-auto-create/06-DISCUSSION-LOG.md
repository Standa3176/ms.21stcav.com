# Phase 6: Product Auto-Create - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-19
**Phase:** 06-product-auto-create
**Mode:** `--auto` (recommended defaults auto-selected without interactive prompting)
**Areas discussed:** SEO template shape, auto-skip rules + rejection enum, completeness score algorithm, ProductOverride pin semantics

---

## SEO template shape + shortcodes (auto-selected)

| Option | Description | Selected |
|--------|-------------|----------|
| Fixed canonical template + per-brand deferred (Recommended) | Single template mirroring meetingstore.co.uk product page; per-brand voice deferred post-v1 | ✓ |
| Per-brand templates from day one | Research D.2 differentiator; scope risk too high for v1 | |
| Supplier-data-only pass-through | No Blade; just dump supplier fields; rejected — SEO-hostile | |

**Auto-choice:** Fixed template + Blade shortcodes in `resources/views/product-auto-create/seo-template.blade.php`.
**Rationale:** 80% coverage across brands with one template; forward-compatible for per-brand overrides post-v1.

---

## Auto-skip rules + rejection reason enum (auto-selected)

### Sub-Q1: Auto-skip rules table

| Option | Description | Selected |
|--------|-------------|----------|
| `auto_create_skip_rules` table with 4 scope types (Recommended) | brand / category / sku_pattern / price_range; admin-editable Filament Resource | ✓ |
| Config-file-based only | No admin UI; requires deploy to change | |
| Hardcoded blocklist | Fastest to ship, zero flexibility | |

**Auto-choice:** DB-table + Filament Resource. Seed 3 sensible defaults (SparesPlus brand, TEST- SKU prefix, <£25 price).

### Sub-Q2: Rejection reason enum

| Option | Description | Selected |
|--------|-------------|----------|
| 7 structured reasons + `other` free-text (Recommended) | not_a_real_product / duplicate / discontinued / spare / poor_data / misclassified / below_viability / other | ✓ |
| Free-text only | No structure for future auto-skip suggestion engine | |
| Larger enum (20+) | Over-specified; ops won't remember distinctions | |

**Auto-choice:** 7 + other. `auto_create_rejections` table persists history indefinitely for future analysis.

---

## Completeness score algorithm (auto-selected)

| Option | Description | Selected |
|--------|-------------|----------|
| Weighted 0-100 per field (Recommended) | 9 weighted components summing to 100; ship gate for publish = 85+ | ✓ |
| Binary "ready / not ready" | No granularity; admin can't prioritise quick-wins | |
| ML-scored (needs training data) | v1 has no training data; speculative | |

**Auto-choice:** Weighted 0-100 with these weights (sum 100): title 15, slug 5, meta_desc 10, short_desc 10, long_desc 15, brand 10, category 10, image 20, price 5.
**Rationale:** Image is single heaviest weight because missing image = unpublishable; long_desc 15 because SEO-critical. Colour-coded Filament badge: red <50, amber 50-84, green 85+. Publish-gate at 85 with override-with-reason path.

---

## ProductOverride pin semantics (auto-selected)

### Sub-Q1: Column shape

| Option | Description | Selected |
|--------|-------------|----------|
| 8 per-field boolean columns on existing product_overrides table (Recommended) | pin_title / pin_short_description / pin_long_description / pin_meta_description / pin_image / pin_slug / pin_brand / pin_category | ✓ |
| Single JSON column of pinned field names | Flexible but harder to query + form-bind | |
| Separate product_pins table | Over-normalised for 8 booleans | |

**Auto-choice:** 8 boolean columns. Extends existing Phase 3 `product_overrides` table (D-08 anticipated forward-compat).

### Sub-Q2: Enforcement mechanism

| Option | Description | Selected |
|--------|-------------|----------|
| Listener-based extension of Phase 2 sync (Recommended) | ApplyPinsDuringSync listener subscribes to SupplierPriceChanged / StockChanged / SkuMissing; intercepts pinned fields; Phase 2 code untouched | ✓ |
| Direct SyncChunkJob modification | Couples Phase 6 into Phase 2; harder to scope regression tests | |

**Auto-choice:** Listener-based extension. Keeps Phase 2 code pristine; `PinnedFieldsSurviveSync` regression test at `tests/Architecture/` is the AUTO-10 ship gate.

---

## Claude's Discretion (defaults documented in CONTEXT.md)

- Image-sourcing fallback chain (supplier.image_url → supplier.image_fallback_urls → placeholder) — supplier schema probed in Plan 01 research step
- Image processing pipeline (intervention/image 1200×1200 → WebP q=85 → EXIF strip → Woo REST /media POST); Windows dev gracefully skips optimizer
- Draft vs immediate-publish gate via `config('product_auto_create.mode', 'draft')` + env + admin-editable Filament Settings Page
- Duplicate detection v1 = casing + whitespace normalisation; fuzzy MPN deferred
- Review inbox = new Filament Resource `AutoCreateReviewResource` with bulk actions
- NewProductOpportunityApplier upgrade (Phase 5 stub → real Phase 6 implementation) closes Phase 5→Phase 6 loop
- Queue routing: CreateWooProductJob on `sync-woo-push`; ProcessAutoCreateImageJob on `sync-bulk`
- Category/brand resolution: case-insensitive + trim match against existing Woo taxonomy; unknowns flagged for admin manual assignment
- `ProductPublished` event emitted for Phase 7 dashboard subscribers

## Deferred Ideas

- Per-brand content templates (research D.2)
- Fuzzy MPN matching for duplicate detection (research D.2)
- AI-generated descriptions (PROJECT.md anti-feature)
- Auto-publish for whitelisted brands (v1.x candidate)
- Rich product variations (research D.4 anti-feature)
- Woo product page preview before publish (research D.3)
- Auto-skip rule suggestion engine (Phase 10 AI-agent territory)
- Image CDN integration (Cloudinary / Imgix)
- Bulk upload from supplier catalogue dump
- State-machine audit view (Phase 7 dashboard candidate)
- Slug redirect trail for edited published-product slugs
- Per-brand immediate-publish override
