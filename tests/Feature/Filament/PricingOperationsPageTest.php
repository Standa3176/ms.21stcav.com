<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Filament\Pages\PricingOperationsPage;
use App\Domain\Pricing\Services\PricingOpsReport;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| PricingOperationsPage — render smoke test
|--------------------------------------------------------------------------
| Exercises getViewData() (the live snapshot/new-SKU queries + the cached
| CompetitorPositionScanner) and the 4-panel Blade end-to-end, plus the
| viewAny RBAC gate (admin/pricing_manager/sales allowed; read_only denied).
*/

function pricingOpsUser(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

it('renders the Pricing Operations page for an authorised user (with data)', function (): void {
    // One below-cost product so panel 4 has a row to render.
    Product::factory()->create(['type' => 'simple', 'sku' => 'PO-AAA', 'buy_price' => 100.00]);
    CompetitorPrice::factory()->forSku('PO-AAA')->create(['price_pennies_ex_vat' => 9000]);

    $this->actingAs(pricingOpsUser('admin'))
        ->get('/admin/pricing-operations')
        ->assertOk()
        ->assertSee('Pricing Operations')
        ->assertSee('Competitor below our cost')
        ->assertSee('PO-AAA');
});

it('renders cleanly with an empty catalogue (no data)', function (): void {
    $this->actingAs(pricingOpsUser('pricing_manager'))
        ->get('/admin/pricing-operations')
        ->assertOk()
        ->assertSee('Recent sell-price changes');
});

it('denies a read_only user', function (): void {
    $this->actingAs(pricingOpsUser('read_only'))
        ->get('/admin/pricing-operations')
        ->assertForbidden();
});

it('below_cost modal renders the Brand column and the 3 filter selects', function (): void {
    // 260606-rld Task 3: clicking the below-cost tile fires the Filament Action
    // closure which renders pricing-ops-bucket.blade with showBrand=true. The
    // modal payload should include the brand name (Yealink) + the 3
    // "All brands / All suppliers / All competitors" SelectFilter placeholders.
    Cache::forget(PricingOpsReport::CACHE_KEY);
    Cache::forget('taxonomy.brands');

    $stub = Mockery::mock(TaxonomyResolver::class);
    $stub->shouldReceive('allBrands')->andReturn([
        ['id' => 10, 'name' => 'Yealink'],
    ]);
    app()->instance(TaxonomyResolver::class, $stub);

    Product::factory()->create([
        'type' => 'simple', 'sku' => 'TBC-BRD-1', 'buy_price' => 100.00, 'brand_id' => 10,
    ]);
    CompetitorPrice::factory()->forSku('TBC-BRD-1')->create(['price_pennies_ex_vat' => 9000]);

    \Livewire\Livewire::actingAs(pricingOpsUser('admin'))
        ->test(PricingOperationsPage::class)
        ->mountAction('belowCost')
        ->assertSee('All brands')
        ->assertSee('All suppliers')
        ->assertSee('All competitors')
        ->assertSee('Yealink')
        ->assertSee('TBC-BRD-1');
});

it('winnable modal does NOT render a Brand column or filter selects', function (): void {
    // Same fixture but the winnable tile uses the legacy 5-column render
    // (no Brand column, no "All brands" placeholder option).
    Cache::forget(PricingOpsReport::CACHE_KEY);
    Cache::forget('taxonomy.brands');

    $stub = Mockery::mock(TaxonomyResolver::class);
    $stub->shouldReceive('allBrands')->andReturn([
        ['id' => 10, 'name' => 'Yealink'],
    ]);
    app()->instance(TaxonomyResolver::class, $stub);

    // cost £100 ex, competitor £120 ex → margin 20% → winnable
    Product::factory()->create([
        'type' => 'simple', 'sku' => 'TBC-WIN-1', 'buy_price' => 100.00, 'brand_id' => 10,
    ]);
    CompetitorPrice::factory()->forSku('TBC-WIN-1')->create(['price_pennies_ex_vat' => 12000]);

    \Livewire\Livewire::actingAs(pricingOpsUser('admin'))
        ->test(PricingOperationsPage::class)
        ->mountAction('winnable')
        ->assertSee('TBC-WIN-1')
        ->assertDontSee('All brands');
});
