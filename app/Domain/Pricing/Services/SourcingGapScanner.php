<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\SupplierFeedSourceabilityChecker;
use Illuminate\Support\Facades\DB;

/**
 * Sourcing-gap read model: parts a COMPETITOR currently lists that NO supplier
 * carries and we don't sell — almost certainly obsolete, or a part we need to
 * find a new supplier for.
 *
 * Pipeline (all ex-VAT, mirrors CompetitorPositionScanner's match semantics):
 *   1. Reduce competitor_prices (within window) to the latest row per
 *      (competitor, sku); aggregate per competitor sku → distinct-competitor
 *      count + lowest current ex-VAT price + the winning competitor (lowest id
 *      on a price tie).
 *   2. Drop any part we already sell (its sku OR mpn equals a local product sku).
 *   3. Ask the supplier feed (via SupplierFeedSourceabilityChecker) which of the
 *      remaining parts ANY supplier carries. Those are add opportunities, NOT
 *      gaps — the "Products to add" tile already surfaces the supplier side.
 *   4. Sourcing gaps = the rest: competitor-listed, we-don't-sell, no-supplier.
 *
 * Why this matters: these parts must NOT inflate the cost dashboard counters
 * (we can't be "below cost" on something we can't buy) and must NOT appear in
 * the add list (nothing to source). The cost scanner already filters to
 * status='publish'; obsolete products we DO sell get demoted to pending by
 * supplier:db-sync --flag-obsolete. This view covers the competitor-only side.
 *
 * Heavy (remote feed scan), so the command caches it; the dashboard reads the
 * cache. Pricing reads competitor_prices + competitors raw (WpDirectDb, same as
 * CompetitorPositionScanner) and delegates the remote feed read to the Sync
 * checker (Pricing → Sync allowed; Sync owns the Integrations credential).
 */
final class SourcingGapScanner
{
    public function __construct(private readonly SupplierFeedSourceabilityChecker $checker) {}

