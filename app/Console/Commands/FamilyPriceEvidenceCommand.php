<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Services\CeilingBlockClassifier;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductPriceSnapshot;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Quick task 260826-fpe — did this margin get CHOSEN, or did it just survive?
 *
 * READ-ONLY. No writes of any kind: no rule, override, taxonomy, price,
 * exclusion or config.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE QUESTION
 *
 * Screen International's families split into two clusters: RAPT/MPCT/MPC/COM/TT
 * near 98.9%, and MJR/MJRT/GTHC/CHC/COMT near 22%, with DFT at 10% — one brand,
 * one category, one supplier. Several families contain BOTH.
 *
 * That does not look like policy. It looks like the Woo import revert loop:
 * products the engine successfully repriced fell to the 22% default tier, while
 * products whose push was lost kept their original price. If so, 98.9% is a
 * legacy list price rather than a margin decision, and writing 29 permanent
 * overrides would enshrine an accident at layer 0 of the resolver.
 *
 * ProductPriceSnapshot answers it, because it records buy_price and sell_price
 * TOGETHER for each day. A family member that STEPPED from ~99% to ~22% on a
 * datable day was repriced. One that has always sat at 22% is a genuinely
 * different product line.
 *
 * Competitor history is checked alongside, because the two causes look
 * identical in the price alone: a step with a competitor present is the market
 * pricing the product, a step with none is the pricing rule doing it.
 *
 *   php artisan pricing:family-evidence --skus=RAPT350X265,GTHC450X281
 *   php artisan pricing:family-evidence --skus=... --brand-search=screen
 *   php artisan pricing:family-evidence --brand-search=screen --category-search=projection
 */
final class FamilyPriceEvidenceCommand extends BaseCommand
{
    /** A margin change this large between consecutive days is a REPRICE, not drift. */
    private const STEP_BPS = 2000;

    protected $signature = 'pricing:family-evidence
        {--skus= : Comma-separated SKUs to trace through their price history}
        {--brand-search= : List Woo BRAND terms whose name contains this}
        {--category-search= : List Woo CATEGORY terms whose name contains this}
        {--step-bps=2000 : Margin jump between consecutive snapshots that counts as a reprice}';

    protected $description = 'READ-ONLY evidence: price history, reprice steps, taxonomy and competitor presence for a product family (260826-fpe).';

