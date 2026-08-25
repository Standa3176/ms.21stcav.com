<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Competitor\Models\CompetitorMatchExclusion;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Exceptions\NoPricingRuleMatchedException;
use App\Domain\Pricing\Services\CompetitorUndercutPricer;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductPriceSnapshot;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260824-p3f — audit a day's sell_price movements against the
 * pricing contract. READ-ONLY: no writes, no events, no Woo calls.
 *
 * "Prices moved" is not the same as "prices moved correctly". This samples the
 * products whose sell_price changed between two daily ProductPriceSnapshot rows
 * and checks each against CompetitorUndercutPricer's three branches:
 *
 *   competitor_undercut  lowest current competitor gross - beat_by_pennies
 *   competitor_floor     cost + min_margin_floor_bps (competitor too cheap to beat)
 *   margin               cost + the resolved rule margin (on no competitor)
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT IS AND IS NOT PROVABLE AFTER THE FACT
 *
 * Competitor prices are overwritten in place, so the exact inputs that produced
 * yesterday's decision are GONE. Re-deriving the branch therefore uses TODAY's
 * competitor data, and a mismatch is weak evidence — the competitor moved, which
 * is the system working. Branch reconciliation is reported as INFORMATIONAL.
 *
 * The floor check is different, and it is the one that matters. Each snapshot
 * row carries buy_price ALONGSIDE the sell_price recorded the same day, so
 * "was this price at or above cost + the minimum margin?" is answerable exactly,
 * for that day, with no dependence on data that has since changed. A product
 * below its floor was sold at a margin the business never agreed to — and unlike
 * a branch mismatch, there is no innocent explanation.
 *
 * So: FLOOR BREACHES are the finding. Everything else is context.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Sampling is STRIDED, not first-N: taking the first 100 by id would audit only
 * the oldest corner of the catalogue. Stride spreads the sample across the whole
 * id range while staying deterministic, so a re-run examines the same products.
 *
 *   php artisan pricing:audit-movements
 *   php artisan pricing:audit-movements --date=2026-08-23 --limit=100
 *   php artisan pricing:audit-movements --date=2026-08-23 --show-all
 */
final class AuditPriceMovementsCommand extends BaseCommand
{
    private const REPORT_CAP = 60;

    protected $signature = 'pricing:audit-movements
        {--date= : Day to audit (Y-m-d). Default: the most recent snapshot date.}
        {--limit=100 : How many movements to sample (0 = all).}
        {--max-age-days=30 : Competitor price freshness window, mirrors the pricing command.}
        {--show-all : List every sampled row, not just the problems.}';

    protected $description = 'READ-ONLY audit of a day\'s sell_price movements against the pricing contract (260824-p3f).';

