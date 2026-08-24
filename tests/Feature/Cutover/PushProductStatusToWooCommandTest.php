<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| products:push-status-to-woo (Cutover step C-NEW)
|--------------------------------------------------------------------------
| Reconciles non-publish LOCAL product status onto Woo so --flag-obsolete
| demotions actually leave the storefront on flip day. Shadow-safe by way
| of WooClient::writeOrShadow().
*/

/** Bind a WooClient spy that records every put() call and returns canned results. */
function bindWooSpy(array $putResultByEndpoint = []): stdClass
{
    $spy = new stdClass;
    $spy->calls = [];
    $spy->results = $putResultByEndpoint;

    $double = Mockery::mock(WooClient::class);
    $double->shouldReceive('put')->andReturnUsing(function (string $endpoint, array $payload) use ($spy): array {
        $spy->calls[] = ['endpoint' => $endpoint, 'payload' => $payload];

        return $spy->results[$endpoint] ?? ['shadow_mode' => true, 'diff_id' => 1];
    });
    app()->instance(WooClient::class, $double);

    return $spy;
}

it('dry-run never calls WooClient::put', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'OBS-1', 'status' => 'pending', 'woo_product_id' => 12345]);

    $spy = bindWooSpy();

    Artisan::call('products:push-status-to-woo');

    expect($spy->calls)->toBe([]);
});

it('--live PUTs the local status to products/{wooId} for each targeted row', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'OBS-1', 'status' => 'pending', 'woo_product_id' => 111]);
    Product::factory()->create(['type' => 'simple', 'sku' => 'OBS-2', 'status' => 'pending', 'woo_product_id' => 222]);

    $spy = bindWooSpy();

    Artisan::call('products:push-status-to-woo', ['--live' => true]);

    expect($spy->calls)->toHaveCount(2)
        ->and($spy->calls[0]['endpoint'])->toBe('products/111')
        ->and($spy->calls[0]['payload'])->toBe(['status' => 'pending'])
        ->and($spy->calls[1]['endpoint'])->toBe('products/222')
        ->and($spy->calls[1]['payload'])->toBe(['status' => 'pending']);
});

it('defaults --statuses to pending only (drafts are NOT touched without opt-in)', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'P-1', 'status' => 'pending', 'woo_product_id' => 111]);
    // A draft locally — almost certainly already a draft on Woo (woo:import-products
    // pulls publish+draft+private). Default scope MUST skip it.
    Product::factory()->create(['type' => 'simple', 'sku' => 'D-1', 'status' => 'draft', 'woo_product_id' => 222]);

    $spy = bindWooSpy();

    Artisan::call('products:push-status-to-woo', ['--live' => true]);

    expect($spy->calls)->toHaveCount(1)
        ->and($spy->calls[0]['endpoint'])->toBe('products/111');
});

it('--statuses=pending,draft widens the scope (opt-in)', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'P-1', 'status' => 'pending', 'woo_product_id' => 111]);
    Product::factory()->create(['type' => 'simple', 'sku' => 'D-1', 'status' => 'draft', 'woo_product_id' => 222]);

    $spy = bindWooSpy();

    Artisan::call('products:push-status-to-woo', ['--live' => true, '--statuses' => 'pending,draft']);

    expect($spy->calls)->toHaveCount(2);
});

it('rejects --statuses=publish (would no-op or un-suppress)', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'X', 'status' => 'publish', 'woo_product_id' => 1]);

    $spy = bindWooSpy();

    $exit = Artisan::call('products:push-status-to-woo', ['--live' => true, '--statuses' => 'publish']);

    expect($spy->calls)->toBe([])
        ->and($exit)->toBe(1);
});

it('skips products that have no woo_product_id (local-only auto-create drafts)', function (): void {
    // Local pending product without a Woo id — never been to Woo, no id to PUT against.
    Product::factory()->create(['type' => 'simple', 'sku' => 'LOC-1', 'status' => 'pending', 'woo_product_id' => null]);

    $spy = bindWooSpy();

    Artisan::call('products:push-status-to-woo', ['--live' => true]);

    expect($spy->calls)->toBe([]);
});

it('skips published products (nothing to reconcile)', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'LIVE-1', 'status' => 'publish', 'woo_product_id' => 999]);

    $spy = bindWooSpy();

    Artisan::call('products:push-status-to-woo', ['--live' => true]);

    expect($spy->calls)->toBe([]);
});

it('honours the --flag-obsolete carve-outs (is_custom_ms + exclude_from_auto_update)', function (): void {
    // Custom MS product — must never be auto-status-pushed.
    Product::factory()->create([
        'type' => 'simple', 'sku' => 'CUS-1', 'status' => 'pending', 'woo_product_id' => 501,
        'is_custom_ms' => true,
    ]);
    // Operator-pinned via exclude_from_auto_update.
    Product::factory()->create([
        'type' => 'simple', 'sku' => 'PIN-1', 'status' => 'pending', 'woo_product_id' => 502,
        'exclude_from_auto_update' => true,
    ]);
    // Normal obsolete product — SHOULD be pushed.
    Product::factory()->create([
        'type' => 'simple', 'sku' => 'OBS-1', 'status' => 'pending', 'woo_product_id' => 503,
    ]);

    $spy = bindWooSpy();

    Artisan::call('products:push-status-to-woo', ['--live' => true]);

    expect($spy->calls)->toHaveCount(1)
        ->and($spy->calls[0]['endpoint'])->toBe('products/503');
});

