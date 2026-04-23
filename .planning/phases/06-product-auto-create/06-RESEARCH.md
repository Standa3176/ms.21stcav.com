# Phase 6: Product Auto-Create - Research

**Researched:** 2026-04-22
**Domain:** Event-driven draft-Woo-product auto-creation from supplier feed + Phase 5 orphan suggestions; image pipeline; completeness-scored review inbox; ProductOverride pin semantics.
**Confidence:** HIGH on stack + architecture (Context7-equivalent via Packagist + official docs). MEDIUM on supplier API image-field availability (probe deferred — see Open Questions Q1). HIGH on Woo REST `/wc/v3/products` image-payload contract.

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions (D-01..D-13)

**D-01: SEO template shape.** Fixed canonical template mirroring current meetingstore.co.uk product-page layout. Title = `{brand_name} {model_name} {product_type}`. Slug = `Str::slug(title)` + D-05 disambiguation. Meta = `{brand} {model} — {short_tagline}. {configurable_cta}` (≤160 chars). Short description = `<ul>` of first 5 spec lines. Long description = 4 `<h2>` sections (Overview / Key Features / Technical Specifications / What's in the Box) — sections without supplier data are skipped.

**D-02: Template file location.** `resources/views/product-auto-create/seo-template.blade.php`. Shortcodes: `$brand_name, $model_name, $product_type, $supplier_overview, $supplier_features (array), $supplier_specs (array label→value), $supplier_box_contents (array), $cta (configurable via config/product_auto_create.php)`. `ProductContentBuilder` service compiles Blade → returns `{title, slug, meta_description, short_description, long_description}`.

**D-03: Brand voice overrides DEFERRED post-v1.** Forward-compatible schema (`brand_content_templates` table can be added later without regression).

**D-04: Auto-skip rules table.** New `auto_create_skip_rules` table. Columns: `id, scope enum(brand, category, sku_pattern, price_range), value (string), reason enum (see D-06), is_active bool, created_by_user_id, timestamps`. Evaluated at `NewSupplierSkuDetected` event handler entry; any matching active rule skips silently + logs `integration_events` with `outcome='auto_skipped'` + rule_id. **Auto-seeded with 3 defaults:** `brand=SparesPlus`, `sku_pattern=^TEST-`, `price_range=<25`.

**D-05: Slug uniqueness (AUTO-09).** `ProductSlugGenerator` service: base `Str::slug($title)`; on collision append `-{brand_sku_lower}`; second collision append `-{product_id}`. Records in product-creation log. Casing-only collisions rejected pre-Woo-POST via `ProductMatcher::existsCaseInsensitive($slug)`.

**D-06: Rejection reason enum (7 fixed + 1 `other`).** `not_a_real_product, duplicate_of_existing, discontinued_by_supplier, spare_part_or_accessory, poor_quality_data, misclassified_brand_or_category, below_viability_threshold, other` (mandatory free-text note). Stored in `auto_create_rejections` table FK'd to the rejected draft; rejection history survives indefinitely (per Phase 1 D-04 audit retention).

**D-07: Completeness score weights (sum=100).** title 15, slug 5, meta 10, short description 10, long description 15, brand 10, category 10, image-not-placeholder 20, price 5. Publish gate = 85+.

**D-08: `CompletenessScorer` service** returns `(int $score, array $missing_fields, bool $ready_to_publish)`. Stored on `products.completeness_score` (int nullable) + `completeness_computed_at` (timestamp) + `completeness_missing_fields` (JSON). Filament shows colour-coded badge (red <50, amber 50-84, green 85+); default sort DESC.

**D-09: Publish-gate enforcement.** `<85` shows confirmation modal listing missing fields; admin can override with reason captured in audit log. Bulk-publish silently SKIPS below-threshold rows + reports count.

**D-10: 8 new pin-* columns on existing `product_overrides` table.** `pin_title, pin_short_description, pin_long_description, pin_meta_description, pin_image, pin_slug, pin_brand, pin_category` (bool, default false). Phase 3's `pin_price` / `margin_basis_points` preserved. Migration backfills all existing rows to `false`.

**D-11: Pin enforcement at sync time via LISTENER EXTENSION** (not direct `SyncChunkJob` modification). New `ApplyPinsDuringSync` listener subscribes to Phase 2's `SupplierPriceChanged`/`SupplierStockChanged`/`SupplierSkuMissing` events → `ProductOverrideGuard` short-circuits pinned-field writes. **Phase 2 sync code is NOT modified.**

**D-12: Pin-toggle audit trail.** `ProductOverride` already has `LogsActivity` (Phase 3). Filament pin UI new tab/panel "Field Pins" on product edit page — 8-toggle form. `->authorize()` mandatory on Save.

**D-13: Regression test `PinnedFieldsSurviveSync`.** Lives at `tests/Architecture/`. Runs full `sync:supplier --live` cycle after pinning title + description + asserts fields byte-identical post-sync. AUTO-10 ship gate.

### Claude's Discretion (areas to research + recommend)

- **Image fallback chain (AUTO-03).** Default: `supplier.image_url` → `supplier.image_fallback_urls[]` → placeholder. Probe supplier API to confirm field shape. `IntegrationLogger` records every attempt + latency. 3-way fail → placeholder + `requires_manual_image_review=true`.
- **Image pipeline (AUTO-04).** `intervention/image` (^3.x Laravel-12-compat verified) + `spatie/image-optimizer` (Linux VPS binaries). Pipeline: download → resize max 1200×1200 (no upscale) → WebP q=85 → EXIF strip → upload. Filename `{product-slug}-main.webp`. Windows dev: optimizer binaries absent but intervention alone covers resize+WebP+EXIF; optimizer skipped gracefully.
- **Draft vs immediate-publish (AUTO-07).** `config('product_auto_create.mode', 'draft')` — env `PRODUCT_AUTO_CREATE_MODE`. Admin-editable from new "Auto-Create Settings" Filament Page (singleton, admin-only). Ships as `draft`.
- **Duplicate detection (AUTO-08) v1.** Casing + trailing-whitespace normalisation only. `ProductMatcher::existsNormalised($sku)` compares `strtolower(trim($x))`. Fuzzy MPN matching deferred.
- **Review inbox.** New Filament Resource `AutoCreateReviewResource`. Admin + pricing_manager view/edit/approve/reject; sales + read_only denied. List filtered to `auto_create_status IN ('draft', 'pending_review')`. Bulk actions: approve (respects D-09), reject (D-06 enum), bulk-edit-image, bulk-set-category. Per-row quick-edit modal for title + descriptions.
- **Phase 5 bridge.** `NewProductOpportunityApplier` body (currently no-op stub at `app/Domain/Competitor/Appliers/`) is REPLACED with real body: query supplier API for orphan SKU + dispatch `CreateWooProductJob`.
- **Integration events routing.** `CreateWooProductJob` logs to existing `integration_events` with `channel='woo-auto-create'`. Horizon retry exhaustion → `suggestions('auto_create_failed')` row + `AlertRecipient` notification via new `receives_auto_create_alerts` bool.
- **Queue routing.** `CreateWooProductJob` on `sync-woo-push` (Phase 1 FOUND-09). `ProcessAutoCreateImageJob` (image resize+WebP+upload) on `sync-bulk`.
- **Category + brand taxonomy lookup.** Case-insensitive + trimmed match against existing Woo categories/brands. Unknown → `auto_create_status='needs_brand_or_category_assignment'`; admin picks existing OR Filament Quick-create.
- **Publish emits `ProductPublished` event** for Phase 7 dashboard + future Phase 8 feeds.

### Deferred Ideas (OUT OF SCOPE)

- Per-brand content templates (D-03; post-v1).
- Fuzzy MPN matching for duplicate detection.
- AI-generated descriptions (PROJECT.md constraint: template-only in v1).
- Auto-publish without review for whitelisted brands.
- Rich product variation auto-creation (simple products only in v1).
- Preview rendering of Woo product page before publish.
- Auto-skip rule suggestion engine ("Ops rejected 12 Brand X products — suggest a skip rule").
- Immediate-publish per-brand override (global flag only in v1).
- Image CDN integration (Cloudinary / Imgix). Woo's own media library is v1 path.
- Bulk upload from supplier catalogue dump (event-driven only in v1).
- Product.auto_create_status state-machine transition events.
- SEO quality check at save.
- Slug redirect trail (Woo-side redirection plugin semantics).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| AUTO-01 | Unmatched supplier SKU triggers `NewSupplierSkuDetected` | ALREADY SHIPPED by Phase 2 (SyncSupplierCommand fires per unknown SKU). Phase 6 replaces Phase 2's `StubNewSupplierSkuListener` with real `HandleNewSupplierSku`. See Focus Area F9. |
| AUTO-02 | Draft Woo product created via REST with title/slug/meta/description/brand/category | `ProductContentBuilder` compiles Blade template D-02. `WooClient::post('/products', ...)` exists (Phase 2). Brand + category resolve via Woo taxonomy endpoints (F7). |
| AUTO-03 | Images sourced from supplier DB or placeholder + manual-review flag | `ProductImageFetcher` probes supplier API image field (F1) + falls through fallback chain. Placeholder ships at `storage/app/public/placeholders/av-product.webp`. |
| AUTO-04 | Images resized/WebP/EXIF-stripped via intervention/image | `intervention/image ^3.x` + `spatie/image-optimizer ^1.8` (Linux-only binaries; Windows-graceful). See F2 + F3. |
| AUTO-05 | `CreateWooProductJob` queued + retried + every attempt logged | Extends Phase 1 `ShouldQueue` + `IntegrationLogger`. `integration_events.channel='woo-auto-create'`. Horizon `sync-woo-push` queue. See F5. |
| AUTO-06 | Filament review inbox with completeness + bulk approve + rejection-reason | `AutoCreateReviewResource` + `CompletenessScorer` service (D-07/D-08) + `AutoCreateRejection` model (D-06). See F11. |
| AUTO-07 | Draft-first default; immediate-publish gated by admin config flag | `config('product_auto_create.mode', 'draft')` + `AutoCreateSettingsPage` Filament singleton. |
| AUTO-08 | Duplicate detection on casing + trailing whitespace | `ProductMatcher::existsNormalised($sku)` — `strtolower(trim(...))` compare. v1 scope; fuzzy deferred. |
| AUTO-09 | Slug uniqueness + deterministic collision handling | `ProductSlugGenerator` per D-05 — base + `-{brand_sku}` + `-{product_id}`. Verify Woo-side reconciliation (F6). |
| AUTO-10 | `ProductOverride` pin-per-field; next sync won't overwrite human edit | 8 new pin columns on existing `product_overrides` (D-10). `ApplyPinsDuringSync` listener (D-11) + `ProductOverrideGuard` service. `PinnedFieldsSurviveSync` regression test (D-13). See F10. |
| AUTO-11 | Filament pin UI with per-field toggle + audit trail | New "Field Pins" tab on `ProductResource` detail page — 8-toggle form; Save gated with `->authorize()`. Audit via `LogsActivity` on `ProductOverride`. |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

- **Tech stack LOCKED**: Laravel 12, PHP ^8.2, Filament 3, Horizon + Redis, MySQL, Pest + PHPUnit 11.
- **WooCommerce integration**: REST only, **never direct WP DB writes** (Deptrac `WpDirectDb` layer enforces). Any code that calls `DB::connection('wp_something')` fails CI.
- **Event-driven sync**: Emit domain events (Phase 6 ships 4 new — see Architecture Patterns).
- **Audit everything**: Every job dispatch, pin toggle, rule edit, rejection writes to `audit_log` (`spatie/laravel-activitylog`) or `integration_events`.
- **Suggestions pattern**: Phase 6 is a CONSUMER of `new_product_opportunity` suggestions + a PRODUCER of `auto_create_failed` suggestions.
- **Feed abstraction**: not used directly; Phase 8 builds on Phase 6's `ProductPublished` event.
- **Parity first**: Phase 6 ships draft-first so cutover can happen without mass-publishing bad drafts.
- **GSD Workflow Enforcement**: every Edit/Write goes via `/gsd-execute-phase`; Phase 6 Plans get their own GSD Plan documents.

---

## Summary

Phase 6 closes the supplier-feed → Woo-catalogue loop: unknown SKUs from Phase 2 plus approved `new_product_opportunity` suggestions from Phase 5 assemble into draft Woo products via a Blade SEO template (D-01/D-02), an image pipeline (`intervention/image` v3 + `spatie/image-optimizer`), and a completeness-scored Filament review inbox. A `ProductOverride` pin mechanism (8 new boolean columns extending the Phase 3 table) lets admins lock human-edited fields; a listener — not modifications to Phase 2's `SyncChunkJob` — intercepts sync writes and short-circuits pinned fields.

The biggest single unknown flagged by ROADMAP.md — "Supplier image-DB availability" — **could not be fully resolved by this research pass** because the current `SupplierClient::fetchAllProducts()` returns only `{sku: {price, stock}}` and throws away every other field the supplier API responds with. **Research has established what the Phase 6 supplier client MUST do (fetch the full product record for an unknown SKU) but the actual image-field shape requires a live probe call in Plan 01 Task 1.** See Open Question Q1 — the planner should schedule an early "probe" task that calls the supplier's `/api/index.php?endpoint=products&sku={X}&per_page=1` (or single-SKU equivalent endpoint) and captures a full response sample to `storage/app/research/supplier-probe.json` before writing `ProductImageFetcher`.

The second major finding reshapes AUTO-04: **WooCommerce REST `/wc/v3/products` accepts image URLs directly via `images: [{src: "URL"}]` — Woo downloads + ingests the image into its media library itself. This eliminates the need for a separate `WooMediaClient` or `/wp-json/wp/v2/media` binary upload** — Phase 6 only needs to host the processed WebP at a URL Woo can reach (i.e. the public Laravel storage symlink or a signed S3/CDN URL). This simplifies the architecture significantly (no WP Application Password; the existing Woo consumer key on `WooClient` is the only auth path required).

**Primary recommendation:** Ship Phase 6 in **6 plans** (06-01 through 06-06 — breakdown below) with Plan 01 Task 1 as an explicit supplier-API probe that outputs a 06-02 dependency before `ProductImageFetcher` is written. Use `intervention/image ^3.11` (NOT v4 — v4 requires PHP 8.3+ and the project floor is 8.2). Woo image upload via URL pass-through (not binary multipart). Integer-pennies initial-price resolution via the existing Phase 3 `RuleResolver` + `PriceCalculator`.

---

## Standard Stack

### Core (new packages required for Phase 6)

| Library | Version | Purpose | Why Standard | Confidence |
|---------|---------|---------|--------------|------------|
| `intervention/image` | `^3.11` (NOT v4) | Image resize + WebP encode + EXIF strip | De-facto Laravel PHP image library (100M+ downloads). v3 is actively maintained for PHP 8.2+. **v4 released April 2026 requires PHP 8.3+ — project floor is 8.2, so v4 is out.** `[VERIFIED: Packagist](https://packagist.org/packages/intervention/image)` | HIGH |
| `intervention/image-laravel` | `^1.5` | Laravel integration (facade + config publish) | Companion package; `^1.5` supports Laravel 8–13 + intervention/image `^3.11`. `[VERIFIED: GitHub](https://github.com/Intervention/image-laravel)` | HIGH |
| `spatie/image-optimizer` | `^1.8` | JPEG/PNG/WebP binary optimisation (Linux VPS) | Standard Spatie package. **Graceful degradation on missing binaries** — returns silently when mozjpeg/pngquant/cwebp absent (Windows dev). Confirmed: "the package will not throw any errors and just operate silently". `[VERIFIED: Packagist](https://packagist.org/packages/spatie/image-optimizer)` | HIGH |

### Supporting (already in project — reused)

| Library | Version (installed) | Purpose | Phase 6 Use |
|---------|---------------------|---------|-------------|
| `automattic/woocommerce` | `3.1` | Woo REST client | Post draft products + update with image URLs. Existing `WooClient::post()/get()/put()` covers the whole surface. |
| `laravel/framework` | `^12.0` | Laravel | Jobs, listeners, events, Blade templates, observers, queues. |
| `filament/filament` | `^3.3` | Admin panel | `AutoCreateReviewResource` + `AutoCreateSkipRuleResource` + `AutoCreateSettingsPage`. |
| `laravel/horizon` | `^5.45` | Queue supervisor | `CreateWooProductJob` → `sync-woo-push`; `ProcessAutoCreateImageJob` → `sync-bulk`. |
| `spatie/laravel-activitylog` | `^4.12` | Audit | Pin toggles, skip-rule edits, rejection events, auto_create_status transitions. |
| `bezhansalleh/filament-shield` | `^3.3` | RBAC | Auto-generates permissions for new Resources — LIKE patterns `%_auto_create_skip_rule`, `%_auto_create_rejection`, `page_AutoCreateSettings`, `page_AutoCreateReview` auto-attach to roles. |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `intervention/image ^3.11` | `intervention/image ^4.0` | v4 requires PHP 8.3+ — project floor is 8.2 (composer.json). Rejected. Can be revisited after a PHP 8.3 bump in a later phase. |
| Woo REST URL pass-through for images | WP `/wp-json/wp/v2/media` binary upload | URL pass-through is simpler (existing consumer_key auth works + no Application Password plumbing) and the documented Woo behaviour. Binary upload is only needed if the processed image cannot be hosted at a URL Woo can reach — edge case for Phase 6. `[CITED: rudrastyh.com](https://rudrastyh.com/woocommerce/rest-api-create-product-with-images.html)` |
| `spatie/image-optimizer` binaries | Skip optimizer (intervention-only) | Optimizer shaves 15-40% off WebP byte size on Linux. Ship the package, wrap the call in try/catch, log `optimizer_unavailable` on Windows. Clean fallback; no decision forced. |
| Fuzzy MPN duplicate detection | `ProductMatcher` v1 (casing + whitespace) | CONTEXT D-discretion locks casing-only for v1. Fuzzy = deferred. `ProductMatcher` has a forward-compat `confidence_score` column on the match result — future fuzzy impl drops in without schema change. |

**Installation:**

```bash
composer require intervention/image:"^3.11" intervention/image-laravel:"^1.5" spatie/image-optimizer:"^1.8"
```

**Version verification (2026-04-22):**

| Package | Verified version | Publish date | Source |
|---------|-----------------|--------------|--------|
| intervention/image | 3.11.x current / 4.0.1 (April 7 2026 — PHP 8.3+) | 2026 | `[VERIFIED: Packagist](https://packagist.org/packages/intervention/image)` |
| intervention/image-laravel | 1.5.8 | 2026 | `[VERIFIED: GitHub](https://github.com/Intervention/image-laravel)` |
| spatie/image-optimizer | 1.8.1 | 2025-11-26 | `[VERIFIED: Packagist](https://packagist.org/packages/spatie/image-optimizer)` |

---

## Architecture Patterns

### Recommended Project Structure (app/Domain/ProductAutoCreate/)

```
app/Domain/ProductAutoCreate/
├── Models/
│   ├── AutoCreateSkipRule.php          # D-04
│   └── AutoCreateRejection.php          # D-06
├── Services/
│   ├── ProductContentBuilder.php        # D-01/D-02 Blade template compile
│   ├── ProductSlugGenerator.php         # D-05
│   ├── ProductMatcher.php               # AUTO-08 + D-05 casing check
│   ├── ProductImageFetcher.php          # AUTO-03 fallback chain
│   ├── ProductImageProcessor.php        # AUTO-04 intervention/image pipeline
│   ├── CompletenessScorer.php           # D-07/D-08
│   ├── ProductOverrideGuard.php         # D-11 pin enforcement
│   └── TaxonomyResolver.php             # Woo brand + category lookup
├── Jobs/
│   ├── CreateWooProductJob.php          # Orchestrates draft creation
│   ├── ProcessAutoCreateImageJob.php    # Image pipeline async
│   └── PublishProductJob.php            # draft → published
├── Listeners/
│   ├── HandleNewSupplierSku.php         # Replaces Phase 2 stub
│   └── ApplyPinsDuringSync.php          # D-11 pin enforcement
├── Appliers/
│   ├── NewProductOpportunityApplier.php # REAL body (moves from app/Domain/Competitor/ OR stays + body-replace — see Q9)
│   └── AutoCreateRetryApplier.php       # kind='auto_create_failed' replay
├── Events/
│   ├── AutoCreateAttempted.php
│   ├── AutoCreateSucceeded.php
│   ├── AutoCreateFailed.php
│   └── ProductPublished.php
├── Filament/
│   ├── Resources/
│   │   ├── AutoCreateReviewResource.php
│   │   └── AutoCreateSkipRuleResource.php
│   └── Pages/
│       └── AutoCreateSettingsPage.php   # singleton admin-only
├── Policies/
│   ├── AutoCreateSkipRulePolicy.php
│   └── AutoCreateRejectionPolicy.php
└── Observers/
    └── ProductCompletenessObserver.php  # recomputes score on Product save
```

### Pattern 1: Listener → Queued Job → Applier (Phase 4 precedent)

**What:** Event fires → listener enqueues a job → job calls domain services → on failure, suggestion row created + replay applier registered.

**When to use:** Every inbound event-driven operation in Phase 6 (new supplier SKU, suggestion approved, auto-create retry).

**Example (mirrors Phase 4 Plan 03):**

```php
// Listener — light; only queues job. Respects 200ms webhook budget (if triggered by webhook).
final class HandleNewSupplierSku implements ShouldQueue
{
    public string $queue = 'sync-bulk'; // reading skip rules + dispatching is cheap

    public function handle(NewSupplierSkuDetected $event): void
    {
        // D-04 skip-rule check
        if ($this->skipRules->shouldSkip($event->sku, $event->supplierPrice)) {
            $this->logger->log([
                'channel' => 'woo-auto-create',
                'operation' => 'auto_skipped',
                'request_body' => ['sku' => $event->sku, 'rule_id' => $match->id],
                'status' => 'success',
                'correlation_id' => $event->correlationId,
            ]);
            return;
        }

        CreateWooProductJob::dispatch($event->sku);
    }
}
```

### Pattern 2: Event Emission Post-DB-Commit (Phase 1 DomainEvent base)

**What:** Events extend `App\Foundation\Events\DomainEvent` which implements `ShouldDispatchAfterCommit` (retrofitted in Phase 2 Plan 03 — P2-I). Rolled-back transactions don't leak events to downstream listeners.

**Example:**

```php
final class AutoCreateSucceeded extends DomainEvent
{
    public function __construct(
        public readonly int $productId,
        public readonly int $wooProductId,
        public readonly string $sku,
        public readonly string $slug,
        public readonly int $completenessScore,
        public readonly string $autoCreateStatus,
    ) {
        parent::__construct(); // seeds correlation_id from Context
    }
}
```

### Pattern 3: SuggestionApplier Registration (Phase 1 D-17 + Phase 4/5 precedent)

**What:** `SuggestionApplierResolver` singleton holds `kind → applier-class` map. AppServiceProvider::boot registers each applier.

**Example (Phase 6 additions):**

```php
// app/Providers/AppServiceProvider.php::boot()
$resolver = $this->app->make(SuggestionApplierResolver::class);

// Phase 6: REAL body for new_product_opportunity (replaces Phase 5 stub)
$resolver->register('new_product_opportunity', NewProductOpportunityApplier::class);

// Phase 6: retry applier for exhausted CreateWooProductJob
$resolver->register('auto_create_failed', AutoCreateRetryApplier::class);
```

### Pattern 4: Pure Service + Observer (Phase 3 precedent)

**What:** `CompletenessScorer::score(Product $p): array` is a pure function. An Eloquent observer wires it into Product save lifecycle.

**Example:**

```php
final class ProductCompletenessObserver
{
    public function __construct(private CompletenessScorer $scorer) {}

    public function saving(Product $product): void
    {
        // Only for auto-create products; observer is a no-op for manual rows
        if ($product->auto_create_status === 'manual') return;

        [$score, $missing, $ready] = $this->scorer->score($product);

        $product->completeness_score = $score;
        $product->completeness_missing_fields = $missing;
        $product->completeness_computed_at = now();
    }
}
```

**Phase 2 `saveQuietly` interaction** — Phase 2's `SyncChunkJob` uses `forceFill + saveQuietly` which bypasses observers. This is a risk for completeness recomputation on price/stock updates (**see Pitfall P6-E**). Mitigation: have the observer ALSO register on the `saving` event via `Product::saving(fn ...)` in a service provider OR have the ProductOverrideGuard / sync path explicitly call `$scorer->score($product)` when a pinned/unpinned price changes. Recommended: add the observer + rely on `Product::saved` as a backup hook since `saveQuietly` does skip `saving` but does NOT skip observers registered via `Product::saved(...)` closures in service providers (Laravel 12 behaviour — verify in Plan 01 Task 1).

### Pattern 5: Deptrac Dual-Config-File Sync (Phase 5 Plan 05 lesson)

**What:** Both `depfile.yaml` AND `deptrac.yaml` exist in the project. The runtime tool prefers `deptrac.yaml`, but 4 existing architecture tests target `depfile.yaml` via explicit `--config-file=`. Any layer-edge change must update BOTH files in the SAME commit.

**Phase 6 ruleset addition:**

```yaml
# Both deptrac.yaml AND depfile.yaml:
ProductAutoCreate:
  - Foundation
  - Products
  - Pricing
  - Sync
  - Suggestions
  - Alerting
  - Webhooks       # only if any listener subscribes to WebhookReceipt (verify — likely not)
  - Competitor     # ONLY if NewProductOpportunityApplier STAYS in app/Domain/Competitor (see Q9)
```

Explicit deny: `CRM, Feeds`. Phase 5's `Competitor` layer does NOT gain `ProductAutoCreate` (one-way arrow).

**Verification test** (`tests/Architecture/DeptracProductAutoCreateLayerTest.php` — 4 it-blocks mirroring `DeptracCompetitorLayerTest`):

1. Positive (clean codebase → exit 0)
2. Negative: CRM-import violator (parameter type-hint — NOT `::class` constant — per Phase 5 Plan 05 lesson)
3. Negative: Feeds-import violator
4. Assertion that `depfile.yaml` AND `deptrac.yaml` grep for the allow-list entries

### Anti-Patterns to Avoid

- **Modifying `SyncChunkJob` to check pins** — D-11 explicitly forbids this. Use the listener pattern instead so Phase 2 sync logic stays untouched.
- **Hand-rolling a slug generator** — use `Str::slug()` as the base (per D-05) with D-05's deterministic disambiguation; never invent a custom slug algorithm.
- **Hand-rolling image resize / WebP / EXIF strip** — `intervention/image` is the standard; see "Don't Hand-Roll" table.
- **Binary upload to `/wp-json/wp/v2/media`** — not needed; `images: [{src: URL}]` via `/wc/v3/products` is the documented Woo path.
- **AI-generated descriptions** — PROJECT.md constraint. Template-only via Blade (D-02).
- **Inline image processing in `CreateWooProductJob`** — violates single-responsibility. Split via `ProcessAutoCreateImageJob` chained after draft creation (Phase 5 Plan 02 Bus::batch `->then()` precedent).
- **Direct final-price computation inside `CreateWooProductJob`** — always go through Phase 3's `RuleResolver::resolve()` + `PriceCalculator::compute()`. Never duplicate pricing math.

---

## Runtime State Inventory

Phase 6 is **greenfield — no renames/refactors**. Category check per protocol:

| Category | Finding |
|----------|---------|
| Stored data | None — Phase 6 adds new tables (`auto_create_skip_rules`, `auto_create_rejections`) + new columns; no existing data is renamed. Existing `products` rows get `auto_create_status='manual'` backfilled (migration default). |
| Live service config | None — no external services have Phase 6 string names pre-registered. Woo has categories/brands but those are looked up at runtime via the Woo REST API (not registered by Phase 6). |
| OS-registered state | None. |
| Secrets / env vars | One new env var: `PRODUCT_AUTO_CREATE_MODE` (default `draft`). No secrets. |
| Build artifacts / installed packages | 3 new composer packages (intervention/image, intervention/image-laravel, spatie/image-optimizer). Normal `composer update` cycle. Filament Shield `shield:generate --all` will regenerate permission rows; handled via the P5-F restoration protocol. |

Nothing to backfill beyond the standard `auto_create_status='manual'` default on existing `products` rows.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Image resize preserving aspect ratio without upscaling | Custom `imagecopyresampled` wrapper | `intervention/image` v3 `->scaleDown(1200, 1200)` | Handles exact fit-in-box semantics, no-upscale, multi-driver (GD/Imagick/libvips). `[CITED: image.intervention.io/v3](https://image.intervention.io/v3/modifying-images/resizing)` |
| WebP encoding with EXIF strip | Native `imagewebp` + custom metadata removal | `->toWebp(quality: 85, strip: true)->save(...)` | v3 exposes `strip` param directly. `[CITED: image.intervention.io/v3](https://image.intervention.io/v3/basics/image-output)` |
| Post-resize binary optimisation | Shell out to mozjpeg / pngquant | `spatie/image-optimizer` | Graceful degrade when binaries missing (Windows dev); unified interface for 7+ optimisers. |
| SKU duplicate detection | Custom Levenshtein / LIKE queries | D-05 + D-08 — `ProductMatcher` with casing+trim normalisation v1 | Fuzzy matching is explicitly deferred. Forward-compat schema for v2. |
| Slug uniqueness at client-side | Custom sequence counter | `Str::slug()` + D-05 deterministic disambiguation; reconciled with Woo's own slug de-dup (see F6) | Woo auto-appends `-2`/`-3` etc.; D-05's generator produces deterministic alternatives that match Woo's behaviour — no reconciliation drift. |
| VAT-inclusive price computation | Custom `$price * 1.2` | Phase 3 `PriceCalculator::compute(supplierPennies, marginBps)` | Integer-pennies math; single `HALF_UP` rounding at boundary; golden-fixture-pinned. NEVER duplicate pricing logic. |
| Pricing rule resolution | Custom if/elseif on brand/category | Phase 3 `RuleResolver::resolve(Product $p)` | Most-specific-wins resolver; chain explainability; purity-guarded. |
| Woo REST retries / 429 backoff | Custom Guzzle middleware | Existing `WooClient::writeLive()` 429 exponential backoff (Phase 2 Plan 02) | Already handles 5 retries, Retry-After header, jitter, RateLimitExceededException. |
| Audit log of pin toggles | Custom audit table | `spatie/laravel-activitylog` `LogsActivity` trait on `ProductOverride` (already attached in Phase 3) | One audit store; `->logOnlyDirty()` on pin columns. |
| HMAC / auth for image URL hosting | Signed URL generation | `Storage::temporaryUrl()` if using S3; public symlink for local disk | Laravel standard; Woo consumes the URL once to import the image then stores its own copy. |
| Image binary upload to WP media library | `WooMediaClient` + multipart form | `images: [{src: URL}]` in the `/wc/v3/products` POST body | Documented Woo behaviour — Woo downloads the URL itself. `[CITED: rudrastyh.com](https://rudrastyh.com/woocommerce/rest-api-create-product-with-images.html)` |

**Key insight:** Almost every Phase 6 problem has a standard Laravel / Spatie / Intervention solution. The ONLY bespoke services are domain-specific (`ProductContentBuilder`, `ProductImageFetcher`, `CompletenessScorer`, `ProductOverrideGuard`) — and each one stays narrow (pure functions where possible, no framework duplication).

---

## Common Pitfalls

### Pitfall P6-A: Supplier image URL might redirect or 404 silently

**What goes wrong:** `ProductImageFetcher` downloads `supplier.image_url` without pre-flight verification; a 302→404 redirect or a supplier-CDN outage serves a 0-byte file. `intervention/image` throws on invalid decode → `CreateWooProductJob` fails → the draft never gets a proper image. Worse: a 302 to an HTML "not found" page downloads successfully with Content-Type `text/html` and the pipeline tries to decode it as JPEG.

**Why it happens:** Supplier CDNs are not under our control; HTTP fetch without Content-Type / content-length verification is a classic supply-chain assumption.

**How to avoid:**
1. **HEAD request first** — confirm 200 + `Content-Type: image/*` before GET. If HEAD fails or returns wrong Content-Type, fall through to next fallback.
2. **Verify decode succeeds** — wrap `$manager->read($binary)` in try/catch; log + fall through on `DecoderException`.
3. **Size sanity check** — reject downloads <5KB (likely HTML error page) or >10MB (DoS via huge file).
4. **IntegrationLogger** every attempt with `operation='image.fetch.{attempt_num}'` + latency — makes debugging a supplier outage trivial.

**Warning signs:** drafts land in inbox with placeholder but `IntegrationLogger` shows 200 OK on image.fetch (means decode failed post-download); `requires_manual_image_review` flag count spikes after a supplier deploy.

### Pitfall P6-B: intervention/image v3 vs v2 method-name drift

**What goes wrong:** Copy-pasting a v2 tutorial (common on StackOverflow) uses `->fit(1200, 1200)` — **does not exist in v3**. v3 has `->cover()`, `->contain()`, `->scale()`, `->scaleDown()`. Wrong method silently produces wrong output (e.g. `->cover()` crops; we want `->scaleDown()` for no-upscale contained-fit).

**Why it happens:** v3 was a full rewrite (not a refactor); API changed significantly. Training data / blog posts skew v2.

**How to avoid:**
1. **Pin to `^3.11`** (NOT v2.7). Lock the composer constraint.
2. **Use v3 API methods only.** For Phase 6: `->scaleDown(1200, 1200)` is the correct "max-fit-no-upscale" call. `->toWebp(quality: 85, strip: true)` is the correct encode+EXIF-strip. `[CITED: image.intervention.io/v3/modifying-images/resizing](https://image.intervention.io/v3/modifying-images/resizing)`
3. **Write a small `ProductImageProcessor` unit test** that asserts output ≤1200 on each axis + WebP mime + no EXIF bytes in output.

**Warning signs:** product images are square-cropped when they should be letterboxed; output WebP retains GPS/camera EXIF.

### Pitfall P6-C: Image-optimizer Windows binaries absent — hard crash

**What goes wrong:** A developer runs Phase 6 tests on Windows; `spatie/image-optimizer` tries to exec `pngquant` / `cwebp` / `mozjpeg` — none exist on the dev PATH. Without a try/catch wrapper, the optimizer could throw `ProcessFailedException`.

**Why it happens:** spatie/image-optimizer does graceful-degrade (skips missing binaries silently per the README), BUT some binaries call sub-binaries that can fail differently — and defensive logging is always cheap.

**How to avoid:**
1. **Wrap optimizer invocation in try/catch** — log `optimizer_unavailable` + continue. Don't propagate.
2. **Config flag** `product_auto_create.optimize_images` — `true` in production, `false` on Windows dev.
3. **CI tests skip the optimizer assertion on Windows** — detect via `PHP_OS_FAMILY === 'Windows'`.

**Warning signs:** CI green on Linux; red on Windows with `sh: pngquant: not found` in logs.

### Pitfall P6-D: `auto_create_status` migration backfill omitted

**What goes wrong:** Migration adds `auto_create_status` column with no default; existing `products` rows (Phase 2 + Phase 5 have populated hundreds/thousands) get NULL. Filament review inbox filter `WHERE auto_create_status IN ('draft', ...)` excludes them (correct), but any code path that expects a non-NULL value (e.g., state-machine transition check) blows up.

**Why it happens:** Standard additive-migration pitfall. Phase 5 Pitfall 7 in research/PITFALLS.md is the canonical warning.

**How to avoid:**
1. **Migration default** `->default('manual')` — all existing rows inherit `'manual'`.
2. **Backfill step** in migration `up()`: `DB::table('products')->whereNull('auto_create_status')->update(['auto_create_status' => 'manual']);` — belt-and-braces for races where the default doesn't apply.
3. **Test** (`tests/Feature/Phase06DataModelTest`) asserts post-migration that every pre-existing product has `auto_create_status='manual'`.

**Warning signs:** Filament inbox shows zero drafts after deploy on prod (filter excluded NULL); sync pipeline throws on NULL enum.

### Pitfall P6-E: CompletenessScorer observer + Phase 2 `saveQuietly` conflict

**What goes wrong:** Observer registered on `Product::saving` never fires during Phase 2's sync because `forceFill + saveQuietly` bypasses observers. Result: completeness score stays stale after price/stock updates; review-inbox sort order is wrong.

**Why it happens:** Phase 2 Plan 01 key-decision locked `forceFill + saveQuietly` for sync writes to avoid activity_log bloat — necessary for 15k-SKU runs. Observer fires only on "normal" saves.

**How to avoid:**
1. **Observer listens on `saved` (NOT `saving`)** — Laravel 12 `saveQuietly` skips `saving`/`updating` events but NOT model events registered via `static::saved()` closures in service providers. Verify behaviour in Plan 01 Task 1 before cementing.
2. **Alternative: listener on Phase 2 events.** Subscribe `CompletenessScorer` to `ProductPriceChanged` (Phase 3) + `SupplierStockChanged` (Phase 2) — recompute + write the score via `forceFill + saveQuietly` in the listener. Cleaner boundary (no observer indirection) but adds two listeners.
3. **Explicit call from the Sync path** — `SyncChunkJob` calls `$scorer->score($product)` + persists before commit. Rejected because it modifies Phase 2 code (D-11 parallel: avoid touching Phase 2).
4. **Test** (`tests/Feature/CompletenessRecomputesAfterSync`) runs a full sync cycle on a draft product and asserts the score changes.

**Recommended approach:** Option 2 (listener on `ProductPriceChanged` + `SupplierStockChanged`). Cleanest boundary; no Phase 2 code change; observer remains as the "fresh-save" fallback.

**Warning signs:** Review inbox sort order never changes after sync; completeness_computed_at never updates beyond initial value.

### Pitfall P6-F: Placeholder image + public-storage symlink on prod VPS

**What goes wrong:** Phase 6 ships `storage/app/public/placeholders/av-product.webp` at deploy. Woo REST accepts the placeholder via `src` URL. But `{APP_URL}/storage/placeholders/av-product.webp` only resolves if `php artisan storage:link` was run on the prod VPS — new deploys often miss this step.

**Why it happens:** Laravel `storage/` to `public/storage` symlink is a one-off post-deploy step; easy to forget in a new-box provisioning runbook.

**How to avoid:**
1. **Deploy playbook update** — explicit step: `php artisan storage:link` (idempotent; safe to re-run).
2. **Smoke test** on Phase 6 deploy: `curl -I {APP_URL}/storage/placeholders/av-product.webp` must return 200 before enabling auto-create.
3. **Alternative — host placeholder outside storage/** — drop it in `public/images/av-product-placeholder.webp` so it doesn't need the symlink. Tradeoff: loses the "uploaded assets" semantic for the asset.
4. **Test** in `tests/Feature/PlaceholderImageAccessibleTest` asserts the file exists + asserts `Storage::disk('public')->exists('placeholders/av-product.webp')` after seeder ran.

**Recommended:** host in `public/images/` (skip symlink dependency entirely) since the placeholder is a shipped asset, not an uploaded one.

**Warning signs:** drafts created with `image_url={APP_URL}/storage/placeholders/...` return 404 when Woo tries to fetch → Woo POST succeeds (image URL not validated at POST time) but product ends up with no image → completeness score capped at 80.

### Pitfall P6-G: Woo-side slug auto-disambiguation vs D-05 client-side generator

**What goes wrong:** D-05's generator produces `logitech-meetup` then Woo's `wp_unique_post_slug()` sees a collision with an already-existing `logitech-meetup` post and silently renames to `logitech-meetup-2`. Our Laravel `Product.slug` column still says `logitech-meetup` → divergence between Laravel-expected slug and Woo-actual slug.

**Why it happens:** Woo DOES auto-modify slugs server-side (WordPress core behaviour). D-05 says we reject casing-only dupes + generate deterministic alternatives — but we only check OUR own Laravel `products.slug` column; we don't pre-scan Woo.

**How to avoid:**
1. **Pre-scan Woo** before POST: `GET /wc/v3/products?slug={candidate}` returns empty array if free; if non-empty, fall through to D-05's `-{brand_sku}` → `-{product_id}` fallback.
2. **Post-POST reconciliation** — the Woo POST response returns the actual persisted slug; `CreateWooProductJob` MUST store `$response['slug']` on `Product.slug`, not the generator's candidate. Drift between Laravel and Woo is resolved by Woo-wins-on-create semantics.
3. **Test** (`tests/Feature/SlugDivergenceTest`) mocks Woo returning `logitech-meetup-2` when we POSTed `logitech-meetup` and asserts Laravel persists the Woo-returned slug.

**Warning signs:** Admin clicks a product in the review inbox, clicks "View on store" link built from Laravel's slug → 404; Woo admin shows slug-2 variant.

---

## Code Examples

### Example 1: ProductImageProcessor (intervention/image v3 pipeline)

```php
// Source: adapted from https://image.intervention.io/v3/basics/image-output
// + https://image.intervention.io/v3/modifying-images/resizing
declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use Intervention\Image\ImageManager;
use Intervention\Image\Encoders\WebpEncoder;

final class ProductImageProcessor
{
    public function __construct(
        private ImageManager $manager,
    ) {}

    /**
     * Resize + WebP + EXIF strip pipeline.
     *
     * @param string $binaryInput Raw image bytes from supplier/placeholder.
     * @return string             WebP-encoded bytes, max 1200x1200, EXIF stripped.
     */
    public function process(string $binaryInput): string
    {
        $image = $this->manager->read($binaryInput);

        // scaleDown: fit in box, preserve aspect, NEVER upscale
        $image->scaleDown(width: 1200, height: 1200);

        // WebP q=85 + strip EXIF in one call
        $encoded = $image->toWebp(quality: 85, strip: true);

        return (string) $encoded;
    }
}
```

### Example 2: CreateWooProductJob orchestrator (follow-up image job pattern)

```php
// Source: pattern from Phase 4 Plan 03 PushOrderToBitrixJob + Phase 5 Plan 02 Bus::batch
declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Jobs;

