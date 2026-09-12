<?php

declare(strict_types=1);

use App\Domain\Pricing\Filament\Resources\PricingRuleResource;
use App\Domain\TradePricing\Filament\Resources\TradeCostAdjustmentResource;
use App\Domain\TradePricing\Models\TradeCostAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260912-f8x — trade cost arrangements admin
|--------------------------------------------------------------------------
|
| The dangerous case is a row carrying BOTH a percentage and a fixed cost.
| The form's scope/value radios are UI-only, so switching a row from one to
| the other would otherwise leave the old column populated — and the resolver
| prefers absolute_cost, so a stale percentage would sit there looking
| authoritative while doing nothing.
*/

it('nulls the percentage when a row is saved as a fixed cost', function (): void {
    $out = TradeCostAdjustmentResource::normalise([
        'scope_kind' => 'sku', 'value_kind' => 'absolute',
        'sku' => 'A24', 'brand_id' => 4242,
        'adjustment_pct' => '11.200', 'absolute_cost' => '773.0000',
    ]);

    expect($out['adjustment_pct'])->toBeNull()
        ->and($out['absolute_cost'])->toBe('773.0000')
        // A SKU arrangement must not also carry a brand.
        ->and($out['brand_id'])->toBeNull()
        ->and($out)->not->toHaveKeys(['scope_kind', 'value_kind']);
});

it('nulls the fixed cost when a row is saved as a percentage', function (): void {
    $out = TradeCostAdjustmentResource::normalise([
        'scope_kind' => 'brand', 'value_kind' => 'pct',
        'sku' => 'A24', 'brand_id' => 4242,
        'adjustment_pct' => '11.200', 'absolute_cost' => '773.0000',
    ]);

    expect($out['absolute_cost'])->toBeNull()
        ->and($out['adjustment_pct'])->toBe('11.200')
        ->and($out['sku'])->toBeNull();
});

it('leaves data alone when the UI radios are absent, so a programmatic save is not mangled', function (): void {
    $out = TradeCostAdjustmentResource::normalise(['sku' => 'A24', 'absolute_cost' => '773.0000']);

    expect($out)->toBe(['sku' => 'A24', 'absolute_cost' => '773.0000']);
});

it('does not collide with another resource on navigationSort', function (): void {
    // The I-01 convention: a duplicate sort makes the sidebar order arbitrary.
    expect(TradeCostAdjustmentResource::getNavigationSort())
        ->not->toBe(PricingRuleResource::getNavigationSort());
});

it('shows whether a row is in force today, not just whether it is active', function (): void {
    // An operator-active row whose deal has lapsed is the case worth surfacing:
    // the switch says yes, the pricer says no.
    $lapsed = TradeCostAdjustment::factory()->create([
        'brand_id' => 1, 'is_active' => true,
        'valid_until' => now()->subDay()->toDateString(),
    ]);

    expect(TradeCostAdjustment::query()->whereKey($lapsed->getKey())->inForce()->exists())->toBeFalse()
        ->and($lapsed->is_active)->toBeTrue();
});
