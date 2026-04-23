# Phase 6: Product Auto-Create - Context

**Gathered:** 2026-04-19 (auto-mode — recommended options selected without interactive input)
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 6 closes the supplier-feed → Woo-catalogue loop: when Phase 2's supplier sync emits a `NewSupplierSkuDetected` event for a SKU that has no matching Woo product, a queued `CreateWooProductJob` assembles a draft Woo product from an SEO template (title, slug, meta description, long description, brand + category taxonomy), sources an image (supplier DB first, URL lookup fallback, placeholder last), pipes it through `intervention/image` for resize + WebP conversion + EXIF strip, uploads via Woo REST `/media`, sets the product's price via Phase 3's `RuleResolver` + `PriceCalculator`, and parks the product in a Filament review inbox with a completeness score. Admin + pricing_manager approve/edit/reject drafts; rejection captures a structured reason. Draft-first is the v1 default (AUTO-07); immediate-publish is an admin-toggled config flag. A `ProductOverride` model lets admins pin individual fields (title, description, image, price) on products — pinned fields survive subsequent supplier syncs untouched, observed via a regression test. Every `CreateWooProductJob` attempt logs to `integration_events` with full request/response; failed attempts retry per Horizon policy then surface in the notification centre. Auto-skip rules (brand/category/price-floor exclusions) prevent supplier kits + spares + test SKUs from flooding the review inbox.

Scope is fixed by ROADMAP.md Phase 6 and REQUIREMENTS.md AUTO-01..AUTO-11. Discussion resolved research D.2 (duplicate detection + review inbox UX + brand templates — latter deferred), D.3 gaps (rejection reason structure, completeness score shape, auto-skip rules, image handling, slug uniqueness), and Claude's Discretion covers image-sourcing fallback order, Phase 5 orphan-suggestion integration, immediate-publish gate location.

</domain>

<decisions>
## Implementation Decisions

### SEO template shape + shortcodes (AUTO-02)

- **D-01:** **Fixed SEO template with per-brand voice overrides.** v1 ships a single canonical template mirroring the current meetingstore.co.uk product-page layout. Fields populated by template:
  - **Title:** `{brand_name} {model_name} {product_type}` (e.g. "Logitech MeetUp Video Conferencing System")
  - **Slug:** `Str::slug(title)` + uniqueness disambiguation suffix per D-05
  - **Meta description:** `{brand_name} {model_name} — {short_tagline_from_supplier_feed}. {configurable_cta}` (≤160 chars, truncate with ellipsis if over)
  - **Short description (Woo `short_description`):** bullet-point list of the first 5 spec lines from the supplier's `spec_summary` payload, wrapped as `<ul><li>...</li></ul>`
  - **Long description (Woo `description`):** HTML assembled from sections: `<h2>Overview</h2>{supplier_overview}`, `<h2>Key Features</h2><ul>{supplier_features}</ul>`, `<h2>Technical Specifications</h2><table>{supplier_specs_as_rows}</table>`, `<h2>What's in the Box</h2><ul>{supplier_box_contents}</ul>`. Sections with no supplier data are skipped (no empty headers).
- **D-02:** **Template lives in `resources/views/product-auto-create/seo-template.blade.php`** (Blade, not a config array) so editors can tweak HTML without code changes. Shortcodes available: `{{ $brand_name }}`, `{{ $model_name }}`, `{{ $product_type }}`, `{{ $supplier_overview }}`, `{{ $supplier_features }}` (array), `{{ $supplier_specs }}` (array of [label → value]), `{{ $supplier_box_contents }}` (array), `{{ $cta }}` (configurable via `config/product_auto_create.php`). `ProductContentBuilder` service compiles the Blade view → returns `{title, slug, meta_description, short_description, long_description}` array.
- **D-03:** **Brand voice overrides are DEFERRED to a post-v1 enhancement** (research D.2 differentiator). Scope risk is too high for v1 — one template covers 80% of SKUs; per-brand templates can be added as a follow-up phase once ops validates the base template works across 3-5 brands. Schema is forward-compatible (`brand_content_templates` table can be added without breaking existing products).

