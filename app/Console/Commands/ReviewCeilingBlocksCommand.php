<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Services\CeilingBlockClassifier;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260825-t4m — review margin-ceiling blocks by CASH, not percentage.
 *
 * READ-ONLY. No writes, no events, no Woo calls.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY PERCENTAGE IS THE WRONG LENS
 *
 * The 50% margin ceiling (config competitor.max_margin_ceiling_bps) was added
 * after the 2026-08-09 incident: a competitor feed error repriced SKU 9C941AA
 * absurdly, and blocking implausible margins is the right instinct.
 *
 * But a percentage cannot tell "the competitor feed is wrong" from "this is a
 * cheap accessory". Observed 2026-08-25:
 *
 *   47590    cost £1.16    competitor £2.22    58.62%  ← normal retail markup
 *   1001072  cost £275.51  competitor £576.67  74.42%  ← ~£300/unit, worth a look
 *
 * Both are blocked identically. The first is noise that clogs review; the
 * second is real money declined. Percentage screams on cables; POUNDS tell you
 * where to care.
 *
 * So this ranks blocks by the cash margin actually forgone per unit, and prints
 * a threshold table showing how many blocks survive at each cash floor — which
 * is the evidence needed to decide whether the guard should become
 * "margin > ceiling AND cash uplift > £X" rather than percentage alone.
 *
 * Suggestions are deduped per SKU while PENDING, so this table accumulates
 * every SKU ever blocked. `blocked_at` in the evidence is refreshed on each
 * block, so --since separates today's live blocks from historical ones whose
 * competitor price has since moved or aged out of the 30-day window.
 *
 *   php artisan pricing:review-ceiling-blocks
 *   php artisan pricing:review-ceiling-blocks --since=2026-08-25
 *   php artisan pricing:review-ceiling-blocks --min-cash=100 --limit=0
 */
final class ReviewCeilingBlocksCommand extends BaseCommand
{
    /** Cash floors (in pounds) for the policy-calibration table. */
    private const THRESHOLDS = [0, 5, 25, 50, 100, 250, 500];

    protected $signature = 'pricing:review-ceiling-blocks
        {--since= : Only blocks recorded on/after this date (Y-m-d), by evidence.blocked_at}
        {--min-cash=0 : Only show blocks worth at least this many pounds per unit}
        {--published-only : Only products currently live on the storefront}
        {--limit=25 : Rows to print (0 = all)}
        {--severity= : Only this severity (cost_fault|competitor_fault|review|noise|no_upside). data_fault is accepted and matches BOTH fault types, including legacy rows.}
        {--include-noise : Also show noise and no-upside blocks (hidden by default)}';

    protected $description = 'READ-ONLY review of margin-ceiling blocks ranked by cash opportunity, with a threshold table for setting guard policy (260825-t4m).';

    public function __construct(private readonly RuleResolver $resolver)
    {
        parent::__construct();
    }

