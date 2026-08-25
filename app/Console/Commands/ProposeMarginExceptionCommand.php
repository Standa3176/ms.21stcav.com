<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Services\CeilingBlockClassifier;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Quick task 260825-mpr — the exact margin exception a family would need.
 *
 * READ-ONLY. Writes nothing, creates no rule, no override, no price. It prints
 * the proposal and the commands that WOULD apply it, for a human to run.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS FOR
 *
 * Built for the two projection-screen families the margin report identified as
 * safe exceptions: RAPT (21 SKUs) and MPCT (8), both at 98.9% with a 0.0%
 * spread, both entirely unpublished. Making that margin EXPLICIT before they
 * are published is the whole point — otherwise the first thing that reprices
 * them cuts 38.66%, because 1.220 / 1.989 = 0.6134.
 *
 * The test of a good exception here is that NOTHING MOVES. The family already
 * charges this; the rule only stops something else deciding otherwise. A
 * proposal that shifts prices is not preserving policy, it is making a new one,
 * and the summary says so outright.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY IT USUALLY CANNOT PROPOSE A RULE
 *
 * RuleResolver reaches a product through brand+category, category, or brand.
 * These families have NONE of those, so no rule can see them and the only
 * mechanism that works today is a per-product override.
 *
 * That is worth resisting. ProductOverride is layer 0 and beats everything
 * permanently; 29 of them is how a pricing system becomes one nobody can reason
 * about. So the recommendation leads with assigning a brand and writing ONE
 * rule, and offers overrides only as the fallback — with the cost of that
 * choice stated rather than implied.
 *
 *   php artisan pricing:propose-exception --prefix=RAPT
 *   php artisan pricing:propose-exception --prefix=RAPT,MPCT
 *   php artisan pricing:propose-exception --prefix=RAPT --margin-bps=9890
 */
final class ProposeMarginExceptionCommand extends BaseCommand
{
    /** Beyond this, the proposal is making new policy rather than recording it. */
    private const MOVE_TOLERANCE = 0.01;

    protected $signature = 'pricing:propose-exception
        {--prefix= : Comma-separated SKU prefixes, e.g. RAPT,MPCT (required)}
        {--margin-bps= : Override the proposed margin; default is the family median}
        {--limit=40 : Products to list per family}';

    protected $description = 'READ-ONLY margin-exception proposal for a SKU family — the exact margin, its price impact, and what would have to be created (260825-mpr).';

    public function __construct(
        private readonly RuleResolver $resolver,
        private readonly PriceCalculator $calculator,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $prefixes = array_values(array_filter(array_map(
            static fn (string $p): string => strtoupper(trim($p)),
            explode(',', (string) $this->option('prefix')),
        ), static fn (string $p): bool => $p !== ''));

        if ($prefixes === []) {
            $this->error('--prefix is required, e.g. --prefix=RAPT,MPCT');

            return SymfonyCommand::FAILURE;
        }

        $this->info('Margin exception proposal — READ-ONLY. Nothing is created or changed.');

        foreach ($prefixes as $prefix) {
            $this->proposeFor($prefix);
        }

        $this->newLine();
        $this->line('Nothing was written. No rule, override, price or product changed.');

        return SymfonyCommand::SUCCESS;
    }