    public function __construct(
        private readonly CompetitorUndercutPricer $pricer,
        private readonly RuleResolver $resolver,
        private readonly PriceCalculator $calculator,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $undercut = (int) config('competitor.beat_by_pennies', 1);
        $minFloorBps = (int) config('competitor.min_margin_floor_bps', 500);
        $ceilingBps = (int) config('competitor.max_margin_ceiling_bps', 5000);
        $vatBps = (int) config('pricing.vat_basis_points', 2000);
        $cutoff = now()->subDays(max(1, (int) $this->option('max-age-days')));
        $limit = max(0, (int) $this->option('limit'));

        [$to, $from] = $this->resolveDates();
        if ($to === null || $from === null) {
            $this->error('Need two snapshot dates to compare — found fewer.');

            return SymfonyCommand::FAILURE;
        }

        $this->info(sprintf(
            'Auditing sell_price movements %s → %s   (floor %.2f%%, ceiling %.2f%%, undercut %dp, VAT %.2f%%)',
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            $minFloorBps / 100,
            $ceilingBps / 100,
            $undercut,
            $vatBps / 100,
        ));

        $moved = $this->movedProductIds($from, $to);
        if ($moved === []) {
            $this->warn('No sell_price movements between those two days.');

            return SymfonyCommand::SUCCESS;
        }

        $sample = $this->stride($moved, $limit);
        $this->line(sprintf(
            '  %d product(s) moved; auditing %d of them (strided across the id range).',
            count($moved),
            count($sample),
        ));

        $newRows = ProductPriceSnapshot::whereDate('recorded_at', $to)
            ->whereIn('product_id', $sample)
            ->get()
            ->keyBy('product_id');
        $oldRows = ProductPriceSnapshot::whereDate('recorded_at', $from)
            ->whereIn('product_id', $sample)
            ->get()
            ->keyBy('product_id');
        $products = Product::whereIn('id', $sample)->get()->keyBy('id');

        $stats = [
            'floor_breach' => 0, 'below_cost' => 0, 'over_ceiling' => 0,
            'matched' => 0, 'branch_drift' => 0, 'no_cost' => 0, 'no_rule' => 0,
        ];
        $problems = [];
        $rows = [];

        foreach ($sample as $productId) {
            $new = $newRows[$productId] ?? null;
            $old = $oldRows[$productId] ?? null;
            $product = $products[$productId] ?? null;
            if ($new === null || $old === null || $product === null) {
                continue;
            }

            $buy = (int) round(((float) $new->buy_price) * 100);
            $sell = (int) round(((float) $new->sell_price) * 100);
            $was = (int) round(((float) $old->sell_price) * 100);

            if ($buy <= 0) {
                $stats['no_cost']++;

                continue;
            }

            // ── The load-bearing check ──────────────────────────────────────
            // Same-day cost vs same-day price. No dependence on data that has
            // moved since, so a breach here is a real finding, not an artefact.
            $floor = $this->calculator->compute($buy, $minFloorBps, $vatBps);
            $sellExVat = $this->calculator->stripVat($sell, $vatBps);
            $actualBps = $buy > 0 ? intdiv(($sellExVat - $buy) * 10000, $buy) : 0;

            $verdict = 'ok';
            if ($sellExVat < $buy) {
                $stats['below_cost']++;
                $verdict = 'BELOW COST';
            } elseif ($sell < $floor) {
                $stats['floor_breach']++;
                $verdict = 'BELOW FLOOR';
            } elseif ($actualBps > $ceilingBps) {
                $stats['over_ceiling']++;
                $verdict = 'over ceiling';
            }

            // ── Informational: which branch does today's data imply? ─────────
            $lowest = $this->lowestCompetitorGross((string) $product->sku, $cutoff);
            $ruleBps = 0;
            if ($lowest === null) {
                try {
                    $ruleBps = (int) $this->resolver->resolve($product)->marginBasisPoints;
                } catch (NoPricingRuleMatchedException) {
                    $stats['no_rule']++;
                }
            }
            $decision = $this->pricer->decide($buy, $lowest, $ruleBps, $undercut, $minFloorBps, $vatBps);
            $expected = (int) $decision['final_pennies'];
            $source = (string) $decision['source'];

            if ($expected === $sell) {
                $stats['matched']++;
            } else {
                $stats['branch_drift']++;
            }

            $row = [
                (string) $product->sku,
                number_format($buy / 100, 2),
                number_format($was / 100, 2).' → '.number_format($sell / 100, 2),
                number_format($actualBps / 100, 2).'%',
                $source.($expected === $sell ? ' ✓' : ' ('.number_format($expected / 100, 2).')'),
                $verdict,
            ];

            $rows[] = $row;
            if ($verdict !== 'ok') {
                $problems[] = $row;
            }
        }

        $this->renderTable($problems, $rows);
        $this->renderSummary($stats, count($rows));

        // A floor breach or a below-cost price is a genuine pricing fault.
        return ($stats['floor_breach'] + $stats['below_cost']) > 0
            ? SymfonyCommand::FAILURE
            : SymfonyCommand::SUCCESS;
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} [to, from] */
    private function resolveDates(): array
    {
        $dates = ProductPriceSnapshot::query()
            ->select('recorded_at')
            ->distinct()
            ->orderByDesc('recorded_at')
            ->limit(30)
            ->pluck('recorded_at');

        if ($dates->count() < 2) {
            return [null, null];
        }

        $requested = (string) $this->option('date');
        if ($requested === '') {
            return [Carbon::parse($dates[0]), Carbon::parse($dates[1])];
        }

        foreach ($dates as $i => $d) {
            if (Carbon::parse($d)->isSameDay(Carbon::parse($requested))) {
                return isset($dates[$i + 1])
                    ? [Carbon::parse($d), Carbon::parse($dates[$i + 1])]
                    : [null, null];
            }
        }

        return [null, null];
    }

