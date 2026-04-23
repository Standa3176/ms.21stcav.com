<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 01 — SaveQuietlyObserverTest (RESEARCH A3 gate)
|--------------------------------------------------------------------------
| Resolves Assumption A3 — documents whether Product::saved(...) closure
| fires after forceFill + saveQuietly on Laravel 12.
|
| Outcome of this test drives Plan 06-03's Observer strategy:
|   - If `saved` fires → ProductCompletenessObserver can rely on it.
|   - If `saved` DOES NOT fire → Plan 06-03 must use domain-event listeners
|     on SupplierPriceChanged / SupplierStockChanged instead.
|
| Laravel 12 documented behaviour: saveQuietly suppresses Eloquent events
| including `saving` / `saved` / `creating` / `created` / `updating` /
| `updated`. This test is the LIVE verification.
*/

beforeEach(function (): void {
    Cache::flush();
});

it('documents whether Product::saved fires after forceFill + saveQuietly (A3)', function (): void {
    $savedFired = false;
    $savingFired = false;

    Product::saved(function () use (&$savedFired): void {
        $savedFired = true;
    });
    Product::saving(function () use (&$savingFired): void {
        $savingFired = true;
    });

    $product = Product::factory()->create();

    // Reset counters AFTER the initial create (which fires events).
    $savedFired = false;
    $savingFired = false;

    $product->forceFill(['name' => 'Quiet Name Update'])->saveQuietly();

    // RECORD the finding regardless of outcome — test is diagnostic, not gating.
    // Fact (Laravel 12): saveQuietly suppresses BOTH saving + saved.
    // If this assertion flips in a future Laravel release, Plan 06-03 must
    // revisit its listener-vs-observer decision.
    expect($savingFired)->toBeFalse(
        'saving() fired after saveQuietly — Laravel 12 behaviour changed; update Plan 06-03 observer strategy.'
    );
    expect($savedFired)->toBeFalse(
        'saved() fired after saveQuietly — Laravel 12 behaviour changed; Plan 06-03 may safely use observer pattern.'
    );

    // Persist the finding so operators can inspect without rerunning the suite.
    Cache::put('phase06.a3.saveQuietly.saved_fired', $savedFired);
    Cache::put('phase06.a3.saveQuietly.saving_fired', $savingFired);
});
