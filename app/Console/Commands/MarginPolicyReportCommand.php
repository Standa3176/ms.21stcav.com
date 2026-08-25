<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Competitor\Models\CompetitorMatchExclusion;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Pricing\Services\CeilingBlockClassifier;
use App\Domain\Pricing\Services\CompetitorUndercutPricer;
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
 * READ-ONLY. No writes, no events, no Woo pushes, no rule changes. It produces
 * the table a commercial decision gets made from; it does not make the decision.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE HEADLINE, FROM THE FIRST LIVE RUN
 *
 * MOST BRANDS ARE ALREADY TIER-CONSISTENT. Asus, Panasonic, Optoma, BenQ,
 * Lenovo and HP all showed a spread of 0.0% with medians landing exactly on
 * 22.0 / 28.0 / 35.0. The engine has already priced this catalogue and did it
 * consistently.
 *
 * So this is NOT a repair job. It is a search for the handful of families that
 * should DELIBERATELY diverge from three cost bands — and for the few whose
 * numbers indict their data rather than their pricing.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT "PROPOSED" MEANS, AND WHAT IT DOES NOT
 *
 * The proposal is the group's MEDIAN CURRENT margin, rounded to 0.5pp and
 * clamped up to the minimum-margin floor. That describes what the business has
 * been charging; it is not a recommendation about what it should charge. It
 * answers "what keeps today's prices where they are", the safe default when the
 * alternative is an unchosen cut.
 *
 * Confidence grades whether a group agrees WITH ITSELF. High confidence means
 * the family has an obvious norm — not that the norm is right.
 *
 *   php artisan pricing:margin-policy-report
 *   php artisan pricing:margin-policy-report --group-by=sku-prefix
 *   php artisan pricing:margin-policy-report --detail=brand:C2G
 *   php artisan pricing:margin-policy-report --format=csv > policy.csv
 */
final class MarginPolicyReportCommand extends BaseCommand
{
    /** A price move at or beyond this share of the current price is "material". */
    private const MATERIAL_MOVE = 0.05;

    /** A group whose median sits within this of the floor is being FLOORED, not choosing. */
    private const FLOOR_PROXIMITY_BPS = 100;

    /** Sub-breakdown only earns its space when a group is genuinely mixed. */
    private const SUBGROUP_SPREAD_BPS = 2500;

    private const SUBGROUP_MIN = 3;

    /**
     * How far a group's median may drift from its RULE-LED median before the
     * median stops describing policy and starts describing the competition.
     */
    private const CONTAMINATION_BPS = 500;

    protected $signature = 'pricing:margin-policy-report
        {--group-by=auto : auto | sku-prefix | name | brand. auto prefers brand, then name, then SKU prefix.}
        {--detail= : Drill into ONE group key and list its products (e.g. brand:C2G)}
        {--min-group=2 : Ignore groups smaller than this}
        {--with-competitor : Also read competitor data (SLOWER; a query per SKU)}
        {--format=table : table or csv}
        {--limit=40 : Groups to print}';

    protected $description = 'READ-ONLY evidence for the brand/category margin decision — group margins, price impact, and which families need a deliberate exception (260825-mpr).';

    /** @var array<int, string> Woo brand term id => name */
    private array $brandNames = [];

    private string $groupBy = 'auto';

    /** True once competitor data has been read, so branch is known per product. */
    private bool $branchesKnown = false;

