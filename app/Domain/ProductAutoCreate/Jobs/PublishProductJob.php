<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Jobs;

use App\Domain\ProductAutoCreate\Events\ProductPublished;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 6 Plan 03 — admin-triggered draft → published transition.
 *
 * Dispatched from Plan 04's "Publish" action in AutoCreateReviewResource.
 * Flips Product.auto_create_status='published' + status='publish' AND mirrors
 * the transition into Woo via WooClient::put('/products/{wooId}', {status}).
 *
 * Fires ProductPublished so Phase 7's dashboard tile + any downstream
 * listeners (feed generators in Phase 8) can subscribe without Phase 6
 * having to predict their shape.
 *
 * Queue: sync-woo-push (same rate-limit budget as CreateWooProductJob).
 * tries=3 so a transient Woo 429 doesn't drop the publish action.
 */
final class PublishProductJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $productId,
        public readonly int $publishedByUserId,
    ) {
        // PHP 8.4 trait-collision guard.
        $this->onQueue('sync-woo-push');
    }

    public function handle(WooClient $woo): void
    {
        $product = Product::findOrFail($this->productId);

        $wooId = (int) ($product->woo_product_id ?? 0);
        if ($wooId > 0) {
            $woo->put("/products/{$wooId}", ['status' => 'publish']);
        }

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
}
