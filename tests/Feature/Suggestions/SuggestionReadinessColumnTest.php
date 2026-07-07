<?php

declare(strict_types=1);

use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260707-gsy — per-record readiness() with supplier_sku_cache
|--------------------------------------------------------------------------
|
| readiness(Suggestion) gates on kind (null for non new_product_opportunity),
| pulls the SKU from evidence in PHP (NO JSON-in-SQL — dodges the SQLite ↔
| MariaDB trap), lowercases/trims it, and checks membership in
| supplier_sku_cache via a plain indexed where()->exists(). The result is
| memoised per request, so each assertion below resets self::$readinessMemo
| (via a fresh reflection reset) OR uses a distinct record.
*/

/** Reset the per-request memo so consecutive assertions don't read a stale verdict. */
function resetReadinessMemo(): void
{
    $ref = new ReflectionProperty(SuggestionResource::class, 'readinessMemo');
    $ref->setAccessible(true);
    $ref->setValue(null, []);
}

function seedSupplierSku(string $sku): void
{
    DB::table('supplier_sku_cache')->insert(['sku' => strtolower(trim($sku))]);
}

function makeReadinessSuggestion(string $kind, array $evidence): Suggestion
{
    return Suggestion::create([
        'kind' => $kind,
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'cid-'.uniqid(),
        'payload' => [],
        'evidence' => $evidence,
        'proposed_at' => now(),
    ]);
}

it('reads Ready for a sourceable, branded pending new_product_opportunity', function (): void {
    resetReadinessMemo();
    seedSupplierSku('barco-r99');

    $record = makeReadinessSuggestion('new_product_opportunity', [
        'sku' => 'BARCO-R99',
        'brand' => 'Barco',
    ]);

    expect(SuggestionResource::readiness($record))
        ->toBe(['label' => 'Ready', 'color' => 'success']);
});

it('reads Not sourceable when the SKU is absent from supplier_sku_cache', function (): void {
    resetReadinessMemo();

    $record = makeReadinessSuggestion('new_product_opportunity', [
        'sku' => 'NOT-IN-CACHE',
        'brand' => 'Barco',
    ]);

    expect(SuggestionResource::readiness($record))
        ->toBe(['label' => 'Not sourceable', 'color' => 'gray']);
});

it('reads Needs brand for a sourceable row with a blank brand', function (): void {
    resetReadinessMemo();
    seedSupplierSku('sku-blank');

    $record = makeReadinessSuggestion('new_product_opportunity', [
        'sku' => 'SKU-BLANK',
        'brand' => '',
    ]);

    expect(SuggestionResource::readiness($record))
        ->toBe(['label' => 'Needs brand', 'color' => 'warning']);
});

it('returns null for a non new_product_opportunity kind (margin_change)', function (): void {
    resetReadinessMemo();
    seedSupplierSku('margin-sku');

    $record = makeReadinessSuggestion('margin_change', [
        'sku' => 'MARGIN-SKU',
        'brand' => 'Barco',
    ]);

    expect(SuggestionResource::readiness($record))->toBeNull();
});
