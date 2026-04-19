<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use Database\Seeders\Phase3\DefaultPricingTierSeeder;

/*
|--------------------------------------------------------------------------
| Plan 03-01 DefaultPricingTierSeeder — 3 rows, idempotent (D-06).
|--------------------------------------------------------------------------
|
| Margin values (3500/2800/2200) are linked to golden-fixtures.json — when
| ops re-baselines from live Woo DB, both move in the same commit per D-04.
*/

it('creates exactly 3 default-tier rows on an empty DB', function () {
    $this->seed(DefaultPricingTierSeeder::class);

    expect(PricingRule::where('is_default_tier', true)->count())->toBe(3);
});

it('is idempotent — running the seeder twice produces 3 rows, not 6', function () {
    $this->seed(DefaultPricingTierSeeder::class);
    $this->seed(DefaultPricingTierSeeder::class);

    expect(PricingRule::where('is_default_tier', true)->count())->toBe(3);
});

it('all 3 rows have scope=default_tier, brand_id+category_id NULL, is_default_tier=true', function () {
    $this->seed(DefaultPricingTierSeeder::class);

    $rows = PricingRule::where('is_default_tier', true)->get();

    foreach ($rows as $row) {
        expect($row->scope)->toBe(PricingRule::SCOPE_DEFAULT_TIER);
        expect($row->brand_id)->toBeNull();
        expect($row->category_id)->toBeNull();
        expect($row->is_default_tier)->toBeTrue();
        expect($row->active)->toBeTrue();
    }
});

it('writes the 3 tier margins in ascending tier_min_pennies order: 3500, 2800, 2200', function () {
    $this->seed(DefaultPricingTierSeeder::class);

    $margins = PricingRule::where('is_default_tier', true)
        ->orderBy('tier_min_pennies')
        ->pluck('margin_basis_points')
        ->all();

    expect($margins)->toBe([3500, 2800, 2200]);
});

it('upper tier has tier_max_pennies NULL (open-ended £500+)', function () {
    $this->seed(DefaultPricingTierSeeder::class);

    $upper = PricingRule::where('is_default_tier', true)
        ->where('tier_min_pennies', 50000)
        ->first();

    expect($upper)->not->toBeNull();
    expect($upper->tier_max_pennies)->toBeNull();
    expect($upper->margin_basis_points)->toBe(2200);
});

it('does NOT overwrite an admin-edited margin on re-seed (firstOrCreate contract)', function () {
    $this->seed(DefaultPricingTierSeeder::class);

    // Admin tweaks the middle-tier margin via Filament.
    PricingRule::where('is_default_tier', true)
        ->where('tier_min_pennies', 10000)
        ->update(['margin_basis_points' => 3100]); // 31% not 28%

    // Seeder re-run MUST NOT overwrite the tweak.
    $this->seed(DefaultPricingTierSeeder::class);

    $middle = PricingRule::where('is_default_tier', true)
        ->where('tier_min_pennies', 10000)
        ->first();

    expect($middle->margin_basis_points)->toBe(3100);
    expect(PricingRule::where('is_default_tier', true)->count())->toBe(3);
});
