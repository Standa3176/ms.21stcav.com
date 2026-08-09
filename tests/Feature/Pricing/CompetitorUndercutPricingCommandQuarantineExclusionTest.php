<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Events\ProductPriceChanged;
use App\Domain\Products\Models\Product;
use Database\Seeders\Phase3\DefaultPricingTierSeeder;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Quick task 260809-jie — Guard 2b: wire the pricer to exclude quarantined
| competitor_prices rows + incident-reproduction regression test.
|--------------------------------------------------------------------------
|
| Born from the 2026-08-09 production incident: competitor_id=3's price for
| a SKU jumped £1067.69 -> £3876.69 ex-VAT overnight. A quarantined row
| (is_price_anomaly=true, flagged by Guard 2a) must never be selectable by
| CompetitorUndercutPricingCommand's "lowest current competitor" query —
| pricing falls back to the next-best unflagged price, or to the
| no-competitor cost-plus path if none exists.
*/

it('prices off the older unflagged price, never the flagged one — the exact incident reproduced', function (): void {
    Event::fake([ProductPriceChanged::class]);

    $competitor = Competitor::factory()->create();
    $product = Product::factory()->create([
        'sku' => 'QUARANTINE-1',
        'buy_price' => 100.00,
        'sell_price' => 1.00,
    ]);

    // Old, good, unflagged price.
    CompetitorPrice::factory()->forSku('QUARANTINE-1')->create([
        'competitor_id' => $competitor->id,
        'price_pennies_gross' => 15000, // £150.00
        'price_pennies_ex_vat' => 12500,
        'recorded_at' => now()->subDays(2),
        'is_price_anomaly' => false,
    ]);

    // Newer row for the SAME competitor — the 263%-jump shape, quarantined.
    CompetitorPrice::factory()->forSku('QUARANTINE-1')->create([
        'competitor_id' => $competitor->id,
        'price_pennies_gross' => 45000, // £450.00
        'price_pennies_ex_vat' => 37500,
        'recorded_at' => now(),
        'is_price_anomaly' => true,
        'price_anomaly_reason' => 'Price moved 263.0% vs prior ex-VAT £106.77 -> £387.67 (threshold 50%)',
    ]);

    $this->artisan('pricing:undercut-competitors', ['--skus' => 'QUARANTINE-1', '--live' => true])
        ->assertSuccessful();

    // Undercut 1p below the OLD good price (£150.00 -> £149.99), NOT the flagged £450.00.
    expect($product->fresh()->sell_price)->toBe('149.9900');
    Event::assertDispatched(ProductPriceChanged::class, function ($event) {
        return $event->newPennies === 14999;
    });
});

it('falls through to the no-competitor cost-plus rule when the ONLY competitor row is flagged', function (): void {
    Event::fake([ProductPriceChanged::class]);
    $this->seed(DefaultPricingTierSeeder::class);

    $competitor = Competitor::factory()->create();
    $product = Product::factory()->create([
        'sku' => 'QUARANTINE-2',
        'buy_price' => 100.00,
        'sell_price' => 1.00,
        'brand_id' => null,
        'category_id' => null,
    ]);

    CompetitorPrice::factory()->forSku('QUARANTINE-2')->create([
        'competitor_id' => $competitor->id,
        'price_pennies_gross' => 45000,
        'price_pennies_ex_vat' => 37500,
        'recorded_at' => now(),
        'is_price_anomaly' => true,
    ]);

    $this->artisan('pricing:undercut-competitors', ['--skus' => 'QUARANTINE-2', '--live' => true])
        ->assertSuccessful();

    // £100 buy -> £100-499 tier -> 28% margin -> 100 * 1.28 * 1.2 = £153.60.
    expect($product->fresh()->sell_price)->toBe('153.6000');
    Event::assertDispatched(ProductPriceChanged::class, function ($event) {
        return $event->resolutionSource === 'margin';
    });
});

it('does NOT change behaviour for a normal unflagged competitor row (no-regression)', function (): void {
    Event::fake([ProductPriceChanged::class]);

    $competitor = Competitor::factory()->create();
    $product = Product::factory()->create([
        'sku' => 'QUARANTINE-3',
        'buy_price' => 100.00,
        'sell_price' => 1.00,
    ]);
    CompetitorPrice::factory()->forSku('QUARANTINE-3')->create([
        'competitor_id' => $competitor->id,
        'price_pennies_gross' => 15601,
        'price_pennies_ex_vat' => 13001,
        'recorded_at' => now(),
        'is_price_anomaly' => false,
    ]);

    $this->artisan('pricing:undercut-competitors', ['--skus' => 'QUARANTINE-3', '--live' => true])
        ->assertSuccessful();

    expect($product->fresh()->sell_price)->toBe('156.0000');
    Event::assertDispatched(ProductPriceChanged::class);
});
