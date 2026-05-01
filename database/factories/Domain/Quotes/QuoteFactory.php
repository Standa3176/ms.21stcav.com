<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Quotes;

use App\Domain\Quotes\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Phase 11 Plan 01 — Quote factory.
 *
 * Default state mirrors a fresh anonymous-lead draft quote (D-01 toggle OFF):
 *   - user_id null + customer_group_id null (= retail)
 *   - status='draft'
 *   - expires_at = created_at + 14 days (config('quote.default_expiry_days'))
 *   - total_pence_at_quote=0 (lines added separately recompute via observer)
 *
 * Tests override fields explicitly via ->create([...]) for status / lines /
 * customer-group coverage.
 *
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'customer_group_id' => null,
            'customer_group_name_at_quote' => null,
            'customer_email' => fake()->safeEmail(),
            'customer_name' => fake()->name(),
            'billing_address' => null,
            'status' => Quote::STATUS_DRAFT,
            'total_pence_at_quote' => 0,
            'expires_at' => now()->addDays(14),
            'sent_at' => null,
            'accepted_at' => null,
            'rejected_at' => null,
            'expired_at' => null,
            'rejection_metadata' => null,
            'correlation_id' => fake()->uuid(),
        ];
    }
}
