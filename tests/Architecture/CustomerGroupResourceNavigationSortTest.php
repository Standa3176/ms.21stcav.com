<?php

declare(strict_types=1);

use App\Domain\Pricing\Filament\Resources\PricingRuleResource;
use App\Domain\TradePricing\Filament\Resources\CustomerGroupResource;

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 05 — I-01 navigationSort architecture invariant (DB-free)
|--------------------------------------------------------------------------
|
| The Feature test CustomerGroupResourceTest covers the same assertion but
| is gated behind RefreshDatabase (file-global pest.php). This Architecture
| test runs offline as the always-on guarantee — bumping either Resource's
| $navigationSort to the same value fails CI even when MySQL is offline.
|
| Mirrors Plan 09-02's TradeRuleResolverPurityTest pattern (purity contract
| asserted via DB-free source-file scan in tests/Unit/, complementing the
| DB-required behavioural tests).
*/

it('CustomerGroupResource navigationSort is distinct from PricingRuleResource (I-01 architecture invariant)', function (): void {
    $cg = (new ReflectionClass(CustomerGroupResource::class))
        ->getStaticPropertyValue('navigationSort');
    $pr = (new ReflectionClass(PricingRuleResource::class))
        ->getStaticPropertyValue('navigationSort');

    expect($cg)->not->toBe($pr, "I-01 navigationSort collision: both Resources sort to {$pr}");
});

it('both Pricing Resources have integer navigationSort values', function (): void {
    $cg = (new ReflectionClass(CustomerGroupResource::class))
        ->getStaticPropertyValue('navigationSort');
    $pr = (new ReflectionClass(PricingRuleResource::class))
        ->getStaticPropertyValue('navigationSort');

    expect($cg)->toBeInt('CustomerGroupResource navigationSort must be an int');
    expect($pr)->toBeInt('PricingRuleResource navigationSort must be an int');
});
