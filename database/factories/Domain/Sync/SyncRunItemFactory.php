<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Sync;

use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\SyncRunItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRunItem>
 */
class SyncRunItemFactory extends Factory
{
    protected $model = SyncRunItem::class;

    public function definition(): array
    {
        return [
            'sync_run_id' => SyncRun::factory(),
            'sku' => fake()->bothify('SKU-####'),
            'woo_product_id' => fake()->numberBetween(1_000, 999_999),
            'woo_variation_id' => null,
            'action' => SyncRunItem::ACTION_UPDATED,
            'reason' => null,
            'old_price' => '10.00',
            'new_price' => '12.00',
            'old_stock' => 5,
            'new_stock' => 3,
            'error_message' => null,
            'correlation_id' => fake()->uuid(),
            'created_at' => now(),
        ];
    }

    public function skipped(): static
    {
        return $this->state(fn () => [
            'action' => SyncRunItem::ACTION_SKIPPED,
            'reason' => 'exclude_from_auto_update',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'action' => SyncRunItem::ACTION_FAILED,
            'error_message' => 'simulated error',
        ]);
    }

    public function missing(): static
    {
        return $this->state(fn () => [
            'action' => SyncRunItem::ACTION_MISSING,
            'reason' => 'not_in_supplier_feed',
        ]);
    }

    public function unknownSku(): static
    {
        return $this->state(fn () => [
            'action' => SyncRunItem::ACTION_UNKNOWN_SKU,
        ]);
    }
}
