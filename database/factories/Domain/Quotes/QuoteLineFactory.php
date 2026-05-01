<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Quotes;

use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Phase 11 Plan 01 — QuoteLine factory.
 *
 * Default state: a single-unit line at £19.99 inc-VAT (Pitfall 1 sentinel —
 * 1999 pence is the case-study amount that loses 99% of value when stored
 * as decimal). Quote relation auto-created via Quote::factory().
 *
 * Tests override unit_price_pence_at_quote / quantity_int / product_snapshot
 * explicitly for snapshot-immutability + line-total-recompute coverage.
 *
 * @extends Factory<QuoteLine>
 */
class QuoteLineFactory extends Factory
{
    protected $model = QuoteLine::class;

    public function definition(): array
    {
        $qty = 1;
        $unit = 1999;  // Pitfall 1 sentinel — VAT-INCLUSIVE pence

        return [
            'quote_id' => Quote::factory(),
            'sku' => 'TEST-SKU-'.fake()->unique()->numberBetween(1000, 9999),
            'quantity_int' => $qty,
            'unit_price_pence_at_quote' => $unit,
            'line_total_pence_at_quote' => $unit * $qty,
            'product_snapshot' => [
                'name' => fake()->words(3, true),
                'brand' => fake()->company(),
                'category' => 'AV',
                'image_url' => null,
            ],
            'sort_order' => 0,
        ];
    }
}
