<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Services\PricingOpsReport;
use App\Domain\Products\Models\Product;
use App\Models\User;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| PricingOpsReport bucket merge + CSV export route
|--------------------------------------------------------------------------
*/

function pricingExportUser(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u->fresh();
}

beforeEach(fn () => config(['competitor.min_margin_floor_bps' => 600]));

it('competitorBucket(matched) merges all buckets worst-margin first', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'BC', 'buy_price' => 100.00]); // -10% below cost
    CompetitorPrice::factory()->forSku('BC')->create(['price_pennies_ex_vat' => 9000]);
    Product::factory()->create(['type' => 'simple', 'sku' => 'WN', 'buy_price' => 100.00]); // +20% winnable
    CompetitorPrice::factory()->forSku('WN')->create(['price_pennies_ex_vat' => 12000]);

    $matched = app(PricingOpsReport::class)->competitorBucket('matched');

    expect($matched)->toHaveCount(2)
        ->and($matched[0]['sku'])->toBe('BC'); // worst (lowest margin) first
});

it('exports a bucket as CSV for an authorised user', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'EXP-1', 'name' => 'Exp One', 'buy_price' => 100.00]);
    CompetitorPrice::factory()->forSku('EXP-1')->create(['price_pennies_ex_vat' => 9000]);

    $res = $this->actingAs(pricingExportUser('admin'))
        ->get(route('pricing-ops.export', ['bucket' => 'below_cost']));

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv')
        ->and($res->streamedContent())->toContain('EXP-1')->toContain('Margin (%)');
});

it('exports a bucket as XLSX when format=xlsx', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'XLS-1', 'name' => 'Xls One', 'buy_price' => 100.00]);
    CompetitorPrice::factory()->forSku('XLS-1')->create(['price_pennies_ex_vat' => 9000]);

    $res = $this->actingAs(pricingExportUser('admin'))
        ->get(route('pricing-ops.export', ['bucket' => 'below_cost', 'format' => 'xlsx']));

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('spreadsheetml')
        ->and($res->headers->get('content-disposition'))->toContain('.xlsx');
});

it('denies export to a read_only user', function (): void {
    $this->actingAs(pricingExportUser('read_only'))
        ->get(route('pricing-ops.export', ['bucket' => 'below_cost']))
        ->assertForbidden();
});

it('404s an unknown export bucket', function (): void {
    $this->actingAs(pricingExportUser('admin'))
        ->get(route('pricing-ops.export', ['bucket' => 'nope']))
        ->assertNotFound();
});
