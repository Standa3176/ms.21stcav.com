<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260824-w9k — freeze a product at the margin it is ALREADY running.
 *
 * DRY-RUN BY DEFAULT (cross-cutting invariant) — --apply writes.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS
 *
 * Found 2026-08-24: 1,954 products carry neither brand_id nor category_id, so
 * RuleResolver cannot reach layers 1-3 (brand_category / category / brand) and
 * falls through to the generic default_tier. 245 of them are PUBLISHED, and 15
 * of those are running materially above that generic tier — including lines at
 * 99.5%, 58% and 50% that look deliberate rather than accidental.
 *
 * The pricing engine wants to cut every one of them to the default tier. The
 * only thing preventing that today is an unrelated bug (woo:import-products
 * overwriting local sell_price), which is itself due to be fixed. When it is,
 * those cuts land on the storefront.
 *
 * This buys time WITHOUT changing a price. It reads each product's CURRENT
 * effective margin from its own cost and price, and writes exactly that as a
 * ProductOverride. The resolved margin becomes what the product already earns,
 * so the engine computes the price it already has and proposes no change.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THIS IS A HOLDING ACTION, NOT AN ANSWER
 *
 * ProductOverride is layer 0 — it beats every rule, permanently and silently.
 * A pile of overrides is how a pricing system becomes unreasonable about: the
 * rules stop describing reality and nobody can tell which products are exempt
 * or why. So:
 *
 *   - every row written carries a reason naming this task and the date
 *   - the RIGHT fix is to assign brand/category and let a real rule apply;
 *     these overrides should be DELETED as that lands
 *   - --max-margin-bps refuses to freeze an implausible margin, so a data
 *     error cannot be cemented into the catalogue by a careless bulk run
 *     (SKU BT6010/Z: £21.37 cost against a £4,240.78 price = 16,437%)
 *
 *   php artisan pricing:protect-margin --skus=CIP-SRH,NRG-5-1DB
 *   php artisan pricing:protect-margin --skus=CIP-SRH --apply
 */
final class ProtectProductMarginCommand extends BaseCommand
{
    protected $signature = 'pricing:protect-margin
        {--skus= : Comma-separated SKUs to freeze at their current margin (required)}
        {--max-margin-bps=20000 : Refuse to freeze a margin above this (default 200%) — guards against cementing a data error}
        {--reason= : Override the recorded reason text}
        {--apply : Write the overrides (default: dry-run, writes nothing)}';

    protected $description = 'Freeze products at the margin they already run, so the pricing engine stops proposing a cut (260824-w9k). Dry-run by default.';

    public function __construct(private readonly RuleResolver $resolver)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $apply = (bool) $this->option('apply');
        $maxBps = max(1, (int) $this->option('max-margin-bps'));
        $vatBps = (int) config('pricing.vat_basis_points', 2000);
        $reason = (string) ($this->option('reason') ?: '260824-w9k holding override — product has no brand/category so it resolves to the generic default_tier. Frozen at its existing margin pending taxonomy assignment. DELETE once a real brand/category rule applies.');

        $skus = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('skus')),
        ), static fn (string $s): bool => $s !== ''));

        if ($skus === []) {
            $this->error('--skus is required. This command never operates on a whole catalogue: freezing margins in bulk is how a pricing system stops being explicable.');

            return SymfonyCommand::FAILURE;
        }

        $this->info(sprintf(
            '%s — freezing %d SKU(s) at their current margin (refusing anything above %.2f%%).',
            $apply ? 'LIVE' : 'DRY-RUN',
            count($skus),
            $maxBps / 100,
        ));

        $rows = [];
        $written = 0;
        $refused = 0;

        foreach ($skus as $sku) {
            $product = Product::where('sku', $sku)->first();

            if ($product === null) {
                $rows[] = [$sku, '-', '-', '-', '-', 'NOT FOUND'];
                $refused++;

                continue;
            }

            $cost = (float) $product->buy_price;
            $sell = (float) $product->sell_price;

            if ($cost <= 0 || $sell <= 0) {
                $rows[] = [$sku, number_format($cost, 2), number_format($sell, 2), '-', '-', 'NO COST/PRICE'];
                $refused++;

                continue;
            }

            // The margin this product ALREADY earns — cost and price are both
            // its own, so this is a description of today, not a new decision.
            $sellExVat = $sell / (1 + ($vatBps / 10000));
            $currentBps = (int) round((($sellExVat - $cost) / $cost) * 10000);

            $ruleBps = $this->currentRuleBps($product);

            if ($currentBps > $maxBps) {
                $rows[] = [
                    $sku,
                    number_format($cost, 2),
                    number_format($sell, 2),
                    number_format($currentBps / 100, 1).'%',
                    $ruleBps === null ? '-' : number_format($ruleBps / 100, 1).'%',
                    'REFUSED (implausible)',
                ];
                $refused++;

                continue;
            }

            if ($currentBps < 0) {
                $rows[] = [
                    $sku,
                    number_format($cost, 2),
                    number_format($sell, 2),
                    number_format($currentBps / 100, 1).'%',
                    $ruleBps === null ? '-' : number_format($ruleBps / 100, 1).'%',
                    'REFUSED (below cost)',
                ];
                $refused++;

                continue;
            }

            $verdict = $apply ? 'frozen' : 'would freeze';

            if ($apply) {
                ProductOverride::updateOrCreate(
                    ['product_id' => $product->id],
                    ['margin_basis_points' => $currentBps, 'reason' => $reason],
                );
                $written++;
            }

            $rows[] = [
                $sku,
                number_format($cost, 2),
                number_format($sell, 2),
                number_format($currentBps / 100, 1).'%',
                $ruleBps === null ? '-' : number_format($ruleBps / 100, 1).'%',
                $verdict,
            ];
        }

        $this->newLine();
        $this->table(['SKU', 'Cost', 'Price', 'Current margin', 'Rule margin', 'Result'], $rows);

        $this->newLine();
        if ($apply) {
            $this->info(sprintf('Wrote %d override(s); %d refused. No price changed — each product now resolves to the margin it already ran.', $written, $refused));
            $this->line('Remember: these are a HOLDING action. Delete them as brand/category rules land.');

            return SymfonyCommand::SUCCESS;
        }

        $this->info(sprintf('%d would be frozen, %d refused. Nothing written — re-run with --apply.', count($rows) - $refused, $refused));

        return SymfonyCommand::SUCCESS;
    }

    /** The margin that WOULD apply today, for the comparison column only. */
    private function currentRuleBps(Product $product): ?int
    {
        try {
            return (int) $this->resolver->resolve($product)->marginBasisPoints;
        } catch (\Throwable) {
            return null;
        }
    }
}
