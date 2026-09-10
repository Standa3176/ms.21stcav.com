<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260910-lwv — trade:sync
|--------------------------------------------------------------------------
|
| The command that actually touches the storefront. What matters:
|   - dry-run is the DEFAULT and writes nothing
|   - the meta key is B2BKing's, built from the configured group
|   - retail regular_price is never in the payload
|   - a suppressed product CLEARS its meta rather than leaving a stale price
|
| That last one is the dangerous case: a trade price left behind after a cost
| rise keeps selling at a loss with nobody looking.
*/

beforeEach(function (): void {
    config()->set('b2b.storefront.group_id', 167507);
});

it('registers both trade commands', function (): void {
    expect(array_keys(app(Kernel::class)->all()))
        ->toContain('trade:sync')
        ->toContain('trade:preview');
});

it('writes NOTHING without --live', function (): void {
    Product::factory()->create([
        'sku' => 'T-1', 'status' => 'publish', 'woo_product_id' => 501,
        'buy_price' => 698.00, 'sell_price' => 1836.25,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    app()->instance(WooClient::class, $woo);

    $this->artisan('trade:sync')
        ->assertExitCode(0)
        ->expectsOutputToContain('DRY-RUN')
        ->expectsOutputToContain('Nothing was written.');
});

it('publishes the computed price to the B2BKing group meta and leaves retail alone', function (): void {
    Product::factory()->create([
        'sku' => 'T-2', 'status' => 'publish', 'woo_product_id' => 502,
        'buy_price' => 698.00, 'sell_price' => 1836.25,
    ]);

    $captured = [];
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')->once()
        ->with('products/502', Mockery::on(function ($payload) use (&$captured): bool {
            $captured = $payload;

            return true;
        }))
        ->andReturn([]);
    app()->instance(WooClient::class, $woo);

    $this->artisan('trade:sync --skus=T-2 --live')->assertExitCode(0);

    expect($captured['meta_data'][0]['key'])->toBe('b2bking_regular_product_price_group_167507')
        ->and($captured['meta_data'][0]['value'])->toBe('963.24')
        // Trade and retail are independent fields — a trade sync must never
        // carry a price that could overwrite the storefront's own.
        ->and($captured)->not->toHaveKey('regular_price');
});

it('CLEARS the meta for a suppressed product instead of leaving a stale price', function (): void {
    // Retail sits exactly on the 6% floor — no publishable trade price.
    Product::factory()->create([
        'sku' => 'T-3', 'status' => 'publish', 'woo_product_id' => 503,
        'buy_price' => 2561.78, 'sell_price' => 3258.58,
    ]);

    $captured = [];
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')->once()
        ->with('products/503', Mockery::on(function ($payload) use (&$captured): bool {
            $captured = $payload;

            return true;
        }))
        ->andReturn([]);
    app()->instance(WooClient::class, $woo);

    $this->artisan('trade:sync --skus=T-3 --live')
        ->assertExitCode(0)
        ->expectsOutputToContain('no_headroom_below_retail');

    expect($captured['meta_data'][0]['value'])->toBe('');
});

it('never touches a draft product or one with no Woo id', function (): void {
    Product::factory()->create([
        'sku' => 'T-DRAFT', 'status' => 'draft', 'woo_product_id' => 601,
        'buy_price' => 100.00, 'sell_price' => 500.00,
    ]);
    Product::factory()->create([
        'sku' => 'T-NOWOO', 'status' => 'publish', 'woo_product_id' => null,
        'buy_price' => 100.00, 'sell_price' => 500.00,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    app()->instance(WooClient::class, $woo);

    $this->artisan('trade:sync --live')
        ->assertExitCode(0)
        ->expectsOutputToContain('for 0 product(s)');
});

it('refuses to run when no B2BKing group is configured', function (): void {
    config()->set('b2b.storefront.group_id', 0);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    app()->instance(WooClient::class, $woo);

    $this->artisan('trade:sync --live')->assertExitCode(1);
});

it('preview writes nothing and makes no Woo calls at all', function (): void {
    Product::factory()->create([
        'sku' => 'T-4', 'status' => 'publish', 'woo_product_id' => 504,
        'buy_price' => 698.00, 'sell_price' => 1836.25,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    $woo->shouldNotReceive('get');
    app()->instance(WooClient::class, $woo);

    $this->artisan('trade:preview')
        ->assertExitCode(0)
        ->expectsOutputToContain('Trade price preview');
});
