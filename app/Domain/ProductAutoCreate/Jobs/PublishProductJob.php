<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Jobs;

use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\ProductAutoCreate\Events\ProductPublished;
use App\Domain\ProductAutoCreate\Services\ProductBrandTermResolver;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase 6 Plan 03 — admin-triggered draft → published transition.
 * Core-loop #3b — now ALSO creates the product on Woo when it has no woo_product_id.
 *
 * Dispatched from Plan 04's "Approve" action in AutoCreateReviewResource. Two paths:
 *
 *   A) Product ALREADY on Woo (woo_product_id set — e.g. a CreateWooProductJob
 *      draft): PUT status=publish to flip the existing Woo draft live.
 *
 *   B) AUTO-DRAFTED product with NO woo_product_id (from products:generate-drafts
 *      / products:draft-competitor-skus): POST it to /products with status=publish
 *      — name, slug, sku, price, descriptions, categories and images all carried
 *      from the local row — then back-fill woo_product_id + Woo-reconciled slug.
 *      This closes the "manual movement to live" gap: before #3b, path B silently
 *      marked the row published locally while never creating it on Woo.
 *
 * Gating (FOUND-08): every write goes through WooClient::put/post → writeOrShadow,
 * so with WOO_WRITE_ENABLED=false it records a SyncDiff instead of touching Woo and
 * returns ['shadow_mode' => true, ...] (no real id). In that case we DO NOT mark the
 * row published — doing so would orphan it (status=published locally, absent on Woo,
 * and gone from the review inbox). The row stays in review; re-running Approve after
 * the cutover flip performs the real create/publish. So pre-cutover, Approve just
 * stages the SyncDiff you can eyeball in parity review.
 *
 * VAT: sell_price is VAT-INCLUSIVE. regular_price is pushed inc-VAT by default;
 * set WOO_PUSH_PRICES_EX_VAT=true to strip VAT first (mirrors PushPriceChangeToWoo).
 *
 * Fires ProductPublished (Phase 7 dashboard tile) only on a real publish.
 *
 * Queue: sync-woo-push (shared Woo rate-limit budget). tries=3 for transient 429s.
 */
