<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Concerns;

use App\Domain\Products\Models\Product;

trait BuildsWooStockPayload
{
    /**
     * WooCommerce stock keys so app-created products display a stock line like
     * legacy products. manage_stock=true makes WC show/track quantity; stock_status
     * is derived from quantity (qty>0 => instock, qty<=0 => outofstock) so we never
     * emit a contradictory qty=0 + instock (oversell risk) — the sole exception is a
     * genuine 'onbackorder', which stays sellable at qty<=0 when backorders are allowed.
     *
     * @return array{manage_stock:bool, stock_quantity:int, stock_status:string}
     */
    protected function wooStockPayload(Product $product): array
    {
        $qty = max(0, (int) ($product->stock_quantity ?? 0));
        // manage_stock=true makes quantity authoritative — derive status from it so we
        // never emit qty=0 + instock (oversell risk). Preserve only a genuine
        // 'onbackorder' (sellable at qty<=0 when backorders are allowed).
        $status = ((string) ($product->stock_status ?? '')) === 'onbackorder'
            ? 'onbackorder'
            : ($qty > 0 ? 'instock' : 'outofstock');

        return [
            'manage_stock' => true,
            'stock_quantity' => $qty,
            'stock_status' => $status,
        ];
    }
}