use App\Domain\ProductAutoCreate\Events\{AutoCreateAttempted, AutoCreateSucceeded, AutoCreateFailed};
use App\Domain\ProductAutoCreate\Services\{ProductContentBuilder, ProductSlugGenerator, ProductMatcher, TaxonomyResolver, CompletenessScorer};
use App\Domain\Pricing\Services\{RuleResolver, PriceCalculator};
use App\Domain\Sync\Services\WooClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class CreateWooProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 300, 1800]; // 30s, 5m, 30m — mirrors Phase 4 Pitfall P4-B

    public function __construct(public readonly string $sku)
    {
        $this->onQueue('sync-woo-push'); // AVOID public string $queue (PHP 8.4 trait collision — Phase 5 Plan 02 lesson)
    }

    public function handle(
        WooClient $woo,
        ProductContentBuilder $content,
        ProductSlugGenerator $slug,
        ProductMatcher $matcher,
        TaxonomyResolver $taxonomy,
        RuleResolver $resolver,
        PriceCalculator $calculator,
        CompletenessScorer $scorer,
    ): void {
        event(new AutoCreateAttempted($this->sku));

        // AUTO-08: duplicate check
        if ($matcher->existsNormalised($this->sku)) {
            event(new AutoCreateFailed($this->sku, reason: 'duplicate'));
            return;
        }

        // Fetch full supplier record (NEW method — see Focus Area F1)
        $supplierData = app(SupplierClient::class)->fetchSingleProduct($this->sku);

        // AUTO-02: compile Blade SEO template
        $compiled = $content->compile($supplierData);

        // AUTO-09: unique slug
        $uniqueSlug = $slug->generate($compiled['title'], $this->sku);

        // AUTO-02: taxonomy resolution
        $brandTermId = $taxonomy->resolveBrand($supplierData['brand']);
        $categoryTermId = $taxonomy->resolveCategory($supplierData['category']);

        // Initial price via Phase 3 engine
        $buyPennies = (int) round(((float) $supplierData['price']) * 100);
        $product = Product::create([
            'sku' => $this->sku,
            'name' => $compiled['title'],
            'buy_price' => $buyPennies / 100,
            'brand_id' => $brandTermId,
            'category_id' => $categoryTermId,
            'auto_create_status' => 'draft',
            'status' => 'draft',
        ]);
        $resolution = $resolver->resolve($product);
        $sellPennies = $calculator->compute($buyPennies, $resolution->marginBasisPoints);

        // Build Woo payload — IMAGES PASSED VIA src URL (no binary upload — see Focus Area F4)
        $payload = [
            'name' => $compiled['title'],
            'slug' => $uniqueSlug,
            'status' => 'draft', // AUTO-07 draft-first default
            'type' => 'simple',
            'sku' => $this->sku,
            'regular_price' => (string) number_format($sellPennies / 100, 2, '.', ''),
            'short_description' => $compiled['short_description'],
            'description' => $compiled['long_description'],
            'meta_data' => [['key' => '_yoast_wpseo_metadesc', 'value' => $compiled['meta_description']]],
            'categories' => $categoryTermId ? [['id' => $categoryTermId]] : [],
            // NO 'images' key here — the follow-up job adds it once the image URL is public
        ];

        $wooResponse = $woo->post('/products', $payload);

        // IMPORTANT: Store Woo-returned slug (may differ from generator output — Pitfall P6-G)
        $product->forceFill([
            'woo_product_id' => $wooResponse['id'],
            'slug' => $wooResponse['slug'], // reconcile with Woo's final slug
            'sell_price' => $sellPennies / 100,
        ])->saveQuietly();

        // Chain image processing
        ProcessAutoCreateImageJob::dispatch($product->id, $supplierData['image_url'] ?? null);

        // Completeness score (Observer / listener will recompute, but snapshot now)
        [$score, $missing, $ready] = $scorer->score($product->fresh());

        event(new AutoCreateSucceeded(
            productId: $product->id,
            wooProductId: $wooResponse['id'],
            sku: $this->sku,
            slug: $wooResponse['slug'],
            completenessScore: $score,
            autoCreateStatus: $product->auto_create_status,
        ));
    }

    public function failed(\Throwable $e): void
    {
        // DLQ via suggestions seam (Phase 1 D-17 + Phase 4 D-12 precedent)
        Suggestion::create([
            'kind' => 'auto_create_failed',
            'status' => 'pending',
            'proposed_at' => now(),
            'evidence' => ['sku' => $this->sku, 'error' => $e->getMessage()],
        ]);
    }
}
```

### Example 3: Woo create-product JSON (images via URL pass-through)

```json
// Source: https://rudrastyh.com/woocommerce/rest-api-create-product-with-images.html
// + https://github.com/woocommerce/woocommerce-rest-api-docs/blob/trunk/source/includes/v3/_products.md
POST /wp-json/wc/v3/products
{
  "name": "Logitech MeetUp Video Conferencing System",
  "slug": "logitech-meetup-video-conferencing-system",
  "status": "draft",
  "type": "simple",
  "sku": "LOG-MEETUP-EU",
  "regular_price": "1249.99",
  "short_description": "<ul><li>4K Ultra HD camera</li>...</ul>",
  "description": "<h2>Overview</h2>...",
  "images": [
    {
      "src": "https://ops.meetingstore.co.uk/storage/auto-create-images/logitech-meetup-main.webp",
      "name": "Logitech MeetUp main",
      "alt": "Logitech MeetUp Video Conferencing System"
    }
  ],
  "categories": [{"id": 17}],
  "attributes": [{"id": 3, "options": ["Logitech"]}]
}
```

Woo downloads the `src` URL into its own media library + attaches the resulting attachment ID to the product. Our URL needs to stay alive until Woo's asynchronous download completes (a few seconds post-POST).

### Example 4: HandleNewSupplierSku listener (replaces Phase 2 stub)

```php
// Source: Phase 2 Plan 03 EventServiceProvider + Phase 4 listener pattern
declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Listeners;

