<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Listeners;

use App\Domain\Pricing\Events\ProductPriceChanged;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Core-loop step #2 — push a recomputed sell price to WooCommerce.
 *
 * Subscribes to ProductPriceChanged (emitted by PriceRecomputer AND
 * pricing:undercut-competitors) and PUTs the new price to Woo's regular_price.
 * This is the listener the EventServiceProvider comment has long referenced as
 * "the downstream Woo PUT on ProductPriceChanged" — it finally exists.
 *
 * Gating: the actual write goes through WooClient::put → writeOrShadow, so with
 * WOO_WRITE_ENABLED=false it records a SyncDiff (shadow) instead of hitting Woo.
 * Nothing reaches the live store until the cutover flips that flag — running the
 * pricing command now just stages SyncDiffs you can review.
 *
 * Queue: sync-woo-push (FOUND-09; sync-woo-push-supervisor caps at ≤3 processes
 * for Woo's ~100 req/min headroom).
 *
 * VAT: sell_price is VAT-INCLUSIVE. By default we push inc-VAT to regular_price
 * (matches CreateWooProductJob). If the Woo store enters prices ex-VAT, set
 * WOO_PUSH_PRICES_EX_VAT=true and we strip VAT first. CONFIRM this against the
 * storefront before cutover — a wrong basis is a 20% price error.
 *
 * Skips silently (logs) when the product has no woo_product_id yet (e.g. a local
 * draft not yet created on Woo) — there is nothing to update.
 */
final class PushPriceChangeToWoo implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly WooClient $woo,
        private readonly PriceCalculator $calculator,
    ) {}

    public function viaQueue(): string
    {
        // 260719-wth — dedicated single-worker write queue. This was the incident's
        // main offender (222 concurrent price pushes); keeping it off the shared
        // sync-woo-push pool stops a price-push backlog starving other queues.
        return 'woo-writes';
    }

    public function handle(ProductPriceChanged $event): void
    {
        $product = Product::query()->where('id', $event->productId)->first();
        if ($product === null || $product->woo_product_id === null) {
            Log::info('pricing.woo_push_skipped_no_woo_id', [
                'product_id' => $event->productId,
                'sku' => $event->sku,
                'new_pennies' => $event->newPennies,
            ]);

            return;
        }

        // Quick task 260701-n4y — skip products not on the storefront. Drafts /
        // pending (old `manual` imports) are the sole source of the 204 stale
        // woo_product_ids Woo 400s on with woocommerce_rest_product_invalid_id;
        // they aren't published so pushing a price is pointless noise. Live-price
        // sync for PUBLISHED products is unaffected — this only silences drafts.
        if ($product->status !== 'publish') {
            Log::info('pricing.woo_push_skipped_not_published', [
                'product_id' => $product->id,
                'sku' => $event->sku,
                'status' => $product->status,
            ]);

            return;
        }

        // sell_price (event newPennies) is VAT-inclusive. Push inc-VAT by default;
        // strip to ex-VAT only when the store is configured ex-VAT.
        $pennies = (bool) config('services.woo.push_prices_ex_vat', false)
            ? $this->calculator->stripVat($event->newPennies)
            : $event->newPennies;
        $regularPrice = number_format($pennies / 100, 2, '.', '');

        // No leading slash — the Woo SDK 404s ("rest_no_route") on a leading "/".
        if ($event->variantId !== null) {
            $variant = ProductVariant::query()->where('id', $event->variantId)->first();
            if ($variant === null || $variant->woo_variation_id === null) {
                Log::info('pricing.woo_push_skipped_no_woo_variation', [
                    'variant_id' => $event->variantId,
                    'sku' => $event->sku,
                ]);

                return;
            }
            $this->putOrClearStale(
                "products/{$product->woo_product_id}/variations/{$variant->woo_variation_id}",
                $regularPrice,
                $product,
                $event,
            );

            return;
        }

        $this->putOrClearStale(
            "products/{$product->woo_product_id}",
            $regularPrice,
            $product,
            $event,
        );
    }

    /**
     * Quick task 260701-n4y — PUT a price to Woo, but self-heal a stale
     * woo_product_id instead of failing the job.
     *
     * On a Woo error whose message contains the WC error CODE
     * `woocommerce_rest_product_invalid_id` (observed message shape:
     * "... Error: Invalid ID. [woocommerce_rest_product_invalid_id] ...") the
     * product's Woo record was deleted underneath us: we NULL the local
     * woo_product_id (saveQuietly — no observer/audit churn), log it, and RETURN
     * WITHOUT rethrowing so the job succeeds, stops retrying, and the product is
     * flagged (null woo id) for re-link. Any OTHER exception rethrows so
     * genuine/transient errors (5xx, 429-exhaustion, auth) still retry.
     *
     * Nulling woo_product_id is the correct recovery even on the variant path —
     * an invalid parent product id makes the variation write moot.
     */
    private function putOrClearStale(string $path, string $regularPrice, Product $product, ProductPriceChanged $event): void
    {
        try {
            $this->woo->put($path, ['regular_price' => $regularPrice]);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'woocommerce_rest_product_invalid_id')) {
                Log::warning('pricing.woo_push_stale_id_cleared', [
                    'product_id' => $product->id,
                    'sku' => $event->sku,
                    'woo_product_id' => $product->woo_product_id,
                    'path' => $path,
                ]);
                $product->forceFill(['woo_product_id' => null])->saveQuietly();

                return; // stale link cleared + flagged; do NOT rethrow (no retry, no failed_jobs)
            }

            throw $e; // genuine/transient — let the job retry
        }
    }
}
