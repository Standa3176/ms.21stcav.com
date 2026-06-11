<?php

declare(strict_types=1);

namespace App\Domain\Products\Observers;

use App\Domain\Products\Events\ProductFieldsChangedEvent;
use App\Domain\Products\Models\Product;

/**
 * Quick task 260611-s2d — Eloquent observer on Product::updated.
 *
 * When the cutover.event_driven_push_enabled flag is ON, dispatches
 * ProductFieldsChangedEvent for direct Product saves that touch one of:
 *   - stock_quantity
 *   - buy_price
 *   - sell_price
 *   - category_id
 *
 * Tracked-field allowlist matches WooFieldComparator's pushable subset
 * (stock_quantity / buy_price / category_id) PLUS sell_price (pushed via
 * ProductPriceChanged event today; 260611-s2d adds parallel coverage so
 * admin-edited sell_price also reaches Woo instantly). If sell_price reaches
 * the listener AND ProductPriceChanged also fires, the duplicate PUT is
 * benign — Woo accepts identical PUTs and the listener is idempotent.
 * FOLLOW-UP: consider de-duping in a future task once observability
 * confirms the duplication is measurable.
 *
 * SAFETY NOTE — bulk paths: Eloquent mass updates via
 * `Product::where(...)->update([...])` do NOT trigger this observer.
 * Laravel only fires model events for instance ->save() (including
 * updateOrCreate, fill+save). Bulk paths therefore stay performant.
 * The ONLY bulk path that uses Eloquent save semantics is
 * WooImportProductsCommand::updateOrCreate — wrapped in
 * Product::withoutEvents() in 260611-s2d Task 5.
 *
 * SAFETY NOTE — feature flag: the observer is always registered, but the
 * dispatch is gated. When the flag is OFF (default) the observer is a
 * pure no-op (one config() read + early return). Cached-config safe
 * (per 2026-05-31 d7d0e39 lesson: always read via config(), never env()
 * outside config/*.php).
 */
final class ProductObserver
{
    /**
     * Subset of Product columns whose change triggers a Woo push.
     *
     * stock_quantity / buy_price / category_id mirror the divergence
     * push (260611-g4q) — single source of truth for the pushable
     * field list lives in PushDivergenceToWooCommand::SUPPORTED_FIELDS;
     * sell_price is added here because direct admin edits to sell_price
     * should also propagate to Woo (the ProductPriceChanged path is
     * driven by pricing recompute, NOT direct edits).
     *
     * @var array<int, string>
     */
    private const TRACKED_FIELDS = [
        'stock_quantity',
        'buy_price',
        'sell_price',
        'category_id',
    ];

    public function updated(Product $product): void
    {
        if (config('cutover.event_driven_push_enabled', false) !== true) {
            return;
        }

        $changed = array_values(array_intersect(
            array_keys($product->getChanges()),
            self::TRACKED_FIELDS,
        ));

        if ($changed === []) {
            return;
        }

        ProductFieldsChangedEvent::dispatch(
            $product->id,
            $product->sku,
            $changed,
        );
    }
}
