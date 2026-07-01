<?php

declare(strict_types=1);

use App\Domain\Pricing\Events\ProductPriceChanged;
use App\Domain\Pricing\Listeners\PushPriceChangeToWoo;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260701-n4y — PushPriceChangeToWoo hardening.
|--------------------------------------------------------------------------
|
| Two behaviour changes (working live-price sync for PUBLISHED products is
| unchanged):
|   1. handle() skips products whose status != 'publish' (drafts aren't on the
|      storefront) — ZERO put calls, logs pricing.woo_push_skipped_not_published.
|   2. Both put() sites route through putOrClearStale(): a Woo error whose
|      message contains 'woocommerce_rest_product_invalid_id' is caught → logs
|      pricing.woo_push_stale_id_cleared → NULLs woo_product_id (saveQuietly) →
|      returns WITHOUT rethrowing (job succeeds, no retry, product flagged for
|      re-link). ANY OTHER exception rethrows so genuine/transient errors retry.
|
| Boundary strategy mirrors bindWooStub in
| tests/Feature/Console/BackfillCategoryFromWooCommandTest.php: an anonymous
| WooClient subclass whose __construct skips the parent and overrides put() to
| record calls + optionally throw a configured Throwable.
*/

/**
 * Bind an anonymous WooClient stub whose put() records calls and optionally
 * throws a configured Throwable. get() is unused for these cases.
 *
 * @return object the bound stub with public $calls + $throwOnPut
 */
function bindWooPutStub(?Throwable $throwOnPut = null): object
{
    $stub = new class($throwOnPut) extends WooClient
    {
        /** @var array<int, array{endpoint:string, payload:array<string,mixed>}> */
        public array $calls = [];

        public function __construct(public ?Throwable $throwOnPut = null)
        {
            // Skip parent constructor — no IntegrationLogger / resolver needed.
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->calls[] = ['endpoint' => $endpoint, 'payload' => $payload];

            if ($this->throwOnPut !== null) {
                throw $this->throwOnPut;
            }

            return ['id' => 1];
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}

/** Build a ProductPriceChanged event for a simple (non-variant) product. */
function priceEventFor(Product $product): ProductPriceChanged
{
    return new ProductPriceChanged(
        productId: $product->id,
        variantId: null,
        sku: (string) $product->sku,
        oldPennies: 8000,
        newPennies: 8100,
        marginBasisPoints: 3500,
        resolutionSource: 'default_tier',
    );
}

// ══════════════════════════════════════════════════════════════════════════════
// Case A — invalid-id error → clears woo_product_id + no rethrow, one put
// ══════════════════════════════════════════════════════════════════════════════

it('Case A: invalid-id error clears woo_product_id and does NOT rethrow', function (): void {
    $product = Product::factory()->create([
        'sku' => 'A-STALE-001',
        'woo_product_id' => 900001,
        'status' => 'publish',
    ]);

    $stub = bindWooPutStub(new RuntimeException(
        'Error: Invalid ID. [woocommerce_rest_product_invalid_id] Status: 400',
    ));

    // Should NOT throw.
    app(PushPriceChangeToWoo::class)->handle(priceEventFor($product));

    expect($product->fresh()->woo_product_id)->toBeNull();
    expect($stub->calls)->toHaveCount(1);
    expect($stub->calls[0]['endpoint'])->toBe('products/900001');
});

// ══════════════════════════════════════════════════════════════════════════════
// Case B — non-publish product → zero puts, id unchanged
// ══════════════════════════════════════════════════════════════════════════════

it('Case B: draft product is skipped — zero put calls, woo_product_id unchanged', function (): void {
    $product = Product::factory()->create([
        'sku' => 'B-DRAFT-001',
        'woo_product_id' => 900002,
        'status' => 'draft',
    ]);

    $stub = bindWooPutStub();

    app(PushPriceChangeToWoo::class)->handle(priceEventFor($product));

    expect($stub->calls)->toHaveCount(0);
    expect($product->fresh()->woo_product_id)->toBe(900002);
});

// ══════════════════════════════════════════════════════════════════════════════
// Case C — generic error → rethrows, id unchanged
// ══════════════════════════════════════════════════════════════════════════════

it('Case C: a generic Woo error is rethrown and woo_product_id is unchanged', function (): void {
    $product = Product::factory()->create([
        'sku' => 'C-ERR-001',
        'woo_product_id' => 900003,
        'status' => 'publish',
    ]);

    bindWooPutStub(new RuntimeException('Woo 500 Internal Server Error'));

    expect(fn () => app(PushPriceChangeToWoo::class)->handle(priceEventFor($product)))
        ->toThrow(RuntimeException::class, 'Woo 500');

    expect($product->fresh()->woo_product_id)->toBe(900003);
});

// ══════════════════════════════════════════════════════════════════════════════
// Case D — happy path (published, put succeeds) → one put, id unchanged
// ══════════════════════════════════════════════════════════════════════════════

it('Case D: published product with a working put keeps woo_product_id and pushes once', function (): void {
    $product = Product::factory()->create([
        'sku' => 'D-OK-001',
        'woo_product_id' => 900004,
        'status' => 'publish',
    ]);

    $stub = bindWooPutStub();

    app(PushPriceChangeToWoo::class)->handle(priceEventFor($product));

    expect($stub->calls)->toHaveCount(1);
    expect($stub->calls[0]['endpoint'])->toBe('products/900004');
    expect($stub->calls[0]['payload'])->toHaveKey('regular_price');
    expect($product->fresh()->woo_product_id)->toBe(900004);
});