use App\Domain\ProductAutoCreate\Jobs\CreateWooProductJob;
use App\Domain\ProductAutoCreate\Models\AutoCreateSkipRule;
use App\Domain\Sync\Events\NewSupplierSkuDetected;
use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Contracts\Queue\ShouldQueue;

final class HandleNewSupplierSku implements ShouldQueue
{
    public function __construct(private IntegrationLogger $logger) {}

    public function handle(NewSupplierSkuDetected $event): void
    {
        // D-04: skip-rule check
        $matches = AutoCreateSkipRule::active()->get()->filter(
            fn (AutoCreateSkipRule $r) => $r->matches($event->sku, (float) $event->supplierPrice)
        );

        if ($matches->isNotEmpty()) {
            $this->logger->log([
                'channel' => 'woo-auto-create',
                'operation' => 'auto_skipped',
                'request_body' => ['sku' => $event->sku, 'matched_rule_ids' => $matches->pluck('id')->all()],
                'status' => 'success',
                'correlation_id' => $event->correlationId,
            ]);
            return;
        }

        CreateWooProductJob::dispatch($event->sku);
    }
}
```

### Example 5: ApplyPinsDuringSync listener (D-11 — NO Phase 2 code change)

```php
// Source: D-11 pin enforcement — listener-based extension
declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Listeners;

