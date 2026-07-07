<?php

declare(strict_types=1);

namespace App\Domain\Products\Services;

use App\Domain\Products\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
 * The empty-images check is driver-portable (SQLite json_array_length vs
 * MariaDB JSON_LENGTH), mirroring AutoCreateHealthPage::emptyImagesExpr.
 * The expression is a compile-time literal — the driver name comes from
 * getDriverName(), never user input, so there is no SQLi vector.
 */
class ProductGapReport
{
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

    private function emptyImagesExpr(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? 'json_array_length(gallery_image_urls) = 0'
            : 'JSON_LENGTH(gallery_image_urls) = 0';
    }

    /** Narrow a query to a single gap (used by Overview counts + the Pass-2 list). */
    public function apply(Builder $query, string $gap): Builder
    {
        return match ($gap) {
            'missing_images' => $query->where(fn (Builder $q) => $q
                ->whereNull('gallery_image_urls')->orWhereRaw($this->emptyImagesExpr())),
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
     * @return array{total:int, gaps:array<string,int>} cached 60s.
     */
    public function counts(): array
    {
        return Cache::remember('woo_maintenance.gap_counts', 60, function (): array {
            $total = $this->liveBase()->count();
            $gaps = [];
            foreach (array_keys(self::GAPS) as $gap) {
                $gaps[$gap] = $this->apply($this->liveBase(), $gap)->count();
            }

            return ['total' => $total, 'gaps' => $gaps];
        });
    }
}