    /** @return array<int, int> */
    private function movedProductIds(Carbon $from, Carbon $to): array
    {
        $new = ProductPriceSnapshot::whereDate('recorded_at', $to)->pluck('sell_price', 'product_id');
        $old = ProductPriceSnapshot::whereDate('recorded_at', $from)->pluck('sell_price', 'product_id');

        $moved = [];
        foreach ($new as $id => $price) {
            if (isset($old[$id]) && (string) $old[$id] !== (string) $price) {
                $moved[] = (int) $id;
            }
        }

        sort($moved);

        return $moved;
    }

    /**
     * Evenly-spaced sample across the id range — first-N would audit only the
     * oldest corner of the catalogue. Deterministic, so a re-run repeats it.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function stride(array $ids, int $limit): array
    {
        $total = count($ids);
        if ($limit <= 0 || $total <= $limit) {
            return $ids;
        }

        $step = $total / $limit;
        $out = [];
        for ($i = 0; $i < $limit; $i++) {
            $out[] = $ids[(int) floor($i * $step)];
        }

        return array_values(array_unique($out));
    }

    /** Mirrors CompetitorUndercutPricingCommand::lowestCurrentCompetitorGross. */
    private function lowestCompetitorGross(string $sku, Carbon $cutoff): ?int
    {
        if (trim($sku) === '') {
            return null;
        }

        $rows = CompetitorPrice::query()
            ->where(static fn ($q) => $q->where('sku', $sku)->orWhere('mpn', $sku))
            ->where('recorded_at', '>=', $cutoff)
            ->where('is_price_anomaly', false)
            ->orderByDesc('recorded_at')
            ->get(['competitor_id', 'price_pennies_gross']);

        $latest = [];
        foreach ($rows as $row) {
            $cid = (int) $row->competitor_id;

            // Must mirror the pricing command exactly (260825-h2r): an audit
            // that still counted an excluded competitor would report branch
            // drift on every product the exclusion covers.
            if (CompetitorMatchExclusion::excludes($cid, $sku)) {
                continue;
            }

            if (! array_key_exists($cid, $latest)) {
                $latest[$cid] = (int) $row->price_pennies_gross;
            }
        }

        $positive = array_filter($latest, static fn (int $p): bool => $p > 0);

        return $positive === [] ? null : min($positive);
    }

    /**
     * @param  array<int, array<int, string>>  $problems
     * @param  array<int, array<int, string>>  $rows
     */
    private function renderTable(array $problems, array $rows): void
    {
        $show = (bool) $this->option('show-all') ? $rows : $problems;

        if ($show === []) {
            $this->newLine();
            $this->info('No pricing-contract violations in the sample.');

            return;
        }

        $this->newLine();
        $this->table(
            ['SKU', 'Cost', 'Sell was → now', 'Margin', 'Branch (expected)', 'Verdict'],
            array_slice($show, 0, self::REPORT_CAP),
        );

        $more = count($show) - self::REPORT_CAP;
        if ($more > 0) {
            $this->line("  … and {$more} more.");
        }
    }

    /** @param array<string, int> $stats */
    private function renderSummary(array $stats, int $audited): void
    {
        $this->newLine();
        $this->line(sprintf('Audited %d movement(s).', $audited));
        $this->line(sprintf(
            '  Contract:     %d below cost, %d below the minimum-margin floor, %d over the ceiling.',
            $stats['below_cost'],
            $stats['floor_breach'],
            $stats['over_ceiling'],
        ));
        $this->line(sprintf(
            '  Branch check: %d reproduce exactly, %d differ (INFORMATIONAL — competitor prices are',
            $stats['matched'],
            $stats['branch_drift'],
        ));
        $this->line('                overwritten in place, so yesterday\'s inputs no longer exist).');

        if ($stats['no_cost'] > 0 || $stats['no_rule'] > 0) {
            $this->line(sprintf(
                '  Skipped:      %d with no cost, %d on no competitor and no matching pricing rule.',
                $stats['no_cost'],
                $stats['no_rule'],
            ));
        }

        $this->newLine();
        if (($stats['below_cost'] + $stats['floor_breach']) > 0) {
            $this->error('FAIL — at least one product was priced below the agreed minimum margin.');

            return;
        }

        $this->info('PASS — every audited movement respects cost and the minimum-margin floor.');
    }
}
