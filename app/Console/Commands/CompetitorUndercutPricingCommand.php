<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Events\ProductPriceChanged;
use App\Domain\Pricing\Exceptions\NoPricingRuleMatchedException;
use App\Domain\Pricing\Services\CompetitorUndercutPricer;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Competitor-undercut pricing (core app loop, step 1).
 *
 * For each simple product with a supplier cost:
 *   - take the LOWEST CURRENT competitor gross price (latest row per competitor,
 *     within --max-age-days) and set our sell price `undercut` pennies below it,
 *     floored at cost + the min-margin floor (never sell at a loss);
 *   - if the SKU is on NO competitor, fall back to the cost-plus rule margin
 *     (the "set margin" case) via the Phase 3 RuleResolver + PriceCalculator.
 *
 * Writes products.sell_price (VAT-inclusive, like the pricing engine) and
 * dispatches ProductPriceChanged on a real change — so a later gated listener
 * can push the price to Woo. NEVER calls Woo itself.
 *
 * DRY-RUN BY DEFAULT (cross-cutting invariant) — pass --live to write.
 *
 *   php artisan pricing:undercut-competitors                 (dry-run, whole catalogue)
 *   php artisan pricing:undercut-competitors --skus=A,B      (dry-run, subset)
 *   php artisan pricing:undercut-competitors --live          (write)
 */
final class CompetitorUndercutPricingCommand extends BaseCommand
{
    protected $signature = 'pricing:undercut-competitors
        {--skus= : Comma-separated SKUs (optional; default = all simple products with a buy price)}
        {--undercut-pennies= : Pennies below the lowest competitor (default: config competitor.beat_by_pennies)}
        {--max-age-days=30 : Ignore competitor prices older than this many days}
        {--limit=0 : Cap products processed (0 = no cap)}
        {--live : Write sell_price + dispatch ProductPriceChanged (default: dry-run, no writes)}';

    protected $description = 'Price each product just below its lowest current competitor; if on no competitor, apply the set cost-plus margin. Dry-run by default; --live writes.';

    /** @var array<string,int> */
    private array $stats = [
        'competitor_undercut' => 0,
        'competitor_floor' => 0,
        'margin' => 0,
        'unchanged' => 0,
        'changed' => 0,
        'skipped' => 0,
        'blocked_ceiling' => 0,
    ];