use App\Domain\ProductAutoCreate\Services\ProductOverrideGuard;
use App\Domain\Sync\Events\{SupplierPriceChanged, SupplierStockChanged, SupplierSkuMissing};

final class ApplyPinsDuringSync
{
    public function __construct(private ProductOverrideGuard $guard) {}

    public function handlePriceChanged(SupplierPriceChanged $event): void
    {
        // This listener fires AFTER Phase 2's SyncChunkJob has already written.
        // Per D-11, we short-circuit BY REVERTING the pinned field if the listener sees a sync-driven write
        // to a pinned column.
        $this->guard->revertIfPinned($event->wooProductId, ['regular_price'], source: 'supplier_price_changed');
    }

    public function handleStockChanged(SupplierStockChanged $event): void
    {
        $this->guard->revertIfPinned($event->wooProductId, ['stock_quantity'], source: 'supplier_stock_changed');
    }

    public function handleSkuMissing(SupplierSkuMissing $event): void
    {
        $this->guard->revertIfPinned($event->wooProductId, ['status'], source: 'supplier_sku_missing');
    }
}
```

**IMPORTANT: This implementation requires re-reviewing.** The CONTEXT D-11 wording "skips the field update (does NOT write to Woo, does NOT compare against supplier)" implies the listener should PREVENT the write, not revert it. But event-driven listeners fire AFTER the write. Two options:

1. **Revert after-the-fact** (shown above) — simpler but means Woo briefly sees the unpinned value.
2. **Preflight listener** — subscribe to a new `SupplierWritePreflightRequested` event (requires Phase 2 to emit this BEFORE each write — THAT modifies Phase 2 code, contradicting D-11).

**Recommended:** Option 1 (revert after-the-fact) with a `ProductOverrideGuard::revertIfPinned()` that does a Woo PUT to restore the pinned value. **Planner must clarify with user — this is an Open Question** (see Q10).

### Example 6: ProductCompletenessObserver

```php
declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Observers;

