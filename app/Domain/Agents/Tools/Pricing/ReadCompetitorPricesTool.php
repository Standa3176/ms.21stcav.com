<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Pricing;

use App\Domain\Agents\Tools\TruncatingTool;
use App\Domain\Competitor\Models\CompetitorPrice;
use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 10 Plan 02 — read_competitor_prices real implementation.
 *
 * Returns the last 90 days of competitor prices for a SKU, grouped by
 * competitor (CONTEXT D-04). Per-tool 3 KB soft cap with `_truncated:true`
 * + `_total_available:N` hints (CONTEXT D-05). Capped at 50 most-recent
 * rows when the SKU has more data; reducer trims data_points per competitor
 * to keep the schema shape intact.
 *
 * Schema returned:
 * {
 *   "sku": "LOGI-MEETUP",
 *   "window_days": 90,
 *   "competitors": [
 *     {
 *       "competitor_id": 5,
 *       "competitor_name": "AV Distributor Ltd",
 *       "data_points": [
 *         {"recorded_at": "2026-04-25T10:00:00Z", "price_pennies_ex_vat": 154500}
 *       ]
 *     }
 *   ],
 *   "_truncated": false,
 *   "_total_available": 36
 * }
 *
 * Unknown SKU returns empty competitors array — never throws (CONTEXT D-07
 * sparse-data → low-confidence path).
 */
final class ReadCompetitorPricesTool extends TruncatingTool
{
    private const QUERY_LIMIT = 50;

    private const WINDOW_DAYS = 90;

    public function name(): string
    {
        return 'read_competitor_prices';
    }

    public function description(): string
    {
        return 'Read the last 90 days of competitor prices for a SKU, grouped by competitor. Returns up to 50 most-recent rows across all competitors. Use to spot competitor pricing trends and active price points.';
    }

    public function asPrismTool(): \Prism\Prism\Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->withStringParameter('sku', 'The SKU to look up')
            ->using(fn (string $sku): string => $this->execute($sku));
    }

    private function execute(string $sku): string
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        $totalAvailable = CompetitorPrice::query()
            ->where('sku', $sku)
            ->where('recorded_at', '>=', $since)
            ->count();

        $rows = CompetitorPrice::query()
            ->where('sku', $sku)
            ->where('recorded_at', '>=', $since)
            ->with('competitor:id,name')
            ->orderByDesc('recorded_at')
            ->limit(self::QUERY_LIMIT)
            ->get();

        $grouped = $rows->groupBy('competitor_id')->map(function ($group, $cid): array {
            return [
                'competitor_id' => (int) $cid,
                'competitor_name' => $group->first()->competitor?->name ?? "Competitor #{$cid}",
                'data_points' => $group->map(fn (CompetitorPrice $p): array => [
                    'recorded_at' => $p->recorded_at?->toIso8601String(),
                    'price_pennies_ex_vat' => (int) $p->price_pennies_ex_vat,
                ])->values()->all(),
            ];
        })->values()->all();

        $payload = [
            'sku' => $sku,
            'window_days' => self::WINDOW_DAYS,
            'competitors' => $grouped,
            '_truncated' => $totalAvailable > $rows->count(),
            '_total_available' => $totalAvailable,
        ];

        return $this->capJson($payload, $totalAvailable);
    }

    /**
     * Trim each competitor's data_points proportionally to fit under the cap.
     * Halves the per-competitor data_points cap on each invocation.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function reduceLargestArray(array $payload, int $maxBytes): array
    {
        if (! isset($payload['competitors']) || ! is_array($payload['competitors'])) {
            return $payload;
        }

        // Find current largest per-competitor data_points count; halve it.
        $maxPoints = 0;
        foreach ($payload['competitors'] as $c) {
            $count = is_array($c['data_points'] ?? null) ? count($c['data_points']) : 0;
            if ($count > $maxPoints) {
                $maxPoints = $count;
            }
        }
        $newCap = max(1, (int) floor($maxPoints / 2));
        if ($newCap >= $maxPoints) {
            $newCap = max(1, $maxPoints - 1);
        }

        foreach ($payload['competitors'] as $idx => $c) {
            if (is_array($c['data_points'] ?? null)) {
                $payload['competitors'][$idx]['data_points'] = array_slice($c['data_points'], 0, $newCap);
            }
        }

        return $payload;
    }
}
