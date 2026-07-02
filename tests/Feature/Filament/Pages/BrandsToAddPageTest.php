<?php

declare(strict_types=1);

use App\Console\Commands\RefreshBrandsToAddCommand;
use App\Domain\ProductAutoCreate\Filament\Pages\BrandsToAddPage;
use App\Domain\Sync\Services\WooClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260702-hg1 — BrandsToAddPage Pest feature test
|--------------------------------------------------------------------------
|
| Covers (plan Task 1 <behavior>):
|   1. admin/pricing_manager can mount; sales/read_only get 403.
|   2. Page renders the cached brands (name + unlock count).
|   3. createBrand('Trantec') with a bound WooClient stub posts
|      products/brands ['name'=>'Trantec'], drops Trantec from the cache
|      summary + the in-memory list, leaves Foo.
|   4. read_only createBrand() aborts 403 and records NO post.
|
| WooClient is stubbed via an anonymous subclass that records every post()
| call (skipping the parent constructor so no IntegrationLogger / resolver
| wiring is needed) — mirrors the CategoryAuditPage TaxonomyResolver stub.
*/

function brandsToAddUser(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * Bind a WooClient stub recording post() calls; returns the shared recorder.
 *
 * @return object{calls: array<int, array{endpoint:string, payload:array}>}
 */
function bindRecordingWooClient(): object
{
    $recorder = new class
    {
        /** @var array<int, array{endpoint:string, payload:array}> */
        public array $calls = [];
    };

    $stub = new class($recorder) extends WooClient
    {
        public function __construct(private object $recorder) {} // skip parent DI

        public function post(string $endpoint, array $payload): array
        {
            $this->recorder->calls[] = ['endpoint' => $endpoint, 'payload' => $payload];

            return ['id' => 999, 'name' => $payload['name'] ?? ''];
        }
    };

    app()->instance(WooClient::class, $stub);

    return $recorder;
}

function seedBrandsToAddCache(): void
{
    Cache::put(RefreshBrandsToAddCommand::CACHE_KEY, [
        'generated_at' => now()->toIso8601String(),
        'brands' => [
            ['brand' => 'Trantec', 'count' => 3, 'skus' => ['tx-1', 'tx-2', 'tx-3']],
            ['brand' => 'Foo', 'count' => 1, 'skus' => ['foo-1']],
        ],
    ]);
}

it('admin can mount the page', function (): void {
    $this->actingAs(brandsToAddUser('admin'));
    seedBrandsToAddCache();

    Livewire::test(BrandsToAddPage::class)->assertSuccessful();
});

it('pricing_manager can mount the page', function (): void {
    $this->actingAs(brandsToAddUser('pricing_manager'));
    seedBrandsToAddCache();

    Livewire::test(BrandsToAddPage::class)->assertSuccessful();
});

it('sales role gets 403 on page access', function (): void {
    $this->actingAs(brandsToAddUser('sales'));

    expect(BrandsToAddPage::canAccess())->toBeFalse();
    $this->get('/admin/brands-to-add')->assertForbidden();
});

it('read_only role gets 403 on page access', function (): void {
    $this->actingAs(brandsToAddUser('read_only'));

    expect(BrandsToAddPage::canAccess())->toBeFalse();
    $this->get('/admin/brands-to-add')->assertForbidden();
});

it('renders the cached brands with names and unlock counts', function (): void {
    $this->actingAs(brandsToAddUser('admin'));
    seedBrandsToAddCache();

    Livewire::test(BrandsToAddPage::class)
        ->assertSuccessful()
        ->assertSee('Trantec')
        ->assertSee('3')
        ->assertSee('Foo');
});

it('createBrand posts products/brands and drops the row from cache + list', function (): void {
    $this->actingAs(brandsToAddUser('admin'));
    $recorder = bindRecordingWooClient();
    seedBrandsToAddCache();

    $component = Livewire::test(BrandsToAddPage::class)
        ->call('createBrand', 'Trantec')
        ->assertHasNoErrors();

    // The stub recorded exactly one post to products/brands with the brand name.
    expect($recorder->calls)->toHaveCount(1);
    expect($recorder->calls[0]['endpoint'])->toBe('products/brands');
    expect($recorder->calls[0]['payload'])->toBe(['name' => 'Trantec']);

    // Trantec removed from the in-memory list; Foo remains.
    $brands = collect($component->get('brands'))->pluck('brand')->all();
    expect($brands)->not->toContain('Trantec');
    expect($brands)->toContain('Foo');

    // Trantec removed from the cached summary too (survives a page reload).
    $cached = Cache::get(RefreshBrandsToAddCommand::CACHE_KEY);
    $cachedBrands = collect($cached['brands'])->pluck('brand')->all();
    expect($cachedBrands)->not->toContain('Trantec');
    expect($cachedBrands)->toContain('Foo');
});

it('read_only createBrand aborts 403 and records no Woo post', function (): void {
    $this->actingAs(brandsToAddUser('read_only'));
    $recorder = bindRecordingWooClient();
    seedBrandsToAddCache();

    // abort_unless(403) throws an HttpException with status 403. Invoke the
    // Livewire method directly on a page instance (Livewire's ->call() wraps
    // the abort in its own snapshot machinery which mangles the exception type)
    // so we assert the raw guard. NO Woo post must be recorded (the write never
    // reaches WooClient — defence-in-depth beyond the Blade @if button gate).
    try {
        (new BrandsToAddPage)->createBrand('Trantec');
        $this->fail('createBrand should have aborted 403 for read_only.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }

    expect($recorder->calls)->toHaveCount(0);
});
