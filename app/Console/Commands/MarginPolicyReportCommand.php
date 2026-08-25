<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Competitor\Models\CompetitorMatchExclusion;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Pricing\Services\CeilingBlockClassifier;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Quick task 260825-mpr — evidence for the brand/category margin decision.
 *
 * READ-ONLY. No writes, no events, no Woo calls, no rule changes. It produces
 * the table a commercial decision gets made from; it does not make the decision,
 * and it deliberately cannot.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY THIS IS NEEDED
 *
 * The catalogue has THREE pricing rules, all default_tier (22% / 28% / 35% by
 * cost band). Layers 1-3 of RuleResolver — brand+category, category, brand —
 * are EMPTY, so every product in the catalogue prices on a cost band regardless
 * of what it is. 1,954 products carry neither brand nor category, but that is
 * incidental: assigning taxonomy changes nothing until rules exist to consult it.
 *
 * The evidence that this matters: ~60 unpublished projection screens sit at a
 * 98.9% implied margin and would be cut 38.66% the moment anything repriced
 * them, because 1.220 / 1.989 = 0.6134. They are unpublished, so there is no
 * live exposure — YET. Publishing one is what makes it real.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT "PROPOSED MARGIN" MEANS HERE, AND WHAT IT DOES NOT
 *
 * The proposal is the group's MEDIAN CURRENT implied margin, rounded. That is a
 * description of what the business has actually been charging, not a
 * recommendation about what it should charge. It answers "what would keep
 * today's prices roughly where they are", which is the safe default when the
 * alternative is a 39% cut nobody chose.
 *
 * Whether a family SHOULD earn its historical margin is a commercial question
 * about competition, volume and strategy, and no query answers it. Confidence
 * grades how tightly the group agrees with itself — high confidence means the
 * family has an obvious norm, not that the norm is right.
 *
 *   php artisan pricing:margin-policy-report
 *   php artisan pricing:margin-policy-report --with-competitor
 *   php artisan pricing:margin-policy-report --format=csv > policy.csv
 *   php artisan pricing:margin-policy-report --min-group=3
 */
final class MarginPolicyReportCommand extends BaseCommand
{
    /** A price move at or beyond this share of the current price is "material". */
    private const MATERIAL_MOVE = 0.05;

    protected $signature = 'pricing:margin-policy-report
        {--min-group=2 : Ignore groups smaller than this (they belong on the override list)}
        {--with-competitor : Also read competitor data per product (SLOWER; adds a query per SKU)}
        {--format=table : table or csv}
        {--limit=40 : Groups to print}';

    protected $description = 'READ-ONLY evidence for the brand/category margin decision — group margins, price impact, and what kind of rule each group needs (260825-mpr).';

    public function __construct(
        private readonly RuleResolver $resolver,
        private readonly PriceCalculator $calculator,
        private readonly TaxonomyResolver $taxonomy,
    ) {
        parent::__construct();
    }

    /** @var array<int, string> Woo brand term id => name */
    private array $brandNames = [];

    protected function perform(): int
    {
        $vatBps = (int) config('pricing.vat_basis_points', 2000);
        $minGroup = max(1, (int) $this->option('min-group'));
        $withCompetitor = (bool) $this->option('with-competitor');
        $csv = $this->option('format') === 'csv';
        $limit = max(1, (int) $this->option('limit'));
        $cutoff = now()->subDays(30);
        $this->brandNames = $this->loadBrandNames();

        if (! $csv) {
            $this->info('Margin policy evidence — READ-ONLY. Nothing is written and no rule changes.');
            $this->line('  Resolving rules per product; on ~6,000 products this takes a minute or two.');
            $this->newLine();
        }

        $held = $this->heldOverrideProductIds();
        $groups = [];
        $dataQuality = [];
        $scanned = 0;

        Product::query()
            ->whereNotNull('buy_price')
            ->where('buy_price', '>', 0)
            ->whereNotNull('sell_price')
            ->where('sell_price', '>', 0)
            ->orderBy('id')
            ->chunkById(400, function ($products) use (
                $vatBps, $withCompetitor, $cutoff, $held, &$groups, &$dataQuality, &$scanned
            ): void {
                foreach ($products as $product) {
                    $scanned++;
                    $this->accumulate($product, $vatBps, $withCompetitor, $cutoff, $held, $groups, $dataQuality);
                }
            });

        $rows = $this->summarise($groups, $vatBps, $minGroup);
        usort($rows, static fn (array $a, array $b): int => [$a['priority'], -abs($a['net_delta'])] <=> [$b['priority'], -abs($b['net_delta'])]);

        if ($csv) {
            $this->emitCsv($rows);

            return SymfonyCommand::SUCCESS;
        }

        $this->renderGroups($rows, $limit);
        $this->renderHumanDecisions($rows);
        $this->renderDataQuality($dataQuality);
        $this->renderFooter($rows, $scanned);

        return SymfonyCommand::SUCCESS;
    }