use App\Domain\ProductAutoCreate\Services\CompletenessScorer;
use App\Domain\Products\Models\Product;

final class ProductCompletenessObserver
{
    public function __construct(private CompletenessScorer $scorer) {}

    public function saving(Product $product): void
    {
        if ($product->auto_create_status === 'manual') return;

        [$score, $missing, $ready] = $this->scorer->score($product);

        $product->completeness_score = $score;
        $product->completeness_missing_fields = $missing;
        $product->completeness_computed_at = now();
    }
}
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `intervention/image` v2 (`->fit()`, `->encode()`) | v3 (`->scaleDown()`, `->toWebp()`) | Oct 2023 (v3.0 release) | Method names changed; copy-pasted v2 tutorials break silently. v3 required; v4 (2026) blocked by PHP 8.2 floor. |
| Binary upload to `/wp-json/wp/v2/media` | URL pass-through via `images: [{src: URL}]` on `/wc/v3/products` | Always (v3) — but internet folklore still recommends the binary path | Eliminates Application Password auth plumbing; simpler architecture. `[CITED: rudrastyh.com](https://rudrastyh.com/woocommerce/rest-api-create-product-with-images.html)` |
| Laravel observer with `saving` hook | Listener on domain events (`ProductPriceChanged` etc.) when producer uses `saveQuietly` | Laravel 8+ | `saveQuietly` bypasses `saving`; listener-on-event is the safe pattern when sync code uses force-write. |