    public function __construct(
        private readonly RuleResolver $resolver,
        private readonly TaxonomyResolver $taxonomy,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $this->info('Family price evidence — READ-ONLY. Nothing is written.');

        $skus = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('skus')),
        ), static fn (string $s): bool => $s !== ''));

        if ($skus !== []) {
            $this->traceAll($skus);
        }

        $brandSearch = trim((string) $this->option('brand-search'));
        if ($brandSearch !== '') {
            $this->renderBrandTerms($brandSearch);
        }

        $categorySearch = trim((string) $this->option('category-search'));
        if ($categorySearch !== '') {
            $this->renderCategoryTerms($categorySearch);
        }

        if ($skus === [] && $brandSearch === '' && $categorySearch === '') {
            $this->error('Give --skus and/or --brand-search / --category-search.');

            return SymfonyCommand::FAILURE;
        }

        $this->newLine();
        $this->line('Nothing was written. No rule, override, taxonomy, price or config changed.');

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @param  array<int, string>  $skus
     */
    private function traceAll(array $skus): void
    {
        $vatBps = (int) config('pricing.vat_basis_points', 2000);
        $stepBps = max(1, (int) $this->option('step-bps'));

        $stepped = [];
        $flat = [];

        foreach ($skus as $sku) {
            $verdict = $this->trace($sku, $vatBps, $stepBps);

            if ($verdict === 'stepped') {
                $stepped[] = $sku;
            } elseif ($verdict === 'flat') {
                $flat[] = $sku;
            }
        }

        $this->renderConclusion($stepped, $flat);
    }

    private function trace(string $sku, int $vatBps, int $stepBps): string
    {
        $product = Product::where('sku', $sku)->first();

        $this->newLine();
        $this->info(sprintf('══ %s', $sku));

        if ($product === null) {
            $this->warn('   no local product with that SKU.');

            return 'missing';
        }

        $this->line(sprintf(
            '   status=%s   brand=%s   category=%s   resolved rule=%s',
            (string) $product->status,
            $product->brand_id === null ? 'none' : '#'.$product->brand_id,
            $product->category_id === null ? 'none' : '#'.$product->category_id,
            ($r = $this->ruleMarginFor($product)) === null ? 'none' : $this->pct($r),
        ));

        $snapshots = ProductPriceSnapshot::where('product_id', $product->id)
            ->orderBy('recorded_at')
            ->get();

        if ($snapshots->isEmpty()) {
            $this->warn('   no price history recorded.');

            return 'no-history';
        }

        $rows = [];
        $previous = null;
        $steps = [];

        foreach ($snapshots as $snapshot) {
            $buy = (int) round(((float) $snapshot->buy_price) * 100);
            $sell = (int) round(((float) $snapshot->sell_price) * 100);
            $bps = CeilingBlockClassifier::currentMarginBps($buy, $sell, $vatBps);

            $delta = ($previous !== null && $bps !== null) ? $bps - $previous : null;
            $isStep = $delta !== null && abs($delta) >= $stepBps;

            if ($isStep) {
                $steps[] = [
                    'date' => $snapshot->recorded_at?->format('Y-m-d') ?? '?',
                    'from' => $previous,
                    'to' => $bps,
                ];
            }

            $rows[] = [
                'date' => $snapshot->recorded_at?->format('Y-m-d') ?? '?',
                'buy' => $buy,
                'sell' => $sell,
                'bps' => $bps,
                'step' => $isStep,
                // The two causes look identical in the price alone: a step WITH a
                // competitor is the market, a step with NONE is the rule.
                'competitor' => $this->competitorNear($sku, $snapshot->recorded_at?->toDateString()),
            ];

            if ($bps !== null) {
                $previous = $bps;
            }
        }

        $this->renderHistory($rows);

        if ($steps === []) {
            $this->line('   No reprice step detected — this margin has been stable throughout.');

            return 'flat';
        }

        foreach ($steps as $step) {
            $this->warn(sprintf(
                '   STEP on %s: %s → %s',
                $step['date'],
                $step['from'] === null ? '?' : $this->pct($step['from']),
                $step['to'] === null ? '?' : $this->pct($step['to']),
            ));
        }

        return 'stepped';
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderHistory(array $rows): void
    {
        $this->table(
            ['Date', 'Cost', 'Sell', 'Margin', '', 'Competitor then'],
            array_map(fn (array $r): array => [
                $r['date'],
                number_format($r['buy'] / 100, 2),
                number_format($r['sell'] / 100, 2),
                $r['bps'] === null ? '-' : $this->pct($r['bps']),
                $r['step'] ? 'STEP' : '',
                $r['competitor'] === null ? 'none' : number_format($r['competitor'] / 100, 2),
            ], $rows),
        );
    }

    /**
     * Lowest competitor gross recorded within 30 days BEFORE this date.
     * competitor_prices is never pruned (COMP-07), so history is genuinely there.
     */
    private function competitorNear(string $sku, ?string $date): ?int
    {
        if ($date === null) {
            return null;
        }

        try {
            $rows = CompetitorPrice::query()
                ->where(static fn ($q) => $q->where('sku', $sku)->orWhere('mpn', $sku))
                ->where('is_price_anomaly', false)
                ->whereDate('recorded_at', '<=', $date)
                ->whereDate('recorded_at', '>=', date('Y-m-d', strtotime($date.' -30 days')))
                ->orderByDesc('recorded_at')
                ->get(['price_pennies_gross']);

            $positive = $rows->pluck('price_pennies_gross')
                ->map(static fn ($p): int => (int) $p)
                ->filter(static fn (int $p): bool => $p > 0);

            return $positive->isEmpty() ? null : (int) $positive->min();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $stepped
     * @param  array<int, string>  $flat
     */
    private function renderConclusion(array $stepped, array $flat): void
    {
        $this->newLine();
        $this->info('══ WHAT THIS MEANS');

        if ($stepped !== []) {
            $this->newLine();
            $this->warn('   STEPPED: '.implode(', ', $stepped));
            $this->line('   These were REPRICED. If a family sitting at 22% today stepped down');
            $this->line('   from ~99%, then 99% was the original price and 22% is what the engine');
            $this->line('   did to it — which makes 98.9% a LEGACY LIST PRICE, not a policy, and');
            $this->line('   the RAPT/MPCT exception would enshrine an accident permanently.');
            $this->line('   Widen the investigation before writing anything.');
        }

        if ($flat !== []) {
            $this->newLine();
            $this->info('   STABLE: '.implode(', ', $flat));
            $this->line('   These have always run their current margin. If the 22% and 10%');
            $this->line('   families are stable AND RAPT/MPCT are stable at 98.9%, they are');
            $this->line('   genuinely different product lines and the exception is real.');
        }

        $this->newLine();
        $this->line('   Read the COMPETITOR column against any step: a step with a competitor');
        $this->line('   present is the market pricing the product, which is the system working.');
        $this->line('   A step with none is the pricing rule, which is the case to worry about.');
        $this->line('   Price history alone cannot tell them apart.');
    }

    private function renderBrandTerms(string $needle): void
    {
        $this->newLine();
        $this->info(sprintf('══ WOO BRAND TERMS matching "%s"', $needle));

        try {
            $brands = $this->taxonomy->allBrands();
        } catch (Throwable $e) {
            $this->error('   Could not read Woo brands: '.$e->getMessage());

            return;
        }

        $matches = array_values(array_filter(
            $brands,
            static fn (array $t): bool => stripos((string) ($t['name'] ?? ''), $needle) !== false,
        ));

        if ($matches === []) {
            $this->warn(sprintf('   NO brand term contains "%s" among %d terms.', $needle, count($brands)));
            $this->line('   A new brand term would have to be created before any brand rule can');
            $this->line('   exist — and creating one is a Woo-side change, not a pricing change.');

            return;
        }

        $this->table(
            ['Term id', 'Name', 'Exact match?'],
            array_map(static fn (array $t): array => [
                (string) ($t['id'] ?? '?'),
                (string) ($t['name'] ?? '?'),
                strcasecmp((string) ($t['name'] ?? ''), $needle) === 0 ? 'yes' : '',
            ], $matches),
        );

        $this->line(sprintf('   %d of %d brand terms match.', count($matches), count($brands)));
        $this->line('   A term whose name is a GENERIC WORD rather than the manufacturer is a');
        $this->line('   fuzzy-match artefact. Assigning it would put these products under a brand');
        $this->line('   they do not belong to, and any brand rule would then reach whatever else');
        $this->line('   was mis-assigned the same way.');
    }

    private function renderCategoryTerms(string $needle): void
    {
        $this->newLine();
        $this->info(sprintf('══ WOO CATEGORY TERMS matching "%s"', $needle));

        try {
            $categories = $this->taxonomy->allCategoriesWithMeta();
        } catch (Throwable $e) {
            $this->error('   Could not read Woo categories: '.$e->getMessage());

            return;
        }

        $matches = array_values(array_filter(
            $categories,
            static fn (array $t): bool => stripos((string) ($t['name'] ?? ''), $needle) !== false
                || stripos((string) ($t['label'] ?? ''), $needle) !== false,
        ));

        if ($matches === []) {
            $this->warn('   No category term matches.');

            return;
        }

        $this->table(
            ['Term id', 'Name / label', 'Products'],
            array_map(static fn (array $t): array => [
                (string) ($t['id'] ?? '?'),
                substr((string) ($t['label'] ?? $t['name'] ?? '?'), 0, 60),
                (string) ($t['count'] ?? '?'),
            ], array_slice($matches, 0, 30)),
        );

        $this->line('   The PRODUCTS column is what decides whether a category rule is narrow');
        $this->line('   enough: a category holding other families cannot carry a margin meant');
        $this->line('   for one of them.');
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