final class PublishProductJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $productId,
        public readonly int $publishedByUserId,
    ) {
        // PHP 8.4 trait-collision guard.
        $this->onQueue('sync-woo-push');
    }

    public function handle(
        WooClient $woo,
        PriceCalculator $calculator,
        TaxonomyResolver $taxonomy,
        ProductBrandTermResolver $brandResolver,
    ): void {
        $product = Product::findOrFail($this->productId);

        $wooId = (int) ($product->woo_product_id ?? 0);

        if ($wooId > 0) {
            // ── Path A — already on Woo: flip the existing draft to publish ──
            // No leading slash — the Woo SDK 404s ("rest_no_route") on a leading "/".
            $response = $woo->put("products/{$wooId}", ['status' => 'publish']);
        } else {
            // ── Path B (#3b) — create the auto-draft on Woo, published ───────
            $payload = $this->buildCreatePayload($product, $calculator);

            // SPLIT WRITE — POST creates the product WITHOUT regular_price,
            // then a follow-up PUT sets the price in isolation. Why: the
            // storefront's Cost-of-Goods plugin (_alg_wc_cog_*) hooks into
            // product save and recomputes/clobbers regular_price when other
            // fields are mass-updated in the same save cycle. Verified
            // 2026-05-31 — the original POST left regular_price empty on all
            // 26 of the first batch; the resync split-PUT (price-first PUT
            // alone, then everything else) made it stick. We bake the same
            // isolation into the create path here so future batches don't
            // need the manual resync-for-price step. Trade-off: 2 HTTP calls
            // per create instead of 1; the price PUT is cheap (single field)
            // so latency cost is ~50-100ms per product.
            $deferredPrice = $payload['regular_price'] ?? null;
            unset($payload['regular_price']);

            try {
                $response = $woo->post('products', $payload);
            } catch (\Throwable $e) {
                // WC 9.x rejects duplicate `global_unique_id` (GTIN/EAN) values.
                // Some suppliers share one EAN across SKU variants (Optoma
                // H1F0H06/H1F0H07, Cisco device-vs-bundle, Epson colour
                // variants, etc.). When that happens, retry ONCE without the
                // EAN — published-no-GTIN beats not-published-at-all.
                // Also clear local EAN so subsequent ops don't re-collide.
                // 2026-06-01: 3 SKUs blocked tonight's batch (H1F0H07BW101,
                // CP-8821-K9-BUN, V11HB07140) before this retry shipped.
                if (
                    is_string($e->getMessage())
                    && str_contains($e->getMessage(), 'product_invalid_global_unique_id')
                    && ! empty($payload['global_unique_id'])
                ) {
                    Log::info('auto_create.publish.ean_collision_retry', [
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                        'ean' => $payload['global_unique_id'],
                    ]);
                    unset($payload['global_unique_id']);
                    $response = $woo->post('products', $payload);
                    $product->forceFill(['ean' => null])->saveQuietly();
                } else {
                    throw $e;
                }
            }

            $newWooId = (int) ($response['id'] ?? 0);
            if ($newWooId > 0) {
                // Live create: reconcile Woo id + the slug Woo actually assigned
                // (it server-side de-duplicates colliding slugs).
                $product->forceFill([
                    'woo_product_id' => $newWooId,
                    'slug' => (string) ($response['slug'] ?? $product->slug),
                ])->saveQuietly();
                $wooId = $newWooId;

                // SPLIT-PUT step 2 — set regular_price now that the product
                // exists and the Cost-of-Goods plugin's create-time recompute
                // has fired. A price-only PUT doesn't re-trigger CoG's hooks
                // (it watches for buy_price changes, not regular_price), so
                // our value sticks. Failures are non-fatal — the product is
                // already live; the operator can fix price in admin.
                if ($deferredPrice !== null && $deferredPrice !== '') {
                    try {
                        $woo->put("products/{$wooId}", ['regular_price' => $deferredPrice]);
                    } catch (\Throwable $priceErr) {
                        Log::warning('auto_create.publish.price_put_failed', [
                            'product_id' => $product->id,
                            'sku' => $product->sku,
                            'woo_id' => $wooId,
                            'price' => $deferredPrice,
                            'error' => $priceErr->getMessage(),
                        ]);
                    }
                }
            }
        }

        // Shadow mode recorded a SyncDiff — nothing was written to Woo. Leave the
        // row in review (do not falsely mark it published / fire the event).
        if ((bool) ($response['shadow_mode'] ?? false)) {
            Log::info('auto_create.publish.shadowed', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'had_woo_id' => $wooId > 0,
                'diff_id' => $response['diff_id'] ?? null,
            ]);

            return;
        }

        // ── Real write succeeded — mark published + announce ────────────────
        $product->forceFill([
            'auto_create_status' => 'published',
            'status' => 'publish',
        ])->saveQuietly();

        event(new ProductPublished(
            productId: (int) $product->id,
            wooProductId: $wooId,
            publishedByUserId: $this->publishedByUserId,
        ));

        // ── Brand linkage via product_brand taxonomy ─────────────────────
        // Best-effort, non-blocking — failures here don't fail the publish
        // (brand still surfaces via tags + attributes spec row). Drives
        // the storefront's clickable Brand: <link> → /brand/<slug>/ display.
        // See memory meetingstore-brand-display for why this is a separate
        // post-publish step (WP REST, not WC REST).
        if ($product->brand_id !== null && $wooId > 0) {
            $brandName = $this->resolveBrandName((int) $product->brand_id, $taxonomy);
            if ($brandName !== null) {
                $termId = $brandResolver->getTermIdForName($brandName);
                if ($termId !== null) {
                    $brandResolver->assignToProduct($wooId, [$termId]);
                }
            }
        }
    }

    /**
     * Resolve our local brand_id (which points at the WC native
     * /products/brands term ids) to a brand NAME. Brand name is the
     * cross-taxonomy identity used by ProductBrandTermResolver to find
     * or create the matching product_brand term.
     */
    private function resolveBrandName(int $brandId, TaxonomyResolver $taxonomy): ?string
    {
        foreach ($taxonomy->allBrands() as $b) {
            if ((int) ($b['id'] ?? 0) === $brandId) {
                $name = trim((string) ($b['name'] ?? ''));

                return $name !== '' ? $name : null;
            }
        }

        return null;
    }

    /**
     * Map the local draft Product onto a Woo /products create payload. Only
     * populated fields are sent so Woo keeps its own defaults for the rest.
     *
     * @return array<string, mixed>
     */
    private function buildCreatePayload(Product $product, PriceCalculator $calculator): array
    {
        $payload = [
            'name' => (string) $product->name,
            'type' => $product->type ?: 'simple',
            'status' => 'publish',
            'catalog_visibility' => 'visible',
        ];

        if (! empty($product->sku)) {
            $payload['sku'] = (string) $product->sku;
        }

        // WC 9.x structured GTIN slot — used by Google Merchant Center /
        // schema.org product markup. Existing meetingstore.co.uk products carry
        // this on wp_postmeta._global_unique_id; auto-created products POSTed
        // via WC REST didn't carry it until now.
        if (! empty($product->ean)) {
            $payload['global_unique_id'] = (string) $product->ean;
        }

        // Send our slug; Woo de-duplicates server-side and returns the final one,
        // which handle() reads back onto the row.
        if (! empty($product->slug)) {
            $payload['slug'] = (string) $product->slug;
        }

        // sell_price is VAT-inclusive. Inc-VAT by default; ex-VAT when configured.
        if ($product->sell_price !== null) {
            $pennies = (int) round(((float) $product->sell_price) * 100);
            if ($pennies > 0) {
                $pennies = (bool) config('services.woo.push_prices_ex_vat', false)
                    ? $calculator->stripVat($pennies)
                    : $pennies;
                $payload['regular_price'] = number_format($pennies / 100, 2, '.', '');
            }
        }

        if (! empty($product->short_description)) {
            $payload['short_description'] = (string) $product->short_description;
        }

        if (! empty($product->long_description)) {
            $payload['description'] = (string) $product->long_description;
        }

        if (! empty($product->meta_description)) {
            $payload['meta_data'] = [
                ['key' => '_yoast_wpseo_metadesc', 'value' => (string) $product->meta_description],
            ];
        }

        $categoryIds = $this->categoryIds($product);
        if ($categoryIds !== []) {
            $payload['categories'] = array_map(static fn (int $id): array => ['id' => $id], $categoryIds);
        }

        // "One image is enough to go live; no image → Woo's store placeholder"
        // (operator rule). Featured image first, then the gallery.
        $images = $this->imageSrcs($product);
        if ($images !== []) {
            $payload['images'] = array_map(static fn (string $src): array => ['src' => $src], $images);
        }

        // Curated key/value attributes → WC's "Additional Information" tab +
        // Flatsome theme spec table. Without these the storefront product page
        // renders visibly thinner than existing meetingstore.co.uk products,
        // which all carry _product_attributes meta (Colour, Compatibility,
        // Material, Connection, etc.). Source: GenerateProductDraftsCommand's
        // Claude schema (attributes[] of {name, value}). All non-variation,
        // visible on storefront, position from array order. The "Brand: ..."
        // row is included here for display in the spec table; the actual
        // brand TAXONOMY link goes through the top-level `brands` field below
        // (so /product-brand/<slug> archive + Brand filter sidebar work).
        $attributes = $this->wooAttributes($product);
        if ($attributes !== []) {
            $payload['attributes'] = $attributes;
        }

        // ── Brand linkage intentionally NOT pushed here ──────────────────
        // 2026-05-31 — investigation revealed the storefront's clickable
        // "Brand:" link comes from a curated `product_brand` taxonomy (WP
        // REST `/wp/v2/product_brand`, URL `/brand/<slug>/`), NOT from the
        // WC native `/products/brands` endpoint we were pushing via
        // `brands: [{id}]`. The native endpoint accepts the field syntactically
        // but silently drops the linkage — every prior `brands[]` push was a
        // no-op, including yesterday's commit 73ac682.
        // Brand still surfaces in THREE places without this push:
        //   1. The product TITLE ("Yealink BH71...")
        //   2. The `tags` field (Claude is instructed to put brand as the
        //      FIRST tag — see GenerateProductDraftsCommand commit 26e7e01)
        //   3. The `attributes_json` "Brand" row in the spec table
        // The fourth display (the clickable Brand: → /brand/<slug>/ link)
        // requires writing to the `product_brand` taxonomy via WP REST. That
        // needs a new WpRestClient + a create-if-missing flow + a curator
        // decision (auto-create brand terms vs. operator-curated whitelist) —
        // tracked separately, NOT shipping inline with this fix.

        // Product tags — WC REST accepts `[{name: "..."}]` and auto-creates
        // missing tags server-side. Local `products.tags` is a JSON array of
        // strings (Phase 2 cast). Trim + drop blanks + dedupe.
        $tags = is_array($product->tags) ? $product->tags : [];
        $tags = array_values(array_unique(array_filter(
            array_map(static fn ($t): string => trim((string) $t), $tags),
            static fn (string $t): bool => $t !== '',
        )));
        if ($tags !== []) {
            $payload['tags'] = array_map(
                static fn (string $name): array => ['name' => $name],
                $tags,
            );
        }

        return $payload;
    }

    /**
     * Full multi-category set (category_ids) when present, else the single
     * primary category_id. Normalised to unique positive ints.
     *
     * @return array<int, int>
     */
    private function categoryIds(Product $product): array
    {
        $ids = is_array($product->category_ids) ? $product->category_ids : [];

        if ($ids === [] && $product->category_id !== null) {
            $ids = [$product->category_id];
        }

        $ids = array_filter(
            array_map(static fn ($id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        );

        return array_values(array_unique($ids));
    }

    /**
     * Featured image_url first, then gallery_image_urls — deduped, order kept.
     *
     * @return array<int, string>
     */
    private function imageSrcs(Product $product): array
    {
        $srcs = [];

        if (! empty($product->image_url)) {
            $srcs[] = (string) $product->image_url;
        }

        $gallery = is_array($product->gallery_image_urls) ? $product->gallery_image_urls : [];
        foreach ($gallery as $url) {
            if (is_string($url) && $url !== '') {
                $srcs[] = $url;
            }
        }

        return array_values(array_unique($srcs));
    }

    /**
     * Map the local attributes_json (array of {name, value}) to the WC REST
     * `attributes[]` shape — name + options:[value] + visible:true +
     * variation:false + position (from array order). Skips rows with a blank
     * name or value, dedupes by lowercased name (last one wins). Returns []
     * when the column is empty/missing — buildCreatePayload then omits the
     * key entirely so Woo doesn't create empty global attributes.
     *
     * @return array<int, array{name:string, options:array<int,string>, position:int, visible:bool, variation:bool}>
     */
    private function wooAttributes(Product $product): array
    {
        $raw = is_array($product->attributes_json) ? $product->attributes_json : [];

        $byKey = [];
        foreach ($raw as $a) {
            if (! is_array($a)) {
                continue;
            }
            $name = trim((string) ($a['name'] ?? ''));
            $value = trim((string) ($a['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $byKey[strtolower($name)] = ['name' => $name, 'value' => $value];
        }

        $out = [];
        $i = 0;
        foreach ($byKey as $entry) {
            $out[] = [
                'name' => $entry['name'],
                'options' => [$entry['value']],
                'position' => $i++,
                'visible' => true,
                'variation' => false,
            ];
        }

        return $out;
    }
}
