<?php

declare(strict_types=1);

use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages\ListSuggestions;
use App\Domain\Suggestions\Models\Suggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260707-iz9 — SuggestionResource Readiness SelectFilter
|--------------------------------------------------------------------------
|
| The Readiness FILTER must classify rows the SAME way as the 260707-gsy
| Readiness COLUMN (SuggestionResource::readiness()):
|   - sourceable (SKU in supplier_sku_cache) + non-blank non-junk brand → ready
|   - sourceable + (blank OR junk brand)                                → needs_brand
|   - NOT sourceable                                                    → not_sourceable
|
| CRITICAL (memory: SQLite ↔ MariaDB strict trap) — the filter SQL is
| driver-portable: sourceableExistsSql() switches json_extract (SQLite,
| tests) vs JSON_UNQUOTE(JSON_EXTRACT()) (MariaDB, prod); brand via the
| existing brandJsonExpr(); junk from product_auto_create.brands_to_add_exclude.
|
| Seeds 4 pending new_product_opportunity rows (so they pass the default
| Pending + new_product_opportunity filters):
|   A: sku in cache, brand='Barco'    → ready
|   B: sku in cache, brand=''         → needs_brand (blank)
|   C: sku in cache, brand='Specials' → needs_brand (junk config)
|   D: sku NOT in cache               → not_sourceable
*/

function readinessFilterAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user->fresh();
}

function seedReadinessCache(string $sku): void
{
    DB::table('supplier_sku_cache')->insert(['sku' => strtolower(trim($sku))]);
}

function seedReadinessSuggestion(string $sku, string $brand): Suggestion
{
    return Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'cid-'.$sku,
        'payload' => [],
        'evidence' => [
            'sku' => $sku,
            'brand' => $brand,
            'supporting_competitors' => 1,
            'competitor_sightings' => [
                ['name' => 'Ballicom', 'price_gross_pennies' => 1000],
            ],
        ],
        'proposed_at' => now(),
    ]);
}

/** Reset the readiness() per-request memo so column assertions read fresh verdicts. */
function resetReadinessFilterMemo(): void
{
    $ref = new ReflectionProperty(SuggestionResource::class, 'readinessMemo');
    $ref->setAccessible(true);
    $ref->setValue(null, []);
}

beforeEach(function (): void {
    // A + B + C are sourceable (SKU in cache); D is not.
    seedReadinessCache('READY-A');
    seedReadinessCache('BLANK-B');
    seedReadinessCache('JUNK-C');

    $this->a = seedReadinessSuggestion('READY-A', 'Barco');      // ready
    $this->b = seedReadinessSuggestion('BLANK-B', '');           // needs_brand (blank)
    $this->c = seedReadinessSuggestion('JUNK-C', 'Specials');    // needs_brand (junk)
    $this->d = seedReadinessSuggestion('MISS-D', 'Barco');       // not_sourceable (not in cache)
});

it('readiness=ready narrows to the sourceable, usably-branded row only', function (): void {
    $this->actingAs(readinessFilterAdmin());

    Livewire::test(ListSuggestions::class)
        ->filterTable('readiness', 'ready')
        ->assertCanSeeTableRecords([$this->a])
        ->assertCanNotSeeTableRecords([$this->b, $this->c, $this->d]);
});

it('readiness=needs_brand narrows to sourceable rows with blank/junk brand', function (): void {
    $this->actingAs(readinessFilterAdmin());

    Livewire::test(ListSuggestions::class)
        ->filterTable('readiness', 'needs_brand')
        ->assertCanSeeTableRecords([$this->b, $this->c])
        ->assertCanNotSeeTableRecords([$this->a, $this->d]);
});

it('readiness=not_sourceable narrows to the row whose SKU is absent from the cache', function (): void {
    $this->actingAs(readinessFilterAdmin());

    Livewire::test(ListSuggestions::class)
        ->filterTable('readiness', 'not_sourceable')
        ->assertCanSeeTableRecords([$this->d])
        ->assertCanNotSeeTableRecords([$this->a, $this->b, $this->c]);
});

it('the filter verdict matches the readiness() column for A–D', function (): void {
    // Column verdict labels for each seeded row (memo reset between reads).
    $verdict = function (Suggestion $record): ?string {
        resetReadinessFilterMemo();

        return SuggestionResource::readiness($record)['label'] ?? null;
    };

    expect($verdict($this->a))->toBe('Ready')          // → filter 'ready'
        ->and($verdict($this->b))->toBe('Needs brand')  // → filter 'needs_brand'
        ->and($verdict($this->c))->toBe('Needs brand')  // → filter 'needs_brand'
        ->and($verdict($this->d))->toBe('Not sourceable'); // → filter 'not_sourceable'
});
