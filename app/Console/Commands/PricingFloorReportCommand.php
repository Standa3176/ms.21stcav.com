<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Read-only analysis: how does our cost compare to competitors across the
 * catalogue, and what min-margin FLOOR should we set?
 *
 * For each simple product with a supplier cost that has a current competitor
 * price, it computes the "achievable undercut margin" = (lowest competitor
 * ex-VAT − our cost) / our cost — i.e. the margin we'd keep if we priced just
 * under the cheapest competitor. Then it:
 *   - excludes BELOW-COST products (competitor sells below our cost — we can
 *     never undercut profitably; these shouldn't drag the floor down);
 *   - shows the margin distribution of the WINNABLE products;
 *   - tabulates, per candidate floor, how many we could undercut vs would floor;
 *   - recommends a floor (≈15th percentile of winnable margins → undercut ~85%).
 *
 * Pure read. Writes nothing. All ex-VAT (cost is ex-VAT; competitor
 * price_pennies_ex_vat is the corrected net value).
 */
final class PricingFloorReportCommand extends BaseCommand
{
    protected $signature = 'pricing:floor-report
        {--max-age-days=30 : Ignore competitor prices older than this many days}
        {--show-exceptions=10 : How many below-cost / too-close example SKUs to print}';

    protected $description = 'Analyse catalogue cost vs competitors and recommend a min-margin floor (read-only).';

    /** Candidate floors to tabulate (basis points). */
    private const FLOORS_BPS = [200, 300, 400, 500, 600, 800, 1000, 1500];

    protected function perform(): int
    {
        $maxAgeDays = max(1, (int) $this->option('max-age-days'));
        $cutoff = now()->subDays($maxAgeDays);
        $showN = max(0, (int) $this->option('show-exceptions'));

        /** @var array<int,int> $margins  achievable undercut margin (bps) for winnable products */
        $margins = [];
        $matched = 0;
        $belowCost = 0;
        $belowCostSamples = []; // [sku, costEx, compEx, marginBps]
        $tooCloseSamples = [];  // 0 < margin < 2%

        $this->info("Analysing catalogue cost vs competitors (prices ≤ {$maxAgeDays}d old)…");

        Product::query()
            ->where('type', 'simple')
            ->whereNotNull('buy_price')
            ->where('buy_price', '>', 0)
            ->orderBy('id')
            ->chunkById(500, function ($products) use (&$margins, &$matched, &$belowCost, &$belowCostSamples, &$tooCloseSamples, $cutoff): void {
                foreach ($products as $product) {
                    $sku = trim((string) $product->sku);
                    if ($sku === '') {
                        continue;
                    }
                    $costEx = (int) round(((float) $product->buy_price) * 100);
                    if ($costEx <= 0) {
                        continue;
                    }
                    $compEx = $this->lowestCompetitorExVat($sku, $cutoff);
                    if ($compEx === null) {
                        continue; // no competitor → margin fallback applies, not the floor
                    }
                    $matched++;
                    $marginBps = intdiv(($compEx - $costEx) * 10000, $costEx);

                    if ($marginBps <= 0) {
                        $belowCost++;
                        if (count($belowCostSamples) < 50) {
                            $belowCostSamples[] = [$sku, $costEx, $compEx, $marginBps];
                        }

                        continue;
                    }
                    $margins[] = $marginBps;
                    if ($marginBps < 200 && count($tooCloseSamples) < 50) {
                        $tooCloseSamples[] = [$sku, $costEx, $compEx, $marginBps];
                    }
                }
            });

        if ($matched === 0) {
            $this->warn('No products with both a supplier cost and a current competitor price were found.');

            return SymfonyCommand::SUCCESS;
        }

        sort($margins);
        $winnable = count($margins);

        $this->newLine();
        $this->line('── Coverage ───────────────────────────────────');
        $this->line("  Matched (cost + current competitor):  {$matched}");
        $this->line(sprintf('  Below our cost (excluded):            %d  (%.1f%%)', $belowCost, 100 * $belowCost / $matched));
        $this->line(sprintf('  Winnable (competitor ≥ our cost):     %d  (%.1f%%)', $winnable, 100 * $winnable / $matched));

        if ($winnable > 0) {
            $this->newLine();
            $this->line('── Achievable undercut margin (winnable) ──────');
            foreach (['p10' => 10, 'p25' => 25, 'median' => 50, 'p75' => 75, 'p90' => 90] as $label => $p) {
                $this->line(sprintf('  %-7s %.2f%%', $label, $this->percentile($margins, $p) / 100));
            }

            $this->newLine();
            $this->line('── Floor scenarios (of '.$winnable.' winnable) ─────────');
            $this->line('  floor   undercut          floored');
            foreach (self::FLOORS_BPS as $floor) {
                $under = count(array_filter($margins, static fn (int $m): bool => $m >= $floor));
                $floored = $winnable - $under;
                $this->line(sprintf(
                    '  %4.1f%%   %5d (%4.1f%%)    %5d (%4.1f%%)',
                    $floor / 100,
                    $under, 100 * $under / $winnable,
                    $floored, 100 * $floored / $winnable,
                ));
            }

            // Recommendation: floor at the 15th percentile of winnable margins
            // (so ~85% of winnable products can still be undercut), rounded down
            // to a clean 0.5%, never below 2%.
            $p15 = $this->percentile($margins, 15);
            $suggested = max(200, (int) (floor($p15 / 50) * 50));
            $underAtSuggested = count(array_filter($margins, static fn (int $m): bool => $m >= $suggested));
            $this->newLine();
            $this->line('── Recommendation ─────────────────────────────');
            $this->info(sprintf(
                '  Suggested floor: %.1f%%  → undercut %d/%d winnable (%.1f%%); the rest hold at the floor (slightly above their competitor).',
                $suggested / 100,
                $underAtSuggested, $winnable, 100 * $underAtSuggested / $winnable,
            ));
            $this->line(sprintf('  (Current floor: %.1f%%.)  Set via COMPETITOR_MIN_MARGIN_FLOOR_BPS or config default.', (int) config('competitor.min_margin_floor_bps', 600) / 100));
        }

        $this->printExceptions('Below-cost exceptions (competitor sells under our cost — investigate supply or skip)', $belowCostSamples, $belowCost, $showN);
        $this->printExceptions('Too-close exceptions (winnable but < 2% — floor will hold us just above them)', $tooCloseSamples, count($tooCloseSamples), $showN);

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @param  array<int, array{0:string,1:int,2:int,3:int}>  $samples
     */
    private function printExceptions(string $title, array $samples, int $total, int $showN): void
    {
        if ($total === 0 || $showN === 0) {
            return;
        }
        $this->newLine();
        $this->line("── {$title} ──");
        // worst first (most negative / smallest margin)
        usort($samples, static fn (array $a, array $b): int => $a[3] <=> $b[3]);
        foreach (array_slice($samples, 0, $showN) as [$sku, $costEx, $compEx, $marginBps]) {
            $this->line(sprintf(
                '  %-18s cost £%s  comp £%s (ex-VAT)  margin %.1f%%',
                $sku,
                number_format($costEx / 100, 2),
                number_format($compEx / 100, 2),
                $marginBps / 100,
            ));
        }
    }

    /**
     * Lowest CURRENT competitor EX-VAT price (pennies) for a SKU: latest row per
     * competitor within the window, min across competitors. Null if none.
     */
    private function lowestCompetitorExVat(string $sku, Carbon $cutoff): ?int
    {
        $rows = CompetitorPrice::query()
            ->where(static fn ($q) => $q->where('sku', $sku)->orWhere('mpn', $sku))
            ->where('recorded_at', '>=', $cutoff)
            ->orderByDesc('recorded_at')
            ->get(['competitor_id', 'price_pennies_ex_vat']);

        if ($rows->isEmpty()) {
            return null;
        }
        $latestPerCompetitor = [];
        foreach ($rows as $row) {
            $cid = (int) $row->competitor_id;
            if (! array_key_exists($cid, $latestPerCompetitor)) {
                $latestPerCompetitor[$cid] = (int) $row->price_pennies_ex_vat;
            }
        }
        $positive = array_filter($latestPerCompetitor, static fn (int $p): bool => $p > 0);

        return $positive === [] ? null : min($positive);
    }

    /**
     * @param  array<int,int>  $sorted  ascending
     */
    private function percentile(array $sorted, float $p): int
    {
        if ($sorted === []) {
            return 0;
        }
        $idx = (int) floor(($p / 100) * (count($sorted) - 1));

        return $sorted[max(0, min($idx, count($sorted) - 1))];
    }
}
