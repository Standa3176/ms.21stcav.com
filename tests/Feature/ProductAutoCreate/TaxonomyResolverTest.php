<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Sync\Services\WooClient;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 03 Task 1 — TaxonomyResolver
|--------------------------------------------------------------------------
| Covers:
|   - resolveBrand('Logitech') returns matching term id from Woo REST.
|   - resolveBrand('logitech') case-insensitive matches 'Logitech' term name.
|   - resolveBrand(null) / resolveBrand('') / resolveBrand('   ') returns null.
|   - resolveBrand with empty Woo response returns null.
|   - resolveBrand swallows Woo REST exceptions → returns null.
|   - resolveCategory mirrors all branches via /products/categories endpoint.
*/

/**
 * Build a WooClient-shaped mock that returns a canned payload for a given
 * endpoint. Reuses the production WooClient::class so the resolver's
 * constructor DI resolves without trait-shim gymnastics.
 */
function fakeWooClientReturning(array $payloadByEndpoint): WooClient
{
    $mock = Mockery::mock(WooClient::class)->shouldAllowMockingProtectedMethods();
    $mock->shouldReceive('get')
        ->andReturnUsing(function (string $endpoint, array $query = []) use ($payloadByEndpoint): array {
            return $payloadByEndpoint[$endpoint] ?? [];
        });

    return $mock;
}

it('resolveBrand returns matching term id', function (): void {
    $woo = fakeWooClientReturning([
        'products/brands' => [
            ['id' => 42, 'name' => 'Logitech'],
            ['id' => 43, 'name' => 'Lenovo'],
        ],
    ]);
    $resolver = new TaxonomyResolver($woo);

    expect($resolver->resolveBrand('Logitech'))->toBe(42);
});

it('resolveBrand is case-insensitive + trim-tolerant', function (): void {
    $woo = fakeWooClientReturning([
        'products/brands' => [
            ['id' => 99, 'name' => 'Logitech'],
        ],
    ]);
    $resolver = new TaxonomyResolver($woo);

    expect($resolver->resolveBrand('logitech'))->toBe(99);
    expect($resolver->resolveBrand('  LOGITECH  '))->toBe(99);
});

it('resolveBrand returns null for null / empty / whitespace inputs', function (): void {
    $woo = fakeWooClientReturning([]);
    $resolver = new TaxonomyResolver($woo);

    expect($resolver->resolveBrand(null))->toBeNull();
    expect($resolver->resolveBrand(''))->toBeNull();
    expect($resolver->resolveBrand('   '))->toBeNull();
});

it('resolveBrand returns null when Woo returns no matching term', function (): void {
    $woo = fakeWooClientReturning([
        'products/brands' => [
            ['id' => 42, 'name' => 'Lenovo'],  // wrong brand
        ],
    ]);
    $resolver = new TaxonomyResolver($woo);

    expect($resolver->resolveBrand('Logitech'))->toBeNull();
});

it('resolveBrand returns null when Woo throws', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->andThrow(new RuntimeException('woo rest fault'));

    $resolver = new TaxonomyResolver($woo);

    expect($resolver->resolveBrand('Logitech'))->toBeNull();
});

it('resolveCategory returns matching category id', function (): void {
    $woo = fakeWooClientReturning([
        'products/categories' => [
            ['id' => 7, 'name' => 'Video Conferencing'],
        ],
    ]);
    $resolver = new TaxonomyResolver($woo);

    expect($resolver->resolveCategory('Video Conferencing'))->toBe(7);
});

it('resolveCategory is case-insensitive', function (): void {
    $woo = fakeWooClientReturning([
        'products/categories' => [
            ['id' => 7, 'name' => 'Video Conferencing'],
        ],
    ]);
    $resolver = new TaxonomyResolver($woo);

    expect($resolver->resolveCategory('VIDEO CONFERENCING'))->toBe(7);
});

it('resolveCategory returns null for null / empty / missing match / exceptions', function (): void {
    $resolver1 = new TaxonomyResolver(fakeWooClientReturning([]));
    expect($resolver1->resolveCategory(null))->toBeNull();
    expect($resolver1->resolveCategory(''))->toBeNull();

    $woo2 = Mockery::mock(WooClient::class);
    $woo2->shouldReceive('get')->andThrow(new RuntimeException('down'));
    $resolver2 = new TaxonomyResolver($woo2);
    expect($resolver2->resolveCategory('AnyCategory'))->toBeNull();
});

it('uses configured brand_taxonomy slug from config (legacy pa_ attribute fallback)', function (): void {
    // The configured brand_taxonomy slug is only consulted on the FALLBACK path,
    // after the native Woo `products/brands` taxonomy comes back empty. The
    // resolver first resolves the slug → numeric attribute id via
    // `products/attributes`, then reads `products/attributes/{id}/terms`.
    config()->set('product_auto_create.brand_taxonomy', 'product_brand');

    $woo = fakeWooClientReturning([
        'products/brands' => [],                                    // native taxonomy empty → fall back
        'products/attributes' => [['id' => 77, 'slug' => 'product_brand']],
        'products/attributes/77/terms' => [['id' => 5, 'name' => 'ACME']],
    ]);

    $resolver = new TaxonomyResolver($woo);
    expect($resolver->resolveBrand('ACME'))->toBe(5);
});
