<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Console\Concerns\NormalisesEan;
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
    use NormalisesEan;

    public function __construct(private readonly WooClient $woo) {}

    /** @return 'published'|'collision'|'skipped'|'invalid' */
    public function publish(Product $product, ?string $ean): string
    {
        $ean = trim((string) $ean);
        $wooId = (int) ($product->woo_product_id ?? 0);

        if ($wooId <= 0 || $ean === '') {
            return 'skipped';
        }

        // 260905-ae5 — THE choke point for Woo GTIN writes, and until now it
        // PUT whatever string it was handed. products:publish-sourced-eans has
        // no gate of its own, so on 2026-08-28 it came within one SKU of pushing
        // 61U3010000AC's fabricated `613010000` live purely because that SKU was
        // still in a --skus list. Refusing here means no caller can do that,
        // present or future.
        //
        // Rejected values are left ALONE locally: this is a publisher, and a
        // value that fails the gate is a data-quality question for
        // products:identity-health-check, not something to silently destroy.
        if ($this->isPlaceholderGtin($ean) || ! $this->isValidGtinChecksum($ean)) {
            Log::warning('WooGtinPublisher: refused a non-GTIN', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'ean' => $ean,
                'reason' => $this->isPlaceholderGtin($ean) ? 'padded_prefix' : 'checksum',
            ]);

            return 'invalid';
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
