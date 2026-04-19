<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 3 Plan 03 Task 3 — Simulated Impact Calculator (PRCE-09).
 *
 * Projects the effect of a HYPOTHETICAL PricingRule on the live catalogue
 * without persisting anything:
 *
 *   1. DB::beginTransaction
 *   2. save() the proposed rule (insert or update)
 *   3. Walk every eligible Product in chunks of 500 (Pitfall: full catalogue
 *      load would OOM on 15k+ SKUs)
 *   4. For each, run the live RuleResolver + PriceCalculator to get what the
 *      price WOULD be if the hypothetical rule existed
 *   5. Compare against stored products.sell_price (in pennies)
 *   6. Drop rows where proposed == current (the UI's value is the diff set)
 *   7. DB::rollBack in finally — nothing persists, no ProductPriceChanged event
 *      is ever emitted (the rollback also drops any activity_log rows)
 *
 * The UI paginates at `$limit` rows but keeps a full count so "N SKUs would
 * change — showing first 50" messaging is accurate.
 *
 * Plan 04 pointer: the `pricing:recompute --all --dry-run` command reuses this
 * core — same iteration, same guard, but skips the wrap-in-transaction step
 * since it reads already-persisted rules rather than a hypothetical.
 */
final class SimulatedImpactCalculator
{
    public function __construct(
        private readonly RuleResolver $resolver,
        private readonly PriceCalculator $calculator,
    ) {}

    /**
     * @return array{count:int, rows:array<int, SimulatedImpactRow>}
     */
    public function simulate(PricingRule $proposedRule, int $limit = 50): array
    {
        $rows = [];
        $count = 0;

        DB::beginTransaction();
        try {
            // Persist the hypothetical rule (insert OR update). Whether existing or
            // new, ->save() writes the current attribute state — the rollback at the
            // end undoes it either way.
            $proposedRule->save();

            Product::query()
                ->whereNotNull('buy_price')
                ->where('buy_price', '>', 0)
                ->chunkById(500, function ($chunk) use (&$rows, &$count, $limit) {
                    foreach ($chunk as $product) {
                        try {
                            $resolution = $this->resolver->resolve($product);
                            $buyPennies = (int) round(((float) $product->buy_price) * 100);
                            if ($buyPennies <= 0) {
                                continue;
                            }
                            $proposedPennies = $this->calculator->compute(
                                $buyPennies,
                                $resolution->marginBasisPoints
                            );
                            $currentPennies = $product->sell_price === null
                                ? 0
                                : (int) round(((float) $product->sell_price) * 100);

                            if ($proposedPennies === $currentPennies) {
                                continue;  // noise — exclude no-diff rows (D-13 spirit)
                            }

                            $count++;
                            if (count($rows) < $limit) {
                                $rows[] = new SimulatedImpactRow(
                                    productId: (int) $product->id,
                                    variantId: null,
                                    sku: (string) $product->sku,
                                    currentPennies: $currentPennies,
                                    proposedPennies: $proposedPennies,
                                    deltaPennies: $proposedPennies - $currentPennies,
                                    resolutionSource: $resolution->source,
                                );
                            }
                        } catch (Throwable $e) {
                            // Catalogue-incomplete state (no rule matches, zero price,
                            // etc.) — simulation must keep going. The error is already
                            // caught by the Listener in production; here we just skip.
                            continue;
                        }
                    }
                });
        } finally {
            DB::rollBack();
        }

        return ['count' => $count, 'rows' => $rows];
    }
}