    public function __construct(
        private readonly CompetitorUndercutPricer $pricer,
        private readonly RuleResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $live = (bool) $this->option('live');
        $undercut = ($u = (int) $this->option('undercut-pennies')) > 0
            ? $u
            : (int) config('competitor.beat_by_pennies', 1);
        $minFloorBps = (int) config('competitor.min_margin_floor_bps', 500);
        $ceilingBps = (int) config('competitor.max_margin_ceiling_bps', 5000);
        $vatBps = (int) config('pricing.vat_basis_points', 2000);
        $maxAgeDays = max(1, (int) $this->option('max-age-days'));
        $cutoff = now()->subDays($maxAgeDays);
        $limit = max(0, (int) $this->option('limit'));

        $skus = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('skus')),
        ), static fn (string $s): bool => $s !== ''));

        $this->info(sprintf(
            '%sCompetitor-undercut pricing — undercut %dp, floor %.2f%% margin, competitor prices ≤ %dd old%s.',
            $live ? 'LIVE — ' : 'DRY-RUN — ',
            $undercut,
            $minFloorBps / 100,
            $maxAgeDays,
            $skus !== [] ? ' ['.count($skus).' SKU(s)]' : '',
        ));

        $query = Product::query()
            ->where('type', 'simple')
            ->whereNotNull('buy_price')
            ->where('buy_price', '>', 0);
        if ($skus !== []) {
            $query->whereIn('sku', $skus);
        }

        $processed = 0;
        $stop = false;
        $query->orderBy('id')->chunkById(200, function ($products) use (&$processed, &$stop, $live, $undercut, $minFloorBps, $ceilingBps, $vatBps, $cutoff, $limit): bool {
            foreach ($products as $product) {
                if ($limit > 0 && $processed >= $limit) {
                    $stop = true;

                    return false;
                }
                $processed++;
                $this->priceOne($product, $live, $undercut, $minFloorBps, $ceilingBps, $vatBps, $cutoff);
            }

            return ! $stop;
        });

        $this->newLine();
        $this->info(sprintf(
            '%s — %d changed (%d undercut, %d floored, %d margin), %d unchanged, %d skipped, %d blocked (margin ceiling) of %d processed.',
            $live ? 'Done' : 'DRY-RUN complete',
            $this->stats['changed'],
            $this->stats['competitor_undercut'],
            $this->stats['competitor_floor'],
            $this->stats['margin'],
            $this->stats['unchanged'],
            $this->stats['skipped'],
            $this->stats['blocked_ceiling'],
            $processed,
        ));
        if (! $live && $this->stats['changed'] > 0) {
            $this->line('Re-run with --live to write these prices.');
        }

        return SymfonyCommand::SUCCESS;
    }

    private function priceOne(Product $product, bool $live, int $undercut, int $minFloorBps, int $ceilingBps, int $vatBps, Carbon $cutoff): void
    {
        $sku = trim((string) $product->sku);
        if ($sku === '') {
            $this->stats['skipped']++;

            return;
        }
        $buyPennies = (int) round(((float) $product->buy_price) * 100);
        if ($buyPennies <= 0) {
            $this->stats['skipped']++;

            return;
        }

        $lowest = $this->lowestCurrentCompetitorGross($sku, $cutoff);

        // No competitor → need a rule margin; skip if the catalogue has no rule.
        $ruleMarginBps = 0;
        if ($lowest === null) {
            try {
                $ruleMarginBps = (int) $this->resolver->resolve($product)->marginBasisPoints;
            } catch (NoPricingRuleMatchedException) {
                $this->stats['skipped']++;
                $this->line("  {$sku}: on no competitor + no pricing rule matched — skipped");

                return;
            }
        }

        $decision = $this->pricer->decide($buyPennies, $lowest, $ruleMarginBps, $undercut, $minFloorBps, $vatBps);

        // ── Guard 1 (2026-08-09 incident response) ── a competitor-driven price
        // whose resulting margin blows past the ceiling is a near-certain feed
        // error, not a real pricing opportunity. Block the write/dispatch and
        // file a review Suggestion instead — fires in BOTH dry-run and --live,
        // since this is a detection mechanism, not a price write.
        if (($decision['source'] === 'competitor_undercut' || $decision['source'] === 'competitor_floor')
            && (int) $decision['effective_margin_bps'] > $ceilingBps) {
            $this->stats['blocked_ceiling']++;
            $this->line(sprintf(
                '  %s  BLOCKED (margin ceiling)  buy £%s  proposed £%s  margin %s%%  vs competitor £%s',
                str_pad($sku, 16),
                number_format($buyPennies / 100, 2),
                number_format(((int) $decision['final_pennies']) / 100, 2),
                number_format(((int) $decision['effective_margin_bps']) / 100, 2),
                $lowest !== null ? number_format($lowest / 100, 2) : 'n/a',
            ));
            $this->recordCeilingBlockedSuggestion(
                $product,
                $sku,
                $buyPennies,
                (int) $decision['final_pennies'],
                (int) $decision['effective_margin_bps'],
                $lowest,
                $ceilingBps,
            );

            return;
        }

        $newPennies = (int) $decision['final_pennies'];
        $source = (string) $decision['source'];
        $oldPennies = $product->sell_price === null ? 0 : (int) round(((float) $product->sell_price) * 100);

        $this->stats[$source] = ($this->stats[$source] ?? 0) + 1;

        if ($newPennies === $oldPennies) {
            $this->stats['unchanged']++;

            return;
        }

        $this->stats['changed']++;
        $this->line(sprintf(
            '  %s  %-19s  £%s → £%s%s',
            str_pad($sku, 16),
            $source,
            number_format($oldPennies / 100, 2),
            number_format($newPennies / 100, 2),
            $lowest !== null ? '   (lowest comp £'.number_format($lowest / 100, 2).')' : '',
        ));

        if ($live) {
            $product->forceFill([
                'sell_price' => number_format($newPennies / 100, 4, '.', ''),
            ])->saveQuietly();

            ProductPriceChanged::dispatch(
                (int) $product->id,
                null,
                $sku,
                $oldPennies,
                $newPennies,
                (int) $decision['effective_margin_bps'],
                $source,
            );
        }
    }

    /**
     * Lowest CURRENT competitor gross price (pennies) for a SKU: the latest row
     * per competitor (within the freshness window), then the minimum across
     * competitors. Null when no fresh competitor data exists. Matches on the
     * competitor row's sku OR mpn (mirrors how the feeds are keyed).
     */
    private function lowestCurrentCompetitorGross(string $sku, Carbon $cutoff): ?int
    {
        $rows = CompetitorPrice::query()
            ->where(static fn ($q) => $q->where('sku', $sku)->orWhere('mpn', $sku))
            ->where('recorded_at', '>=', $cutoff)
            ->orderByDesc('recorded_at')
            ->get(['competitor_id', 'price_pennies_gross', 'recorded_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        $latestPerCompetitor = [];
        foreach ($rows as $row) {
            $cid = (int) $row->competitor_id;
            if (! array_key_exists($cid, $latestPerCompetitor)) {
                $latestPerCompetitor[$cid] = (int) $row->price_pennies_gross; // first seen = latest (desc sort)
            }
        }

        $positive = array_filter($latestPerCompetitor, static fn (int $p): bool => $p > 0);

        return $positive === [] ? null : min($positive);
    }

    /**
     * Guard 1 (2026-08-09 incident response) — informational-only review flag,
     * no registered SuggestionApplier (same shape as the existing
     * auto_create_failed / crm_push_failed / quote_push_failed kinds). Keyed
     * on (kind, evidence->sku, PENDING status) — same updateOrCreate-style
     * dedup pattern OrphanDetector::record() already uses in this codebase, so
     * a still-anomalous SKU refreshes one row instead of piling up duplicates.
     */
    private function recordCeilingBlockedSuggestion(
        Product $product,
        string $sku,
        int $buyPennies,
        int $proposedPennies,
        int $marginBps,
        ?int $competitorGrossPennies,
        int $ceilingBps,
    ): void {
        $evidence = [
            'sku' => $sku,
            'buy_price_pennies' => $buyPennies,
            'proposed_sell_price_pennies' => $proposedPennies,
            'effective_margin_bps' => $marginBps,
            'competitor_price_pennies' => $competitorGrossPennies,
            'ceiling_bps' => $ceilingBps,
            'blocked_at' => now()->toIso8601String(),
        ];

        $existing = Suggestion::query()
            ->where('kind', 'competitor_price_ceiling_blocked')
            ->where('status', Suggestion::STATUS_PENDING)
            ->whereJsonContains('evidence->sku', $sku)
            ->first();

        if ($existing !== null) {
            $existing->update([
                'evidence' => $evidence,
                'proposed_at' => now(),
            ]);

            return;
        }

        Suggestion::create([
            'kind' => 'competitor_price_ceiling_blocked',
            'status' => Suggestion::STATUS_PENDING,
            'evidence' => $evidence,
            'payload' => ['product_id' => $product->id, 'sku' => $sku],
            'proposed_at' => now(),
        ]);
    }
}
