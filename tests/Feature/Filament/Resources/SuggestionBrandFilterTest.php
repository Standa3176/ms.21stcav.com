<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions;
use App\Domain\Suggestions\Models\Suggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260702-hg1 — SuggestionResource Brand/Competitor/on-Woo filters
|--------------------------------------------------------------------------
|
| Covers (plan Task 2 <behavior>):
|   Seeds 3 pending new_product_opportunity suggestions:
|     S1 brand=Yealink brand_on_woo=true  sightings=[Ballicom]
|     S2 brand=Trantec brand_on_woo=false sightings=[AVPartsmaster]
|     S3 brand=Trantec brand_on_woo=false sightings=[Ballicom]
|   plus Competitor rows (Ballicom, AVPartsmaster) so the SelectFilter options
|   resolve. Asserts each filter narrows via Livewire ->filterTable():
|     - brand_on_woo=false → S2 + S3, not S1
|     - brand='Trantec'    → S2 + S3, not S1
|     - competitor='AVPartsmaster' → only S2
*/

function brandFilterAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user->fresh();
}

function seedBrandSuggestion(string $sku, string $brand, bool $brandOnWoo, string $competitor): Suggestion
{
    return Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'cid-'.$sku,
        'payload' => [],
        'evidence' => [
            'sku' => $sku,
            'brand' => $brand,
            'brand_on_woo' => $brandOnWoo,
            'supporting_competitors' => 1,
            'competitor_sightings' => [
                ['name' => $competitor, 'price_gross_pennies' => 1000],
            ],
        ],
        'proposed_at' => now(),
    ]);
}

beforeEach(function (): void {
    Competitor::factory()->create(['name' => 'Ballicom', 'slug' => 'ballicom']);
    Competitor::factory()->create(['name' => 'AVPartsmaster', 'slug' => 'avpartsmaster']);

    $this->s1 = seedBrandSuggestion('SKU-1', 'Yealink', true, 'Ballicom');
    $this->s2 = seedBrandSuggestion('SKU-2', 'Trantec', false, 'AVPartsmaster');
    $this->s3 = seedBrandSuggestion('SKU-3', 'Trantec', false, 'Ballicom');
});

it('brand_on_woo=false narrows to the not-on-Woo suggestions', function (): void {
    $this->actingAs(brandFilterAdmin());

    Livewire::test(ListSuggestions::class)
        ->filterTable('brand_on_woo', false)
        ->assertCanSeeTableRecords([$this->s2, $this->s3])
        ->assertCanNotSeeTableRecords([$this->s1]);
});

it('brand=Trantec narrows to the Trantec suggestions', function (): void {
    $this->actingAs(brandFilterAdmin());

    Livewire::test(ListSuggestions::class)
        ->filterTable('brand', 'Trantec')
        ->assertCanSeeTableRecords([$this->s2, $this->s3])
        ->assertCanNotSeeTableRecords([$this->s1]);
});

it('competitor=AVPartsmaster narrows to the single matching suggestion', function (): void {
    $this->actingAs(brandFilterAdmin());

    Livewire::test(ListSuggestions::class)
        ->filterTable('competitor', 'AVPartsmaster')
        ->assertCanSeeTableRecords([$this->s2])
        ->assertCanNotSeeTableRecords([$this->s1, $this->s3]);
});