### Auto-skip rules + rejection reason enum (research D.3 gaps)

- **D-04:** **Auto-skip rules persist in a new `auto_create_skip_rules` table.** Admin-editable Filament Resource. Columns: `id`, `scope` enum(`brand`, `category`, `sku_pattern`, `price_range`), `value` (string — brand name, category slug, SKU regex fragment, or price range like `<50` / `>5000`), `reason` enum (see D-06), `is_active` (bool), `created_by_user_id`, timestamps. Evaluated at `NewSupplierSkuDetected` event-handler entry: if ANY active rule matches, the event is skipped silently (no job dispatched, log to `integration_events` with outcome `auto_skipped` + rule_id reference). Rules auto-seeded with 3 sensible defaults: `brand=SparesPlus` (supplier kits/spares vendor), `sku_pattern=^TEST-`, `price_range=<25`.
- **D-05:** **Slug uniqueness guarantee (AUTO-09).** `ProductSlugGenerator` service: base slug `Str::slug($title)`; on collision with existing Woo product slug, append `-{brand_sku_lower}`; on second collision, append `-{product_id}`. Returns the unique slug + records in the product creation log. Casing-only collisions rejected pre-Woo-POST via `ProductMatcher::existsCaseInsensitive($slug)` check.
- **D-06:** **Rejection reason enum (8 fixed values).** Admin picks from: `not_a_real_product`, `duplicate_of_existing`, `discontinued_by_supplier`, `spare_part_or_accessory`, `poor_quality_data`, `misclassified_brand_or_category`, `below_viability_threshold`, `other` (with mandatory free-text note). Stored in `auto_create_rejections` table with FK to the rejected Woo draft (even though draft is deleted, rejection history survives indefinitely per Phase 1 D-04 audit retention). Enum informs future-phase auto-skip rule suggestions: "Ops rejected 12 Brand X products with reason `spare_part_or_accessory` in the last 90d — suggest adding an auto-skip rule."

### Completeness score algorithm (AUTO-06)

- **D-07:** **Weighted 0-100 completeness score, per-product, recomputed on every save.** Weights:
  - Title present + non-template-default: 15 points
  - Slug valid + unique: 5 points
  - Meta description 100-160 chars: 10 points
  - Short description has ≥3 bullets: 10 points
  - Long description has all 4 sections: 15 points
  - Brand set: 10 points
  - Category set: 10 points
  - Image uploaded AND not placeholder: 20 points
  - Price set AND > £0: 5 points
  - Total: 100 (ship gate for publish = 85+)
- **D-08:** **`CompletenessScorer` service returns `(int $score, array $missing_fields, bool $ready_to_publish)`.** Stored on `products.completeness_score` (new column, integer nullable) + `products.completeness_computed_at` (timestamp) + `products.completeness_missing_fields` (JSON). Filament review inbox shows the score as a colour-coded badge (red <50, amber 50-84, green 85+); sorted descending by default so closest-to-ready appears first.
- **D-09:** **Publish-gate enforcement.** Admin clicking "Publish" on a product with `completeness_score < 85` sees a confirmation modal listing missing fields; can override with reason captured in audit log. Bulk-publish bulk-action silently SKIPS products below threshold + reports the skipped count.

### ProductOverride pin semantics (AUTO-10, AUTO-11)

