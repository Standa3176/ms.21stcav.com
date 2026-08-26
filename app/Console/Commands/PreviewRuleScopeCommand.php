<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Services\CeilingBlockClassifier;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Products\Models\Product;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260826-rsp — what would a PricingRule at this scope actually reach?
 *
 * READ-ONLY. Creates no rule, changes no price, assigns no taxonomy. It answers
 * the questions that must be settled BEFORE a rule exists, because a rule's
 * blast radius is invisible until it is written and then it is too late.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE QUESTION IT EXISTS FOR
 *
 * The RAPT (21) and MPCT (8) projection-screen families run 98.9% with a 0.0%
 * spread and adopting that moves £0.69 across all 29 — a textbook exception.
 * But they carry NO brand and NO category, so no rule can reach them until
 * taxonomy is assigned, and the moment taxonomy IS assigned the rule reaches
 * everything else sharing that term too.
 *
 * That is the danger. A brand rule at 98.9% is correct for a premium screen and
 * catastrophic for a cable that happens to share the brand. So before writing
 * anything:
 *
 *   - which products would the scope actually catch?
 *   - are any of them OUTSIDE the family we meant?
 *   - would any PUBLISHED price move?
 *   - what is the worst single move?
 *
 * --expect-prefix names the families the rule is FOR. Anything the scope
 * catches outside them is reported as UNRELATED, which is the check that turns
 * "this looks right" into "this is right".
 *
 *   php artisan pricing:preview-rule-scope --brand-id=1234 --margin-bps=9890 --expect-prefix=RAPT,MPCT
 *   php artisan pricing:preview-rule-scope --category-id=99 --margin-bps=9890 --expect-prefix=RAPT,MPCT
 *   php artisan pricing:preview-rule-scope --brand-id=1234 --category-id=99 --margin-bps=9890
 */
final class PreviewRuleScopeCommand extends BaseCommand
{
    /** A move beyond this is not "recording current pricing" any more. */
    private const MOVE_TOLERANCE = 0.01;

    protected $signature = 'pricing:preview-rule-scope
        {--brand-id= : Woo brand term id the rule would target}
        {--category-id= : Woo category term id the rule would target}
        {--margin-bps= : The margin the rule would carry (required)}
        {--expect-prefix= : Comma-separated SKU prefixes the rule is FOR; anything else is flagged UNRELATED}
        {--limit=60 : Products to list}';

    protected $description = 'READ-ONLY preview of what a PricingRule at a given scope would reach, and what it would do to prices (260826-rsp).';

    public function __construct(private readonly PriceCalculator $calculator)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $brandId = ($b = (int) $this->option('brand-id')) > 0 ? $b : null;
        $categoryId = ($c = (int) $this->option('category-id')) > 0 ? $c : null;
        $marginBps = (int) $this->option('margin-bps');
        $limit = max(1, (int) $this->option('limit'));
        $vatBps = (int) config('pricing.vat_basis_points', 2000);

        if ($marginBps <= 0) {
            $this->error('--margin-bps is required, e.g. --margin-bps=9890 for 98.9%.');

            return SymfonyCommand::FAILURE;
        }

        if ($brandId === null && $categoryId === null) {
            $this->error('Give --brand-id and/or --category-id. A rule with neither is a default tier, not a scope.');

            return SymfonyCommand::FAILURE;
        }

        $expected = array_values(array_filter(array_map(
            static fn (string $p): string => strtoupper(trim($p)),
            explode(',', (string) $this->option('expect-prefix')),
        ), static fn (string $p): bool => $p !== ''));

        [$scope, $label] = $this->scopeFor($brandId, $categoryId);

        $this->info('Rule scope preview — READ-ONLY. No rule, price or taxonomy is changed.');
        $this->newLine();
        $this->line(sprintf('  Scope:  %s  (%s)', $scope, $label));
        $this->line(sprintf('  Margin: %s', $this->pct($marginBps)));
        $this->line(sprintf('  Layer:  %s', $this->layerNote($scope)));

        $query = Product::query()
            ->whereNotNull('buy_price')
            ->where('buy_price', '>', 0)
            ->whereNotNull('sell_price')
            ->where('sell_price', '>', 0);

        if ($brandId !== null) {
            $query->where('brand_id', $brandId);
        }
        if ($categoryId !== null) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->orderBy('sku')->get();

        $this->newLine();
        if ($products->isEmpty()) {
            $this->warn('  This scope currently reaches NO products.');
            $this->line('  If taxonomy has not been assigned yet that is expected — assign it, then');
            $this->line('  re-run this before creating the rule. A rule written against an empty');
            $this->line('  scope is a rule nobody can see the effect of.');

            return SymfonyCommand::SUCCESS;
        }

        $rows = [];
        $unrelated = 0;
        $publishedMoved = 0;
        $net = 0;
        $worst = 0.0;

        foreach ($products as $product) {
            $buy = (int) round(((float) $product->buy_price) * 100);
            $sell = (int) round(((float) $product->sell_price) * 100);
            $new = $this->calculator->compute($buy, $marginBps, $vatBps);
            $delta = $new - $sell;
            $net += $delta;

            $move = $sell > 0 ? abs($delta) / $sell : 0.0;
            $worst = max($worst, $move);

            $inFamily = $expected === [] || $this->matchesPrefix((string) $product->sku, $expected);
            if (! $inFamily) {
                $unrelated++;
            }

            if ((string) $product->status === 'publish' && $move > 0.0001) {
                $publishedMoved++;
            }

            $rows[] = [
                'sku' => (string) $product->sku,
                'status' => (string) $product->status,
                'buy' => $buy,
                'sell' => $sell,
                'new' => $new,
                'delta' => $delta,
                'margin' => CeilingBlockClassifier::currentMarginBps($buy, $sell, $vatBps),
                'unrelated' => ! $inFamily,
            ];
        }

