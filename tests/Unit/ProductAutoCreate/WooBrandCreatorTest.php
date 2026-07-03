<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\ProductAutoCreate\Services\WooBrandCreator;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Quick task 260702-qd8 / 260703-c8q — WooBrandCreator::ensureBrandTermId()
|--------------------------------------------------------------------------
| Find-or-create a WC-native /products/brands term for a manufacturer name.
| Normalised (HTML-decode + trim + collapse ws) + junk-guarded (config
| brands_to_add_exclude). NEVER throws — returns null on blank/junk, when an
| existing term is found it returns that id WITHOUT posting, treats a
| woocommerce_term_exists error as success, and returns null in shadow mode /
| on any failure. On a fresh create it forgets the taxonomy.brands cache so
| TaxonomyResolver re-reads the new term.
|
| 260703-c8q: the existence check now delegates to the SAME fuzzy matcher the
| per-row create path trusts (TaxonomyResolver::resolveBrand → bestMatchId,
| FUZZY_THRESHOLD 0.85). So a more-specific feed name like 'Barco Clickshare'
| reuses the existing 'Barco' term (containment scores 0.9) instead of POSTing
| a near-duplicate; genuinely-new names (Trantec) still create. These tests use
| a REAL TaxonomyResolver from the container and seed the brand list the
| resolver reads via Cache::put('taxonomy.brands', ...) — the only mock is the
| WooClient (asserting post() call-count).
*/

/**
 * Build a WooBrandCreator with a mocked WooClient + a REAL TaxonomyResolver
 * (from the container) so the existence check exercises the actual fuzzy
 * matcher. The resolver reads the cached `taxonomy.brands` list, so tests seed
 * it via seedBrands() — no Woo REST calls happen for the lookup.
 */
function makeBrandCreator(WooClient $woo): WooBrandCreator
{
    app()->instance(WooClient::class, $woo);
    $taxonomy = app(TaxonomyResolver::class);

    return new WooBrandCreator($woo, $taxonomy);
}

/** Seed the brand list the resolver reads (bypasses the Woo REST paginate). */
function seedBrands(array $brands): void
{
    Cache::put('taxonomy.brands', $brands, 3600);
}

beforeEach(function (): void {
    Cache::flush();
    // Match the shipped config so isJunkBrand() excludes the expected names.
    config(['product_auto_create.brands_to_add_exclude' => ['specials', 'un-branded', 'unbranded']]);
});

it('returns null and never POSTs for a junk brand', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('post');
    seedBrands([['id' => 10, 'name' => 'Barco']]);

    expect(makeBrandCreator($woo)->ensureBrandTermId('Specials'))->toBeNull();
});

it('returns null and never POSTs for a blank or whitespace name', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('post');
    seedBrands([['id' => 10, 'name' => 'Barco']]);

    $creator = makeBrandCreator($woo);
    expect($creator->ensureBrandTermId(''))->toBeNull();
    expect($creator->ensureBrandTermId('   '))->toBeNull();
    expect($creator->ensureBrandTermId(null))->toBeNull();
});

it('reuses an existing brand via fuzzy match — "Barco Clickshare" → existing Barco, no POST', function (): void {
    // THE FIX: a more-specific feed name resolves to the existing 'Barco' term
    // (containment scores 0.9 ≥ 0.85) instead of minting a near-duplicate.
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('post');
    seedBrands([['id' => 10, 'name' => 'Barco'], ['id' => 11, 'name' => 'Yealink']]);

    expect(makeBrandCreator($woo)->ensureBrandTermId('Barco Clickshare'))->toBe(10);
});

it('returns an existing Woo brand id without POSTing (exact match)', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('post');
    seedBrands([['id' => 10, 'name' => 'Barco'], ['id' => 11, 'name' => 'Yealink']]);

    expect(makeBrandCreator($woo)->ensureBrandTermId('Yealink'))->toBe(11);
});

it('POSTs a genuinely-new brand, forgets the cache and returns the new id', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')->once()
        ->with('products/brands', ['name' => 'Trantec'])
        ->andReturn(['id' => 555]);
    // No seeded brand scores >= 0.85 against 'Trantec' → resolveBrand returns null.
    seedBrands([['id' => 10, 'name' => 'Barco'], ['id' => 11, 'name' => 'Yealink']]);

    expect(makeBrandCreator($woo)->ensureBrandTermId('Trantec'))->toBe(555);

    // Create path forgets the brand cache so the resolver re-reads the new term.
    expect(Cache::has('taxonomy.brands'))->toBeFalse();
});

it('normalises an HTML-entity name before POSTing', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')->once()
        ->with('products/brands', ['name' => "VOGEL'S"])
        ->andReturn(['id' => 77]);
    seedBrands([]);

    expect(makeBrandCreator($woo)->ensureBrandTermId('VOGEL&#039;S'))->toBe(77);
});

it('treats a term_exists error as success and re-reads the existing id', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')->once()
        ->andThrow(new RuntimeException('woocommerce_term_exists: A term with the name provided already exists.'));
    // After the race-forget the resolver re-reads brands from Woo and finds it.
    $woo->shouldReceive('get')->with('products/brands', Mockery::any())
        ->andReturn([['id' => 99, 'name' => 'Trantec']]);
    seedBrands([]); // pre-POST lookup misses

    expect(makeBrandCreator($woo)->ensureBrandTermId('Trantec'))->toBe(99);
});

it('returns null in shadow mode (WOO_WRITE_ENABLED=false → no real id)', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')->once()->andReturn(['shadow_mode' => true, 'diff_id' => 1]);
    seedBrands([]);

    expect(makeBrandCreator($woo)->ensureBrandTermId('Trantec'))->toBeNull();
});

it('returns null (never throws) when the POST fails with a non-term-exists error', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')->once()->andThrow(new RuntimeException('500 Internal Server Error'));
    seedBrands([]);

    expect(makeBrandCreator($woo)->ensureBrandTermId('Trantec'))->toBeNull();
});
