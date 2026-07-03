<?php

declare(strict_types=1);

use App\Console\Commands\RefreshBrandsToAddCommand;
use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260703-qc0 — SuggestionResource::brandFilterOptions() cache
|--------------------------------------------------------------------------
|
| The /admin/suggestions Brand SelectFilter used to run a DISTINCT-JSON scan
| over the whole suggestions table (~8,826 rows) on EVERY page render, which
| 30s-timed-out the admin page under load. brandFilterOptions() now wraps that
| SAME query in Cache::remember(key='suggestions.brand_filter_options', 300s)
| so a cold cache costs at most ONE scan per 5 minutes and a warm cache is
| instant. products:refresh-brands-to-add pre-warms the key off the web path.
|
| This is a pure caching wrapper — the cached result is byte-identical to the
| live query (same kind scope, brandJsonExpr, filter/sort/mapWithKeys shape),
| and the filter's ->query() (the WHERE applied when a brand is picked) is
| UNCHANGED. Driver-portable: brandJsonExpr() is SQLite here, MariaDB in prod.
|
| Helper name intentionally unique — SuggestionBrandFilterTest.php already
| declares seedBrandSuggestion()/brandFilterAdmin(); Pest loads every test file
| so a redeclaration would fatal.
*/

function makeNpoSuggestionWithBrand(string $sku, ?string $brand): Suggestion
{
    $evidence = ['sku' => $sku, 'supporting_competitors' => 1];
    if ($brand !== null) {
        $evidence['brand'] = $brand;
    }

    return Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'cid-'.$sku,
        'payload' => [],
        'evidence' => $evidence,
        'proposed_at' => now(),
    ]);
}

it('returns distinct sorted brand options and caches them', function (): void {
    Cache::forget(SuggestionResource::BRAND_FILTER_OPTIONS_CACHE_KEY);

    makeNpoSuggestionWithBrand('A', 'Yealink');
    makeNpoSuggestionWithBrand('B', 'Sony');
    makeNpoSuggestionWithBrand('C', 'Yealink'); // duplicate brand
    makeNpoSuggestionWithBrand('D', null);      // no brand → excluded

    $options = SuggestionResource::brandFilterOptions();

    // Distinct, sorted, mapWithKeys shape — byte-identical to the live query.
    expect($options)->toBe(['Sony' => 'Sony', 'Yealink' => 'Yealink']);
    expect(Cache::has(SuggestionResource::BRAND_FILTER_OPTIONS_CACHE_KEY))->toBeTrue();
});

it('serves the cached set until forgotten, then rebuilds', function (): void {
    Cache::forget(SuggestionResource::BRAND_FILTER_OPTIONS_CACHE_KEY);

    makeNpoSuggestionWithBrand('A', 'Yealink');
    makeNpoSuggestionWithBrand('B', 'Sony');

    expect(SuggestionResource::brandFilterOptions())
        ->toBe(['Sony' => 'Sony', 'Yealink' => 'Yealink']);

    // Insert a NEW brand after the first (caching) call.
    makeNpoSuggestionWithBrand('E', 'Bosch');

    // Still the OLD cached set — proves it is cached, not re-queried.
    expect(SuggestionResource::brandFilterOptions())
        ->toBe(['Sony' => 'Sony', 'Yealink' => 'Yealink']);

    // Forget + rebuild now reflects the new brand.
    Cache::forget(SuggestionResource::BRAND_FILTER_OPTIONS_CACHE_KEY);
    expect(SuggestionResource::brandFilterOptions())
        ->toBe(['Bosch' => 'Bosch', 'Sony' => 'Sony', 'Yealink' => 'Yealink']);
});

it('products:refresh-brands-to-add leaves the brand-filter cache populated', function (): void {
    // Stub TaxonomyResolver so the command finds Woo brands and does not abort.
    $taxonomy = new class extends TaxonomyResolver
    {
        public function __construct() {} // skip parent WooClient constructor

        public function allBrands(): array
        {
            return [['id' => 1, 'name' => 'Sony']];
        }
    };
    app()->instance(TaxonomyResolver::class, $taxonomy);

    // Stub the credential resolver → a fast-failing mysqli target so
    // fetchManufacturers() returns the all-empty map (no live supplier_db in
    // tests) and the command still reaches its cache write + pre-warm.
    $resolver = new class extends IntegrationCredentialResolver
    {
        public function for(IntegrationCredentialKind $kind): array
        {
            return [
                'host' => '127.0.0.1',
                'port' => '1',
                'database' => 'x',
                'username' => 'x',
                'password' => 'x',
            ];
        }
    };
    app()->instance(IntegrationCredentialResolver::class, $resolver);

    makeNpoSuggestionWithBrand('A', 'Yealink');

    // Cold cache before the scheduled run.
    Cache::forget(SuggestionResource::BRAND_FILTER_OPTIONS_CACHE_KEY);

    $this->artisan('products:refresh-brands-to-add')->assertSuccessful();

    // The command pre-warmed (forget + rebuild) the filter-options key.
    expect(Cache::has(SuggestionResource::BRAND_FILTER_OPTIONS_CACHE_KEY))->toBeTrue();
});