**Deprecated/outdated to avoid:**
- `intervention/image` v2 — use v3.
- `maatwebsite/laravel-excel` for anything (not Phase 6 — already banned project-wide).
- `Z3d0X/filament-logger` — unmaintained; use `rmsramos/activitylog`.
- Binary upload to Woo product images — URL pass-through is the documented path.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Supplier API returns `image_url` / `image_fallback_urls` / `brand` / `category` / `name` / `description` fields on product rows | F1 + Summary | **HIGH.** If supplier has no image field, Phase 6 ships placeholder-primary mode (every draft is manual-image-review). Plan 01 Task 1 MUST probe the live API before finalising `ProductImageFetcher`. |
| A2 | WooCommerce `wp_unique_post_slug()` auto-appends `-2`/`-3` on collision (Woo-side) | F6 + P6-G | MEDIUM. If Woo instead returns 4xx on slug collision, the pre-POST slug scan (Pitfall P6-G option 1) becomes mandatory, not optional. Behaviour is WordPress core — very likely auto-append. |
| A3 | `Product::saveQuietly` does not fire `saving`/`updating` observer events but DOES fire `saved` closures registered via service providers | Pattern 4 + P6-E | MEDIUM. If `saved` also skipped, Option 2 (listener on domain events) becomes the only path. Verify in Plan 01 Task 1 with a 2-line Pest test. |
| A4 | `spatie/image-optimizer` on Windows dev silently skips missing binaries without throwing | F3 + P6-C | LOW. README explicitly states graceful degrade. Still wrap in try/catch for belt-and-braces. |
| A5 | Filament Shield auto-generates permissions for new Resources in the form `{action}_{resource}` AND `{action}_{word1}::{word2}` (underscore + `::` separators) | Filament notes | HIGH. Phase 5 Plan 04a confirmed MySQL LIKE `_` wildcard bug — Phase 6 seeder MUST use explicit `whereIn` (NOT LIKE) for mixed-grant resources. |
| A6 | `intervention/image ^3.11` works on project's PHP 8.2 floor + PHP 8.4 dev box | Standard Stack | HIGH. Verified via Packagist `[VERIFIED]`. Still pin explicitly in composer.json. |
| A7 | Phase 3 `RuleResolver::resolve(Product $p)` works for a just-created Product with `brand_id` / `category_id` / `buy_price` set | Code Example 2 | HIGH. Purity-tested in Phase 3 Plan 02. |
| A8 | Woo `/wc/v3/products?slug={x}` supports slug filtering | F6 + P6-G | MEDIUM. Woo docs confirm `slug` as query param. Backup: `GET /wc/v3/products?search={x}` + client-side slug-match. |
| A9 | Phase 2 `SupplierClient` can be EXTENDED with a `fetchSingleProduct(sku)` method that returns the full API record — supplier API supports filtering by SKU on `/api/index.php` | F1 | HIGH. 21stcav API accepts `endpoint=products` with filters (existing `page` + `per_page` proves filter-param support). |
| A10 | Existing placeholder image format decision — **WebP** is fine; all modern browsers + Woo support WebP | Placeholder section | HIGH. WebP is universal (Chrome/Edge/Firefox/Safari since 2020). |

**Status:** 10 assumptions flagged. **A1 is the #1 risk + drives Plan 01 probe task.** A2 + A3 are verifiable in a single test session.

---

## Open Questions

1. **Q1 — Supplier API full-product response shape.** CRITICAL pre-Phase-6-implementation task.
   - What we know: `SupplierClient::fetchAllProducts()` currently returns only `{sku: {price, stock}}` (from `/api/index.php?endpoint=products`). All other fields are discarded.
   - What's unclear: Does the supplier response carry `name`, `description`, `brand`, `category`, `image_url`, `image_fallback_urls`, `spec_summary`, `features`, `specs` fields the SEO template (D-01) needs? If not, Phase 6 cannot ship a useful draft — falls back to "SKU-only draft flagged for manual data entry."
   - **Recommendation:** Plan 01 Task 1 = an explicit probe. Add a one-off artisan command `supplier:probe-single-sku {sku}` that calls `/api/index.php?endpoint=products&sku=EXAMPLE-SKU&per_page=1` with `SUPPLIER_API_USERNAME/PASSWORD` populated, dumps the full response to `storage/app/research/supplier-probe.json`, then the planner updates `SupplierClient::fetchSingleProduct()` with the discovered shape. This probe must complete BEFORE Plan 02 (image pipeline) starts.
   - Owner: operator with supplier API credentials (populate .env before the probe).

2. **Q2 — Brand + category taxonomy shape on meetingstore.co.uk Woo.**
   - What we know: Woo exposes `/wc/v3/products/categories` + `/wc/v3/products/attributes/{id}/terms`. Phase 2 didn't touch brand taxonomy.
   - What's unclear: does meetingstore.co.uk use a custom `pa_brand` attribute (common), a `product_brand` custom taxonomy (WooCommerce Brands plugin), or no brand taxonomy at all (brand-in-title only)?
   - **Recommendation:** Plan 01 Task 1 calls `/wc/v3/products/attributes` + `/wc/v3/products/categories` live against the prod Woo (read-only) to map the schema. Write findings into config/product_auto_create.php `brand_taxonomy` + `category_taxonomy`.

3. **Q3 — Blade SEO template field list is complete?**
   - What we know: D-02 locks 8 shortcodes (`brand_name`, `model_name`, `product_type`, `supplier_overview`, `supplier_features`, `supplier_specs`, `supplier_box_contents`, `cta`).
   - What's unclear: Does supplier response actually populate `box_contents`? Meta description format is `{brand} {model} — {short_tagline}. {cta}` — does the supplier feed have `short_tagline`?
   - **Recommendation:** resolved by Q1's probe output.

4. **Q4 — `NewProductOpportunityApplier` location decision.**
   - Current: `app/Domain/Competitor/Appliers/NewProductOpportunityApplier.php` (Phase 5 stub).
   - Options: (a) replace body in-place, keep in Competitor; (b) move to `app/Domain/ProductAutoCreate/Appliers/`, delete Competitor copy.
   - **Recommendation:** **Option (b) move** — cleaner domain boundary (the applier now belongs to ProductAutoCreate domain; Competitor is only the producer). Deptrac ruleset: `ProductAutoCreate` depends on `Suggestions` (applier contract) + `Competitor` allow-list stays. `AppServiceProvider::boot` resolver registration updates to the new FQCN.
   - Tradeoff: breaks Phase 5 Plan 02's `NewProductOpportunityApplierTest.php` (imports the old FQCN). **Planner must include the test-file update in the move.**

5. **Q5 — `ApplyPinsDuringSync` listener — revert-after or preflight-before?** (see Example 5 IMPORTANT note).
   - Preflight needs Phase 2 to emit an event BEFORE the write — contradicts D-11.
   - Revert-after means Woo briefly sees the unpinned value (milliseconds window).
   - **Recommendation:** Revert-after is the pragmatic choice; window is too small to cause customer-facing issues, and the audit log captures both the supplier write and the revert. Filament pin UI explains this clearly to admins.

6. **Q6 — `completeness_computed_at` observer performance for bulk supplier syncs.**
   - A 15k-SKU sync could hit the observer 15k times. Each call runs `$scorer->score(Product)` + 4 JSON field reads.
   - **Recommendation:** `CompletenessScorer::score()` is a pure function that reads already-loaded Eloquent attributes. No DB queries. Total overhead estimated at ~0.5ms per call × 15k = 7.5s — acceptable. If profiling shows otherwise, switch to a batched recompute at end-of-run.

7. **Q7 — Auto-create for variable products?**
   - D-discretion + Deferred Ideas both exclude variations from v1. Phase 6 ships simple-product-only auto-create.
   - But the supplier API probe (Q1) may reveal most new SKUs are variations. If so, Phase 6 ships with a "variations detected — manual-only" log entry.
   - **Recommendation:** Defer variation auto-create per CONTEXT. Log + skip + surface in review inbox with status `variations_not_supported_v1`.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | Runtime | ✓ | 8.4.19 (dev Herd) / 8.2+ (VPS) | — |
| Laravel Horizon | CreateWooProductJob / ProcessAutoCreateImageJob queues | ✓ | 5.45 | — |
| Redis | Horizon backing | ✓ | 7.x prod / phpredis ext. dev | — (predis/predis also in composer.json as backup) |
| MySQL | 3 new tables + 8 new columns | ✓ | 8.0+ | — |
| `intervention/image ^3.11` | Image pipeline | ✗ | to install | — (composer require in Plan 01) |
| `intervention/image-laravel ^1.5` | Laravel integration | ✗ | to install | — |
| `spatie/image-optimizer ^1.8` | WebP optimisation | ✗ | to install | Skip gracefully on Windows — optimizer is optional |
| GD or Imagick (PHP extension) | intervention/image driver | ✓ (probable on dev/prod) | verify via `php -m \| grep -iE 'gd\|imagick'` | libvips if both missing |
| `pngquant` / `cwebp` / `mozjpeg` (Linux binaries) | image-optimizer post-WebP | ✓ prod / ✗ dev | apt install | spatie/image-optimizer gracefully skips — no hard dep |
| Supplier API credentials | Plan 01 probe + live auto-create | — | populate `.env` | — (Plan 01 probe requires; cannot defer) |
| Woo REST consumer key + url | WooClient.post('/products') | ✓ | populated in Phase 2 | — |
| Storage symlink `public/storage` on VPS | Placeholder image URL | ? | `php artisan storage:link` | ship placeholder in `public/images/` to skip dependency (P6-F recommended) |

