<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Concerns;

use App\Domain\Products\Models\Product;

trait BuildsWooStockPayload
{
    /**
     * WooCommerce stock keys so app-created products display a stock line like
     * legacy products. manage_stock=true makes WC show/track quantity; stock_status
     * is set explicitly for theme display and reconciled by WC from quantity.
     *
     * @return array{manage_stock:bool, stock_quantity:int, stock_status:string}
     */
    protected function wooStockPayload(Product $product): array
    {
        $qty = max(0, (int) ($product->stock_quantity ?? 0));
        $status = (string) ($product->stock_status ?? '');
        if (! in_array($status, ['instock', 'outofstock', 'onbackorder'], true)) {
            $status = $qty > 0 ? 'instock' : 'outofstock';
        }

        return [
            'manage_stock' => true,
            'stock_quantity' => $qty,
            'stock_status' => $status,
        ];
    }
}
