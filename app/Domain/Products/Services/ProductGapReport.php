<?php

declare(strict_types=1);

namespace App\Domain\Products\Services;

use App\Domain\Products\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Quick task 260707-w2w — single source of truth for "Woo maintenance"
 * catalogue gaps: products LIVE on Woo (status=publish + woo_product_id)
 * that are missing a maintainable field. Backs the Maintenance Overview
 * (WooMaintenanceGapsWidget) and the Catalogue Gaps drill-down — both call
 * liveBase() + apply()/counts() so the overview totals and the list ALWAYS
 * agree.
 *
 * Quick task 260708-akz — PROD HANG FIX. counts() is ONE aggregate query
 * (conditional SUMs over liveBase()) with plain, index-friendly predicates
 * — never a per-row JSON scan (that hung the admin).
 *
 * Quick task 260708-cey — PASS 2 REWIRE onto the RECONCILED woo_* mirror.
 * Pass 1 (260708-b4f) added woo_image_count / woo_gtin / woo_category_count
 * / woo_stock_status / woo_reconciled_at, populated nightly by
 * products:reconcile-woo-maintenance from the real Woo /products state.
 * The gaps now read that mirror instead of the local gallery/ean/stock/brand
 * columns, so the dashboard reports the TRUE whole-shop state (prod-validated:
 * 180 missing images / 628 missing EAN / 0 missing category over 4,604 live)
 * rather than the misleading local-mirror emptiness.
 *
 * Gaps are only meaningful for RECONCILED products (we know their real Woo
 * state), so every gap predicate gates on woo_reconciled_at IS NOT NULL:
 *   missing_images   — woo_image_count = 0
 *   missing_ean      — woo_gtin IS NULL
 *   missing_category — woo_category_count = 0
 *   missing_brand    — woo_brand_count = 0
 *
 * Quick task 260708-fyh — PASS B adds missing_brand. woo_brand_count is the
 * live product_brand term count captured by the WP-REST brand pass in
 * products:reconcile-woo-maintenance (260708-dyy); 0 means the storefront
 * Brand: link is empty (prod-verified 391 over 4,612 reconciled). This
 * completes the reconciled gap set (images / EAN / category / brand).
 *
 * Stock dropped from the gap set: stock_status is always set on Woo so it's
 * never a gap.
 *
 * All predicates are plain indexed-column comparisons (no JSON function) —
 * driver-portable across SQLite (tests) and MariaDB (prod), and cheap (keeps
 * the akz hang fix).
 */
class ProductGapReport
{
    /** gap key => human label (order = display order). */
    public const GAPS = [
        'missing_images' => 'Missing images',
        'missing_ean' => 'Missing EAN',
        'missing_category' => 'Missing category',
        'missing_brand' => 'Missing brand',
    ];

    /** Products live on the shop — the maintenance target. */
    public function liveBase(): Builder
    {
        return Product::query()
            ->where('status', 'publish')
            ->whereNotNull('woo_product_id');
    }

    /**
     * Narrow a query to a single gap (used by Overview counts + the drill-down
     * list). Gaps are only meaningful for RECONCILED products, so this always
     * gates on woo_reconciled_at IS NOT NULL first.
     */
    public function apply(Builder $query, string $gap): Builder
    {
        $query->whereNotNull('woo_reconciled_at');

        return match ($gap) {
            'missing_images' => $query->where('woo_image_count', 0),
            'missing_ean' => $query->whereNull('woo_gtin'),
            'missing_category' => $query->where('woo_category_count', 0),
            'missing_brand' => $query->where('woo_brand_count', 0),
            default => $query,
        };
    }

    /**
     * @return array{
     *     total:int,
     *     reconciled:int,
     *     not_reconciled:int,
     *     last_reconciled_at:?string,
     *     gaps:array<string,int>
     * } cached 300s.
     */
    public function counts(): array
    {
        return Cache::remember('woo_maintenance.gap_counts', 300, function (): array {
            $row = $this->liveBase()->selectRaw(
                'COUNT(*) as total'
                .', SUM(CASE WHEN woo_reconciled_at IS NOT NULL THEN 1 ELSE 0 END) as reconciled'
                .', SUM(CASE WHEN woo_reconciled_at IS NOT NULL AND woo_image_count = 0 THEN 1 ELSE 0 END) as missing_images'
                .', SUM(CASE WHEN woo_reconciled_at IS NOT NULL AND woo_gtin IS NULL THEN 1 ELSE 0 END) as missing_ean'
                .', SUM(CASE WHEN woo_reconciled_at IS NOT NULL AND woo_category_count = 0 THEN 1 ELSE 0 END) as missing_category'
                .', SUM(CASE WHEN woo_reconciled_at IS NOT NULL AND woo_brand_count = 0 THEN 1 ELSE 0 END) as missing_brand'
                .', MAX(woo_reconciled_at) as last_reconciled_at'
            )->first();

            $total = (int) ($row->total ?? 0);
            $reconciled = (int) ($row->reconciled ?? 0);

            return [
                'total' => $total,
                'reconciled' => $reconciled,
                'not_reconciled' => max(0, $total - $reconciled),
                'last_reconciled_at' => $row->last_reconciled_at ?? null,
                'gaps' => [
                    'missing_images' => (int) ($row->missing_images ?? 0),
                    'missing_ean' => (int) ($row->missing_ean ?? 0),
                    'missing_category' => (int) ($row->missing_category ?? 0),
                    'missing_brand' => (int) ($row->missing_brand ?? 0),
                ],
            ];
        });
    }
}
