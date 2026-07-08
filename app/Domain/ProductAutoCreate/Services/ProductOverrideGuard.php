<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Audit\Services\Auditor;

/**
 * Phase 6 Plan 03 — ProductOverrideGuard (D-10 / D-11 pin enforcement).
 *
 * Called from Plan 05's ApplyPinsDuringSync listener AFTER Phase 2's
 * SyncChunkJob has written supplier-sourced data to Woo. For any pinned
 * field (per-product, per-field pin_* boolean), we PUT the locally-held
 * value back to Woo — "Woo briefly sees the supplier value, then our pin
 * re-asserts its sovereignty". See RESEARCH Example 5 rationale.
 *
 * 7-field woo-field → (pin_* flag, local column) mapping:
 *   name              → pin_title             + name
 *   slug              → pin_slug              + slug
 *   short_description → pin_short_description + short_description
 *   description       → pin_long_description  + long_description
 *   meta_description  → pin_meta_description  + meta_description
 *   regular_price     → pin_price             + sell_price  (Phase 3 column)
 *   images            → pin_image             + image_url
 *
 * brand + category pins live in product_overrides but don't have a direct
 * single-field PUT payload equivalent (brand/category require attribute/term
 * lookups via the taxonomy endpoints); Plan 05 handles those via a distinct
 * flow and revertIfPinned ignores them here.
 *
 * D-12 audit — every successful revert writes `product_auto_create.pin_reverted`
 * to the system activity log via Auditor so the ops-side inspector can see
 * exactly which fields the guard pushed back.
 */
class ProductOverrideGuard
{
    /**
     * @var array<string, array{pin: ?string, local: ?string}>
     */
    private const FIELD_MAP = [
        'name' => ['pin' => 'pin_title', 'local' => 'name'],
        'slug' => ['pin' => 'pin_slug', 'local' => 'slug'],
        'short_description' => ['pin' => 'pin_short_description', 'local' => 'short_description'],
        'description' => ['pin' => 'pin_long_description', 'local' => 'long_description'],
        'meta_description' => ['pin' => 'pin_meta_description', 'local' => 'meta_description'],
        'regular_price' => ['pin' => 'pin_price', 'local' => 'sell_price'],
        'images' => ['pin' => 'pin_image', 'local' => 'image_url'],
        // Not individually pinnable via Woo PUT — ignored intentionally.
        'stock_quantity' => ['pin' => null, 'local' => null],
        'status' => ['pin' => null, 'local' => null],
    ];

    public function __construct(
        private WooClient $woo,
        private Auditor $auditor,
    ) {}

    /**
     * For the Woo product with the given wooProductId, walk the passed
     * $fieldNames list; if the corresponding pin flag is set on the product's
     * ProductOverride row, PUT the local value back to Woo.
     *
     * @param  array<int, string>  $fieldNames
     */
    public function revertIfPinned(int $wooProductId, array $fieldNames, string $source): void
    {
        $product = Product::query()->where('woo_product_id', $wooProductId)->first();
        if ($product === null) {
            return;
        }
        $override = ProductOverride::query()->where('product_id', $product->id)->first();
        if ($override === null) {
            return;
        }

        $revertPayload = [];
        foreach ($fieldNames as $wooField) {
            $entry = self::FIELD_MAP[$wooField] ?? null;
            if ($entry === null || $entry['pin'] === null || $entry['local'] === null) {
                continue;
            }

            if ((bool) ($override->{$entry['pin']} ?? false) !== true) {
                continue;
            }

            $local = $product->{$entry['local']} ?? null;
            if ($local === null) {
                continue;
            }

            $revertPayload[$wooField] = $this->shapeForWoo($wooField, $local);
        }

        if ($revertPayload === []) {
            return;
        }

        $this->woo->put("/products/{$wooProductId}", $revertPayload);
        $this->auditor->record('product_auto_create.pin_reverted', [
            'product_id' => (int) $product->id,
            'woo_product_id' => $wooProductId,
            'fields' => array_keys($revertPayload),
            'source' => $source,
        ]);
    }

    /**
     * Cast each local field value to the shape Woo's REST API expects.
     * images → [{src: URL}]; regular_price → "xx.xx" string; everything else
     * passes through as-is.
     */
    private function shapeForWoo(string $wooField, mixed $local): mixed
    {
        return match ($wooField) {
            'images' => [['src' => (string) $local]],
            'regular_price' => number_format((float) $local, 2, '.', ''),
            default => $local,
        };
    }
}