    private function proposeFor(string $prefix): void
    {
        $vatBps = (int) config('pricing.vat_basis_points', 2000);
        $limit = max(1, (int) $this->option('limit'));

        $products = Product::query()
            ->whereNotNull('buy_price')
            ->where('buy_price', '>', 0)
            ->whereNotNull('sell_price')
            ->where('sell_price', '>', 0)
            ->where('sku', 'like', $prefix.'%')
            ->orderBy('sku')
            ->get();

        $this->newLine();
        $this->info(sprintf('── %s ──', $prefix));

        if ($products->isEmpty()) {
            $this->warn('  No products with a usable cost and price under that prefix.');

            return;
        }

        $rows = [];
        $margins = [];

        foreach ($products as $product) {
            $buy = (int) round(((float) $product->buy_price) * 100);
            $sell = (int) round(((float) $product->sell_price) * 100);
            $bps = CeilingBlockClassifier::currentMarginBps($buy, $sell, $vatBps);

            if ($bps === null) {
                continue;
            }

            $margins[] = $bps;
            $rows[] = [
                'sku' => (string) $product->sku,
                'status' => (string) $product->status,
                'buy' => $buy,
                'sell' => $sell,
                'bps' => $bps,
                'brand_id' => $product->brand_id === null ? null : (int) $product->brand_id,
                'category_id' => $product->category_id === null ? null : (int) $product->category_id,
                'rule_bps' => $this->ruleMarginFor($product),
            ];
        }

        if ($rows === []) {
            $this->warn('  Nothing with a computable margin.');

            return;
        }

        sort($margins);
        $median = $margins[(int) floor(0.5 * (count($margins) - 1))];
        $proposed = ($m = (int) $this->option('margin-bps')) > 0 ? $m : $median;

        $this->renderProducts($rows, $proposed, $vatBps, $limit);
        $this->renderVerdict($rows, $prefix, $proposed, $median, $margins, $vatBps);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderProducts(array $rows, int $proposed, int $vatBps, int $limit): void
    {
        $this->newLine();
        $this->table(
            ['SKU', 'Status', 'Cost', 'Price now', 'Margin now', 'At proposed', 'Move'],
            array_map(function (array $r) use ($proposed, $vatBps): array {
                $new = $this->calculator->compute((int) $r['buy'], $proposed, $vatBps);
                $delta = $new - (int) $r['sell'];

                return [
                    $r['sku'],
                    $r['status'],
                    number_format($r['buy'] / 100, 2),
                    number_format($r['sell'] / 100, 2),
                    number_format($r['bps'] / 100, 1).'%',
                    number_format($new / 100, 2),
                    $delta === 0 ? '-' : sprintf('%+.2f', $delta / 100),
                ];
            }, array_slice($rows, 0, $limit)),
        );

        $more = count($rows) - $limit;
        if ($more > 0) {
            $this->line("  … and {$more} more.");
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $margins
     */
    private function renderVerdict(array $rows, string $prefix, int $proposed, int $median, array $margins, int $vatBps): void
    {
        $spread = $margins[(int) floor(0.75 * (count($margins) - 1))]
            - $margins[(int) floor(0.25 * (count($margins) - 1))];

        $net = 0;
        $worst = 0.0;
        $published = 0;
        $withBrand = 0;
        $withCategory = 0;

        foreach ($rows as $r) {
            $new = $this->calculator->compute((int) $r['buy'], $proposed, $vatBps);
            $delta = $new - (int) $r['sell'];
            $net += $delta;

            if ((int) $r['sell'] > 0) {
                $worst = max($worst, abs($delta) / (int) $r['sell']);
            }

            if ($r['status'] === 'publish') {
                $published++;
            }
            if ($r['brand_id'] !== null) {
                $withBrand++;
            }
            if ($r['category_id'] !== null) {
                $withCategory++;
            }
        }

        $count = count($rows);

        $this->newLine();
        $this->line(sprintf(
            '  %d product(s): %d published, %d unpublished. Median %s, spread %s.',
            $count,
            $published,
            $count - $published,
            $this->pct($median),
            $this->pct($spread),
        ));
        $this->line(sprintf('  Proposed margin: %s', $this->pct($proposed)));
        $this->line(sprintf(
            '  Price impact: £%s net, worst single move %.2f%%.',
            number_format($net / 100, 2),
            $worst * 100,
        ));

        $this->newLine();
        if ($worst <= self::MOVE_TOLERANCE) {
            $this->info('  SAFE — this records what the family already charges; nothing moves.');
        } else {
            $this->warn('  NOT A PURE RECORD — this proposal MOVES prices.');
            $this->line('  A margin exception is meant to make current pricing explicit. One that');
            $this->line('  shifts prices is making new policy, which is a different decision and');
            $this->line('  needs to be taken as one.');
        }

        $this->renderMechanism($prefix, $proposed, $count, $withBrand, $withCategory);
    }

    /**
     * What would actually have to exist for this margin to apply — and the
     * honest cost of the only mechanism available today.
     */
    private function renderMechanism(string $prefix, int $proposed, int $count, int $withBrand, int $withCategory): void
    {
        $this->newLine();
        $this->line('  HOW IT WOULD BE APPLIED');

        if ($withBrand === 0) {
            $this->line(sprintf(
                '  These %d products have NO brand and %s category, so RuleResolver cannot',
                $count,
                $withCategory === 0 ? 'no' : 'a partial',
            ));
            $this->line('  reach them: brand+category, category and brand all need taxonomy.');
            $this->newLine();
            $this->line('  RECOMMENDED — assign a brand, then ONE rule:');
            $this->line(sprintf('    1. php artisan products:assign-taxonomy --skus=<%s SKUs> --dry-run', $prefix));
            $this->line(sprintf('    2. create a brand-scope PricingRule at %s for that brand', $this->pct($proposed)));
            $this->line('    One row, self-documenting, and it catches future SKUs in the family.');
            $this->newLine();
            $this->line(sprintf('  FALLBACK — %d ProductOverride rows at %s.', $count, $this->pct($proposed)));
            $this->line('  Works today with no taxonomy, but ProductOverride is layer 0 and beats');
            $this->line('  every rule permanently. A pile of them is how a pricing system becomes');
            $this->line('  one nobody can reason about, and a new SKU in this family gets none of');
            $this->line('  it. Prefer the brand route unless there is a reason not to.');

            return;
        }

        if ($withBrand === $count && $withCategory === $count) {
            $this->line(sprintf('  Every product carries brand AND category — a brand+category rule at %s', $this->pct($proposed)));
            $this->line('  is the most specific and therefore the safest scope.');

            return;
        }

        if ($withBrand === $count) {
            $this->line(sprintf('  Every product carries a brand — one brand-scope rule at %s covers them,', $this->pct($proposed)));
            $this->line('  and catches future SKUs in the family automatically.');

            return;
        }

        $this->line(sprintf('  Only %d of %d carry a brand, so a brand rule would MISS the rest.', $withBrand, $count));
        $this->line('  Assign the missing taxonomy first, or the exception applies unevenly —');
        $this->line('  which is worse than not applying it at all.');
    }

    private function ruleMarginFor(Product $product): ?int
    {
        try {
            return (int) $this->resolver->resolve($product)->marginBasisPoints;
        } catch (Throwable) {
            return null;
        }
    }

    private function pct(int $bps): string
    {
        return number_format($bps / 100, 1).'%';
    }
}