- **D-10:** **Per-field pin booleans on existing `product_overrides` table** (from Phase 3 D-08 — extended here). New columns added to existing table: `pin_title` (bool, default false), `pin_short_description` (bool, default false), `pin_long_description` (bool, default false), `pin_meta_description` (bool, default false), `pin_image` (bool, default false), `pin_slug` (bool, default false), `pin_brand` (bool, default false), `pin_category` (bool, default false). Existing columns `pin_price` / `margin_basis_points` (from Phase 3) preserved. Migration adds the new pin columns + backfills all existing rows to `false`.
- **D-11:** **Pin enforcement at sync time.** Phase 2's `SyncChunkJob` (already shipped) reads `ProductOverride` for each product about to be updated. For each pinned field, the sync skips the field update (does NOT write to Woo, does NOT compare against supplier). Requires a NEW `ProductOverrideGuard` service injected into `SyncChunkJob` via a listener on `SupplierPriceChanged` / `SupplierStockChanged` / `SupplierSkuMissing` — OR extends Phase 2 `SyncChunkJob` directly to check pins. Planner decides: listener-based extension (cleaner, adds indirection) vs direct `SyncChunkJob` modification (tighter integration, modifies Phase 2 code). **Recommended: listener-based extension** to keep Phase 2's sync logic untouched.
- **D-12:** **Pin-toggle audit trail.** Every pin toggle writes to `audit_log` via `spatie/activitylog` `LogsActivity` trait on `ProductOverride` model (already applied in Phase 3). Filament pin UI on the product edit page (new tab/panel "Field Pins") uses a 9-toggle form (one per field above). `->authorize()` mandatory on the Save action.
- **D-13:** **Regression test: `PinnedFieldsSurviveSync` (tests/Architecture/)** runs a full `sync:supplier --live` cycle after pinning title + description on a product + asserts those fields are byte-identical post-sync. This test is the AUTO-10 ship gate.

### Claude's Discretion

Areas not separately discussed — planner/researcher may pick the default best-practice approach:

- **Image-sourcing fallback chain (AUTO-03).** Default order: (1) `supplier.image_url` field from API response, HEAD request to confirm 200, download; (2) if absent, `supplier.image_fallback_urls` array; (3) placeholder at `storage/app/public/placeholders/av-product.webp`. Planner's research step MUST probe the 21stcav.com supplier API to confirm which image fields exist + response shape. `IntegrationLogger` captures every image-fetch attempt + latency. Failed downloads (404/timeout/invalid image) fall through to next fallback silently; all 3 fail → placeholder + flag `requires_manual_image_review` on the product.
- **Image processing pipeline (AUTO-04).** `intervention/image` (^3.x if Laravel-12-compatible, else ^2.x) + `spatie/image-optimizer` (mozjpeg, pngquant, optipng, webp — installed on the Linux VPS). Pipeline: download → resize to max 1200×1200 (preserve aspect ratio, no upscale) → convert to WebP q=85 → strip EXIF via `->image->removeExif()` → upload via Woo REST `POST /wp-json/wp/v2/media`. Output filename: `{product-slug}-main.webp`. Windows dev note: image-optimizer binaries absent on Windows; intervention alone handles resize+WebP+EXIF strip; optimizer is a no-op on Windows (skipped gracefully).
- **Draft vs immediate-publish gate (AUTO-07).** `config('product_auto_create.mode', 'draft')` — env-overridable `PRODUCT_AUTO_CREATE_MODE`. Admin-editable from a new "Auto-Create Settings" Filament Page (singleton, admin-only). Per-brand override stays deferred (Deferred Ideas). Default ships as `draft`; immediate-publish requires explicit admin toggle + written ops runbook (documented in Phase 7 cutover docs).
- **Duplicate detection (AUTO-08).** v1 covers casing + trailing-whitespace normalisation only. `ProductMatcher::existsNormalised($sku)` compares `strtolower(trim($candidate))` against `strtolower(trim($existing.sku))`. Fuzzy MPN matching deferred (Phase 5 research C.2 called it out; schema is forward-compatible via a confidence_score column that can be added later).
- **Review inbox location.** New Filament Resource `AutoCreateReviewResource` (admin + pricing_manager can view/edit/approve/reject, sales + read_only denied). List view filtered to `products.auto_create_status IN ('draft', 'pending_review')`. Bulk actions: approve-selected (respects D-09 publish gate), reject-with-reason (D-06 enum), bulk-edit-image, bulk-set-category. Per-row quick-edit modal for title + description.
- **`NewSupplierSkuDetected` → Phase 5 orphan bridge.** Phase 5 Plan 05-02 ships `NewProductOpportunityApplier` as a NO-OP STUB (Phase 5 D-08). Phase 6 REPLACES this stub: when admin approves a `new_product_opportunity` suggestion, applier queries the supplier API for the orphan SKU + dispatches `CreateWooProductJob`. This closes the competitor-analysis → auto-create loop (research C.3 differentiator fulfilled).
- **Integration events routing.** `CreateWooProductJob` writes to existing `integration_events` table (Phase 1) with `channel='woo-auto-create'` for filter scoping in the CRM push log / integration events viewer. Failed job after Horizon retry exhaustion → `suggestions('auto_create_failed')` row + AlertRecipient notification via new `receives_auto_create_alerts` boolean (Phase 2/4/5 pattern).
- **Queue routing.** `CreateWooProductJob` runs on `sync-woo-push` (already allocated in Phase 1 FOUND-09) — it's a Woo REST write like supplier sync writes. `ProcessAutoCreateImageJob` (image resize + WebP + upload) runs on `sync-bulk` to avoid blocking the Woo-push queue during bulk brand launches.
- **Category + brand resolution.** Supplier feed's `category` + `brand` strings matched against existing Woo Category/Brand taxonomy (case-insensitive + trimmed). Unknown values land the product as `auto_create_status='needs_brand_or_category_assignment'` in the review inbox; admin manually picks from existing taxonomy OR creates a new term via Filament Quick-create action.
- **Publish action emits `ProductPublished` event** so Phase 7 dashboard + future feed generators (Phase 8) can subscribe. Phase 6 ships the event + fires it on publish; downstream listeners are placeholders in this phase.