**Missing dependencies blocking Plan 01:**
- Supplier API credentials must be populated in `.env` before Plan 01 probe can run. If they're not available at planning time, Plan 01 Task 1 becomes a human-verify checkpoint.

**Missing dependencies with fallback:**
- `pngquant` / `cwebp` on Windows dev — spatie/image-optimizer skips silently; intervention alone covers the WebP conversion.

---

## Validation Architecture

Test framework from `.planning/config.json`: assume nyquist_validation is enabled.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 3 + PHPUnit 11 |
| Config file | `phpunit.xml` (DB_DATABASE overridden to `meetingstore_ops_testing`) |
| Quick run command | `vendor/bin/pest tests/Feature/ProductAutoCreate tests/Unit/ProductAutoCreate --parallel` (once tests exist) |
| Full suite command | `vendor/bin/pest` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| AUTO-01 | `NewSupplierSkuDetected` → `HandleNewSupplierSku` → `CreateWooProductJob` dispatched | feature | `vendor/bin/pest tests/Feature/ProductAutoCreate/HandleNewSupplierSkuTest.php -x` | ❌ Plan 03 |
| AUTO-02 | Blade template compile produces 5 keys with correct populated values | feature | `vendor/bin/pest tests/Feature/ProductAutoCreate/ProductContentBuilderTest.php` | ❌ Plan 01 |
| AUTO-03 | Image fallback chain (supplier → fallback array → placeholder) | feature | `vendor/bin/pest tests/Feature/ProductAutoCreate/ProductImageFetcherTest.php` | ❌ Plan 02 |
| AUTO-04 | intervention/image resize+WebP+EXIF+optimize pipeline | unit | `vendor/bin/pest tests/Unit/ProductAutoCreate/ProductImageProcessorTest.php` | ❌ Plan 02 |
| AUTO-05 | CreateWooProductJob logs + retries + integration_events entry | feature | `vendor/bin/pest tests/Feature/ProductAutoCreate/CreateWooProductJobTest.php` | ❌ Plan 03 |
| AUTO-06 | AutoCreateReviewResource shows drafts + completeness + bulk actions | feature | `vendor/bin/pest tests/Feature/ProductAutoCreate/AutoCreateReviewResourceTest.php` | ❌ Plan 04 |
| AUTO-07 | `config('product_auto_create.mode')=draft` default; env override works | feature | `vendor/bin/pest tests/Feature/ProductAutoCreate/AutoCreateSettingsPageTest.php` | ❌ Plan 04 |
| AUTO-08 | ProductMatcher::existsNormalised catches casing + whitespace | unit | `vendor/bin/pest tests/Unit/ProductAutoCreate/ProductMatcherTest.php` | ❌ Plan 01 |
| AUTO-09 | Slug generator D-05 disambiguation + Woo-side reconciliation | feature | `vendor/bin/pest tests/Feature/ProductAutoCreate/ProductSlugGeneratorTest.php` | ❌ Plan 01 |
| AUTO-10 | PinnedFieldsSurviveSync regression (D-13 ship gate) | **architecture** | `vendor/bin/pest tests/Architecture/PinnedFieldsSurviveSyncTest.php` | ❌ Plan 05 — ship gate |
| AUTO-11 | Filament pin UI toggle + audit log entry | feature | `vendor/bin/pest tests/Feature/ProductAutoCreate/ProductResourcePinTabTest.php` | ❌ Plan 04 |

### Sampling Rate

- **Per task commit:** `vendor/bin/pest --filter='ProductAutoCreate|PinnedFieldsSurviveSync|DeptracProductAutoCreateLayer'`
- **Per wave merge:** `vendor/bin/pest`
- **Phase gate:** Full suite green + `vendor/bin/deptrac analyse` 0 violations + `tests/Architecture/PinnedFieldsSurviveSyncTest` + `tests/Architecture/PolicyTemplateIntegrityTest` both green.

### Wave 0 Gaps

- [ ] `tests/Unit/ProductAutoCreate/` — directory + `Pest.php` setup
- [ ] `tests/Feature/ProductAutoCreate/` — directory
- [ ] `tests/Architecture/DeptracProductAutoCreateLayerTest.php` — covers Focus Area F13
- [ ] `tests/Architecture/PinnedFieldsSurviveSyncTest.php` — covers D-13 (AUTO-10 ship gate)
- [ ] `tests/Fixtures/ProductAutoCreate/` — sample supplier API response (populated from Q1 probe); sample image binary for unit tests
- [ ] Framework install: **required** — `composer require intervention/image:"^3.11" intervention/image-laravel:"^1.5" spatie/image-optimizer:"^1.8"` in Plan 02 Task 1

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Existing: Filament Shield + Laravel Auth. Phase 6 Resource policies gate admin + pricing_manager; sales + read_only denied. `->authorize()` on every mutation Action. |
| V3 Session Management | no | Not a new surface — Phase 6 mutations all behind authenticated Filament panel sessions. |
| V4 Access Control | yes | Policies on `AutoCreateSkipRule`, `AutoCreateRejection`, `AutoCreateSettingsPage`, `AutoCreateReviewResource`. Shield LIKE patterns (`%_auto_create_skip_rule`, `%_auto_create_rejection`, `page_AutoCreateSettings`, `page_AutoCreateReview`) + D-11 admin-only pin toggle. |
| V5 Input Validation | yes | SKU / slug / brand name / URL all validated — Laravel validator + `mb_convert_encoding` on supplier response. Filament form + job-level re-validation. Image binary sniff via `ProductImageProcessor::process()` catches malformed decode. |
| V6 Cryptography | partial | Image URL to the placeholder uses `Storage::temporaryUrl()` if on S3 (signed); local disk uses public symlink (unsigned — but placeholder is non-sensitive). Supplier API auth via JWT (Phase 2) + Woo consumer key (Phase 2) — no new keys in Phase 6. |
| V12 File / Resource | yes | Image input validation: HEAD request verifies Content-Type; max file size 10MB (reject larger); decode wrapped in try/catch. Optimizer output extension locked to `.webp`. |
| V13 API & Web Services | yes | Woo REST consumer key is the only credential; every outbound call uses existing Phase 2 `WooClient` which threads `IntegrationLogger` (headers redacted, body logged). |

### Known Threat Patterns for Laravel + Filament + Woo REST

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Supplier API returns malicious image (SSRF to internal IPs via redirect) | Tampering | HEAD pre-flight + refuse non-image Content-Type; IP allowlist via Guzzle middleware if threat model warrants |
| Reflected HTML in supplier description / features → XSS on admin review | XSS | Filament auto-escapes by default; `description` field rendered via Blade `{!! !!}` only if explicitly sanitised via `Purifier::clean()` — **do NOT render raw** in v1 (show rendered HTML only after admin approves) |
| Malicious SKU pattern in skip-rule value triggers ReDoS | DoS | Validate regex on skip-rule save via `preg_match('/pattern/', '', ...)` with `PREG_BACKTRACK_LIMIT` + timeout; reject patterns with catastrophic backtracking |
| Draft product published without review via parameter tampering | Elevation of Privilege | `->authorize()` on Publish + Approve Actions; D-09 completeness gate + confirmation modal; bulk action re-validates per-row |
| Image file upload bypass — pinned image field bypassed | Tampering | D-11 listener revert-if-pinned + Filament pin UI audit-logged + ProductOverrideGuard single-source-of-truth |
| SKU injection in Woo POST (e.g. `"; DROP TABLE`) | Injection | Woo REST accepts JSON body; Automattic SDK encodes properly. Supplier SKU cast to string + length-validated (<64 chars). |
| Reject-reason free-text exposed in Filament → stored XSS | XSS | Laravel's `e()` helper in blade auto-escapes; Filament form renders text as plain string |

Phase 6 inherits Phase 1–5's security posture; adds no new trust boundaries beyond the supplier-image-URL fetch path (mitigated by HEAD + Content-Type + decode validation).

---

## Plan Breakdown Proposal

