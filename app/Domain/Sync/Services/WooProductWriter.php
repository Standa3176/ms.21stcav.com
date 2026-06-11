<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Cutover\Services\WooFieldComparator;
use App\Domain\Products\Models\Product;

/**
 * Quick task 260611-s2d — single source of truth for MS→Woo PUT payload
 * construction for stock_quantity / buy_price / category_id.
 *
 * Consumed by BOTH:
 *   - PushDivergenceToWooCommand (batched divergence push) — calls per-product
 *     inside the cursor loop after streaming sync_diffs.
 *   - PushProductFieldsToWoo listener (event-driven instant push) — calls
 *     once per ProductFieldsChangedEvent.
 *
 * Drift-prevention: if a 4th pushable field is added (e.g. brand_id once
 * pa_brand→id resolves), extend BOTH putProductFields branches AND
 * PushDivergenceToWooCommand::SUPPORTED_FIELDS in the same commit.
 *
 * Behaviour contract:
 *   - Pre-GET non-negotiable: buy_price meta-merge requires the existing
 *     Woo meta_data array to preserve Yoast / brand / EAN entries.
 *   - manage_stock=true is ALWAYS set when stock_quantity is pushed —
 *     without it Woo treats the quantity as a manual override.
 *   - meta_data merge drops any existing _alg_wc_cog_cost entry then
 *     appends a single fresh entry; every OTHER meta entry survives.
 *   - Empty payload (e.g. category_id requested but Product has neither
 *     category_id nor category_ids) → status='pushed' + fields_pushed=[]
 *     so caller treats it as "nothing to do" without erroring.
 *
 * No sync_diffs writes — that bookkeeping is command-level concern.
 * No usleep — per-PUT pacing is a COMMAND-loop concern (batched pushes);
 * the listener fires once per event and needs no pacing.
 *
 * Not `final` so listener Pest tests can swap a stub through the container
 * via anonymous-subclass binding (mirrors the WooClient pattern used by
 * PushDivergenceToWooCommandTest).
 */
class WooProductWriter
{
    public function __construct(private readonly WooClient $woo) {}

    /**
     * PUT the requested fields to Woo for $product.
     *
     * @param  array<int, string>  $fields  subset of {stock_quantity, buy_price, category_id}
     * @return array{status: string, fields_pushed: array<int, string>, http_status: ?int, reason: ?string}
     */
    public function putProductFields(Product $product, array $fields, ?string $correlationId = null): array
    {
        $wooId = (int) ($product->woo_product_id ?? 0);

        // ── Pre-GET (non-negotiable for buy_price meta merge) ────────────
        try {
            $wooDict = $this->woo->get("products/{$wooId}");
        } catch (\Throwable $e) {
            if ($this->looksLike404($e)) {
                return [
                    'status' => 'woo_not_found',
                    'fields_pushed' => [],
                    'http_status' => 404,
                    'reason' => null,
                ];
            }

            return [
                'status' => 'error',
                'fields_pushed' => [],
                'http_status' => null,
                'reason' => $e->getMessage(),
            ];
        }

        // ── Build PUT payload from requested $fields ─────────────────────
        $putPayload = [];
        $fieldsBeingPushed = [];

        if (in_array('stock_quantity', $fields, true)) {
            $putPayload['stock_quantity'] = (int) ($product->stock_quantity ?? 0);
            // manage_stock=true is non-negotiable — without it Woo treats
            // the quantity as a manual override, not a storefront source-of-truth.
            $putPayload['manage_stock'] = true;
            $fieldsBeingPushed[] = 'stock_quantity';
        }

        if (in_array('category_id', $fields, true)) {
            $categories = $this->buildCategoriesPayload($product);
            if ($categories !== []) {
                $putPayload['categories'] = $categories;
                $fieldsBeingPushed[] = 'category_id';
            }
        }

        if (in_array('buy_price', $fields, true)) {
            $putPayload['meta_data'] = $this->mergeBuyPriceMeta(
                is_array($wooDict['meta_data'] ?? null) ? $wooDict['meta_data'] : [],
                (float) ($product->buy_price ?? 0),
            );
            $fieldsBeingPushed[] = 'buy_price';
        }

        if ($putPayload === []) {
            // Caller asked for fields but Product can't satisfy them
            // (e.g. category_id-only request but Product has neither
            // category_id nor category_ids set). Treat as benign no-op.
            return [
                'status' => 'pushed',
                'fields_pushed' => [],
                'http_status' => null,
                'reason' => null,
            ];
        }

        // ── Live PUT ──────────────────────────────────────────────────────
        try {
            $this->woo->put("products/{$wooId}", $putPayload);
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'fields_pushed' => [],
                'http_status' => null,
                'reason' => $e->getMessage(),
            ];
        }

        return [
            'status' => 'pushed',
            'fields_pushed' => $fieldsBeingPushed,
            'http_status' => 200,
            'reason' => null,
        ];
    }

    /**
     * Build the Woo `categories` payload entry from local Product taxonomy.
     *
     * Prefers `category_ids` (multi-cat) when populated; falls back to single
     * `category_id`. Empty result (both null/empty) means caller should skip
     * the categories key entirely.
     *
     * @return array<int, array{id:int}>
     */
    private function buildCategoriesPayload(Product $product): array
    {
        $multi = $product->category_ids;
        if (is_array($multi) && $multi !== []) {
            return array_values(array_map(
                static fn ($id): array => ['id' => (int) $id],
                $multi,
            ));
        }

        if ($product->category_id !== null) {
            return [['id' => (int) $product->category_id]];
        }

        return [];
    }

    /**
     * Merge the local buy_price into Woo's existing meta_data array.
     *
     * Drops any existing `_alg_wc_cog_cost` entry, then appends a single fresh
     * entry with the local value (4 dp number_format matches Woo COG storage).
     * Every OTHER meta entry — Yoast SEO, EAN, brand_id, etc — survives
     * UNCHANGED. This contract is non-negotiable: a blind PUT with
     * meta_data=[{key:_alg_wc_cog_cost,...}] WIPES the rest.
     *
     * @param  array<int, mixed>  $wooMetaData
     * @return array<int, array{key:string,value:string}|array<string, mixed>>
     */
    private function mergeBuyPriceMeta(array $wooMetaData, float $localBuyPrice): array
    {
        $merged = [];
        foreach ($wooMetaData as $entry) {
            if (! is_array($entry)) {
                $merged[] = $entry;

                continue;
            }
            if ((string) ($entry['key'] ?? '') === WooFieldComparator::BUY_PRICE_META_KEY) {
                // Drop the existing cost-of-goods entry; we'll re-append fresh.
                continue;
            }
            $merged[] = $entry;
        }

        $merged[] = [
            'key' => WooFieldComparator::BUY_PRICE_META_KEY,
            'value' => number_format($localBuyPrice, 4, '.', ''),
        ];

        return $merged;
    }

    /**
     * Best-effort 404 detection for Woo GET failures.
     *
     * The Automattic Woo SDK throws HttpClientException with a 404 status code
     * on Woo product-deleted responses; we treat string-match "404" in message
     * as the portable fallback (stubs can throw RuntimeException with "404"
     * in the message to exercise the woo_not_found branch).
     */
    private function looksLike404(\Throwable $e): bool
    {
        if (str_contains($e->getMessage(), '404')) {
            return true;
        }
        if (method_exists($e, 'getResponse')) {
            $response = $e->getResponse();
            if (is_object($response) && method_exists($response, 'getCode')) {
                return (int) $response->getCode() === 404;
            }
        }

        return false;
    }
}
