<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

/**
 * Paginates Woo /products at per_page=100 (Woo's hard cap) and, for type=variable
 * parents, fetches /products/{id}/variations — also paginated at 100/page (A9).
 *
 * Yields a flat per-page stream of sync units so SyncChunkJob can be dispatched
 * per iterator yield without caring whether a row originated from a simple
 * product or a variation.
 *
 * grouped / external products are skipped in v1 (scope decision D-02).
 *
 * Each yielded row shape:
 *   {
 *     type: 'simple'|'variation',
 *     sku: string,
 *     woo_product_id: int,
 *     woo_variation_id: ?int,
 *     price: string,                (Woo regular_price or price, as string — preserves 2dp)
 *     stock_quantity: int,
 *     manage_stock: bool,
 *     is_custom_ms: bool,           (cached from tags — inherited by variations from parent)
 *     exclude_from_auto_update: bool,  (cached from meta — inherited by variations from parent)
 *     attributes?: array            (variations only — colour/size etc)
 *   }
 */
final class WooProductIterator
{
    public function __construct(private WooClient $woo) {}

    /**
     * @return \Generator<int, array{page: int, skus: array<int, array<string, mixed>>}>
     */
    public function pages(int $fromPage = 1): \Generator
    {
        $page = max(1, $fromPage);
        do {
            $products = $this->woo->get('products', ['per_page' => 100, 'page' => $page]);
            if (empty($products)) {
                break;
            }

            $skus = [];
            foreach ($products as $p) {
                $type = (string) ($p['type'] ?? 'simple');
                $isCustomMs = $this->hasSlug($p['tags'] ?? [], 'custom-ms');
                $excludeFlag = $this->hasMeta($p['meta_data'] ?? [], '_exclude_from_auto_update');

                if ($type === 'simple') {
                    $skus[] = [
                        'type' => 'simple',
                        'sku' => (string) ($p['sku'] ?? ''),
                        'woo_product_id' => (int) ($p['id'] ?? 0),
                        'woo_variation_id' => null,
                        'price' => (string) ($p['regular_price'] ?? $p['price'] ?? ''),
                        'stock_quantity' => (int) ($p['stock_quantity'] ?? 0),
                        'manage_stock' => (bool) ($p['manage_stock'] ?? false),
                        'is_custom_ms' => $isCustomMs,
                        'exclude_from_auto_update' => $excludeFlag,
                    ];

                    continue;
                }

                if ($type === 'variable') {
                    $variationPage = 1;
                    do {
                        $variations = $this->woo->get("products/{$p['id']}/variations", [
                            'per_page' => 100,
                            'page' => $variationPage,
                        ]);
                        if (empty($variations)) {
                            break;
                        }

                        foreach ($variations as $v) {
                            $manageStockRaw = $v['manage_stock'] ?? false;
                            $manageStock = is_bool($manageStockRaw)
                                ? $manageStockRaw
                                : ($manageStockRaw === 'parent' ? (bool) ($p['manage_stock'] ?? false) : (bool) $manageStockRaw);

                            $skus[] = [
                                'type' => 'variation',
                                'sku' => (string) ($v['sku'] ?? ''),
                                'woo_product_id' => (int) ($p['id'] ?? 0),
                                'woo_variation_id' => (int) ($v['id'] ?? 0),
                                'price' => (string) ($v['regular_price'] ?? $v['price'] ?? ''),
                                'stock_quantity' => (int) ($v['stock_quantity'] ?? 0),
                                'manage_stock' => $manageStock,
                                'is_custom_ms' => $isCustomMs,
                                'exclude_from_auto_update' => $excludeFlag,
                                'attributes' => $v['attributes'] ?? [],
                            ];
                        }

                        $variationCount = count($variations);
                        $variationPage++;
                    } while ($variationCount === 100);

                    continue;
                }

                // grouped / external — v1 scope skip (D-02).
            }

            yield ['page' => $page, 'skus' => $skus];
            $productCount = count($products);
            $page++;
        } while ($productCount === 100);
    }

    /**
     * Case-insensitive slug match across a Woo tag array.
     */
    private function hasSlug(array $tags, string $slug): bool
    {
        $needle = strtolower($slug);
        foreach ($tags as $tag) {
            if (strtolower((string) ($tag['slug'] ?? '')) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Truthy-aware meta match — Woo persists truthy values as 'yes' / '1' / 1 / true.
     */
    private function hasMeta(array $meta, string $key): bool
    {
        foreach ($meta as $m) {
            if (($m['key'] ?? '') !== $key) {
                continue;
            }
            $value = $m['value'] ?? null;
            if ($value === 'yes' || $value === '1' || $value === true || $value === 1) {
                return true;
            }
        }

        return false;
    }
}