    /**
     * Products pinned by a 260824-w9k holding override. They are priced
     * deliberately, so their margin is EVIDENCE of an intended policy rather
     * than an accident — and they are the group whose protection expires the
     * moment real rules land.
     *
     * @return array<int, true>
     */
    private function heldOverrideProductIds(): array
    {
        return ProductOverride::query()
            ->where('reason', 'like', '%260824-w9k%')
            ->pluck('product_id')
            ->mapWithKeys(static fn ($id): array => [(int) $id => true])
            ->all();
    }

    /**
     * @param  array<int, true>  $held
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<int, array<string, mixed>>  $dataQuality
     */
    private function accumulate(
        Product $product,
        int $vatBps,
        bool $withCompetitor,
        Carbon $cutoff,
        array $held,
        array &$groups,
        array &$dataQuality,
    ): void {
        $buy = (int) round(((float) $product->buy_price) * 100);
        $sell = (int) round(((float) $product->sell_price) * 100);
        $currentBps = CeilingBlockClassifier::currentMarginBps($buy, $sell, $vatBps);

        if ($currentBps === null) {
            return;
        }

        $ruleBps = $this->ruleMarginFor($product);

        // Absurd margins are a DATA question, not a policy one. Including them
        // would drag a group's median toward a number nobody chose.
        if ($currentBps >= 20000 && $buy >= 1000) {
            $dataQuality[] = [
                'sku' => (string) $product->sku,
                'status' => (string) $product->status,
                'buy' => $buy,
                'sell' => $sell,
                'current_bps' => $currentBps,
                'rule_bps' => $ruleBps,
            ];

            return;
        }

        [$key, $basis] = $this->groupKeyFor($product);

        if (! array_key_exists($key, $groups)) {
            $groups[$key] = [
                'key' => $key,
                'basis' => $basis,
                'margins' => [],
                'rule_margins' => [],
                'products' => [],
                'published' => 0,
                'unpublished' => 0,
                'held' => 0,
                'has_brand' => 0,
                'has_category' => 0,
                'categories' => [],
                'competitor_margins' => [],
                'examples' => [],
            ];
        }

        $g = &$groups[$key];
        $g['margins'][] = $currentBps;
        if ($ruleBps !== null) {
            $g['rule_margins'][] = $ruleBps;
        }
        $g['products'][] = ['buy' => $buy, 'sell' => $sell, 'sku' => (string) $product->sku];

        (string) $product->status === 'publish' ? $g['published']++ : $g['unpublished']++;
        if (array_key_exists((int) $product->id, $held)) {
            $g['held']++;
        }
        if ($product->brand_id !== null) {
            $g['has_brand']++;
        }
        if ($product->category_id !== null) {
            $g['has_category']++;
            $g['categories'][(int) $product->category_id] = true;
        }
        if (count($g['examples']) < 3) {
            $g['examples'][] = (string) $product->sku;
        }

        if ($withCompetitor) {
            $comp = $this->lowestCompetitorGross((string) $product->sku, $cutoff);
            if ($comp !== null && $buy > 0) {
                $compExVat = (int) round($comp / (1 + ($vatBps / 10000)));
                $g['competitor_margins'][] = (int) round((($compExVat - $buy) / $buy) * 10000);
            }
        }
    }

