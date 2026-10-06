<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Services;

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorMatchExclusion;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Contracts\LowestCompetitorSource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Quick task 260930-eo3 — "the lowest current competitor price", in ONE place.
 *
 * Extracted verbatim from CompetitorUndercutPricingCommand, which had it as a
 * private method. Nothing about the rule changed; what changed is that a second
 * caller now exists and cannot get a different answer.
 *
 * WHY IT WAS EXTRACTED: the 260930-eo3 price-position report wrote its own
 * windowed "cheapest competitor" query and got a DIFFERENT number, because it
 * reproduced only the recency window and not the three guards below. The first
 * run showed Neat Board Pro against a lowest competitor price of £299.00 on a
 * ~£6,900 product — a quarantined feed row that the pricing job ignores and the
 * report did not. That single row flipped the verdict from winnable to
 * "cannot win at floor", which is precisely the wrong call to hand someone
 * planning ad spend.
 *
 * The three guards, and why each exists:
 *
 *   is_price_anomaly = false   Guard 2b (2026-08-09 incident). CsvRowWriter
 *     flags a row whose price jumped more than max_row_move_pct against its own
 *     previous row. The row stays for audit; it is invisible here so one bad
 *     feed line can never drive a price. This is the guard the report was
 *     missing.
 *
 *   paused competitors         260826-cpp. A competitor paused for pricing is
 *     skipped whole. screenmoove went silent on 2026-07-19 while holding 65% of
 *     all rows; age alone excludes it, but the pause is what stops five-week-old
 *     prices snapping back the instant the feed is repaired.
 *
 *   match exclusions           260825-h2r. SKU homonyms: CP4 is a Unicol
 *     ceiling mount here and a Crestron control processor at AVITDirect. Both
 *     feeds are right about their own product. Excluded rows drop out BEFORE
 *     the minimum is taken, so they can neither set nor floor a price.
 *
 * Matching is on the competitor row's `sku` OR `mpn`, mirroring how the feeds
 * are keyed. Deliberately EXACT — a looser normalised match would widen the set
 * beyond what the pricing job would act on, which is the drift this class
 * exists to prevent.
 */
final class LowestCompetitorResolver implements LowestCompetitorSource
{
    /**
     * Lowest CURRENT competitor gross price in pennies, or null when no usable
     * competitor data exists inside the window.
     *
     * "Current" = the latest row per competitor within the window, then the
     * minimum across competitors — so a competitor with a year of daily rows
     * counts once, not 365 times.
     */
    public function lowestGrossPence(string $sku, Carbon $cutoff): ?int
    {
        return $this->resolve($sku, $cutoff)['lowest'];
    }

    /**
     * 261006-r3n — the SAME rule, for a whole-catalogue pass.
     *
     * `resolve()` issues one query per SKU, which is right for 50 rows and
     * ruinous for 4,300: the ad scanners sweep the entire catalogue, so they
     * need one windowed query and a PHP-side reduction. They had exactly that
     * already — and NONE of the three guards, which is how the price-position
     * report came to report a £299 lowest competitor on a £6,938 product.
     * An ad shortlist built on unguarded prices picks its "we undercut them"
     * winners partly from bad feed rows.
     *
     * Keyed by the competitor row's sku AND mpn, both lower-cased and trimmed,
     * mirroring how the scanners look products up.
     *
     * @return array<string, array{lowest: int, competitors: int}>
     */
    public function bulkCurrentByKey(int $windowDays): array
    {
        $cutoff = now()->subDays($windowDays)->toDateTimeString();

        // Guard 1 applied in SQL; the window function gives the LATEST row per
        // (competitor, sku) so a daily publisher counts once, not 365 times.
        $rows = DB::select(
            'SELECT competitor_id, sku, mpn, price_pennies_gross FROM ('
            .'SELECT competitor_id, sku, mpn, price_pennies_gross, '
            .'ROW_NUMBER() OVER (PARTITION BY competitor_id, sku ORDER BY recorded_at DESC) AS rn '
            .'FROM competitor_prices '
            .'WHERE recorded_at >= ? AND price_pennies_gross > 0 AND is_price_anomaly = 0'
            .') t WHERE t.rn = 1',
            [$cutoff],
        );

        $paused = Competitor::pausedIds();

        /** @var array<string, array{lowest: int, ids: array<int, true>}> $acc */
        $acc = [];
        foreach ($rows as $r) {
            $price = (int) $r->price_pennies_gross;
            if ($price <= 0) {
                continue;
            }

            $competitorId = (int) $r->competitor_id;

            // Guard 2 — a competitor paused for pricing is skipped whole.
            if (array_key_exists($competitorId, $paused)) {
                continue;
            }

            foreach ([(string) $r->sku, (string) ($r->mpn ?? '')] as $raw) {
                $key = strtolower(trim($raw));
                if ($key === '') {
                    continue;
                }

                // Guard 3 — SKU homonyms. Checked per (competitor, key) because
                // an exclusion can name one competitor or all of them.
                if (CompetitorMatchExclusion::excludes($competitorId, $key)) {
                    continue;
                }

                if (! isset($acc[$key])) {
                    $acc[$key] = ['lowest' => $price, 'ids' => []];
                }
                $acc[$key]['lowest'] = min($acc[$key]['lowest'], $price);
                $acc[$key]['ids'][$competitorId] = true;
            }
        }

        $out = [];
        foreach ($acc as $key => $v) {
            $out[$key] = ['lowest' => $v['lowest'], 'competitors' => count($v['ids'])];
        }

        return $out;
    }

    /**
     * The same reduction, with the contributing competitor count — the report
     * needs to say "3 rivals list this" alongside the price, and recomputing
     * that separately is how the two would drift apart again.
     *
     * @return array{lowest: int|null, competitors: int}
     */
    public function resolve(string $sku, Carbon $cutoff): array
    {
        $rows = CompetitorPrice::query()
            ->where(static fn ($q) => $q->where('sku', $sku)->orWhere('mpn', $sku))
            ->where('recorded_at', '>=', $cutoff)
            ->where('is_price_anomaly', false)
            ->orderByDesc('recorded_at')
            ->get(['competitor_id', 'price_pennies_gross', 'recorded_at']);

        if ($rows->isEmpty()) {
            return ['lowest' => null, 'competitors' => 0];
        }

        $paused = Competitor::pausedIds();

        /** @var array<int, int> $latestPerCompetitor */
        $latestPerCompetitor = [];
        foreach ($rows as $row) {
            $cid = (int) $row->competitor_id;

            if (array_key_exists($cid, $paused)) {
                continue;
            }

            if (CompetitorMatchExclusion::excludes($cid, $sku)) {
                continue;
            }

            if (! array_key_exists($cid, $latestPerCompetitor)) {
                // First seen is the latest, because the query sorts desc.
                $latestPerCompetitor[$cid] = (int) $row->price_pennies_gross;
            }
        }

        $positive = array_filter($latestPerCompetitor, static fn (int $p): bool => $p > 0);

        return [
            'lowest' => $positive === [] ? null : min($positive),
            'competitors' => count($positive),
        ];
    }
}
