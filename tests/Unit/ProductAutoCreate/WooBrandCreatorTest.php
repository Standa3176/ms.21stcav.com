<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\ProductAutoCreate\Services\WooBrandCreator;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Quick task 260702-qd8 — WooBrandCreator::ensureBrandTermId()
|--------------------------------------------------------------------------
| Find-or-create a WC-native /products/brands term for a manufacturer name.
| Normalised (HTML-decode + trim + collapse ws) + junk-guarded (config
| brands_to_add_exclude). NEVER throws — returns null on blank/junk, when an
| existing term is found it returns that id WITHOUT posting, treats a
| woocommerce_term_exists error as success, and returns null in shadow mode /
| on any failure. On a fresh create it forgets the taxonomy.brands cache so
| TaxonomyResolver re-reads the new term.
*/

/** Build a WooBrandCreator with a mocked WooClient + TaxonomyResolver. */
function makeBrandCreator(WooClient $woo, TaxonomyResolver $taxonomy): WooBrandCreator
{
    return new WooBrandCreator($woo, $taxonomy);
}

beforeEach(function (): void {
    // Match the shipped config so isJunkBrand() excludes the expected names.
    config(['product_auto_create.brands_to_add_exclude' => ['specials', 'un-branded', 'unbranded']]);
});

it('returns null and never POSTs for a junk brand', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('post');
    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('allBrands')->andReturn([]);

    expect(makeBrandCreator($woo, $taxonomy)->ensureBrandTermId('Specials'))->toBeNull();
});

it('returns null and never POSTs for a blank or whitespace name', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('post');
    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('allBrands')->andReturn([]);

    $creator = makeBrandCreator($woo, $taxonomy);
    expect($creator->ensureBrandTermId(''))->toBeNull();
    expect($creator->ensureBrandTermId('   '))->toBeNull();
    expect($creator->ensureBrandTermId(null))->toBeNull();
});

it('returns an existing Woo brand id without POSTing (case-insensitive)', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('post');
    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('allBrands')->andReturn([
        ['id' => 10, 'name' => 'Yealink'],
        ['id' => 11, 'name' => 'Lindy'],
    ]);

    expect(makeBrandCreator($woo, $taxonomy)->ensureBrandTermId('yealink'))->toBe(10);
});

it('POSTs a new brand, forgets the cache and returns the new id', function (): void {
    Cache::spy();

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')->once()
        ->with('products/brands', ['name' => 'Trantec'])
        ->andReturn(['id' => 555]);
    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('allBrands')->andReturn([
        ['id' => 10, 'name' => 'Yealink'],
    ]);

    expect(makeBrandCreator($woo, $taxonomy)->ensureBrandTermId('Trantec'))->toBe(555);

    Cache::shouldHaveReceived('forget')->with('taxonomy.brands');
});

it('normalises an HTML-entity name before POSTing', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')->once()
        ->with('products/brands', ['name' => "VOGEL'S"])
        ->andReturn(['id' => 77]);
    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('allBrands')->andReturn([]);

    expect(makeBrandCreator($woo, $taxonomy)->ensureBrandTermId('VOGEL&#039;S'))->toBe(77);
});

it('treats a term_exists error as success and re-reads the existing id', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')->once()
        ->andThrow(new RuntimeException('woocommerce_term_exists: A term with the name provided already exists.'));
    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    // First lookup (pre-POST) misses; after the race-forget the term is present.
    $taxonomy->shouldReceive('allBrands')->andReturn(
        [],
        [['id' => 99, 'name' => 'Trantec']],
    );

    expect(makeBrandCreator($woo, $taxonomy)->ensureBrandTermId('Trantec'))->toBe(99);
});

it('returns null in shadow mode (WOO_WRITE_ENABLED=false → no real id)', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')->once()->andReturn(['shadow_mode' => true, 'diff_id' => 1]);
    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('allBrands')->andReturn([]);

    expect(makeBrandCreator($woo, $taxonomy)->ensureBrandTermId('Trantec'))->toBeNull();
});

it('returns null (never throws) when the POST fails with a non-term-exists error', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')->once()->andThrow(new RuntimeException('500 Internal Server Error'));
    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('allBrands')->andReturn([]);

    expect(makeBrandCreator($woo, $taxonomy)->ensureBrandTermId('Trantec'))->toBeNull();
});