Recommended **6-plan breakdown** (from CONTEXT Focus Area #12, lightly adjusted):

### Plan 06-01 — Data Model + Skip Rules + SEO Template + Placeholder
**Scope:** Foundation for everything else.

- Migrations: `auto_create_skip_rules`, `auto_create_rejections`, `alter products add auto_create_status + completeness_score + completeness_computed_at + completeness_missing_fields`, `alter product_overrides add 8 pin_* bool columns`, `alter alert_recipients add receives_auto_create_alerts`.
- Models: `AutoCreateSkipRule`, `AutoCreateRejection`, `ProductOverride` pin accessors.
- Services: `ProductSlugGenerator`, `ProductMatcher`, `ProductContentBuilder`, `CompletenessScorer`.
- Policies: `AutoCreateSkipRulePolicy`, `AutoCreateRejectionPolicy`.
- Factories: for all new models.
- Config: `config/product_auto_create.php` (mode, CTA template, taxonomy mappings).
- Blade template: `resources/views/product-auto-create/seo-template.blade.php`.
- Placeholder asset: `public/images/av-product-placeholder.webp` (P6-F recommendation).
- Seeders: `AutoCreateSkipRuleSeeder` (3 default rules).
- **TASK 1 — CRITICAL PROBE:** `supplier:probe-single-sku` artisan command + `SupplierClient::fetchSingleProduct($sku)` extension. Human-verify output stored in `storage/app/research/supplier-probe.json`. This unblocks all other image-dependent work.

**Requirements:** AUTO-02 partial, AUTO-08, AUTO-09, D-01..D-06, D-07..D-09.

### Plan 06-02 — Image Pipeline + Supplier Image Fetch
**Scope:** Everything image-related.

- Composer: `intervention/image:^3.11 + intervention/image-laravel:^1.5 + spatie/image-optimizer:^1.8`.
- Services: `ProductImageFetcher` (HEAD + fallback chain per P6-A), `ProductImageProcessor` (intervention v3 `->scaleDown()->toWebp(quality:85, strip:true)` + optional optimizer wrapped in try/catch per P6-C).
- Job: `ProcessAutoCreateImageJob` on `sync-bulk` queue.
- Storage: `storage/app/public/auto-create-images/{slug}-main.webp` or alternative no-symlink location.
- Unit tests: pipeline reproduces <=1200×1200 WebP; EXIF stripped.
- Feature tests: fallback chain (supplier → fallback_urls → placeholder); 404-safe HEAD; P6-A defenses.

**Requirements:** AUTO-03, AUTO-04.

### Plan 06-03 — Auto-Create Orchestration + Events + Listeners + Applier
**Scope:** The core flow.

- Events: `AutoCreateAttempted`, `AutoCreateSucceeded`, `AutoCreateFailed`, `ProductPublished` (all extend `DomainEvent`).
- Jobs: `CreateWooProductJob` (orchestrator; `sync-woo-push`); `PublishProductJob` (draft → published on publish action).
- Listeners: `HandleNewSupplierSku` (REAL — replaces Phase 2 stub); registered in EventServiceProvider.
- Applier upgrade: `NewProductOpportunityApplier` — move FQCN OR replace body (Q4 decision). Ships real dispatch of `CreateWooProductJob`.
- Service: `TaxonomyResolver` (Woo brand + category lookup; per CONTEXT "needs_brand_or_category_assignment" fallback).
- Service: `ProductOverrideGuard` (used by Plan 05).
- Observer: `ProductCompletenessObserver` (registered on Product model in AppServiceProvider).
- Tests: end-to-end dispatch → draft creation → image chained → events fire in order.

**Requirements:** AUTO-01, AUTO-02, AUTO-05.

### Plan 06-04 — Filament UI + RBAC + Shield Restoration Protocol
**Scope:** Admin-facing.

- Resources: `AutoCreateReviewResource` (list + quick-edit modal + bulk approve/reject/bulk-set-category/bulk-set-brand), `AutoCreateSkipRuleResource` (CRUD).
- Page: `AutoCreateSettingsPage` (singleton; admin-only; toggle mode).
- `ProductResource` extended with "Field Pins" tab (8 toggles) — D-12.
- `SuggestionResource` extended with kind `auto_create_failed` replay action + kind `new_product_opportunity` action body update.
- `AlertRecipientResource` `receives_auto_create_alerts` toggle.
- Applier: `AutoCreateRetryApplier` (kind='auto_create_failed' replay).
- **P5-F Shield restoration protocol** MANDATORY after `shield:generate --all`.
- `RolePermissionSeeder` — explicit whereIn whitelist (P5 MySQL `_` wildcard bug lesson) for mixed-grant resources.
- Human-verify checkpoint: review inbox UX.

**Requirements:** AUTO-06, AUTO-07, AUTO-11.

### Plan 06-05 — Pin Enforcement + Regression Test (AUTO-10 Ship Gate)
**Scope:** D-11 + D-13.

- Listener: `ApplyPinsDuringSync` subscribes to `SupplierPriceChanged` / `SupplierStockChanged` / `SupplierSkuMissing` via EventServiceProvider.
- Service: `ProductOverrideGuard::revertIfPinned()` — writes revert via WooClient if pinned field changed (Q5 revert-after-the-fact pattern).
- **Ship gate test:** `tests/Architecture/PinnedFieldsSurviveSyncTest.php` — full `sync:supplier --live` cycle with Http::fake + pinned title + description → asserts byte-identical post-sync.
- Event wiring test: each of the 3 Phase 2 events routes through the listener.

**Requirements:** AUTO-10.

### Plan 06-06 — Retention + Deptrac + Verification
**Scope:** Ship-gate paperwork.

- Deptrac: add `ProductAutoCreate` layer to BOTH `depfile.yaml` + `deptrac.yaml` (Phase 5 Plan 05 lesson). Allow-list: `[Foundation, Products, Pricing, Sync, Suggestions, Alerting]` + possibly `Competitor` (Q4 decision).
- `tests/Architecture/DeptracProductAutoCreateLayerTest.php` — 4 it-blocks (positive + 2 negative violators + allow-list grep).
- Retention: no new prune command (auto_create_rejections retained indefinitely per D-06 + Phase 1 audit retention; auto_create_skip_rules admin-edited so no auto-prune).
- Update `AutoCreateRejectionRetentionTest` ensuring prune commands never touch `auto_create_rejections`.
- `06-VERIFICATION.md` — ship verdict following 05-VERIFICATION.md template.

**Requirements:** all 11 AUTO-* re-verified at the VERIFICATION level.

---

## Sources

### Primary (HIGH confidence)

- [Packagist — intervention/image](https://packagist.org/packages/intervention/image) — v4.0.1 (April 7 2026, PHP 8.3+) vs v3.11.x (PHP 8.2+) confirms v3 is the correct pin for this project.
- [Packagist — spatie/image-optimizer](https://packagist.org/packages/spatie/image-optimizer) — v1.8.1 (Nov 26 2025); graceful degrade on missing binaries documented in README.
- [GitHub — Intervention/image-laravel](https://github.com/Intervention/image-laravel) — v1.5.8 requires PHP 8.1+, supports Laravel 8–13 + intervention/image ^3.11.
- [Intervention Image v3 — Resizing](https://image.intervention.io/v3/modifying-images/resizing) — `scaleDown()` is the correct method for "fit no-upscale."
- [Intervention Image v3 — Image Output](https://image.intervention.io/v3/basics/image-output) — `toWebp(quality:85, strip:true)` covers WebP + EXIF strip in one call.
- [WooCommerce REST API v3 Products](https://woocommerce.github.io/woocommerce-rest-api-docs/v3.html) — payload shape; image array via URL src.
- [WooCommerce REST API docs repo (trunk)](https://github.com/woocommerce/woocommerce-rest-api-docs/blob/trunk/source/includes/v3/_products.md) — authoritative products endpoint docs.
- `.planning/phases/02-supplier-sync/02-03-SUMMARY.md` — `NewSupplierSkuDetected` event + StubNewSupplierSkuListener producer contract.
- `.planning/phases/02-supplier-sync/02-CONTEXT.md` — D-09 new-SKU event contract.
- `.planning/phases/03-pricing-engine/03-01-SUMMARY.md` + `03-02-SUMMARY.md` — `RuleResolver` + `PriceCalculator` contracts.
- `.planning/phases/04-bitrix24-crm-sync/04-03-SUMMARY.md` — listener → queued job → applier + DLQ pattern.
- `.planning/phases/05-competitor-analysis/05-02-SUMMARY.md` — stub applier location + evidence JSON shape + Bus::batch chaining pattern.
- `.planning/phases/05-competitor-analysis/05-04a-SUMMARY.md` — P5-F shield restoration protocol; MySQL `_` LIKE wildcard bug.
- `.planning/phases/05-competitor-analysis/05-05-SUMMARY.md` — Deptrac dual-config-file (depfile.yaml + deptrac.yaml) sync lesson.
- Source code: `app/Domain/Sync/Services/WooClient.php` — confirms writeLive 429 backoff + shadow-mode gate + `post()`/`put()` surface ready for Phase 6.
- Source code: `app/Domain/Sync/Services/SupplierClient.php` — confirms current `fetchAllProducts` returns ONLY `{sku: {price, stock}}` — discards other fields.
- Source code: `app/Domain/Competitor/Appliers/NewProductOpportunityApplier.php` — confirms current stub location.
- Source code: `deptrac.yaml` — confirms current layer structure.
- `composer.json` — confirms PHP ^8.2, no intervention/image or spatie/image-optimizer installed.

### Secondary (MEDIUM confidence)

- [rudrastyh.com — WooCommerce REST API create product with images](https://rudrastyh.com/woocommerce/rest-api-create-product-with-images.html) — verifies URL pass-through + dual auth paths (consumer key OR application password). Independent tutorial, multi-source-corroborated.
- [GitHub woocommerce/woocommerce issue #17556 — file upload for products](https://github.com/woocommerce/woocommerce/issues/17556) — unresolved file-upload feature request confirms URL-pass-through is the standard path.
- [Laravel Daily — intervention/image integration](https://medium.com/unleash-the-power-of-image-intervention-in-laravel/integrating-intervention-image-version-3-into-your-laravel-app-c8be066b22ad) — supplemental v3 integration pattern.

### Tertiary (LOW confidence — needs validation)

- Assumed: supplier API `/api/index.php?endpoint=products&sku={x}` supports single-SKU filtering (A9). **Plan 01 probe validates.**
- Assumed: meetingstore.co.uk Woo uses `pa_brand` attribute taxonomy (A-extension of Q2). **Plan 01 probe validates.**
- Assumed: `Product::saveQuietly` does not skip `saved` closures registered via `Product::saved(...)` in AppServiceProvider (A3). **2-line Plan 01 test validates.**

---

## Metadata

**Confidence breakdown:**

| Area | Level | Reason |
|------|-------|--------|
| Standard stack (intervention/image + image-optimizer) | HIGH | Packagist-verified + multi-source-corroborated |
| Woo REST image payload shape | HIGH | Official docs + multi-source tutorial + 2017-open GitHub issue confirms URL-pass-through is the default path |
| Supplier API response shape for images | LOW → upgraded once Plan 01 probe runs | Current `SupplierClient` throws away all non-price/stock fields; image-field existence is an assumption |
| Architecture patterns (listener + job + applier) | HIGH | Phase 4 + Phase 5 precedent; well-tested in existing code |
| Deptrac allow-list + dual-file sync | HIGH | Phase 5 Plan 05 locked the lesson; this research follows it verbatim |
| RBAC patterns | HIGH | Phase 5 Plan 04a locked the MySQL `_` wildcard + P5-F restoration protocol |
| Pitfalls (P6-A..G) | MEDIUM-HIGH | P6-A/B/C/D/F high-confidence from research; P6-E/G marked MEDIUM pending Plan 01 validation |

**Research date:** 2026-04-22
**Valid until:** 2026-05-22 (30 days — intervention/image v4 bump to PHP 8.3 is the main expiry risk)

---

*Phase 06-product-auto-create RESEARCH*
*Author: gsd-researcher*
*Consumer: gsd-planner + optional gsd-discuss-phase refinement pass*