### Folded Todos

None — no pending todos matched Phase 6 scope at discussion time.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 2 Supplier Sync (new-SKU producer + sync-pin gate)

- `.planning/phases/02-supplier-sync/02-CONTEXT.md` — D-09 `NewSupplierSkuDetected` event (Phase 2 producer; Phase 6 is the first real consumer)
- `.planning/phases/02-supplier-sync/02-03-SUMMARY.md` — SyncChunkJob pattern; Phase 6 D-11 listener-based pin extension hooks in WITHOUT modifying Phase 2 code
- `.planning/phases/02-supplier-sync/02-04-SUMMARY.md` — Filament Resource patterns (SyncRunResource + ImportIssueResource) — Phase 6's AutoCreateReviewResource follows the shape

### Phase 3 Pricing Engine (ProductOverride extension + price resolution)

- `.planning/phases/03-pricing-engine/03-CONTEXT.md` — D-08 `ProductOverride` model ships in Phase 3 (pin_price + margin override). Phase 6 EXTENDS this table with 8 new pin-* columns per D-10.
- `.planning/phases/03-pricing-engine/03-02-SUMMARY.md` — `RuleResolver` + `PriceCalculator` — Phase 6 uses both when setting a new auto-created product's price; NEVER duplicate resolution logic
- `.planning/phases/03-pricing-engine/03-04-SUMMARY.md` — bulk recompute pattern — not reused but informs D-11 listener-based pin enforcement

### Phase 5 Competitor Analysis (new_product_opportunity applier upgrade)

- `.planning/phases/05-competitor-analysis/05-CONTEXT.md` — D-08 orphan rows produce `new_product_opportunity` suggestions; D-09 cross-competitor dedup
- `.planning/phases/05-competitor-analysis/05-02-SUMMARY.md` — `NewProductOpportunityApplier` NO-OP stub location; Phase 6 replaces with real implementation
- `.planning/phases/05-competitor-analysis/05-04a-SUMMARY.md` — SuggestionResource kind-specific Approve action pattern

### Phase 1 Foundation (audit + alerting + event bus)