    /**
     * The margin a product's rules WOULD give it, or null when nothing
     * matches. Used only to exempt a deliberately-pinned line from being
     * called a cost fault — never to change a price.
     */
    private function ruleMarginFor(Product $product): ?int
    {
        try {
            return (int) $this->resolver->resolve($product)->marginBasisPoints;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function perform(): int
    {
        $since = (string) $this->option('since');
        $minCash = (float) $this->option('min-cash');
        $severityFilter = (string) $this->option('severity');
        // 260825-z8q — data_fault predates the cost/competitor split. Existing
        // runbooks pass it, so it is kept as an ALIAS matching both new types
        // plus any legacy row that could not be attributed. Failing here would
        // break a command an operator already has in their history.
        $severityMatches = $severityFilter === CeilingBlockClassifier::DATA_FAULT
            ? CeilingBlockClassifier::FAULT_SEVERITIES
            : ($severityFilter === '' ? [] : [$severityFilter]);
        $includeNoise = (bool) $this->option('include-noise') || $severityFilter !== '';
        $classifier = CeilingBlockClassifier::fromConfig();
        $suppressed = 0;
        $severityCounts = [];
        $publishedOnly = (bool) $this->option('published-only');
        $limit = max(0, (int) $this->option('limit'));
        $vatBps = (int) config('pricing.vat_basis_points', 2000);
        $vatDivisor = 1 + ($vatBps / 10000);
        $ceilingBps = (int) config('competitor.max_margin_ceiling_bps', 5000);

        $suggestions = Suggestion::query()
            ->where('kind', 'competitor_price_ceiling_blocked')
            ->where('status', Suggestion::STATUS_PENDING)
            ->get();

        if ($suggestions->isEmpty()) {
            $this->info('No pending margin-ceiling blocks.');

            return SymfonyCommand::SUCCESS;
        }

        $rows = [];
        $skipped = 0;

        foreach ($suggestions as $suggestion) {
            $evidence = (array) $suggestion->evidence;
            $sku = (string) ($evidence['sku'] ?? '');
            $blockedAt = substr((string) ($evidence['blocked_at'] ?? ''), 0, 10);

            if ($sku === '' || ($since !== '' && $blockedAt < $since)) {
                $skipped++;

                continue;
            }

            $product = Product::where('sku', $sku)->first();
            if ($product === null) {
                $skipped++;

                continue;
            }

            if ($publishedOnly && (string) $product->status !== 'publish') {
                $skipped++;

                continue;
            }

            $cost = (int) ($evidence['buy_price_pennies'] ?? 0);
            $proposed = (int) ($evidence['proposed_sell_price_pennies'] ?? 0);
            $marginBps = (int) ($evidence['effective_margin_bps'] ?? 0);
            $current = $product->sell_price === null
                ? 0
                : (int) round(((float) $product->sell_price) * 100);

            // Cash forgone per unit: the EX-VAT gap between what the competitor
            // would let us charge and what we charge. VAT is not margin.
            // Prefer the cash recorded AT BLOCK TIME: the current price may
            // have moved since, and the block was judged against the price
            // as it then stood. Legacy rows predate the key, so fall back to
            // computing it — that is what --since exists to separate.
            $cashPence = array_key_exists('cash_uplift_pence', $evidence)
                ? (int) $evidence['cash_uplift_pence']
                : (int) round((($proposed - $current) / $vatDivisor));

            // Our own cost-to-price relationship TODAY — the discriminator
            // between a broken cost and a broken competitor row.
            $currentMarginBps = array_key_exists('current_margin_bps', $evidence)
                && $evidence['current_margin_bps'] !== null
                ? (int) $evidence['current_margin_bps']
                : CeilingBlockClassifier::currentMarginBps($cost, $current, $vatBps);

            $stored = (string) ($evidence['severity'] ?? '');

            // A stored data_fault is UNREFINED, not final: it was recorded
            // before the split existed. Re-classify it so the 37 faults
            // already on file split into the two piles with opposite fixes.
            $severity = ($stored === '' || $stored === CeilingBlockClassifier::DATA_FAULT)
                ? $classifier->classify($marginBps, $cashPence, $currentMarginBps, $this->ruleMarginFor($product), $cost)
                : $stored;
            $severityCounts[$severity] = ($severityCounts[$severity] ?? 0) + 1;

            if ($severityMatches !== [] && ! in_array($severity, $severityMatches, true)) {
                $skipped++;

                continue;
            }

            // Noise and no-upside are recorded but hidden: 20 of 48 published
            // blocks on 2026-08-25 were worth GBP 0.00 between them, and they
            // were the reason the nine that mattered went unread.
            if (! $includeNoise && ! CeilingBlockClassifier::isActionable($severity)) {
                $suppressed++;

                continue;
            }

            $rows[] = [
                'severity' => $severity,
                'sku' => $sku,
                'status' => (string) $product->status,
                'cost' => $cost,
                'current' => $current,
                'proposed' => $proposed,
                'margin_bps' => $marginBps,
                'current_margin_bps' => $currentMarginBps,
                'cash' => $cashPence,
                'blocked_at' => $blockedAt,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['cash'] <=> $a['cash']);

        $this->renderThresholds($rows, $ceilingBps);

        $shown = array_values(array_filter(
            $rows,
            static fn (array $r): bool => ($r['cash'] / 100) >= $minCash,
        ));

        $this->renderRows($shown, $limit);
        $this->renderTotals($rows, $shown, $skipped, $minCash, $severityCounts);

        if ($suppressed > 0) {
            $this->line(sprintf(
                '  %d noise / no-upside block(s) hidden — pass --include-noise to see them.',
                $suppressed,
            ));
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * The policy question, answered with data: if the guard also required a
     * minimum CASH uplift, how many blocks would remain at each floor?
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderThresholds(array $rows, int $ceilingBps): void
    {
        $this->info(sprintf(
            'Margin-ceiling blocks — ceiling %.2f%%, ranked by cash forgone per unit (ex VAT).',
            $ceilingBps / 100,
        ));
        $this->newLine();

        $table = [];
        foreach (self::THRESHOLDS as $floor) {
            $matching = array_filter($rows, static fn (array $r): bool => ($r['cash'] / 100) >= $floor);
            $cash = array_sum(array_map(static fn (array $r): int => $r['cash'], $matching));

            $table[] = [
                $floor === 0 ? 'any' : '>= £'.number_format($floor),
                (string) count($matching),
                '£'.number_format($cash / 100, 2),
            ];
        }

        $this->line('  If the guard ALSO required a minimum cash uplift:');
        $this->table(['Cash floor', 'Blocks remaining', 'Total cash/unit'], $table);
        $this->line('  Rows falling away as the floor rises are the cheap-accessory noise —');
        $this->line('  a high percentage on a small absolute number.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderRows(array $rows, int $limit): void
    {
        if ($rows === []) {
            $this->newLine();
            $this->warn('No blocks at or above that cash floor.');

            return;
        }

        $slice = $limit > 0 ? array_slice($rows, 0, $limit) : $rows;

        $this->newLine();
        $this->table(
            ['SKU', 'Severity', 'Status', 'Cost', 'Now', 'Our margin', 'Competitor-led', 'Margin', 'Cash/unit', 'Blocked'],
            array_map(static fn (array $r): array => [
                $r['sku'],
                CeilingBlockClassifier::label((string) ($r['severity'] ?? CeilingBlockClassifier::REVIEW)),
                $r['status'],
                number_format($r['cost'] / 100, 2),
                number_format($r['current'] / 100, 2),
                $r['current_margin_bps'] === null ? '-' : number_format($r['current_margin_bps'] / 100, 1).'%',
                number_format($r['proposed'] / 100, 2),
                number_format($r['margin_bps'] / 100, 1).'%',
                '£'.number_format($r['cash'] / 100, 2),
                $r['blocked_at'],
            ], $slice),
        );

        $more = count($rows) - count($slice);
        if ($more > 0) {
            $this->line("  … and {$more} more. Pass --limit=0 for the full list.");
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $all
     * @param  array<int, array<string, mixed>>  $shown
     */
    private function renderTotals(array $all, array $shown, int $skipped, float $minCash, array $severityCounts = []): void
    {
        $total = array_sum(array_map(static fn (array $r): int => $r['cash'], $all));
        $shownCash = array_sum(array_map(static fn (array $r): int => $r['cash'], $shown));
        $published = array_filter($all, static fn (array $r): bool => $r['status'] === 'publish');
        $publishedCash = array_sum(array_map(static fn (array $r): int => $r['cash'], $published));

        $this->newLine();
        if ($severityCounts !== []) {
            // Emitted as a LINE, not only as a table column: this is the
            // headline an operator reads, and it makes the triage assertable.
            $parts = [];
            foreach ([CeilingBlockClassifier::COST_FAULT, CeilingBlockClassifier::COMPETITOR_FAULT, CeilingBlockClassifier::DATA_FAULT, CeilingBlockClassifier::REVIEW, CeilingBlockClassifier::NOISE, CeilingBlockClassifier::NO_UPSIDE] as $sev) {
                $parts[] = ($severityCounts[$sev] ?? 0).' '.CeilingBlockClassifier::label($sev);
            }
            $this->line('  Severity: '.implode(', ', $parts));
        }
        $this->line(sprintf('%d block(s) examined, %d skipped (filtered, or product no longer exists).', count($all), $skipped));
        $this->line(sprintf('  Total cash per unit across all blocks:        £%s', number_format($total / 100, 2)));
        $this->line(sprintf('  Of which on PUBLISHED products (%d):          £%s', count($published), number_format($publishedCash / 100, 2)));

        if ($minCash > 0) {
            $this->line(sprintf('  At the £%s floor (%d shown):                   £%s', number_format($minCash, 2), count($shown), number_format($shownCash / 100, 2)));
        }

        $this->newLine();
        $this->line('Per UNIT, not per year — multiply by volume before drawing conclusions.');
        $this->line('A block is not automatically an opportunity: the competitor price may be');
        $this->line('wrong, which is exactly what the ceiling exists to catch. Check the big');
        $this->line('ones against the competitor site before changing any price.');
    }
}
