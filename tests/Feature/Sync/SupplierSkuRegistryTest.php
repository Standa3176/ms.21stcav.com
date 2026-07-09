<?php

declare(strict_types=1);

use App\Domain\Products\Models\SupplierSkuCache;

/*
|--------------------------------------------------------------------------
| SupplierSkuRegistry — supplier_sku_cache write contract (SYNC-04 guard)
|--------------------------------------------------------------------------
|
| Quick task 260709-hl0. SupplierSkuRegistry::refresh() was de-facaded:
| DB::table('supplier_sku_cache')->truncate()/insertOrIgnore() became
| SupplierSkuCache::query()->truncate()/insertOrIgnore() so the Sync layer
| no longer imports the Illuminate DB facade (SYNC-04 / -WpDirectDb).
|
| refresh() reads the ~900k-row remote feed via a raw \mysqli connection to
| the supplier DB (no injectable seam), so it cannot run end-to-end under
| the SQLite test DB. These tests instead pin the LOCAL write contract that
| the DB->Eloquent swap actually touched: truncate empties the table, the
| chunked insertOrIgnore repopulates it, the sku PRIMARY KEY dedupes, and
| the LOWER+TRIM+191-char key shape round-trips identically. If the model's
| $table / $guarded / $timestamps drift, or the query builder stops
| delegating truncate/insertOrIgnore to the base builder, these fail.
*/

/**
 * Mirror of the registry's buffer construction: DISTINCT LOWER(TRIM(...))
 * across mpn + suppliersku, empty-skipped, first-seen-wins, mb_substr(191).
 * Kept identical to SupplierSkuRegistry::refresh() so the assertions track
 * the real production key shape.
 *
 * @param  array<int, array{mpn: string, suppliersku: string}>  $feedRows
 * @return array<int, array{sku: string}>
 */
function buildSkuBuffer(array $feedRows): array
{
    $buffer = [];
    $seen = [];
    foreach ($feedRows as $row) {
        $mpnKey = mb_strtolower(trim($row['mpn']));
        $sskuKey = mb_strtolower(trim($row['suppliersku']));
        foreach ([$mpnKey, $sskuKey] as $k) {
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $buffer[] = ['sku' => mb_substr($k, 0, 191)];
        }
    }

    return $buffer;
}

it('truncates then repopulates supplier_sku_cache with LOWER+TRIM keys via the Eloquent model', function (): void {
    // Stale rows from a prior refresh — must be gone after truncate.
    SupplierSkuCache::query()->insertOrIgnore([
        ['sku' => 'stale-key-1'],
        ['sku' => 'stale-key-2'],
    ]);
    expect(SupplierSkuCache::query()->count())->toBe(2);

    // Synthetic feed with mixed case + surrounding whitespace + a shared key
    // across mpn/suppliersku that must collapse to one row.
    $feedRows = [
        ['mpn' => '  ABC-123 ', 'suppliersku' => 'SKU-Zeta'],
        ['mpn' => 'abc-123', 'suppliersku' => 'sku-zeta'], // dupes of row 1 after LOWER+TRIM
        ['mpn' => 'Def-456', 'suppliersku' => '   '],       // blank suppliersku skipped
    ];

    // The swapped write path: truncate + chunked insertOrIgnore on the model.
    SupplierSkuCache::query()->truncate();
    SupplierSkuCache::query()->insertOrIgnore(buildSkuBuffer($feedRows));

    $keys = SupplierSkuCache::query()->orderBy('sku')->pluck('sku')->all();

    // Stale rows gone; exactly the 3 distinct normalized keys present.
    expect($keys)->toBe(['abc-123', 'def-456', 'sku-zeta']);
});

it('insertOrIgnore silently drops duplicate sku primary keys', function (): void {
    SupplierSkuCache::query()->truncate();

    SupplierSkuCache::query()->insertOrIgnore([['sku' => 'dup-key']]);
    // A second insert of the same primary key must not throw and must not duplicate.
    SupplierSkuCache::query()->insertOrIgnore([['sku' => 'dup-key'], ['sku' => 'fresh-key']]);

    expect(SupplierSkuCache::query()->count())->toBe(2)
        ->and(SupplierSkuCache::query()->orderBy('sku')->pluck('sku')->all())
        ->toBe(['dup-key', 'fresh-key']);
});
