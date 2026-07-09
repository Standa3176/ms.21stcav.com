<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductPriceSnapshot;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use Carbon\CarbonImmutable;

/**
 * Compose the post-supplier-sync digest payload — replaces the legacy WP
 * plugin's send_results_and_cleanup() 4-CSV email.
 *
 * Output shape (consumed by SupplierSyncDigestMail Blade view):
 *   [
 *     'window_start' => CarbonImmutable, 'window_end' => CarbonImmutable,
 *     'totals' => [products, with_buy_price, pending, missing_supplier_offer],
 *     'price_changes' => [['sku','name','old','new','delta_pct'], ...],
 *     'stock_changes' => [['sku','name','old','new'], ...],
 *     'flipped_pending' => [['sku','name','buy_price'], ...],
 *     'missing_supplier_offer' => [['sku','name','status'], ...],
 *   ]
 *
 * Stateless — safe to instantiate per-run.
 */
final class SupplierSyncDigestComposer
{
    /** Top-N rows surfaced in each list section. */
    private const TOP_N = 10;

    /**
     * @return array<string, mixed>
     */
    public function compose(): array
    {
        $end = CarbonImmutable::now('Europe/London');
        $start = $end->subDay();

        return [
            'window_start' => $start,
            'window_end' => $end,
            'totals' => $this->totals(),
            'price_changes' => $this->priceChanges($start),
            'stock_changes' => $this->stockChanges($start),
            'flipped_pending' => $this->flippedPending($start),
            'missing_supplier_offer' => $this->missingSupplierOffer(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function totals(): array
    {
        $today = today();

        return [
            'products' => (int) Product::query()->count(),
            'with_buy_price' => (int) Product::query()->whereNotNull('buy_price')->where('buy_price', '>', 0)->count(),
            'pending' => (int) Product::query()->where('status', 'pending')->count(),
            'missing_supplier_offer' => (int) Product::query()
                ->whereNotNull('sku')
                ->whereNotIn('id', SupplierOfferSnapshot::query()
                    ->where('recorded_at', $today)
                    ->whereNotNull('product_id')
                    ->select('product_id'))
                ->count(),
        ];
    }

    /**
     * Buy-price changes detected by comparing today's snapshot vs the most
     * recent prior snapshot per product.
     *
     * @return array<int, array<string, mixed>>
     */
    private function priceChanges(CarbonImmutable $start): array
    {
        $rows = ProductPriceSnapshot::query()
            ->from('product_price_snapshots as today')
            ->join('product_price_snapshots as prev', function ($join) {
                $join->on('today.product_id', '=', 'prev.product_id')
                    ->whereRaw('prev.recorded_at = (SELECT MAX(recorded_at) FROM product_price_snapshots WHERE product_id = today.product_id AND recorded_at < today.recorded_at)');
            })
            ->join('products', 'products.id', '=', 'today.product_id')
            ->where('today.recorded_at', today())
            ->whereColumn('today.buy_price', '!=', 'prev.buy_price')
            ->selectRaw('products.sku, products.name, prev.buy_price as old_buy, today.buy_price as new_buy')
            ->orderByRaw('ABS(today.buy_price - prev.buy_price) DESC')
            ->limit(self::TOP_N)
            ->get();

        return $rows->map(function ($r): array {
            $old = (float) $r->old_buy;
            $new = (float) $r->new_buy;
            $deltaPct = $old > 0 ? round(($new - $old) / $old * 100, 1) : null;

            return [
                'sku' => (string) $r->sku,
                'name' => (string) $r->name,
                'old' => $old,
                'new' => $new,
                'delta_pct' => $deltaPct,
            ];
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stockChanges(CarbonImmutable $start): array
    {
        $rows = ProductPriceSnapshot::query()
            ->from('product_price_snapshots as today')
            ->join('product_price_snapshots as prev', function ($join) {
                $join->on('today.product_id', '=', 'prev.product_id')
                    ->whereRaw('prev.recorded_at = (SELECT MAX(recorded_at) FROM product_price_snapshots WHERE product_id = today.product_id AND recorded_at < today.recorded_at)');
            })
            ->join('products', 'products.id', '=', 'today.product_id')
            ->where('today.recorded_at', today())
            ->whereColumn('today.stock_quantity', '!=', 'prev.stock_quantity')
            ->selectRaw('products.sku, products.name, prev.stock_quantity as old_stock, today.stock_quantity as new_stock')
            ->orderByRaw('ABS(today.stock_quantity - prev.stock_quantity) DESC')
            ->limit(self::TOP_N)
            ->get();

        return $rows->map(static fn ($r): array => [
            'sku' => (string) $r->sku,
            'name' => (string) $r->name,
            'old' => (int) $r->old_stock,
            'new' => (int) $r->new_stock,
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function flippedPending(CarbonImmutable $start): array
    {
        return Product::query()
            ->where('status', 'pending')
            ->where('updated_at', '>=', $start)
            ->orderByDesc('updated_at')
            ->limit(self::TOP_N)
            ->get(['sku', 'name', 'buy_price'])
            ->map(static fn (Product $p): array => [
                'sku' => (string) $p->sku,
                'name' => (string) $p->name,
                'buy_price' => $p->buy_price,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function missingSupplierOffer(): array
    {
        $today = today();

        return Product::query()
            ->whereNotNull('sku')
            ->where('status', 'publish')
            ->whereNotIn('id', SupplierOfferSnapshot::query()
                ->where('recorded_at', $today)
                ->whereNotNull('product_id')
                ->select('product_id'))
            ->orderBy('sku')
            ->limit(self::TOP_N)
            ->get(['sku', 'name', 'status'])
            ->map(static fn (Product $p): array => [
                'sku' => (string) $p->sku,
                'name' => (string) $p->name,
                'status' => (string) $p->status,
            ])
            ->all();
    }
}