    public function __construct(
        private readonly RuleResolver $resolver,
        private readonly PriceCalculator $calculator,
        private readonly TaxonomyResolver $taxonomy,
        private readonly CompetitorUndercutPricer $pricer,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $vatBps = (int) config('pricing.vat_basis_points', 2000);
        $floorBps = (int) config('competitor.min_margin_floor_bps', 600);
        $minGroup = max(1, (int) $this->option('min-group'));
        $withCompetitor = (bool) $this->option('with-competitor');
        $csv = $this->option('format') === 'csv';
        $limit = max(1, (int) $this->option('limit'));
        $detail = trim((string) $this->option('detail'));
        $cutoff = now()->subDays(30);

        $this->groupBy = (string) $this->option('group-by');
        if (! in_array($this->groupBy, ['auto', 'sku-prefix', 'name', 'brand'], true)) {
            $this->error('--group-by must be auto, sku-prefix, name or brand.');

            return SymfonyCommand::FAILURE;
        }

        $this->brandNames = $this->loadBrandNames();
        $this->branchesKnown = $withCompetitor;

        if (! $csv) {
            $this->info('Margin policy evidence — READ-ONLY. Nothing is written and no rule changes.');
            $this->line(sprintf('  Grouping: %s. Resolving rules per product takes a minute or two.', $this->groupBy));
            $this->newLine();
        }

        $held = $this->heldOverrideProductIds();
        $groups = [];
        $dataQuality = [];
        $detailRows = [];
        $scanned = 0;

        Product::query()
            ->whereNotNull('buy_price')
            ->where('buy_price', '>', 0)
            ->whereNotNull('sell_price')
            ->where('sell_price', '>', 0)
            ->orderBy('id')
            ->chunkById(400, function ($products) use (
                $vatBps, $withCompetitor, $cutoff, $held, $detail,
                &$groups, &$dataQuality, &$detailRows, &$scanned
            ): void {
                foreach ($products as $product) {
                    $scanned++;
                    $this->accumulate(
                        $product, $vatBps, $withCompetitor, $cutoff, $held, $detail,
                        $groups, $dataQuality, $detailRows
                    );
                }
            });

        if ($detail !== '') {
            $this->renderDetail($detail, $detailRows, $vatBps, $floorBps);

            return SymfonyCommand::SUCCESS;
        }

        $rows = $this->summarise($groups, $vatBps, $floorBps, $minGroup);
        usort($rows, static fn (array $a, array $b): int => [$a['priority'], -abs($a['net_delta'])] <=> [$b['priority'], -abs($b['net_delta'])]);

        if ($csv) {
            $this->emitCsv($rows);

            return SymfonyCommand::SUCCESS;
        }

        $this->renderHeadline($rows);
        $this->renderGroups($rows, $limit);
        $this->renderSubBreakdowns($rows);
        $this->renderDecisionsNeeded($rows);
        $this->renderDataQuality($dataQuality);
        $this->renderFooter($rows, $scanned);

        return SymfonyCommand::SUCCESS;
    }

