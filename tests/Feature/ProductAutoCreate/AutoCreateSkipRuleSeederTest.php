<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Models\AutoCreateSkipRule;
use Database\Seeders\AutoCreateSkipRuleSeeder;

it('seeds exactly 3 D-04 default skip rules', function (): void {
    $this->seed(AutoCreateSkipRuleSeeder::class);

    expect(AutoCreateSkipRule::count())->toBe(3);

    expect(AutoCreateSkipRule::query()
        ->where('scope', AutoCreateSkipRule::SCOPE_BRAND)
        ->where('value', 'SparesPlus')
        ->where('is_active', true)
        ->exists())->toBeTrue();

    expect(AutoCreateSkipRule::query()
        ->where('scope', AutoCreateSkipRule::SCOPE_SKU_PATTERN)
        ->where('value', '^TEST-')
        ->where('is_active', true)
        ->exists())->toBeTrue();

    expect(AutoCreateSkipRule::query()
        ->where('scope', AutoCreateSkipRule::SCOPE_PRICE_RANGE)
        ->where('value', '<25')
        ->where('is_active', true)
        ->exists())->toBeTrue();
});

it('is idempotent — running the seeder twice still leaves exactly 3 rows', function (): void {
    $this->seed(AutoCreateSkipRuleSeeder::class);
    $this->seed(AutoCreateSkipRuleSeeder::class);

    expect(AutoCreateSkipRule::count())->toBe(3);
});

it('AutoCreateSkipRule::matches() covers all 4 scope branches', function (): void {
    // sku_pattern
    $r = new AutoCreateSkipRule(['scope' => 'sku_pattern', 'value' => '^TEST-']);
    expect($r->matches('TEST-ABC', 100.0))->toBeTrue();
    expect($r->matches('PROD-ABC', 100.0))->toBeFalse();

    // price_range <25
    $r2 = new AutoCreateSkipRule(['scope' => 'price_range', 'value' => '<25']);
    expect($r2->matches('ANY', 15.0))->toBeTrue();
    expect($r2->matches('ANY', 50.0))->toBeFalse();

    // price_range >500
    $r3 = new AutoCreateSkipRule(['scope' => 'price_range', 'value' => '>500']);
    expect($r3->matches('ANY', 600.0))->toBeTrue();
    expect($r3->matches('ANY', 400.0))->toBeFalse();

    // price_range 25-50 (inclusive)
    $r4 = new AutoCreateSkipRule(['scope' => 'price_range', 'value' => '25-50']);
    expect($r4->matches('ANY', 30.0))->toBeTrue();
    expect($r4->matches('ANY', 25.0))->toBeTrue();
    expect($r4->matches('ANY', 50.0))->toBeTrue();
    expect($r4->matches('ANY', 51.0))->toBeFalse();
    expect($r4->matches('ANY', 24.99))->toBeFalse();

    // brand (case-insensitive)
    $r5 = new AutoCreateSkipRule(['scope' => 'brand', 'value' => 'SparesPlus']);
    expect($r5->matches('ANY', 10.0, ['brand' => 'sparesplus']))->toBeTrue();
    expect($r5->matches('ANY', 10.0, ['brand' => 'Logitech']))->toBeFalse();
    expect($r5->matches('ANY', 10.0, []))->toBeFalse();

    // category (case-insensitive)
    $r6 = new AutoCreateSkipRule(['scope' => 'category', 'value' => 'Cables']);
    expect($r6->matches('ANY', 10.0, ['category' => 'cables']))->toBeTrue();
    expect($r6->matches('ANY', 10.0, ['category' => 'Monitors']))->toBeFalse();
});

it('matches() returns false on malformed regex (T-06-01-01 catastrophic-backtracking guard)', function (): void {
    // Intentionally broken regex (unmatched ( ).
    $r = new AutoCreateSkipRule(['scope' => 'sku_pattern', 'value' => '(unclosed']);
    expect($r->matches('ANYTHING', 0.0))->toBeFalse();

    // Over-long pattern capped at 256 chars.
    $huge = str_repeat('a', 300);
    $r2 = new AutoCreateSkipRule(['scope' => 'sku_pattern', 'value' => $huge]);
    expect($r2->matches('ANYTHING', 0.0))->toBeFalse();
});