- `.planning/phases/01-foundation/01-CONTEXT.md` — suggestions seam (D-14..D-17), AlertRecipient pattern (D-12), retention (D-04..D-09)
- `.planning/phases/01-foundation/01-03-SUMMARY.md` — `DomainEvent` base + `Auditor` + `IntegrationLogger` + `BaseCommand` — Phase 6's CreateWooProductJob/ProcessAutoCreateImageJob/PublishProductCommand all use these
- `.planning/phases/01-foundation/01-04-SUMMARY.md` — `SuggestionApplier` contract — Phase 6 ships the real `NewProductOpportunityApplier` + a new `AutoCreateRetryApplier` for kind `auto_create_failed`
- `.planning/phases/01-foundation/01-05-SUMMARY.md` — `sync-woo-push` + `sync-bulk` queues pre-allocated

### Phase 4 CRM (listener + applier + payload builder patterns)

- `.planning/phases/04-bitrix24-crm-sync/04-03-SUMMARY.md` — listener → queued job → applier pattern mirrors Phase 6 shape; CrmPushRetryApplier registration template applies to AutoCreateRetryApplier

### Project foundations

- `.planning/PROJECT.md` — Core Value (one Laravel app owns product data); Constraint "audit everything + suggestions pattern"; Out-of-scope: AI-generated descriptions (Phase 6 uses template-only)
- `.planning/REQUIREMENTS.md` — AUTO-01 through AUTO-11 acceptance criteria
- `.planning/ROADMAP.md` §Phase 6 — 5 success criteria; depends-on Phases 1 + 2 + 3 + 5
- `.planning/STATE.md` — Open item "supplier image-DB availability" (research-phase deliverable; Claude's Discretion for default fallback chain)

### Research artefacts

- `.planning/research/FEATURES.md` §Module D — D.1 brief items (SEO template, image handling, draft-first, queue+audit, supplier-image-DB-unknown), D.2 differentiators (review inbox, bulk approve, brand templates — deferred, duplicate detection — deferred), D.3 gaps (completeness score, rejection reason, auto-skip rules, image pipeline, slug uniqueness, preview rendering — deferred), D.4 anti-features (AI-generated content, auto-publish-v1, rich variations)
- `.planning/research/PITFALLS.md` — Pitfall 6 (slug collision on Woo REST POST — handled by D-05); Pitfall 7 (nullable columns need backfill path — new ProductOverride pin columns default false per D-10)
- `.planning/research/STACK.md` — `intervention/image` version compatibility; `spatie/image-optimizer` Linux-only binaries; Woo REST `/media` endpoint

### No external specs

No ADRs, RFCs, or external spec documents beyond the above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets (Phase 1–5 delivered)

- **`NewSupplierSkuDetected` event** (Phase 2 D-09) — Phase 6's primary trigger. Already fires after every sync; Phase 2 shipped a no-op stub listener; Phase 6 replaces with real `HandleNewSupplierSku` listener.
- **`ProductOverride` model** (Phase 3 D-08) — extend schema with 8 pin-* boolean columns per D-10.
- **`PriceCalculator` + `RuleResolver`** (Phase 3) — Phase 6 uses both to set auto-created products' initial price (integer-pennies math, single rounding boundary).
- **`NewProductOpportunityApplier`** (Phase 5 stub) — Phase 6 REPLACES with real implementation that dispatches `CreateWooProductJob`.
- **`WooClient`** (Phase 2) — `post()` / `put()` / `patch()` already wrap Woo REST; Phase 6 adds `postMedia()` method for `/wp-json/wp/v2/media` binary upload (or uses existing `post()` with multipart — planner decides).
- **`SuggestionApplier` contract + `ApplySuggestionJob`** (Phase 1 D-17) — register `NewProductOpportunityApplier` (upgraded) + new `AutoCreateRetryApplier` against kind `auto_create_failed`.
- **`DomainEvent` base + `ShouldDispatchAfterCommit`** (Phase 1) — Phase 6's `AutoCreateAttempted` / `AutoCreateSucceeded` / `AutoCreateFailed` / `ProductPublished` events extend the same base.
- **`IntegrationLogger`** (Phase 1) — every Woo POST/PUT/image-upload wraps in `integration_events`.
- **`Auditor`** (Phase 1) — logs `ProductOverride` pin changes + auto-skip rule edits + rejection events.
- **`BaseCommand`** (Phase 1) — `product:auto-create-dry-run` or `product:publish` commands extend this (if Plan ships any CLI).
- **`AlertRecipient` Notifiable** (Phase 1 D-12) — extend with `receives_auto_create_alerts` boolean column.
- **`sync-woo-push` + `sync-bulk` Horizon supervisors** (Phase 1 FOUND-09) — already configured; Phase 6 dispatches onto them.
- **`ThrottledFailedJobNotifier`** (Phase 1) — 5-min dedup on failed auto-create jobs prevents alert storms during a bad Woo-REST outage.
- **Shield RBAC pattern** — seeder LIKE patterns (`%_auto_create_skip_rule`, `%_auto_create_rejection`, `page_AutoCreateSettings`, `page_AutoCreateReview`) auto-attach after `shield:generate --all` (Pitfall P5-F restoration protocol MANDATORY).
- **`SuggestionResource` kind-specific Approve action** (Phase 5 04a) — Phase 6 adds kind `auto_create_failed` + kind `new_product_opportunity` real-applier action.
- **`Product::completeness_score` / `completeness_computed_at` / `completeness_missing_fields`** — new columns on existing Phase 2 `products` table (migration adds).
- **`Product::auto_create_status` enum** — new column on existing products table with values `manual`, `draft`, `pending_review`, `approved`, `published`, `rejected`. Default `manual` (backfill — existing rows are all manually-created).
- **`storage/app/public/placeholders/av-product.webp`** — placeholder image asset ships as part of Phase 6.

### Established Patterns (from Phase 1–5 SUMMARY files)

- **Migration timestamps** — Phase 5 used `2026_04_21_*`; Phase 6 starts `2026_04_22_*` (planner picks exact minutes).
- **Domain layout** — `app/Domain/ProductAutoCreate/` (currently absent) gets populated: `Models/` (`AutoCreateSkipRule`, `AutoCreateRejection`), `Services/` (`ProductContentBuilder`, `ProductSlugGenerator`, `ProductMatcher`, `ProductImageFetcher`, `ProductImageProcessor`, `CompletenessScorer`, `ProductOverrideGuard`), `Jobs/` (`CreateWooProductJob`, `ProcessAutoCreateImageJob`, `PublishProductJob`), `Listeners/` (`HandleNewSupplierSku`, `ApplyPinsDuringSync` — subscribes to Phase 2 SupplierPriceChanged / SupplierStockChanged / SupplierSkuMissing), `Appliers/` (`NewProductOpportunityApplier` upgrade + `AutoCreateRetryApplier`), `Events/` (`AutoCreateAttempted`, `AutoCreateSucceeded`, `AutoCreateFailed`, `ProductPublished`), `Filament/Resources/` (`AutoCreateReviewResource`, `AutoCreateSkipRuleResource`), `Filament/Pages/` (`AutoCreateSettingsPage`), `Policies/`, `Console/Commands/` (minimal — most work is event-driven). Relation to existing `app/Domain/Products/` — Phase 6 EXTENDS `Product` model (new columns + pin booleans on `ProductOverride`) but owns its own domain directory for auto-create-specific services.
- **Deptrac layer** — new `ProductAutoCreate` layer allowed to depend on `Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks`. Deny: `CRM, Competitor, Feeds`. Extend `deptrac.yaml` + `depfile.yaml` (both — Phase 5 lesson) + add `DeptracProductAutoCreateLayerTest`.
- **Filament Resource + Page pattern** — Shield + policy per Phase 1/2/3/4/5 pattern. New Resources + Pages documented above.
- **Policy template integrity** — `tests/Architecture/PolicyTemplateIntegrityTest` auto-checks all Phase 6 policies; floor bumped.
- **Testing DB** — `meetingstore_ops_testing` MySQL (Phase 1 P03). Phase 6 tests follow the same pattern.
- **`->authorize()` on Filament Actions** — Approve draft, Reject draft, Publish, bulk-approve, bulk-reject, Save pins, Toggle auto-create mode.
- **Image-optimizer binaries Windows-hostile** — use `try/catch` around optimizer invocation + log skip on Windows dev; binaries present on Linux VPS.
- **Pitfall P5-F shield:generate restoration protocol** — after every `shield:generate --all`, restore hand-written policies from git. Follow Phase 5 04a documented protocol.
- **Double-config-file Deptrac update** — Phase 5 Plan 05-05 lesson: update BOTH `depfile.yaml` AND `deptrac.yaml` when adding a layer allow-list.

### Integration Points

- **Inbound (event-driven):** `NewSupplierSkuDetected` → `HandleNewSupplierSku` listener → auto-skip-rule check → dispatch `CreateWooProductJob` OR log `auto_skipped`.
- **Inbound (suggestion-driven):** Admin approves `new_product_opportunity` suggestion → `NewProductOpportunityApplier` (real) → dispatch `CreateWooProductJob`.
- **Pin enforcement:** `ApplyPinsDuringSync` listener subscribes to Phase 2's `SupplierPriceChanged` / `SupplierStockChanged` / `SupplierSkuMissing` → checks `ProductOverride.pin_*` → short-circuits the Phase 2 sync write path for pinned fields. No Phase 2 code modification — pure listener extension.
- **Outbound (to Woo):** `CreateWooProductJob` → `ProductContentBuilder.compile()` → `PriceCalculator::compute()` → `WooClient::post('/products', draft_payload)` → `ProcessAutoCreateImageJob` (async, dispatched from success callback) → `WooClient::postMedia(...)` → `WooClient::put('/products/{id}', {image_id})` → emit `AutoCreateSucceeded` event.
- **Outbound (Phase 7 dashboard subscribes):** `ProductPublished` event → dashboard tile "N products published this week" (Phase 7 wires the listener; Phase 6 just emits).
- **New migrations:** `auto_create_skip_rules`, `auto_create_rejections`, `alter products add auto_create_status + completeness_score + completeness_computed_at + completeness_missing_fields`, `alter product_overrides add 8 pin_* boolean columns`, `alter alert_recipients add receives_auto_create_alerts column`.
- **New Filament Resources + Pages:** `AutoCreateReviewResource`, `AutoCreateSkipRuleResource`, `AutoCreateSettingsPage`.
- **Existing extended:** `ProductOverride` (Phase 3), `SuggestionResource` (Phase 1/5), `AlertRecipientResource` (Phase 1/4/5), `Product` model (Phase 2).

</code_context>

<specifics>
## Specific Ideas

- **Placeholder image file.** Ship `storage/app/public/placeholders/av-product.webp` (1000×1000 WebP) as part of Plan 01 migrations — binary asset committed to repo. File served by Laravel's public symlink; URL `{APP_URL}/storage/placeholders/av-product.webp` used when image fetch falls through all fallbacks.
- **The D-11 listener-based pin enforcement is the cleanest architectural choice** — Phase 2's `SyncChunkJob` stays untouched; Phase 6 adds a listener that intercepts and skips writes for pinned fields. Alternative (direct SyncChunkJob modification) was rejected because it couples Phase 6's pin logic with Phase 2's sync logic and makes Phase 2 regression tests harder to scope.
- **The `NewProductOpportunityApplier` upgrade closes the Phase 5→Phase 6 loop.** Phase 5 shipped it as a no-op stub deliberately (documented at the time); Phase 6's replacement makes that applier real. This is NOT scope creep — it's closing a deferred-to-Phase-6 promise from Phase 5 D-08.
- **Supplier image availability is the #1 research item.** Before Plan 01 writes `ProductImageFetcher`, the researcher MUST probe the 21stcav.com supplier API to answer: (a) does a single `image_url` field exist? (b) does a `image_fallback_urls` array exist? (c) what's the typical image format (JPEG/PNG/WebP/mixed)? (d) are there size guarantees (min width/height)? If the supplier has no images, Phase 6 ships with placeholder-only mode + manual-image-review as the PRIMARY flow (not the exception path).
- **Draft-first is a load-bearing default.** AUTO-07 locks this. Immediate-publish is available via config flag but Phase 6 execution MUST NOT enable it by default. Operator runbook update for Phase 7 cutover documents how to flip the flag.
- **Completeness score weights sum to 100 exactly.** Ship gate for auto-publish (if immediate-publish enabled) is 85+. Manual publish from review inbox can override with reason captured in audit log.
- **ProductOverride table is forward-compatible.** Phase 3 shipped the table; Phase 6 adds 8 pin columns. If Phase 7+ adds per-variation pins, `variant_id` nullable column additions remain forward-compatible (Phase 3 D-09 design anticipated this).
- **Rejection enum is intentionally fixed + small.** Free-text `other` catches edge cases but 80% of rejections should fit the 7 structured reasons. Once operational data accumulates, the enum can expand based on `other` note frequency analysis.
- **Regression test `PinnedFieldsSurviveSync` is the AUTO-10 ship gate.** Full `sync:supplier --live` cycle after pinning. The test's presence is CI-enforced (Pest filter name check in `05-05-VERIFICATION.md` pattern).

</specifics>

<deferred>
## Deferred Ideas

These surfaced during analysis or research D.2/D.3 but are explicitly scoped out of Phase 6 to keep the auto-create ship goal tight:

- **Per-brand content templates** (research D.2 differentiator) — post-v1 enhancement. Schema is forward-compatible (`brand_content_templates` table can be added later without breaking existing products). v1 ships a single canonical template per D-01.
- **Fuzzy MPN matching for duplicate detection** (research D.2) — casing/whitespace normalisation in v1. MPN-based + confidence-score matching deferred.
- **AI-generated descriptions** (research D.4 anti-feature) — PROJECT.md constraint: "AI for formatting only, never for inventing scope". Template-only in v1. Phase 10 AI-agent framework may add AI-suggested enrichment AFTER ops approval.
- **Auto-publish without review for whitelisted brands** (research D.4) — v1.x candidate once draft-first establishes trust.
- **Rich product variation auto-creation** (research D.4 anti-feature) — simple products only. Variations require separate variation creation via `POST /products/{id}/variations`. Deferred to v2.
- **Preview rendering of Woo product page before publish** (research D.3) — nice-to-have; defer.
- **Auto-skip rule suggestion engine** (D-06 informs future enhancement) — "Ops rejected 12 Brand X products as `spare_part_or_accessory` — auto-suggest a skip rule" — post-v1 Phase 10 AI-agent territory.
- **Immediate-publish per-brand override** — single global config flag in v1; per-brand granularity is deferred.
- **Image CDN integration (Cloudinary / Imgix)** — Woo REST `/media` upload is the v1 path. CDN offload is post-v1 performance work.
- **Bulk upload from supplier catalogue dump** — v1 trigger is event-driven only (`NewSupplierSkuDetected`). Batch import via artisan command is Phase 7 cutover candidate.
- **Product.auto_create_status state-machine transitions logged as discrete events** — Phase 6 ships the 4 domain events; a Phase 7 state-machine audit view is nice-to-have.
- **SEO quality check (Meta description length enforcement at save)** — Validator fires at save time per D-07; Phase 7 dashboard tile "N products below SEO threshold" is a visualisation candidate.
- **Slug redirect trail** — if admin edits a published product's slug, old slug should 301 to new. Woo has `redirection` plugin semantics; Phase 6 ignores (v1 draft-first means slug is set once pre-publish).

### Reviewed Todos (not folded)

No pending todos matched Phase 6 scope — none to defer.

</deferred>

---

*Phase: 06-product-auto-create*
*Context gathered: 2026-04-19 via auto-mode (recommended defaults selected inline)*