    /**
     * @return array{
     *   gaps: array<int, array{part:string, mpn:?string, competitors:int, comp_ex:int, competitor_name:?string}>,
     *   count:int, max_age_days:int, computed_at:string
     * }
     */
    public function compute(int $maxAgeDays = 30, int $listCap = 5000): array
    {
        $maxAgeDays = max(1, $maxAgeDays);
        $cutoff = now()->subDays($maxAgeDays)->toDateTimeString();
        $empty = ['gaps' => [], 'count' => 0, 'max_age_days' => $maxAgeDays, 'computed_at' => now()->toIso8601String()];

        // 1. Latest row per (competitor, sku) within the window, then aggregate
        //    per competitor sku (the listing identity).
        $rows = DB::select(
            'SELECT competitor_id, sku, mpn, price_pennies_ex_vat FROM ('
            .'SELECT competitor_id, sku, mpn, price_pennies_ex_vat, '
            .'ROW_NUMBER() OVER (PARTITION BY competitor_id, sku ORDER BY recorded_at DESC) AS rn '
            .'FROM competitor_prices WHERE recorded_at >= ? AND price_pennies_ex_vat > 0'
            .') t WHERE t.rn = 1',
            [$cutoff],
        );

        /** @var array<string, array{sku:string, mpn:string, comps:array<int,int>, best_ex:int, best_cid:int}> $parts */
        $parts = [];
        foreach ($rows as $r) {
            $skuKey = strtolower(trim((string) $r->sku));
            $price = (int) $r->price_pennies_ex_vat;
            if ($skuKey === '' || $price <= 0) {
                continue;
            }
            $cid = (int) $r->competitor_id;
            $mpn = trim((string) $r->mpn);

            if (! isset($parts[$skuKey])) {
                $parts[$skuKey] = ['sku' => (string) $r->sku, 'mpn' => $mpn, 'comps' => [], 'best_ex' => PHP_INT_MAX, 'best_cid' => 0];
            }
            // Lowest price per competitor (latest already chosen by the window).
            $parts[$skuKey]['comps'][$cid] = isset($parts[$skuKey]['comps'][$cid])
                ? min($parts[$skuKey]['comps'][$cid], $price)
                : $price;
            if ($parts[$skuKey]['mpn'] === '' && $mpn !== '') {
                $parts[$skuKey]['mpn'] = $mpn;
            }
        }

        if ($parts === []) {
            return $empty;
        }

        // Lowest-across-competitors + winning competitor id (lowest id on a tie).
        foreach ($parts as &$p) {
            $comps = $p['comps'];
            ksort($comps);
            foreach ($comps as $cid => $price) {
                if ($price < $p['best_ex']) {
                    $p['best_ex'] = $price;
                    $p['best_cid'] = $cid;
                }
            }
        }
        unset($p);

        // 2. Drop parts we already sell (sku OR mpn matches a local product sku).
        $localSkus = Product::query()
            ->whereNotNull('sku')
            ->pluck('sku')
            ->mapWithKeys(static fn ($s): array => [strtolower(trim((string) $s)) => true])
            ->all();

        foreach ($parts as $key => $p) {
            $mpnKey = strtolower(trim((string) $p['mpn']));
            if (isset($localSkus[$key]) || ($mpnKey !== '' && isset($localSkus[$mpnKey]))) {
                unset($parts[$key]);
            }
        }

        if ($parts === []) {
            return $empty;
        }

        // 3. Which of these does ANY supplier carry? (sku or mpn present in the feed)
        $candidateKeys = [];
        foreach ($parts as $key => $p) {
            $candidateKeys[] = $key;
            $mpnKey = strtolower(trim((string) $p['mpn']));
            if ($mpnKey !== '') {
                $candidateKeys[] = $mpnKey;
            }
        }
        $sourceable = $this->checker->sourceableKeys($candidateKeys);

        // 4. Gaps = parts whose sku AND mpn are both absent from the supplier feed.
        /** @var array<int, true> $usedCids */
        $usedCids = [];
        /** @var array<string, array{sku:string, mpn:string, comps:array<int,int>, best_ex:int, best_cid:int}> $gapParts */
        $gapParts = [];
        foreach ($parts as $key => $p) {
            $mpnKey = strtolower(trim((string) $p['mpn']));
            if (isset($sourceable[$key]) || ($mpnKey !== '' && isset($sourceable[$mpnKey]))) {
                continue; // a supplier carries it → add opportunity, not a gap
            }
            $gapParts[$key] = $p;
            if ($p['best_cid'] > 0) {
                $usedCids[$p['best_cid']] = true;
            }
        }

        if ($gapParts === []) {
            return $empty;
        }

        $competitorNames = $this->competitorNamesByIds(array_keys($usedCids));

        $gaps = [];
        foreach ($gapParts as $p) {
            $cid = $p['best_cid'];
            $gaps[] = [
                'part' => $p['sku'],
                'mpn' => $p['mpn'] !== '' ? $p['mpn'] : null,
                'competitors' => count($p['comps']),
                'comp_ex' => $p['best_ex'] === PHP_INT_MAX ? 0 : $p['best_ex'],
                'competitor_name' => ($cid > 0 && isset($competitorNames[$cid])) ? $competitorNames[$cid] : null,
            ];
        }

        // Most-tracked first (more competitors ⇒ more likely a real demand gap),
        // then highest competitor price, then part name for determinism.
        usort($gaps, static fn (array $a, array $b): int => [$b['competitors'], $b['comp_ex'], $a['part']]
            <=> [$a['competitors'], $a['comp_ex'], $b['part']]);

        $count = count($gaps);

        return [
            'gaps' => $count > $listCap ? array_slice($gaps, 0, $listCap) : $gaps,
            'count' => $count,
            'max_age_days' => $maxAgeDays,
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Batched competitor_id → name map via raw DB::select on `competitors`
     * (Pricing must not depend on the Competitor domain — same pattern as
     * CompetitorPositionScanner). Ids bound as placeholders, never interpolated.
     *
     * @param  array<int, int>  $competitorIds
     * @return array<int, string>
     */
    private function competitorNamesByIds(array $competitorIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $competitorIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select('SELECT id, name FROM competitors WHERE id IN ('.$placeholders.')', $ids);

        /** @var array<int, string> $names */
        $names = [];
        foreach ($rows as $r) {
            $name = $r->name;
            if ($name === null || $name === '') {
                continue;
            }
            $names[(int) $r->id] = (string) $name;
        }

        return $names;
    }
}
