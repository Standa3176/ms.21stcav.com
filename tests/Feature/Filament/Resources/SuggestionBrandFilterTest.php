<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions;
use App\Domain\Suggestions\Models\Suggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
|     - brand='Trantec'    → S2 + S3, not S1
|     - competitor='AVPartsmaster' → only S2
|
| NOTE (260707-iz9): the Brand-on-Woo TernaryFilter was retired (superseded by
| the Readiness SelectFilter), so its assertion was removed here. The seed still
| writes evidence.brand_on_woo (harmless) to keep the fixture shape stable.
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

/*
| Quick task 260710-obl — the Brand SelectFilter is ->searchable() but had no
| ->optionsLimit(), so Filament 3 capped the un-searched scroll dropdown at its
| default of 50 options. With >50 distinct alphabetically-sorted brands the list
| stopped partway through the "C"s. A functional filterTable() test can't catch
| this (it sets the value directly and ignores the render limit), so we pin the
| configured limit on the booted table filter instead.
*/
it('brand filter option limit clears the seeded brand count (no default-50 cap)', function (): void {
    $this->actingAs(brandFilterAdmin());

    // Seed 61 distinct pending new_product_opportunity brands (Brand-000..Brand-060),
    // well above Filament's default optionsLimit of 50. Reuse the existing Ballicom
    // competitor so the sightings fixture shape stays valid.
    for ($i = 0; $i <= 60; $i++) {
        $suffix = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        seedBrandSuggestion('OBL-'.$suffix, 'Brand-'.$suffix, false, 'Ballicom');
    }

    // The distinct-brand option list is cached (5-min TTL, pre-warmed by
    // products:refresh-brands-to-add); clear it so the seeded brands are visible.
    Cache::forget(SuggestionResource::BRAND_FILTER_OPTIONS_CACHE_KEY);

    $limit = Livewire::test(ListSuggestions::class)
        ->instance()->getTable()->getFilter('brand')->getOptionsLimit();

    // > the 61 seeded brands, and > Filament's default 50.
    expect($limit)->toBeGreaterThanOrEqual(61);
});