it('--skus filters the cohort to the listed SKUs', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'OBS-A', 'status' => 'pending', 'woo_product_id' => 11]);
    Product::factory()->create(['type' => 'simple', 'sku' => 'OBS-B', 'status' => 'pending', 'woo_product_id' => 22]);
    Product::factory()->create(['type' => 'simple', 'sku' => 'OBS-C', 'status' => 'pending', 'woo_product_id' => 33]);

    $spy = bindWooSpy();

    Artisan::call('products:push-status-to-woo', ['--live' => true, '--skus' => 'OBS-A,OBS-C']);

    expect($spy->calls)->toHaveCount(2)
        ->and(collect($spy->calls)->pluck('endpoint')->all())->toBe(['products/11', 'products/33']);
});

it('--limit caps the number of products processed', function (): void {
    for ($i = 1; $i <= 5; $i++) {
        Product::factory()->create([
            'type' => 'simple', 'sku' => "OBS-{$i}", 'status' => 'pending', 'woo_product_id' => 1000 + $i,
        ]);
    }

    $spy = bindWooSpy();

    Artisan::call('products:push-status-to-woo', ['--live' => true, '--limit' => 2]);

    expect($spy->calls)->toHaveCount(2);
});

it('reports shadow vs live counts and surfaces error rows without throwing', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'OBS-OK', 'status' => 'pending', 'woo_product_id' => 1]);
    Product::factory()->create(['type' => 'simple', 'sku' => 'OBS-ERR', 'status' => 'pending', 'woo_product_id' => 2]);

    $spy = new stdClass;
    $spy->calls = [];
    $double = Mockery::mock(WooClient::class);
    $double->shouldReceive('put')->andReturnUsing(function (string $endpoint, array $payload) use ($spy): array {
        $spy->calls[] = $endpoint;
        if ($endpoint === 'products/2') {
            throw new RuntimeException('Woo 500');
        }

        return ['shadow_mode' => true, 'diff_id' => 99]; // products/1 shadowed
    });
    app()->instance(WooClient::class, $double);

    $exit = Artisan::call('products:push-status-to-woo', ['--live' => true]);
    $output = Artisan::output();

    expect($spy->calls)->toBe(['products/1', 'products/2'])
        ->and($exit)->toBe(1) // errors > 0 → FAILURE
        ->and($output)->toContain('shadowed=1')
        ->and($output)->toContain('errors=1');
});

/*
|--------------------------------------------------------------------------
| 260824-lsd — the Woo write ceiling is back-pressure, not a failure
|--------------------------------------------------------------------------
|
| Prod 2026-08-24: a 2,249-product run reported live_pushed=35, errors=2214.
| Every "error" was WooWriteThrottleException — about one minute's allowance
| got through and the rest hit the 60/min ceiling. It was invisible in
| laravel.log because this command prints the exception instead of logging it.
|
| The 260822-rmo fix does not cover this path: it made QUEUED jobs release and
| retry, and this is a synchronous console command with no queue to release to.
| It has to pace itself.
*/

it('waits out the write ceiling and retries rather than counting it as an error', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'THR-1', 'status' => 'pending', 'woo_product_id' => 900]);

    $attempts = 0;
    $double = Mockery::mock(WooClient::class);
    $double->shouldReceive('put')->andReturnUsing(function () use (&$attempts): array {
        $attempts++;
        if ($attempts === 1) {
            throw new App\Domain\Sync\Exceptions\WooWriteThrottleException('ceiling reached', retryAfterSeconds: 1);
        }

        return ['id' => 900];
    });
    app()->instance(WooClient::class, $double);

    $exit = Artisan::call('products:push-status-to-woo', ['--live' => true, '--statuses' => 'pending']);

    // Two attempts, one success, and crucially exit 0 — a throttle must not
    // make the whole run report failure.
    expect($attempts)->toBe(2)
        ->and($exit)->toBe(0)
        ->and(Artisan::output())->toContain('live_pushed=1');
});

it('leaves a product for the next run when the ceiling is still closed', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'THR-2', 'status' => 'pending', 'woo_product_id' => 901]);

    $double = Mockery::mock(WooClient::class);
    $double->shouldReceive('put')->andReturnUsing(function (): array {
        throw new App\Domain\Sync\Exceptions\WooWriteThrottleException('still closed', retryAfterSeconds: 1);
    });
    app()->instance(WooClient::class, $double);

    $exit = Artisan::call('products:push-status-to-woo', ['--live' => true, '--statuses' => 'pending']);
    $output = Artisan::output();

    // Not an error: the command re-selects by local status, so an unpushed
    // product is simply picked up next time.
    expect($output)->toContain('errors=0')
        ->and($output)->toContain('left unpushed')
        ->and($exit)->toBe(0);
});

it('still reports a genuine Woo failure as an error', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'ERR-1', 'status' => 'pending', 'woo_product_id' => 902]);

    $double = Mockery::mock(WooClient::class);
    $double->shouldReceive('put')->andReturnUsing(function (): array {
        throw new RuntimeException('Woo 500 Internal Server Error');
    });
    app()->instance(WooClient::class, $double);

    Artisan::call('products:push-status-to-woo', ['--live' => true, '--statuses' => 'pending']);

    expect(Artisan::output())->toContain('errors=1');
});
