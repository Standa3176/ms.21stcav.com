<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Products\Models\Product;
use App\Models\User;
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
