<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Products\Models\Product;
use App\Domain\TradePricing\Services\TradeCostAdjustmentResolver;
use App\Domain\TradePricing\Services\TradeStorefrontPricer;

/**
 * NOTE ON PLACEMENT (260910-lwv): these live in the Pricing domain, not
 * TradePricing, because TradePricing is deliberately a thin read-side layer
 * whose deptrac allow-list is [Foundation, Pricing, Products, Webhooks] — it
 * must never reach into Sync. Pricing may depend on BOTH Sync and
 * TradePricing, so a command that reads a trade price and writes it to Woo
 * belongs here. Widening TradePricing's allow-list would have been the easy
 * fix and the wrong one; DeptracTradePricingLayerTest guards that boundary.
 */

/**
 * Quick task 260910-lwv — read-only shape of what trade pricing would publish.
 *
 * Touches nothing: no Woo calls, no DB writes. Its job is to answer "what does
 * this policy actually do to my catalogue" BEFORE anybody runs trade:sync
 * --live, and to make the effect of a cost adjustment visible as a number
 * rather than an argument.
 *
 * `--adjust=12` models a hypothetical 12% off feed cost applied to EVERY
 * product, which is how you size a brand arrangement before entering it:
 * run once bare, once with the percentage, and compare the suppressed counts.
 */
final class TradePreviewCommand extends BaseCommand
{
    protected $signature = 'trade:preview
        {--skus= : Comma-separated SKU list. Default: all published products.}
        {--adjust= : Model a hypothetical % off feed cost for EVERY product (e.g. 12).}
        {--show=15 : How many example rows to print per bucket.}';

    protected $description = 'Read-only preview of trade prices across the catalogue (writes nothing, no Woo calls).';

    public function __construct(
        private readonly TradeStorefrontPricer $pricer,
        private readonly TradeCostAdjustmentResolver $adjustments,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $this->adjustments->flush();

        $hypothetical = $this->option('adjust') === null ? null : (float) $this->option('adjust');
        $show = max(0, (int) $this->option('show'));

        $query = Product::query()->where('status', 'publish');
        $skus = $this->parseSkus();
        if ($skus !== []) {
            $query->whereIn('sku', $skus);
        }

        $counts = ['target' => 0, 'retail_capped' => 0, 'suppressed' => 0];
        $reasons = [];
        $discounts = [];
        $examples = ['target' => [], 'retail_capped' => [], 'suppressed' => []];

        foreach ($query->cursor() as $product) {
            $original = $product->buy_price;

            // Modelling only — the mutation is never saved, and the cursor
            // hands back a fresh instance on the next iteration anyway.
            if ($hypothetical !== null) {
                $product->buy_price = ((float) $original) * (1 - $hypothetical / 100);
            }

            $result = $this->pricer->price($product);
            $counts[$result->source]++;

            if ($result->source === 'suppressed') {
                $reasons[$result->reason ?? 'unknown'] = ($reasons[$result->reason ?? 'unknown'] ?? 0) + 1;
            }

            $retail = (int) round(((float) ($product->sell_price ?? 0)) * 100);
            if ($result->isPublishable() && $retail > 0) {
                $discounts[] = ($retail - $result->pennies) / $retail * 100;
            }

            if (count($examples[$result->source]) < $show) {
                $examples[$result->source][] = sprintf(
                    '  %-24s retail %9s  trade %9s  %s',
                    (string) $product->sku,
                    number_format($retail / 100, 2),
                    $result->isPublishable() ? number_format($result->pennies / 100, 2) : '—',
                    $result->isPublishable()
                        ? sprintf('%.1f%% off', ($retail - $result->pennies) / max($retail, 1) * 100)
                        : (string) $result->reason,
                );
            }
        }

        $total = array_sum($counts);
        $this->info(sprintf(
            'Trade price preview — %d published product(s)%s.',
            $total,
            $hypothetical !== null ? sprintf(' (modelling %.1f%% off feed cost for ALL)', $hypothetical) : '',
        ));

        foreach ($counts as $bucket => $n) {
            $this->line(sprintf('  %-16s %6d  %5.1f%%', $bucket, $n, $total > 0 ? $n / $total * 100 : 0));
        }

        if ($reasons !== []) {
            $this->line('  suppressed because:');
            foreach ($reasons as $reason => $n) {
                $this->line(sprintf('    %-28s %6d', $reason, $n));
            }
        }

        if ($discounts !== []) {
            sort($discounts);
            $this->line(sprintf(
                '  discount vs retail — median %.1f%%, best %.1f%%, worst %.1f%%',
                $discounts[intdiv(count($discounts), 2)],
                end($discounts),
                $discounts[0],
            ));
        }

        foreach ($examples as $bucket => $rows) {
            if ($rows === []) {
                continue;
            }
            $this->line('');
            $this->line(strtoupper($bucket).':');
            foreach ($rows as $row) {
                $this->line($row);
            }
        }

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function parseSkus(): array
    {
        $raw = trim((string) ($this->option('skus') ?? ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));
    }
}
