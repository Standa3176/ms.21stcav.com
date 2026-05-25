<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Products\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read model behind the Pricing Operations dashboard. Single source for all
 * four datasets so the page (preview tables + tile modals) and the CSV export
 * controller agree exactly:
 *
 *   - positions()    : cached competitor-position scan (below_cost / at_floor /
 *                      winnable + counts) from CompetitorPositionScanner.
 *   - recentChanges(): day-over-day sell-price moves from product_price_snapshots.
 *   - newSkus()      : auto-drafted products awaiting review.
 *   - bucket()       : the rows for one dashboard "number" (below_cost, at_floor,
 *                      winnable, matched, recent_changes, new_skus).
 *   - csv()          : {filename, header, rows} for the export route.
 *
 * Pure read — touches the local DB only, never Woo.
 */
final class PricingOpsReport
{
    public const CACHE_KEY = 'pricing_ops:positions';

    public const CACHE_TTL = 900; // 15 min

    /** Supplier add-candidates cache (written by supplier:scan-add-candidates). */
    public const ADD_CANDIDATES_CACHE_KEY = 'pricing_ops:add_candidates';

    /** Buckets the dashboard tiles + export route understand. */
    public const BUCKETS = ['below_cost', 'at_floor', 'winnable', 'matched', 'recent_changes', 'new_skus', 'add_candidates'];

    public function __construct(private readonly CompetitorPositionScanner $scanner) {}

    /**
     * Cached competitor-position scan (the heavy part). Page + export share it.
     *
     * @return array<string, mixed>
     */
    public function positions(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn (): array => $this->scanner->compute());
    }

    public function forgetPositions(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Day-over-day sell-price changes from the daily snapshots (LAG window).
     *
     * @return array<int, object>
     */
    public function recentChanges(int $days = 30, int $limit = 1000): array
    {
        $since = now()->subDays($days)->toDateString();

        return DB::select(
            'SELECT sku, sell_price AS new_price, prev_sell AS old_price, recorded_at FROM ('
            .'SELECT sku, sell_price, recorded_at, '
            .'LAG(sell_price) OVER (PARTITION BY product_id ORDER BY recorded_at) AS prev_sell, '
            .'ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY recorded_at DESC) AS rn '
            .'FROM product_price_snapshots WHERE recorded_at >= ?'
            .') t WHERE t.rn = 1 AND t.prev_sell IS NOT NULL AND t.sell_price <> t.prev_sell '
            .'ORDER BY t.recorded_at DESC, t.sku ASC LIMIT '.(int) $limit,
            [$since],
        );
    }

    /**
     * Auto-drafted products awaiting manual review, newest first.
     *
     * @return Collection<int, Product>
     */
    public function newSkus(int $limit = 1000): Collection
    {
        return Product::query()
            ->whereIn('auto_create_status', ['draft', 'pending_review', 'needs_brand_or_category_assignment'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'sku', 'name', 'auto_create_status', 'sell_price', 'created_at']);
    }

    /**
     * The competitor-position rows behind one tile. `matched` = all three
     * buckets merged (worst margin first).
     *
     * @return array<int, array<string, mixed>>
     */
    public function competitorBucket(string $bucket): array
    {
        $p = $this->positions();

        return match ($bucket) {
            'below_cost' => $p['below_cost'],
            'at_floor' => $p['at_floor'],
            'winnable' => $p['winnable'],
            'matched' => collect($p['below_cost'])
                ->merge($p['at_floor'])->merge($p['winnable'])
                ->sortBy('margin_bps')->values()->all(),
            default => [],
        };
    }

    /**
     * Supplier add-candidates from the cached scan (supplier:scan-add-candidates):
     * parts on ≥N suppliers we don't sell yet. Empty if never scanned.
     *
     * @return array{candidates:array<int,array<string,mixed>>, count:int, min_suppliers:int, computed_at:?string}
     */
    public function addCandidates(): array
    {
        $cached = Cache::get(self::ADD_CANDIDATES_CACHE_KEY);
        if (! is_array($cached)) {
            return ['candidates' => [], 'count' => 0, 'min_suppliers' => 3, 'computed_at' => null];
        }

        return [
            'candidates' => is_array($cached['candidates'] ?? null) ? $cached['candidates'] : [],
            'count' => (int) ($cached['count'] ?? 0),
            'min_suppliers' => (int) ($cached['min_suppliers'] ?? 3),
            'computed_at' => $cached['computed_at'] ?? null,
        ];
    }

    /**
     * CSV payload for the export route.
     *
     * @return array{filename:string, header:array<int,string>, rows:array<int,array<int,string>>}
     */
    public function csv(string $bucket): array
    {
        $stamp = now()->format('Y-m-d');
        $money = static fn (int $pennies): string => number_format($pennies / 100, 2, '.', '');

        if ($bucket === 'add_candidates') {
            $header = ['Brand', 'Part (MPN)', 'Description', 'Suppliers'];
            $rows = array_map(static fn (array $r): array => [
                (string) ($r['brand'] ?? ''),
                (string) ($r['part'] ?? ''),
                (string) ($r['title'] ?? ''),
                (string) ($r['suppliers'] ?? ''),
            ], $this->addCandidates()['candidates']);

            return ['filename' => "products-to-add-{$stamp}.csv", 'header' => $header, 'rows' => $rows];
        }

        if ($bucket === 'recent_changes') {
            $header = ['SKU', 'Old (£)', 'New (£)', 'Change (£)', 'Date'];
            $rows = array_map(static function (object $r): array {
                $delta = (float) $r->new_price - (float) $r->old_price;

                return [
                    (string) $r->sku,
                    number_format((float) $r->old_price, 2, '.', ''),
                    number_format((float) $r->new_price, 2, '.', ''),
                    number_format($delta, 2, '.', ''),
                    Carbon::parse($r->recorded_at)->toDateString(),
                ];
            }, $this->recentChanges());

            return ['filename' => "pricing-recent-changes-{$stamp}.csv", 'header' => $header, 'rows' => $rows];
        }

        if ($bucket === 'new_skus') {
            $header = ['SKU', 'Name', 'Status', 'Sell (£)', 'Added'];
            $rows = $this->newSkus()->map(static fn (Product $p): array => [
                (string) $p->sku,
                (string) $p->name,
                (string) $p->auto_create_status,
                $p->sell_price !== null ? number_format((float) $p->sell_price, 2, '.', '') : '',
                optional($p->created_at)->toDateTimeString() ?? '',
            ])->all();

            return ['filename' => "pricing-new-skus-{$stamp}.csv", 'header' => $header, 'rows' => $rows];
        }

        // Competitor-position buckets (below_cost / at_floor / winnable / matched).
        $header = ['SKU', 'Name', 'Our cost ex-VAT (£)', 'Lowest competitor ex-VAT (£)', 'Margin (%)'];
        $rows = array_map(static fn (array $r): array => [
            (string) $r['sku'],
            (string) $r['name'],
            $money((int) $r['cost_ex']),
            $money((int) $r['comp_ex']),
            number_format($r['margin_bps'] / 100, 2, '.', ''),
        ], $this->competitorBucket($bucket));

        return ['filename' => "pricing-{$bucket}-{$stamp}.csv", 'header' => $header, 'rows' => $rows];
    }
}
