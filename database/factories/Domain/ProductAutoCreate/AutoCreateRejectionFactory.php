<?php

declare(strict_types=1);

namespace Database\Factories\Domain\ProductAutoCreate;

use App\Domain\ProductAutoCreate\Models\AutoCreateRejection;
use App\Domain\Products\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutoCreateRejection>
 */
class AutoCreateRejectionFactory extends Factory
{
    protected $model = AutoCreateRejection::class;

    private const REASONS = [
        'not_a_real_product',
        'duplicate_of_existing',
        'discontinued_by_supplier',
        'spare_part_or_accessory',
        'poor_quality_data',
        'misclassified_brand_or_category',
        'below_viability_threshold',
        'other',
    ];

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'reason' => self::REASONS[array_rand(self::REASONS)],
            'notes' => null,
            'rejected_by_user_id' => null,
        ];
    }

    public function other(string $note): static
    {
        return $this->state(fn () => [
            'reason' => 'other',
            'notes' => $note,
        ]);
    }
}
