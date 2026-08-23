<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\ProductMatcher;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductSupplierSku;
use App\Domain\Sync\Services\SkuMatcher;

/*
|--------------------------------------------------------------------------
| 260823-clp — alternative supplier SKUs
|--------------------------------------------------------------------------
|
| Restores the legacy Stock Updater plugin's "alternative SKU" field. The
| behaviour that matters: a second supplier's code for a part we already sell
| must be RECOGNISED, so it never becomes an add-candidate and never gets
| auto-created as a duplicate product on Woo.
|
| Explicitly NOT covered, because it is deliberately out of scope: which
| supplier offer feeds buy_price, and whether stock sums across suppliers.
*/

// ── Normalisation ─────────────────────────────────────────────────────────

it('normalises an alternative SKU on write so casing cannot defeat the unique index', function (): void {
    $product = Product::factory()->create(['sku' => 'MAIN-001']);

    $alias = ProductSupplierSku::create([
        'product_id' => $product->id,
        'supplier_sku' => '  Alt-Code-99  ',
    ]);

    expect($alias->supplier_sku)->toBe('Alt-Code-99')
        ->and($alias->normalised_sku)->toBe('alt-code-99');
});

it('refuses a duplicate alternative SKU for the same supplier', function (): void {
    $a = Product::factory()->create(['sku' => 'MAIN-A']);
    $b = Product::factory()->create(['sku' => 'MAIN-B']);

    ProductSupplierSku::create(['product_id' => $a->id, 'supplier_id' => 7, 'supplier_sku' => 'SHARED-1']);

    // The same code from the same supplier cannot mean two different parts.
    expect(fn () => ProductSupplierSku::create([
        'product_id' => $b->id, 'supplier_id' => 7, 'supplier_sku' => 'shared-1',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('allows the same code from two different suppliers', function (): void {
    $a = Product::factory()->create(['sku' => 'MAIN-C']);
    $b = Product::factory()->create(['sku' => 'MAIN-D']);

    // Distinct suppliers genuinely do reuse short codes for different parts —
    // identity is the (code, supplier) PAIR, not the code alone.
    ProductSupplierSku::create(['product_id' => $a->id, 'supplier_id' => 1, 'supplier_sku' => 'X-1']);
    ProductSupplierSku::create(['product_id' => $b->id, 'supplier_id' => 2, 'supplier_sku' => 'X-1']);

    expect(ProductSupplierSku::where('normalised_sku', 'x-1')->count())->toBe(2);
});

// ── Duplicate prevention (the whole point) ────────────────────────────────

it('treats an alternative SKU as already stocked at the auto-create gate', function (): void {
    $product = Product::factory()->create(['sku' => 'YEA-MVC940']);
    ProductSupplierSku::create(['product_id' => $product->id, 'supplier_sku' => 'MVC940-C5-008-ALT']);

    $matcher = new ProductMatcher;

    // This is the AUTO-08 gate immediately before a Woo POST. Before 260823-clp
    // it saw only products.sku, so supplier B's code looked like a new part and
    // the pipeline published a duplicate of something already on the storefront.
    expect($matcher->existsNormalised('MVC940-C5-008-ALT'))->toBeTrue()
        ->and($matcher->existsNormalised('  mvc940-c5-008-alt  '))->toBeTrue()
        ->and($matcher->existsNormalised('SOMETHING-WE-DO-NOT-SELL'))->toBeFalse();
});

it('still recognises the product by its own SKU', function (): void {
    Product::factory()->create(['sku' => 'PLAIN-001']);

    // Regression guard: the alias lookup is additive, never a replacement.
    expect((new ProductMatcher)->existsNormalised('plain-001'))->toBeTrue();
});

// ── SkuMatcher ────────────────────────────────────────────────────────────

it('matches a supplier feed row through the product alternative SKU', function (): void {
    $product = Product::factory()->create(['sku' => 'LOCAL-9']);
    ProductSupplierSku::create(['product_id' => $product->id, 'supplier_sku' => 'SUPP-B-9']);

    $matcher = (new SkuMatcher)
        ->build(['SUPP-B-9' => ['price' => '10.00', 'stock' => 4]])
        ->withAliases();

    // The feed has no row under LOCAL-9 at all — only the alternative code.
    expect($matcher->match('LOCAL-9'))->toBe(['price' => '10.00', 'stock' => 4]);
});

it('prefers an exact feed match over the alias fallback', function (): void {
    $product = Product::factory()->create(['sku' => 'LOCAL-10']);
    ProductSupplierSku::create(['product_id' => $product->id, 'supplier_sku' => 'SUPP-B-10']);

    $matcher = (new SkuMatcher)
        ->build([
            'LOCAL-10' => ['price' => '1.00', 'stock' => 1],
            'SUPP-B-10' => ['price' => '2.00', 'stock' => 2],
        ])
        ->withAliases();

    // Ordering guarantee: every pre-260823-clp exact match keeps its meaning.
    expect($matcher->match('LOCAL-10'))->toBe(['price' => '1.00', 'stock' => 1]);
});

it('leaves direct matching case-sensitive, as AUTO-08 intended', function (): void {
    // The 2026-08-09 TODO proposed folding case here to align with
    // SupplierOfferSnapshot's lowercase-trimmed matchKey. Not done: SkuMatcher's
    // case-sensitivity is a deliberate AUTO-08 Woo convention with its own named
    // test (SkuMatcherTest M2), and on this path a wrong match is a wrong PRICE.
    // Adding aliases must not quietly overturn it.
    $matcher = (new SkuMatcher)->build(['ABC-1' => ['price' => '5.00', 'stock' => 3]]);

    expect($matcher->match('ABC-1'))->toBe(['price' => '5.00', 'stock' => 3])
        ->and($matcher->match('abc-1'))->toBeNull();
});

it('resolves an alias whatever casing the supplier feed uses', function (): void {
    // Aliases ARE normalised (stored lowercase-trimmed), so the feed is indexed
    // normalised for alias lookups only — this is the one place folding applies.
    $product = Product::factory()->create(['sku' => 'LOCAL-13']);
    ProductSupplierSku::create(['product_id' => $product->id, 'supplier_sku' => 'supp-b-13']);

    $matcher = (new SkuMatcher)
        ->build(['SUPP-B-13' => ['price' => '7.00', 'stock' => 2]])
        ->withAliases();

    expect($matcher->match('LOCAL-13'))->toBe(['price' => '7.00', 'stock' => 2]);
});

it('returns null when neither the SKU nor any alias is in the feed', function (): void {
    $product = Product::factory()->create(['sku' => 'LOCAL-11']);
    ProductSupplierSku::create(['product_id' => $product->id, 'supplier_sku' => 'SUPP-B-11']);

    $matcher = (new SkuMatcher)
        ->build(['SOMETHING-ELSE' => ['price' => '3.00', 'stock' => 1]])
        ->withAliases();

    expect($matcher->match('LOCAL-11'))->toBeNull();
});

it('leaves matching unchanged when aliases are not loaded', function (): void {
    $product = Product::factory()->create(['sku' => 'LOCAL-12']);
    ProductSupplierSku::create(['product_id' => $product->id, 'supplier_sku' => 'SUPP-B-12']);

    // withAliases() is opt-in; build()-only callers (and the unit tests that
    // pass a bare feed array with no database) behave exactly as before.
    $matcher = (new SkuMatcher)->build(['SUPP-B-12' => ['price' => '9.00', 'stock' => 1]]);

    expect($matcher->match('LOCAL-12'))->toBeNull();
});

// ── Lookup map ────────────────────────────────────────────────────────────

it('exposes every alias as a normalised code to product map', function (): void {
    $a = Product::factory()->create(['sku' => 'M-1']);
    $b = Product::factory()->create(['sku' => 'M-2']);
    ProductSupplierSku::create(['product_id' => $a->id, 'supplier_id' => 1, 'supplier_sku' => 'Alt-A']);
    ProductSupplierSku::create(['product_id' => $b->id, 'supplier_id' => 2, 'supplier_sku' => 'ALT-B']);

    // The add-candidate scanner reads this to build its exclusion set.
    expect(ProductSupplierSku::normalisedMap())
        ->toBe(['alt-a' => (int) $a->id, 'alt-b' => (int) $b->id]);
});

it('drops the alias rows when the product is deleted', function (): void {
    $product = Product::factory()->create(['sku' => 'M-3']);
    ProductSupplierSku::create(['product_id' => $product->id, 'supplier_sku' => 'Alt-C']);

    $product->forceDelete();

    // Orphan aliases would silently suppress legitimate add-candidates forever.
    expect(ProductSupplierSku::count())->toBe(0);
});
