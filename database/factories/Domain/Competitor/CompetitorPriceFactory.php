<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Competitor;

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompetitorPrice>
 *
 * Deliberately does NOT use `->unique()->bothify('SKU-####')` for `sku`.
 * Plan 05-02 orphan-test fixtures + trend-chart tests need to produce
 * MULTIPLE rows per SKU across dates — unique() would break that.
 * Tests that need inter-run SKU distinctness should pass `sku` explicitly.
 */
class CompetitorPriceFactory extends Factory
{
    protected $model = CompetitorPrice::class;

    public function definition(): array
    {
        $exVat = fake()->numberBetween(1_000, 200_000); // £10.00 — £2,000.00

        return [
            'competitor_id' => Competitor::factory(),
            'sku' => fake()->bothify('SKU-####-???'),
            'mpn' => null,
            'price_pennies_ex_vat' => $exVat,
            // gross = ex_vat × 1.2 rounded to nearest penny (UK standard VAT)
            'price_pennies_gross' => (int) round($exVat * 1.2),
            'recorded_at' => now(),
            'ingest_run_id' => null,
        ];
    }

    /** Deterministic SKU for dedup / trend tests. */
    public function forSku(string $sku): static
    {
        return $this->state(fn () => ['sku' => $sku]);
    }

    /** Stamped at a specific date — multiple calls with different dates build trends. */
    public function recordedAt(\DateTimeInterface $at): static
    {
        return $this->state(fn () => ['recorded_at' => $at]);
    }
}
