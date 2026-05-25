<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Jobs;

use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\ProductAutoCreate\Events\ProductPublished;
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

    public function handle(WooClient $woo, PriceCalculator $calculator): void
    {
        $product = Product::findOrFail($this->productId);

        $wooId = (int) ($product->woo_product_id ?? 0);

        if ($wooId > 0) {
            // ── Path A — already on Woo: flip the existing draft to publish ──
            // No leading slash — the Woo SDK 404s ("rest_no_route") on a leading "/".
            $response = $woo->put("products/{$wooId}", ['status' => 'publish']);
        } else {
            // ── Path B (#3b) — create the auto-draft on Woo, published ───────
            $response = $woo->post('products', $this->buildCreatePayload($product, $calculator));

            $newWooId = (int) ($response['id'] ?? 0);
            if ($newWooId > 0) {
                // Live create: reconcile Woo id + the slug Woo actually assigned
                // (it server-side de-duplicates colliding slugs).
                $product->forceFill([
                    'woo_product_id' => $newWooId,
                    'slug' => (string) ($response['slug'] ?? $product->slug),
                ])->saveQuietly();
                $wooId = $newWooId;
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
}