        // Anything the scope catches that we did NOT mean to catch is the whole
        // point of the check, so it sorts to the top.
        usort($rows, static fn (array $a, array $b): int => [$b['unrelated'], abs($b['delta'])] <=> [$a['unrelated'], abs($a['delta'])]);

        $this->renderProducts($rows, $limit);
        $this->renderVerdict($rows, $scope, $marginBps, $unrelated, $publishedMoved, $net, $worst, $brandId, $categoryId);

        return ($unrelated > 0 || $publishedMoved > 0 || $worst > self::MOVE_TOLERANCE)
            ? SymfonyCommand::FAILURE
            : SymfonyCommand::SUCCESS;
    }

    /**
     * @param  array<int, string>  $expected
     */
    private function matchesPrefix(string $sku, array $expected): bool
    {
        $upper = strtoupper(trim($sku));

        foreach ($expected as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function scopeFor(?int $brandId, ?int $categoryId): array
    {
        if ($brandId !== null && $categoryId !== null) {
            return [PricingRule::SCOPE_BRAND_CATEGORY, sprintf('brand #%d + category #%d', $brandId, $categoryId)];
        }

        return $brandId !== null
            ? [PricingRule::SCOPE_BRAND, sprintf('brand #%d', $brandId)]
            : [PricingRule::SCOPE_CATEGORY, sprintf('category #%d', $categoryId)];
    }

    private function layerNote(string $scope): string
    {
        return match ($scope) {
            PricingRule::SCOPE_BRAND_CATEGORY => 'layer 1 — most specific, beats category and brand rules',
            PricingRule::SCOPE_CATEGORY => 'layer 2 — beats a brand rule, loses to brand+category',
            default => 'layer 3 — the broadest scope, loses to both of the above',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderProducts(array $rows, int $limit): void
    {
        $this->table(
            ['SKU', 'Status', 'Cost', 'Price now', 'Margin now', 'Under rule', 'Move', ''],
            array_map(function (array $r): array {
                return [
                    $r['sku'],
                    $r['status'],
                    number_format($r['buy'] / 100, 2),
                    number_format($r['sell'] / 100, 2),
                    $r['margin'] === null ? '-' : number_format($r['margin'] / 100, 1).'%',
                    number_format($r['new'] / 100, 2),
                    $r['delta'] === 0 ? '-' : sprintf('%+.2f', $r['delta'] / 100),
                    $r['unrelated'] ? 'UNRELATED' : '',
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
     */
    private function renderVerdict(
        array $rows,
        string $scope,
        int $marginBps,
        int $unrelated,
        int $publishedMoved,
        int $net,
        float $worst,
        ?int $brandId,
        ?int $categoryId,
    ): void {
        $published = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'publish'));

        $this->newLine();
        $this->line(sprintf('  Reaches %d product(s): %d published, %d not.', count($rows), $published, count($rows) - $published));
        $this->line(sprintf('  Published products whose price WOULD MOVE: %d', $publishedMoved));
        $this->line(sprintf('  Outside the expected families: %d', $unrelated));
        $this->line(sprintf('  Net list-price change: £%s, worst single move %.2f%%.', number_format($net / 100, 2), $worst * 100));

        $this->newLine();

        $problems = [];
        if ($unrelated > 0) {
            $problems[] = sprintf('%d product(s) outside the families this rule is for', $unrelated);
        }
        if ($publishedMoved > 0) {
            $problems[] = sprintf('%d PUBLISHED price(s) would move', $publishedMoved);
        }
        if ($worst > self::MOVE_TOLERANCE) {
            $problems[] = sprintf('worst move %.2f%% exceeds the 1%% tolerance', $worst * 100);
        }

        if ($problems === []) {
            $this->info('  SAFE — this scope reaches only the intended families and moves nothing.');
            $this->renderRuleRow($scope, $marginBps, $brandId, $categoryId);

            return;
        }

        $this->error('  NOT SAFE at this scope:');
        foreach ($problems as $problem) {
            $this->line('    - '.$problem);
        }

        $this->newLine();
        $this->line('  A narrower scope is the usual answer: brand+category beats category, which');
        $this->line('  beats brand. If the brand covers products that should NOT carry this');
        $this->line('  margin, the brand alone is the wrong scope however convenient it looks.');
    }

    private function renderRuleRow(string $scope, int $marginBps, ?int $brandId, ?int $categoryId): void
    {
        $this->newLine();
        $this->line('  THE RULE THIS WOULD BE — nothing below has been created:');
        $this->line(sprintf('    scope               %s', $scope));
        $this->line(sprintf('    brand_id            %s', $brandId ?? 'null'));
        $this->line(sprintf('    category_id         %s', $categoryId ?? 'null'));
        $this->line(sprintf('    margin_basis_points %d   (%s)', $marginBps, $this->pct($marginBps)));
        $this->line('    is_default_tier     false');
        $this->line('    active              true');
        $this->newLine();
        $this->line('  Creating it is a deliberate, approved step — this command will not do it.');
    }

    private function pct(int $bps): string
    {
        return number_format($bps / 100, 1).'%';
    }
}