    /**
     * How a product gets grouped, and the honesty of saying which signal was
     * used: a brand is a fact, a SKU prefix is an inference.
     *
     * @return array{0: string, 1: string}
     */
    private function groupKeyFor(Product $product): array
    {
        // brand_id is a WOO TERM ID, not a local foreign key — there is no
        // Brand model and no brand() relation. Reading `$product->brand`
        // returns null silently, which is exactly how an earlier version of
        // this method grouped every branded product by SKU prefix instead.
        // Names come from Woo (cached); without them the id still groups
        // correctly, just less readably.
        if ($product->brand_id !== null) {
            $id = (int) $product->brand_id;
            $label = $this->brandNames[$id] ?? ('#'.$id);

            return ['brand:'.$label, 'brand'];
        }

        // No brand — infer a family. First word of the name is the usual shape
        // for auto-created products ("{Brand} {category words} {SKU}").
        $name = trim((string) $product->name);
        $firstWord = $name === '' ? '' : (explode(' ', $name)[0] ?? '');
        if (strlen($firstWord) >= 3 && ctype_alpha($firstWord)) {
            return ['name:'.strtolower($firstWord), 'name (no brand set)'];
        }

        // Fall back to the alphabetic prefix of the SKU — this is what groups
        // the projection screens (RAPT/MPCT/TT/GTHC/COM/MJRT/DFT).
        $sku = strtoupper(trim((string) $product->sku));
        preg_match('/^[A-Z]+/', $sku, $m);
        $prefix = $m[0] ?? '';

        return $prefix !== ''
            ? ['sku:'.$prefix, 'SKU prefix (inferred)']
            : ['sku:(ungrouped)', 'ungrouped'];
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function summarise(array $groups, int $vatBps, int $minGroup): array
    {
        $rows = [];
        $floorBps = (int) config('competitor.min_margin_floor_bps', 600);

        foreach ($groups as $g) {
            $count = count($g['margins']);
            if ($count < $minGroup) {
                continue;
            }

            sort($g['margins']);
            $median = $this->percentile($g['margins'], 0.5);
            $p25 = $this->percentile($g['margins'], 0.25);
            $p75 = $this->percentile($g['margins'], 0.75);
            $spread = $p75 - $p25;
            $ruleMedian = $g['rule_margins'] === [] ? null : $this->percentile($g['rule_margins'], 0.5);

            // The proposal is a DESCRIPTION of current practice, rounded to a
            // number a human would actually write down.
            //
            // 50bps, NOT 250bps. The first live run rounded to a 2.5pp grid and
            // manufactured its own headline: Samsung's median is 22.0% —
            // exactly its tier, so nothing should move — and rounding to 22.5%
            // pushed 79 products up for GBP 69,397 of entirely synthetic
            // movement. A grid coarser than the tiers themselves cannot
            // represent 'leave this group alone'.
            $proposed = (int) (round($median / 50) * 50);

            // Never propose below the minimum-margin floor. A group sitting at
            // 6.0% is being FLOORED by competitor undercut, not choosing 6%;
            // proposing 5% describes a price the floor would refuse to set.
            $proposed = max($proposed, $floorBps);

            [$netDelta, $material, $up, $down] = $this->impact($g['products'], $proposed, $vatBps);

            $rows[] = [
                'key' => $g['key'],
                'basis' => $g['basis'],
                'count' => $count,
                'published' => $g['published'],
                'unpublished' => $g['unpublished'],
                'held' => $g['held'],
                'median_bps' => $median,
                'spread_bps' => $spread,
                'rule_bps' => $ruleMedian,
                'proposed_bps' => $proposed,
                'net_delta' => $netDelta,
                'material' => $material,
                'up' => $up,
                'down' => $down,
                'competitor_median_bps' => $g['competitor_margins'] === []
                    ? null
                    : $this->percentile($g['competitor_margins'], 0.5),
                'confidence' => $this->confidence($count, $spread),
                'rule_type' => $this->ruleType($g),
                'examples' => implode(', ', $g['examples']),
                'priority' => $this->priority($g, $median, $ruleMedian, $material),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function impact(array $products, int $proposedBps, int $vatBps): array
    {
        $net = 0;
        $material = 0;
        $up = 0;
        $down = 0;

        foreach ($products as $p) {
            $new = $this->calculator->compute((int) $p['buy'], $proposedBps, $vatBps);
            $delta = $new - (int) $p['sell'];
            $net += $delta;

            if ((int) $p['sell'] > 0 && abs($delta) / (int) $p['sell'] >= self::MATERIAL_MOVE) {
                $material++;
                $delta > 0 ? $up++ : $down++;
            }
        }

        return [$net, $material, $up, $down];
    }

    /**
     * Confidence grades how tightly a group agrees WITH ITSELF. High confidence
     * means the family has an obvious norm — NOT that the norm is correct.
     */
    private function confidence(int $count, int $spreadBps): string
    {
        if ($count >= 8 && $spreadBps <= 1000) {
            return 'high';
        }

        if ($count >= 4 && $spreadBps <= 2500) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  array<string, mixed>  $g
     */
    private function ruleType(array $g): string
    {
        $count = count($g['margins']);

        if ($g['has_brand'] === 0) {
            // Cannot write a brand rule against a brand nobody has assigned.
            return 'assign brand first';
        }

        if ($g['has_brand'] === $count && count($g['categories']) === 1 && $g['has_category'] === $count) {
            return 'brand+category';
        }

        if ($g['has_brand'] === $count) {
            return 'brand';
        }

        return $count <= 3 ? 'product override' : 'brand (partial taxonomy)';
    }

    /**
     * The operator's stated order: unpublished screens first (no live exposure
     * yet, and publishing is what makes it real), then products whose holding
     * override expires when rules land, then material cuts, then rises.
     *
     * @param  array<string, mixed>  $g
     */
    private function priority(array $g, int $median, ?int $ruleMedian, int $material): int
    {
        $wouldFall = $ruleMedian !== null && $median > $ruleMedian + 2500;

        if ($g['unpublished'] > 0 && $wouldFall) {
            return 1;   // unpublished and would be cut hard on publish
        }

        if ($g['held'] > 0) {
            return 2;   // protected only by a temporary override
        }

        if ($g['published'] > 0 && $wouldFall) {
            return 3;   // live and would be cut
        }

        if ($material > 0) {
            return 4;   // would move materially in either direction
        }

        return 5;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderGroups(array $rows, int $limit): void
    {
        $this->info('── DECISION TABLE ──  proposed = median of what this group ALREADY earns');
        $this->newLine();

        $this->table(
            ['Pri', 'Group', 'N', 'Pub', 'Unpub', 'Held', 'Median', 'Spread', 'Rule', 'Proposed', 'Net £', 'Moves', 'Conf', 'Rule type'],
            array_map(fn (array $r): array => [
                (string) $r['priority'],
                substr($r['key'], 0, 26),
                (string) $r['count'],
                (string) $r['published'],
                (string) $r['unpublished'],
                $r['held'] > 0 ? (string) $r['held'] : '',
                $this->pct($r['median_bps']),
                $this->pct($r['spread_bps']),
                $r['rule_bps'] === null ? '-' : $this->pct($r['rule_bps']),
                $this->pct($r['proposed_bps']),
                number_format($r['net_delta'] / 100, 0),
                sprintf('%d (%d↑ %d↓)', $r['material'], $r['up'], $r['down']),
                $r['confidence'],
                $r['rule_type'],
            ], array_slice($rows, 0, $limit)),
        );

        $more = count($rows) - $limit;
        if ($more > 0) {
            $this->line("  … and {$more} more group(s). --limit=0 or --format=csv for all.");
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderHumanDecisions(array $rows): void
    {
        $needs = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['confidence'] === 'low' || $r['rule_type'] === 'assign brand first',
        ));

        if ($needs === []) {
            return;
        }

        $this->newLine();
        $this->warn('── NEEDS A HUMAN DECISION ──');
        $this->line('  Low confidence means the group does NOT agree with itself: its members');
        $this->line('  run different margins, so no single number describes them. "Assign brand');
        $this->line('  first" means there is no brand to hang a rule on yet.');
        $this->newLine();

        $this->table(
            ['Group', 'N', 'Median', 'Spread', 'Why', 'Examples'],
            array_map(fn (array $r): array => [
                substr($r['key'], 0, 26),
                (string) $r['count'],
                $this->pct($r['median_bps']),
                $this->pct($r['spread_bps']),
                $r['rule_type'] === 'assign brand first' ? 'no brand assigned' : 'margins disagree',
                substr($r['examples'], 0, 34),
            ], array_slice($needs, 0, 20)),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderDataQuality(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->warn('── DATA QUALITY FIRST ──  excluded from every group above');
        $this->line('  A margin over 200% on a real cost is a broken COST or a wrong identity,');
        $this->line('  not a pricing policy. Including these would drag a median toward a');
        $this->line('  number nobody chose. Fix the data, then re-run this report.');
        $this->newLine();

        usort($rows, static fn (array $a, array $b): int => $b['current_bps'] <=> $a['current_bps']);

        $this->table(
            ['SKU', 'Status', 'Cost', 'Price', 'Implied margin', 'Its rule'],
            array_map(fn (array $r): array => [
                $r['sku'],
                $r['status'],
                number_format($r['buy'] / 100, 2),
                number_format($r['sell'] / 100, 2),
                $this->pct($r['current_bps']),
                $r['rule_bps'] === null ? '-' : $this->pct($r['rule_bps']),
            ], array_slice($rows, 0, 20)),
        );

        $more = count($rows) - 20;
        if ($more > 0) {
            $this->line("  … and {$more} more.");
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderFooter(array $rows, int $scanned): void
    {
        $netAll = array_sum(array_map(static fn (array $r): int => (int) $r['net_delta'], $rows));

        $this->newLine();
        $this->line(sprintf('Scanned %d product(s) with a usable cost and price; %d group(s) at or above the size threshold.', $scanned, count($rows)));
        $this->line(sprintf('If EVERY proposed margin were adopted, list price moves by £%s net.', number_format($netAll / 100, 2)));
        $this->newLine();
        $this->line('Read the proposal as "what keeps today\'s prices roughly where they are".');
        $this->line('Whether a family SHOULD earn its historical margin is a commercial question');
        $this->line('about competition, volume and strategy — no query answers that.');
        $this->newLine();
        $this->line('Nothing was written. No rule, price, override or product changed.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function emitCsv(array $rows): void
    {
        $this->line('priority,group,basis,count,published,unpublished,held,median_pct,spread_pct,rule_pct,proposed_pct,net_delta_pounds,material_moves,up,down,competitor_median_pct,confidence,rule_type,examples');

        foreach ($rows as $r) {
            $this->line(implode(',', [
                $r['priority'],
                '"'.str_replace('"', '""', $r['key']).'"',
                '"'.$r['basis'].'"',
                $r['count'],
                $r['published'],
                $r['unpublished'],
                $r['held'],
                number_format($r['median_bps'] / 100, 2, '.', ''),
                number_format($r['spread_bps'] / 100, 2, '.', ''),
                $r['rule_bps'] === null ? '' : number_format($r['rule_bps'] / 100, 2, '.', ''),
                number_format($r['proposed_bps'] / 100, 2, '.', ''),
                number_format($r['net_delta'] / 100, 2, '.', ''),
                $r['material'],
                $r['up'],
                $r['down'],
                $r['competitor_median_bps'] === null ? '' : number_format($r['competitor_median_bps'] / 100, 2, '.', ''),
                $r['confidence'],
                '"'.$r['rule_type'].'"',
                '"'.str_replace('"', '""', $r['examples']).'"',
            ]));
        }
    }

    /** Mirrors the pricing command, exclusions included. */
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
     * Woo term id => brand name, best effort. A report that cannot NAME a
     * brand is still useful; one that fails because Woo is unreachable is not.
     *
     * @return array<int, string>
     */
    private function loadBrandNames(): array
    {
        try {
            $map = [];
            foreach ($this->taxonomy->allBrands() as $term) {
                $id = (int) ($term['id'] ?? 0);
                $name = trim((string) ($term['name'] ?? ''));
                if ($id > 0 && $name !== '') {
                    $map[$id] = $name;
                }
            }

            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    private function ruleMarginFor(Product $product): ?int
    {
        try {
            return (int) $this->resolver->resolve($product)->marginBasisPoints;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, int>  $values
     */
    private function percentile(array $values, float $p): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $index = (int) floor($p * (count($values) - 1));

        return (int) $values[$index];
    }

    private function pct(int $bps): string
    {
        return number_format($bps / 100, 1).'%';
    }
}
