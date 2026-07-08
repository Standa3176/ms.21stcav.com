<?php

declare(strict_types=1);

namespace App\Domain\Products\Services;

use App\Domain\Products\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Quick task 260707-w2w — single source of truth for "Woo maintenance"
 * catalogue gaps: products LIVE on Woo (status=publish + woo_product_id)
 * that are missing a maintainable field.
 *
 * Backs the Maintenance Overview summary (WooMaintenanceGapsWidget) and,
 * from Pass 2, the Catalogue Gaps drill-down list — both call liveBase()
 * + apply()/counts() so the overview totals and the list ALWAYS agree
 * (same drift-prevention pattern as AutoCreateHealthPage::unhealthyQuery
 * and Suggestion::scopeHighConfidenceSourceable).
 *
 * Quick task 260708-akz — PROD HANG FIX. counts() previously ran 5-6
 * separate full-table scans, each with a per-row JSON function
 * (JSON_LENGTH(gallery_image_urls)=0) over the whole live catalogue. That
 * exceeded PHP's 30s max_execution_time and, because it timed out INSIDE
 * Cache::remember, it never cached — so every render (including the
 * auto-discovered dashboard widget, which auto-polled) re-ran it and hung.
 *
 * counts() is now ONE aggregate query (conditional SUMs over liveBase())
 * with plain, index-friendly predicates. The empty-gallery test is a plain
 * string compare (EMPTY_GALLERY_SQL) — Laravel's array cast stores an empty
 * gallery as the literal '[]', so the verdict is IDENTICAL to the old
 * JSON_LENGTH=0 (empty array) with no JSON function and no driver branch.
 * All operators used (TRIM/CASE/SUM/string-compare) are driver-portable
 * across SQLite (tests) and MariaDB (prod).
 */
class ProductGapReport
{
    /**
     * Empty gallery, index-friendly (Laravel array-cast stores [] as the
     * literal '[]'). No JSON function — compile-time literal, no SQLi vector.
     */
    private const EMPTY_GALLERY_SQL = "(gallery_image_urls IS NULL OR gallery_image_urls = '[]' OR gallery_image_urls = '')";

    /** gap key => human label (order = display order). */
    public const GAPS = [
        'missing_images' => 'Missing images',
        'missing_ean' => 'Missing EAN',
        'missing_stock_status' => 'Missing stock status',
        'missing_brand' => 'Missing brand',
        'missing_category' => 'Missing category',
    ];

    /** Products live on the shop — the maintenance target. */
    public function liveBase(): Builder
    {
        return Product::query()
            ->where('status', 'publish')
            ->whereNotNull('woo_product_id');
    }

    /** Narrow a query to a single gap (used by Overview counts + the Pass-2 list). */
    public function apply(Builder $query, string $gap): Builder
    {
        return match ($gap) {
            'missing_images' => $query->whereRaw(self::EMPTY_GALLERY_SQL),
            'missing_ean' => $query->where(fn (Builder $q) => $q
                ->whereNull('ean')->orWhereRaw("TRIM(ean) = ''")),
            'missing_stock_status' => $query->where(fn (Builder $q) => $q
                ->whereNull('stock_status')->orWhereRaw("TRIM(stock_status) = ''")),
            'missing_brand' => $query->whereNull('brand_id'),
            'missing_category' => $query->whereNull('category_id'),
            default => $query,
        };
    }

    /**
     * @return array{total:int, gaps:array<string,int>} cached 300s.
     */
    public function counts(): array
    {
        return Cache::remember('woo_maintenance.gap_counts', 300, function (): array {
            $row = $this->liveBase()->selectRaw(
                'COUNT(*) as total'
                .', SUM(CASE WHEN '.self::EMPTY_GALLERY_SQL.' THEN 1 ELSE 0 END) as missing_images'
                .", SUM(CASE WHEN (ean IS NULL OR TRIM(ean) = '') THEN 1 ELSE 0 END) as missing_ean"
                .", SUM(CASE WHEN (stock_status IS NULL OR TRIM(stock_status) = '') THEN 1 ELSE 0 END) as missing_stock_status"
                .', SUM(CASE WHEN brand_id IS NULL THEN 1 ELSE 0 END) as missing_brand'
                .', SUM(CASE WHEN category_id IS NULL THEN 1 ELSE 0 END) as missing_category'
            )->first();

            return [
                'total' => (int) ($row->total ?? 0),
                'gaps' => [
                    'missing_images' => (int) ($row->missing_images ?? 0),
                    'missing_ean' => (int) ($row->missing_ean ?? 0),
                    'missing_stock_status' => (int) ($row->missing_stock_status ?? 0),
                    'missing_brand' => (int) ($row->missing_brand ?? 0),
                    'missing_category' => (int) ($row->missing_category ?? 0),
                ],
            ];
        });
    }
}
