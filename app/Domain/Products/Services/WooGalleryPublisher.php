<?php

declare(strict_types=1);

namespace App\Domain\Products\Services;

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Log;

/**
 * 260708-kg4 — publishes a product's locally-sourced image gallery to its EXISTING
 * Woo product. A Woo images-PUT replaces the whole gallery, so this removes any
 * placeholder image. Also bumps the local woo_image_count so the Woo Maintenance
 * dashboard reflects the fix without waiting for the nightly reconcile.
 * Used by products:source-images --push-to-woo (the Catalogue Gaps Source-images fix).
 */
final class WooGalleryPublisher
{
    public function __construct(private readonly WooClient $woo) {}

    /**
     * @param  array<int, string>  $imageUrls  ops-hosted public URLs (Woo downloads them)
     * @return bool true if published to Woo; false if skipped (not live / no images)
     */
    public function publish(Product $product, array $imageUrls): bool
    {
        $urls = array_values(array_filter(
            $imageUrls,
            static fn ($u): bool => is_string($u) && $u !== '',
        ));

        $wooId = (int) ($product->woo_product_id ?? 0);
        if ($wooId <= 0 || $urls === []) {
            return false; // draft / not on Woo, or nothing real to push — leave as-is.
        }

        $this->woo->put("products/{$wooId}", [
            'images' => array_map(static fn (string $u): array => ['src' => $u], $urls),
        ]);

        // Reflect on the dashboard immediately (missing_images = reconciled woo_image_count = 0).
        $product->forceFill(['woo_image_count' => count($urls)])->saveQuietly();

        Log::info('WooGalleryPublisher: published gallery to Woo', [
            'product_id' => $product->id, 'woo_product_id' => $wooId, 'image_count' => count($urls),
        ]);

        return true;
    }
}
