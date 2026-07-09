<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Log;

/**
 * 260708-pw3 — publishes a product's local EAN to its EXISTING Woo product's GTIN
 * field (global_unique_id) + bumps local woo_gtin so the Woo Maintenance 'missing
 * EAN' gap clears. WC 9.x rejects DUPLICATE GTINs (suppliers share one EAN across
 * variants) — on that specific rejection we clear the local ean (so it stops
 * colliding) and report 'collision' rather than failing, mirroring PublishProductJob.
 */
final class WooGtinPublisher
{
    public function __construct(private readonly WooClient $woo) {}

    /** @return 'published'|'collision'|'skipped' */
    public function publish(Product $product, ?string $ean): string
    {
        $ean = trim((string) $ean);
        $wooId = (int) ($product->woo_product_id ?? 0);

        if ($wooId <= 0 || $ean === '') {
            return 'skipped';
        }

        try {
            $this->woo->put("products/{$wooId}", ['global_unique_id' => $ean]);
        } catch (\Throwable $e) {
            if (is_string($e->getMessage()) && str_contains($e->getMessage(), 'product_invalid_global_unique_id')) {
                Log::info('WooGtinPublisher: GTIN collision — clearing local EAN', [
                    'product_id' => $product->id, 'sku' => $product->sku, 'ean' => $ean,
                ]);
                $product->forceFill(['ean' => null])->saveQuietly();

                return 'collision';
            }

            throw $e; // real error — let the caller/queue see it.
        }

        $product->forceFill(['woo_gtin' => $ean])->saveQuietly();

        Log::info('WooGtinPublisher: published GTIN to Woo', [
            'product_id' => $product->id, 'woo_product_id' => $wooId, 'ean' => $ean,
        ]);

        return 'published';
    }
}