    /**
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
     * @param  array<int, array<string, mixed>>  $detailRows
     */
    private function accumulate(
        Product $product,
        int $vatBps,
        bool $withCompetitor,
        Carbon $cutoff,
        array $held,
        string $detail,
        array &$groups,
        array &$dataQuality,
        array &$detailRows,
    ): void {
        $buy = (int) round(((float) $product->buy_price) * 100);
        $sell = (int) round(((float) $product->sell_price) * 100);
        $currentBps = CeilingBlockClassifier::currentMarginBps($buy, $sell, $vatBps);

        if ($currentBps === null) {
            return;
        }

        [$key, $basis] = $this->groupKeyFor($product);
        $ruleBps = $this->ruleMarginFor($product);

        // The detail view deliberately does NOT apply the data-quality exclusion
        // below: when drilling into one group you want everything in it,
        // including rows that would otherwise be quarantined.
        if ($detail !== '') {
            if ($key === $detail) {
                $detailRows[] = [
                    'sku' => (string) $product->sku,
                    'status' => (string) $product->status,
                    'buy' => $buy,
                    'sell' => $sell,
                    'current_bps' => $currentBps,
                    'rule_bps' => $ruleBps,
                    'held' => array_key_exists((int) $product->id, $held),
                    'competitor' => $this->lowestCompetitorGross((string) $product->sku, $cutoff),
                ];
            }

            return;
        }

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
                'sub' => [],
                'branches' => [],
                'rule_led_margins' => [],
            ];
        }

        $g = &$groups[$key];
        $g['margins'][] = $currentBps;
        if ($ruleBps !== null) {
            $g['rule_margins'][] = $ruleBps;
        }
        // 260825-mpr follow-up — WHICH BRANCH prices this product today?
        // A rule margin is only consulted on the `margin` branch: a product
        // with a live competitor is priced by undercut or by the floor, and
        // changing its rule moves nothing. Counting those in rule impact is
        // what made the first run's Net GBP an overstatement.
        //
        // Classified by CompetitorUndercutPricer itself, not a local copy of
        // its logic, so this cannot drift from what the pricing run does.
        $branch = null;
        if ($withCompetitor) {
            $lowest = $this->lowestCompetitorGross((string) $product->sku, $cutoff);
            $branch = (string) $this->pricer->decide(
                $buy,
                $lowest,
                $ruleBps ?? 0,
                (int) config('competitor.beat_by_pennies', 1),
                (int) config('competitor.min_margin_floor_bps', 600),
                $vatBps,
            )['source'];

            $g['branches'][$branch] = ($g['branches'][$branch] ?? 0) + 1;

            // Only a RULE-LED product's margin is evidence of policy. A floored
            // product's margin is evidence of a competitor.
            if ($branch === 'margin') {
                $g['rule_led_margins'][] = $currentBps;
            }

            if ($lowest !== null && $buy > 0) {
                $compExVat = (int) round($lowest / (1 + ($vatBps / 10000)));
                $g['competitor_margins'][] = (int) round((($compExVat - $buy) / $buy) * 10000);
            }
        }

        $g['products'][] = [
            'buy' => $buy,
            'sell' => $sell,
            'sku' => (string) $product->sku,
            'branch' => $branch,
        ];

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

        // Secondary breakdown by SKU prefix. This is what separates the TT /
        // RAPT / MPCT projection-screen families inside a "name:screen" group
        // that otherwise averages 139 heterogeneous products together and hides
        // a ~99% family behind a 28.0% median.
        $prefix = $this->skuPrefix((string) $product->sku);
        if ($prefix !== '') {
            $g['sub'][$prefix][] = $currentBps;
        }

    }

    /**
     * @return array{0: string, 1: string}
     */
    private function groupKeyFor(Product $product): array
    {
        $brandLabel = null;
        if ($product->brand_id !== null) {
            $id = (int) $product->brand_id;
            $brandLabel = $this->brandNames[$id] ?? ('#'.$id);
        }

        if ($this->groupBy === 'brand') {
            return $brandLabel === null
                ? ['brand:(none)', 'no brand assigned']
                : ['brand:'.$brandLabel, 'brand'];
        }

        if ($this->groupBy === 'sku-prefix') {
            $prefix = $this->skuPrefix((string) $product->sku);

            return $prefix === ''
                ? ['sku:(ungrouped)', 'ungrouped']
                : ['sku:'.$prefix, 'SKU prefix (forced)'];
        }

        if ($this->groupBy === 'name') {
            $word = $this->nameFirstWord((string) $product->name);

            return $word === ''
                ? ['name:(none)', 'no usable name']
                : ['name:'.$word, 'name'];
        }

        // auto — brand_id is a WOO TERM ID, not a local foreign key; there is no
        // brand() relation, and reading one returns null silently.
        if ($brandLabel !== null) {
            return ['brand:'.$brandLabel, 'brand'];
        }

        $word = $this->nameFirstWord((string) $product->name);
        if ($word !== '') {
            return ['name:'.$word, 'name (no brand set)'];
        }

        $prefix = $this->skuPrefix((string) $product->sku);

        return $prefix !== ''
            ? ['sku:'.$prefix, 'SKU prefix (inferred)']
            : ['sku:(ungrouped)', 'ungrouped'];
    }

    private function nameFirstWord(string $name): string
    {
        $name = trim($name);
        $first = $name === '' ? '' : (explode(' ', $name)[0] ?? '');

        return (strlen($first) >= 3 && ctype_alpha($first)) ? strtolower($first) : '';
    }

    private function skuPrefix(string $sku): string
    {
        preg_match('/^[A-Z]+/', strtoupper(trim($sku)), $m);

        return $m[0] ?? '';
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function summarise(array $groups, int $vatBps, int $floorBps, int $minGroup): array
    {
        $rows = [];

        foreach ($groups as $g) {
            $count = count($g['margins']);
            if ($count < $minGroup) {
                continue;
            }

            $median = $this->percentile($g['margins'], 0.5);
            $spread = $this->percentile($g['margins'], 0.75) - $this->percentile($g['margins'], 0.25);
            $ruleMedian = $g['rule_margins'] === [] ? null : $this->percentile($g['rule_margins'], 0.5);

            // 0.5pp, not 2.5pp: a grid coarser than the tiers themselves cannot
            // express "leave this group alone", and the first live run invented
            // GBP 69,397 of Samsung movement that way.
            //
            // Clamped UP to the floor: a group at 6.0% is being FLOORED by
            // competitor undercut, not choosing 6%, and proposing less describes
            // a price the floor would refuse to set.
            // 260825-mpr follow-up — a group's median is only policy evidence if
            // it is not describing the competition instead.
            //
            // brand:SMART on prod: 23 products, 15 competitor-FLOORED at ~6%, 8
            // rule-led. The group median reads 6.0% — but those 8 have no
            // competitor and are not under pressure. Adopting 6% as a rule would
            // cut them to floor margin GRATUITOUSLY, dragged down by neighbours
            // whose prices the market set. That is a rule doing the opposite of
            // preserving current prices.
            //
            // So when the rule-led median differs materially from the overall
            // one, the rule-led figure is the rule-safe number and the raw
            // median is labelled as contaminated rather than quietly used.
            $ruleLedMedian = $g['rule_led_margins'] === []
                ? null
                : $this->percentile($g['rule_led_margins'], 0.5);

            $contaminated = $ruleLedMedian !== null
                && abs($median - $ruleLedMedian) > self::CONTAMINATION_BPS;

            $basis = $contaminated ? $ruleLedMedian : $median;
            $proposed = max((int) (round($basis / 50) * 50), $floorBps);

            // Rule impact over RULE-LED products only. Without --with-competitor
            // the branch is unknown and every product is counted, which is why
            // the total is labelled an UPPER BOUND in that mode.
            [$netDelta, $material, $up, $down, $ruleLed, $compLed] =
                $this->impact($g['products'], $proposed, $vatBps);

            $rows[] = [
                'key' => $g['key'],
                'basis' => $g['basis'],
                'count' => $count,
                'published' => $g['published'],
                'unpublished' => $g['unpublished'],
                'held' => $g['held'],
                'median_bps' => $median,
                'rule_led_median_bps' => $ruleLedMedian,
                'contaminated' => $contaminated,
                'spread_bps' => $spread,
                'rule_bps' => $ruleMedian,
                'proposed_bps' => $proposed,
                'net_delta' => $netDelta,
                'rule_led' => $ruleLed,
                'competitor_led' => $compLed,
                'branches' => $g['branches'],
                'material' => $material,
                'up' => $up,
                'down' => $down,
                'competitor_median_bps' => $g['competitor_margins'] === []
                    ? null
                    : $this->percentile($g['competitor_margins'], 0.5),
                'confidence' => $this->confidence($count, $spread),
                'rule_type' => $this->ruleType($g),
                'examples' => implode(', ', $g['examples']),
                'sub' => $this->summariseSub($g['sub']),
                'reasons' => $this->decisionReasons($g, $median, $spread, $floorBps, $contaminated),
                'priority' => $this->priority($g, $median, $ruleMedian, $material),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array<int, int>>  $sub
     * @return array<int, array<string, mixed>>
     */
    private function summariseSub(array $sub): array
    {
        $out = [];

        foreach ($sub as $prefix => $margins) {
            if (count($margins) < self::SUBGROUP_MIN) {
                continue;
            }

            $out[] = [
                'prefix' => $prefix,
                'count' => count($margins),
                'median_bps' => $this->percentile($margins, 0.5),
                'spread_bps' => $this->percentile($margins, 0.75) - $this->percentile($margins, 0.25),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['median_bps'] <=> $a['median_bps']);

        return $out;
    }

    /**
     * Why a group needs a human, in words rather than a grade.
     *
     * @param  array<string, mixed>  $g
     * @return array<int, string>
     */
    private function decisionReasons(array $g, int $median, int $spread, int $floorBps, bool $contaminated = false): array
    {
        $reasons = [];

        if ($g['held'] > 0) {
            $reasons[] = 'pinned by 260824-w9k';
        }

        if ($median <= $floorBps + self::FLOOR_PROXIMITY_BPS) {
            $reasons[] = 'sitting on the margin floor';
        }

        if ($median >= 20000) {
            $reasons[] = 'extreme margin';
        }

        if ($spread > self::SUBGROUP_SPREAD_BPS) {
            $reasons[] = 'members disagree';
        }

        if ($contaminated) {
            $reasons[] = 'median contaminated by floored members';
        }

        if ($g['has_brand'] === 0) {
            $reasons[] = 'no brand to hang a rule on';
        }

        return $reasons;
    }

    /**
     * A rule margin is consulted ONLY on the `margin` branch. A product with a
     * live competitor is priced by undercut or by the floor, so changing its
     * rule moves nothing — counting it inflates the figure a commercial
     * conversation would be built on.
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}
     */
    private function impact(array $products, int $proposedBps, int $vatBps): array
    {
        $net = 0;
        $material = 0;
        $up = 0;
        $down = 0;
        $ruleLed = 0;
        $compLed = 0;

        foreach ($products as $p) {
            $branch = $p['branch'] ?? null;

            if ($branch !== null && $branch !== 'margin') {
                $compLed++;

                continue;   // competitor-led: a rule change cannot reach it
            }

            $ruleLed++;
            $new = $this->calculator->compute((int) $p['buy'], $proposedBps, $vatBps);
            $delta = $new - (int) $p['sell'];
            $net += $delta;

            if ((int) $p['sell'] > 0 && abs($delta) / (int) $p['sell'] >= self::MATERIAL_MOVE) {
                $material++;
                $delta > 0 ? $up++ : $down++;
            }
        }

        return [$net, $material, $up, $down, $ruleLed, $compLed];
    }

    private function confidence(int $count, int $spreadBps): string
    {
        if ($count >= 8 && $spreadBps <= 1000) {
            return 'high';
        }

        return ($count >= 4 && $spreadBps <= 2500) ? 'medium' : 'low';
    }

    /**
     * @param  array<string, mixed>  $g
     */
    private function ruleType(array $g): string
    {
        $count = count($g['margins']);

        if ($g['has_brand'] === 0) {
            return 'assign brand first';
        }

        if ($g['has_brand'] === $count && $g['has_category'] === $count && count($g['categories']) === 1) {
            return 'brand+category';
        }

        if ($g['has_brand'] === $count) {
            return 'brand';
        }

        return $count <= 3 ? 'product override' : 'brand (partial taxonomy)';
    }

    /**
     * @param  array<string, mixed>  $g
     */
    private function priority(array $g, int $median, ?int $ruleMedian, int $material): int
    {
        $wouldFall = $ruleMedian !== null && $median > $ruleMedian + 2500;

        if ($g['unpublished'] > 0 && $wouldFall) {
            return 1;
        }

        if ($g['held'] > 0) {
            return 2;
        }

        if ($g['published'] > 0 && $wouldFall) {
            return 3;
        }

        return $material > 0 ? 4 : 5;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderHeadline(array $rows): void
    {
        $tierConsistent = count(array_filter(
            $rows,
            static fn (array $r): bool => $r['rule_bps'] !== null && abs($r['median_bps'] - $r['rule_bps']) <= 100,
        ));

        $this->info('── HEADLINE ──');

        if (! $this->branchesKnown) {
            $this->warn('  Net £ figures below are an UPPER BOUND — re-run with --with-competitor.');
            $this->line('  A rule margin is consulted ONLY on the `margin` branch. A product with');
            $this->line('  a live competitor is priced by undercut or by the floor, so changing');
            $this->line('  its rule moves NOTHING. Without competitor data every product is');
            $this->line('  counted, which overstates any group the market is already pricing.');
            $this->newLine();
        }
        $this->line(sprintf(
            '  %d of %d groups already sit within 1pp of their tier.',
            $tierConsistent,
            count($rows),
        ));
        $this->line('  Most brands are ALREADY tier-consistent — the engine has priced this');
        $this->line('  catalogue and did it consistently. What follows is a search for');
        $this->line('  DELIBERATE EXCEPTIONS, not a repair of the whole catalogue.');
        $this->newLine();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderGroups(array $rows, int $limit): void
    {
        $this->info('── DECISION TABLE ──  proposed = median of what this group ALREADY earns');
        $this->newLine();

        $this->table(
            ['Pri', 'Group', 'N', 'Pub', 'Unpub', 'Held', 'Median', 'Spread', 'Rule', 'Proposed', 'Rule-led', 'Comp-led', 'Net £', 'Moves', 'Conf', 'Rule type'],
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
                $this->pct($r['proposed_bps']).($r['contaminated'] ? ' *' : ''),
                (string) $r['rule_led'],
                $r['competitor_led'] > 0 ? (string) $r['competitor_led'] : '',
                number_format($r['net_delta'] / 100, 0),
                sprintf('%d (%d up %d dn)', $r['material'], $r['up'], $r['down']),
                $r['confidence'],
                $r['rule_type'],
            ], array_slice($rows, 0, $limit)),
        );

        if (array_filter($rows, static fn (array $r): bool => (bool) $r['contaminated']) !== []) {
            $this->line('  * proposal taken from the RULE-LED members only - the group median is');
            $this->line('    contaminated by competitor-floored products and is NOT rule-safe.');
        }

        $more = count($rows) - $limit;
        if ($more > 0) {
            $this->line("  … and {$more} more group(s). --format=csv for all.");
        }
    }

    /**
     * A mixed inferred group hides its own structure. "name:screen" swept 139
     * heterogeneous products together and reported a 28.0% median, burying the
     * TT / RAPT / MPCT families that run near 99%.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderSubBreakdowns(array $rows): void
    {
        $mixed = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['spread_bps'] > self::SUBGROUP_SPREAD_BPS
                && count($r['sub']) > 1
                && ! str_starts_with($r['key'], 'sku:'),
        ));

        if ($mixed === []) {
            return;
        }

        $this->newLine();
        $this->info('── INSIDE THE MIXED GROUPS ──  sub-families by SKU prefix');
        $this->line('  These groups do not agree with themselves, so their median describes');
        $this->line('  nobody. The sub-families are what a rule would actually have to target.');

        foreach (array_slice($mixed, 0, 6) as $r) {
            $this->newLine();
            $this->line(sprintf(
                '  %s — %d products, median %s, spread %s',
                $r['key'],
                $r['count'],
                $this->pct($r['median_bps']),
                $this->pct($r['spread_bps']),
            ));

            $this->table(
                ['SKU prefix', 'N', 'Median', 'Spread'],
                array_map(fn (array $s): array => [
                    $s['prefix'],
                    (string) $s['count'],
                    $this->pct($s['median_bps']),
                    $this->pct($s['spread_bps']),
                ], array_slice($r['sub'], 0, 12)),
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderDecisionsNeeded(array $rows): void
    {
        $buckets = [
            'pinned by 260824-w9k' => 'Protected only by a TEMPORARY override — decide before those are deleted',
            'extreme margin' => 'Margin so high it may indict the data rather than the pricing',
            'sitting on the margin floor' => 'Being FLOORED by competitors, not choosing this margin',
            'members disagree' => 'No single number describes the group',
            'median contaminated by floored members' => 'Median describes the COMPETITION, not policy — adopting it would cut the members who are not under pressure',
        ];

        $this->newLine();
        $this->warn('── DECISION NEEDED ──');

        foreach ($buckets as $reason => $why) {
            $matching = array_values(array_filter(
                $rows,
                static fn (array $r): bool => in_array($reason, $r['reasons'], true),
            ));

            if ($matching === []) {
                continue;
            }

            $this->newLine();
            $this->line('  '.strtoupper($reason));
            $this->line('  '.$why);

            $this->table(
                ['Group', 'N', 'Pub', 'Unpub', 'Median', 'Spread', 'Rule', 'Proposed', 'Net £', 'Examples'],
                array_map(fn (array $r): array => [
                    substr($r['key'], 0, 24),
                    (string) $r['count'],
                    (string) $r['published'],
                    (string) $r['unpublished'],
                    $this->pct($r['median_bps']),
                    $this->pct($r['spread_bps']),
                    $r['rule_bps'] === null ? '-' : $this->pct($r['rule_bps']),
                    $this->pct($r['proposed_bps']),
                    number_format($r['net_delta'] / 100, 0),
                    substr($r['examples'], 0, 30),
                ], array_slice($matching, 0, 12)),
            );
        }
    }

    /**
     * Product-level drill-down for ONE group. Built for brand:C2G, where four
     * cable SKUs read 396% and the question is whether that is a real cable
     * margin or a data smell.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderDetail(string $key, array $rows, int $vatBps, int $floorBps): void
    {
        $this->info(sprintf('── DETAIL: %s ──  READ-ONLY', $key));

        if ($rows === []) {
            $this->warn('  No products in that group. Keys look like brand:C2G, name:screen, sku:TT.');

            return;
        }

        usort($rows, static fn (array $a, array $b): int => $b['current_bps'] <=> $a['current_bps']);

        $margins = array_map(static fn (array $r): int => (int) $r['current_bps'], $rows);
        $median = $this->percentile($margins, 0.5);
        $spread = $this->percentile($margins, 0.75) - $this->percentile($margins, 0.25);
        $proposed = max((int) (round($median / 50) * 50), $floorBps);

        $this->newLine();
        $this->table(
            ['SKU', 'Status', 'Cost', 'Price', 'Margin', 'Its rule', 'Competitor', 'Branch', 'At proposed', 'Held'],
            array_map(function (array $r) use ($proposed, $vatBps): array {
                $atProposed = $this->calculator->compute((int) $r['buy'], $proposed, $vatBps);

                return [
                    $r['sku'],
                    $r['status'],
                    number_format($r['buy'] / 100, 2),
                    number_format($r['sell'] / 100, 2),
                    $this->pct((int) $r['current_bps']),
                    $r['rule_bps'] === null ? '-' : $this->pct((int) $r['rule_bps']),
                    $r['competitor'] === null ? 'none' : number_format($r['competitor'] / 100, 2),
                    $this->branchLabel($r),
                    number_format($atProposed / 100, 2),
                    $r['held'] ? 'yes' : '',
                ];
            }, array_slice($rows, 0, 40)),
        );

        $withComp = count(array_filter($rows, static fn (array $r): bool => $r['competitor'] !== null));
        [$net, $material, $up, $down] = $this->impact(
            array_map(static fn (array $r): array => ['buy' => $r['buy'], 'sell' => $r['sell'], 'sku' => $r['sku']], $rows),
            $proposed,
            $vatBps,
        );

        $this->newLine();
        $this->line(sprintf('  %d product(s). Median margin %s, spread %s.', count($rows), $this->pct($median), $this->pct($spread)));
        $this->line(sprintf('  Competitor data on %d of %d.', $withComp, count($rows)));
        $this->line(sprintf(
            '  A group rule at %s would move list price by £%s net (%d material: %d up, %d down).',
            $this->pct($proposed),
            number_format($net / 100, 2),
            $material,
            $up,
            $down,
        ));

        $this->newLine();
        $this->line('  Internally consistent?  a tight spread means the group agrees with itself.');
        $this->line('  Competitor evidence?    "none" everywhere means nothing external corroborates');
        $this->line('                          the price, so the margin rests on your own cost alone.');
        $this->line('  Data smell?             a very low cost with a very high percentage is normal');
        $this->line('                          for accessories and alarming for hardware. The COST');
        $this->line('                          column is the one to sanity-check against the part.');
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
        $this->line('  not a pricing policy. Fix the data, then re-run this report.');
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
        $net = array_sum(array_map(static fn (array $r): int => (int) $r['net_delta'], $rows));

        $this->newLine();
        $this->line(sprintf('Scanned %d product(s) with a usable cost and price; %d group(s) above the size threshold.', $scanned, count($rows)));
        $ruleLed = array_sum(array_map(static fn (array $r): int => (int) $r['rule_led'], $rows));
        $compLed = array_sum(array_map(static fn (array $r): int => (int) $r['competitor_led'], $rows));

        $this->line(sprintf(
            'Adopting EVERY proposal would move list price by £%s net%s.',
            number_format($net / 100, 2),
            $this->branchesKnown ? '' : '   (UPPER BOUND — see above)',
        ));

        if ($this->branchesKnown) {
            $this->line(sprintf(
                '  %d product(s) are RULE-LED and would actually move; %d are COMPETITOR-LED',
                $ruleLed,
                $compLed,
            ));
            $this->line('  (undercut or floored), and a rule change cannot reach those at all.');
        }
        $this->newLine();
        $this->line('Read a proposal as "what keeps today\'s prices roughly where they are".');
        $this->line('Whether a family SHOULD earn its historical margin is commercial — no query');
        $this->line('answers that. Nothing was written; no rule, price, override or product changed.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function emitCsv(array $rows): void
    {
        $this->line('priority,group,basis,count,published,unpublished,held,median_pct,spread_pct,rule_pct,proposed_pct,rule_led_median_pct,contaminated,rule_led,competitor_led,net_delta_pounds,material_moves,up,down,competitor_median_pct,confidence,rule_type,reasons,examples');

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
                $r['rule_led_median_bps'] === null ? '' : number_format($r['rule_led_median_bps'] / 100, 2, '.', ''),
                $r['contaminated'] ? 'yes' : 'no',
                $r['rule_led'],
                $r['competitor_led'],
                number_format($r['net_delta'] / 100, 2, '.', ''),
                $r['material'],
                $r['up'],
                $r['down'],
                $r['competitor_median_bps'] === null ? '' : number_format($r['competitor_median_bps'] / 100, 2, '.', ''),
                $r['confidence'],
                '"'.$r['rule_type'].'"',
                '"'.implode('; ', $r['reasons']).'"',
                '"'.str_replace('"', '""', $r['examples']).'"',
            ]));
        }
    }

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

        return (int) $values[(int) floor($p * (count($values) - 1))];
    }

    /**
     * Which branch prices this product TODAY. Every C2G SKU came back
     * `undercut`, sitting exactly 1p below its competitor, which settled in a
     * single glance that 396% was never a margin decision at all.
     *
     * @param  array<string, mixed>  $r
     */
    private function branchLabel(array $r): string
    {
        $source = (string) $this->pricer->decide(
            (int) $r['buy'],
            $r['competitor'] === null ? null : (int) $r['competitor'],
            (int) ($r['rule_bps'] ?? 0),
            (int) config('competitor.beat_by_pennies', 1),
            (int) config('competitor.min_margin_floor_bps', 600),
            (int) config('pricing.vat_basis_points', 2000),
        )['source'];

        return match ($source) {
            'competitor_undercut' => 'undercut',
            'competitor_floor' => 'floored',
            default => 'RULE-LED',
        };
    }

    private function pct(int $bps): string
    {
        return number_format($bps / 100, 1).'%';
    }
}
