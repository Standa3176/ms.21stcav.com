<?php

declare(strict_types=1);

use App\Domain\Sync\Services\WooClient;
use App\Domain\Sync\Services\WooProductIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Context::add('correlation_id', (string) Str::uuid());
});

/**
 * Build a mocked WooClient whose get() call returns queued responses in order.
 * Each call with matching ($endpoint, $page) pops the matching response.
 */
function wooIteratorClientMock(array $responsesByEndpointAndPage): WooClient
{
    return new class($responsesByEndpointAndPage) extends WooClient
    {
        public function __construct(private array $responses)
        {
            // No parent construct — we intercept get() entirely.
        }

        public function get(string $endpoint, array $query = []): array
        {
            $page = (int) ($query['page'] ?? 1);
            $key = "{$endpoint}|{$page}";

            if (array_key_exists($key, $this->responses)) {
                return $this->responses[$key];
            }

            // Unknown endpoint/page → empty response (ends iteration cleanly).
            return [];
        }
    };
}

// -----------------------------------------------------------------------------
// I1: Paginates /products until page returns < 100 rows
// -----------------------------------------------------------------------------
test('I1: paginates /products until a page returns fewer than 100 rows', function () {
    $page1 = array_map(fn ($n) => [
        'id' => $n,
        'type' => 'simple',
        'sku' => "SKU-P1-{$n}",
        'regular_price' => '10.00',
        'stock_quantity' => 1,
    ], range(1, 100));

    $page2 = array_map(fn ($n) => [
        'id' => 100 + $n,
        'type' => 'simple',
        'sku' => "SKU-P2-{$n}",
        'regular_price' => '12.00',
        'stock_quantity' => 2,
    ], range(1, 50));  // < 100 triggers stop

    $woo = wooIteratorClientMock([
        'products|1' => $page1,
        'products|2' => $page2,
    ]);

    $iterator = new WooProductIterator($woo);

    $pages = iterator_to_array($iterator->pages());

    expect($pages)->toHaveCount(2)
        ->and($pages[0]['page'])->toBe(1)
        ->and($pages[0]['skus'])->toHaveCount(100)
        ->and($pages[1]['page'])->toBe(2)
        ->and($pages[1]['skus'])->toHaveCount(50);
});

// -----------------------------------------------------------------------------
// I2: simple product yields expected shape
// -----------------------------------------------------------------------------
test('I2: simple product yields canonical row shape', function () {
    $woo = wooIteratorClientMock([
        'products|1' => [
            [
                'id' => 555,
                'type' => 'simple',
                'sku' => 'SIMPLE-1',
                'regular_price' => '99.00',
                'stock_quantity' => 7,
                'manage_stock' => true,
                'tags' => [],
                'meta_data' => [],
            ],
        ],
    ]);

    $iterator = new WooProductIterator($woo);
    $first = iterator_to_array($iterator->pages())[0];

    $row = $first['skus'][0];
    expect($row['type'])->toBe('simple')
        ->and($row['sku'])->toBe('SIMPLE-1')
        ->and($row['woo_product_id'])->toBe(555)
        ->and($row['woo_variation_id'])->toBeNull()
        ->and($row['price'])->toBe('99.00')
        ->and($row['stock_quantity'])->toBe(7)
        ->and($row['manage_stock'])->toBeTrue()
        ->and($row['is_custom_ms'])->toBeFalse()
        ->and($row['exclude_from_auto_update'])->toBeFalse();
});

// -----------------------------------------------------------------------------
// I3: variable product triggers inner variations fetch + inherits tags/meta
// -----------------------------------------------------------------------------
test('I3: variable product triggers per-variation fetch and inherits custom-ms + exclude flags', function () {
    $woo = wooIteratorClientMock([
        'products|1' => [
            [
                'id' => 700,
                'type' => 'variable',
                'tags' => [['slug' => 'custom-ms']],
                'meta_data' => [['key' => '_exclude_from_auto_update', 'value' => 'yes']],
            ],
        ],
        'products/700/variations|1' => [
            ['id' => 7001, 'sku' => 'VAR-1', 'regular_price' => '50.00', 'stock_quantity' => 3, 'manage_stock' => true, 'attributes' => [['name' => 'colour', 'option' => 'red']]],
            ['id' => 7002, 'sku' => 'VAR-2', 'regular_price' => '55.00', 'stock_quantity' => 1, 'manage_stock' => true, 'attributes' => [['name' => 'colour', 'option' => 'blue']]],
        ],
    ]);

    $iterator = new WooProductIterator($woo);
    $first = iterator_to_array($iterator->pages())[0];

    expect($first['skus'])->toHaveCount(2)
        ->and($first['skus'][0]['type'])->toBe('variation')
        ->and($first['skus'][0]['sku'])->toBe('VAR-1')
        ->and($first['skus'][0]['woo_product_id'])->toBe(700)
        ->and($first['skus'][0]['woo_variation_id'])->toBe(7001)
        ->and($first['skus'][0]['is_custom_ms'])->toBeTrue()  // inherited from parent
        ->and($first['skus'][0]['exclude_from_auto_update'])->toBeTrue()
        ->and($first['skus'][0]['attributes'])->toBe([['name' => 'colour', 'option' => 'red']]);
});

// -----------------------------------------------------------------------------
// I4: variable with >100 variations — inner pagination follows through
// -----------------------------------------------------------------------------
test('I4: variable product with >100 variations paginates the inner call', function () {
    $innerPage1 = array_map(fn ($n) => [
        'id' => 10_000 + $n,
        'sku' => "BIG-V-{$n}",
        'regular_price' => '5.00',
        'stock_quantity' => 1,
    ], range(1, 100));

    $innerPage2 = array_map(fn ($n) => [
        'id' => 20_000 + $n,
        'sku' => "BIG-V-{$n}-p2",
        'regular_price' => '5.00',
        'stock_quantity' => 1,
    ], range(1, 30));

    $woo = wooIteratorClientMock([
        'products|1' => [['id' => 900, 'type' => 'variable']],
        'products/900/variations|1' => $innerPage1,
        'products/900/variations|2' => $innerPage2,
    ]);

    $iterator = new WooProductIterator($woo);
    $first = iterator_to_array($iterator->pages())[0];

    expect($first['skus'])->toHaveCount(130);
});

// -----------------------------------------------------------------------------
// I5: custom-ms tag detection is case-insensitive on slug
// -----------------------------------------------------------------------------
test('I5: custom-ms tag slug match is case-insensitive', function () {
    $woo = wooIteratorClientMock([
        'products|1' => [
            ['id' => 1, 'type' => 'simple', 'sku' => 'A', 'tags' => [['slug' => 'Custom-MS']]],  // mixed case
            ['id' => 2, 'type' => 'simple', 'sku' => 'B', 'tags' => [['slug' => 'CUSTOM-MS']]],  // upper
            ['id' => 3, 'type' => 'simple', 'sku' => 'C', 'tags' => [['slug' => 'custom-ms']]],  // lower
            ['id' => 4, 'type' => 'simple', 'sku' => 'D', 'tags' => [['slug' => 'other']]],
        ],
    ]);

    $iterator = new WooProductIterator($woo);
    $first = iterator_to_array($iterator->pages())[0];

    expect($first['skus'][0]['is_custom_ms'])->toBeTrue()
        ->and($first['skus'][1]['is_custom_ms'])->toBeTrue()
        ->and($first['skus'][2]['is_custom_ms'])->toBeTrue()
        ->and($first['skus'][3]['is_custom_ms'])->toBeFalse();
});

// -----------------------------------------------------------------------------
// I6: _exclude_from_auto_update meta match matches yes/1/true
// -----------------------------------------------------------------------------
test('I6: _exclude_from_auto_update meta match matches yes, 1, and true', function () {
    $woo = wooIteratorClientMock([
        'products|1' => [
            ['id' => 1, 'type' => 'simple', 'sku' => 'E-YES', 'meta_data' => [['key' => '_exclude_from_auto_update', 'value' => 'yes']]],
            ['id' => 2, 'type' => 'simple', 'sku' => 'E-1STR', 'meta_data' => [['key' => '_exclude_from_auto_update', 'value' => '1']]],
            ['id' => 3, 'type' => 'simple', 'sku' => 'E-1INT', 'meta_data' => [['key' => '_exclude_from_auto_update', 'value' => 1]]],
            ['id' => 4, 'type' => 'simple', 'sku' => 'E-BOOL', 'meta_data' => [['key' => '_exclude_from_auto_update', 'value' => true]]],
            ['id' => 5, 'type' => 'simple', 'sku' => 'E-NO', 'meta_data' => [['key' => '_exclude_from_auto_update', 'value' => 'no']]],
            ['id' => 6, 'type' => 'simple', 'sku' => 'E-OTHER', 'meta_data' => [['key' => 'unrelated', 'value' => 'yes']]],
        ],
    ]);

    $iterator = new WooProductIterator($woo);
    $first = iterator_to_array($iterator->pages())[0];

    expect($first['skus'][0]['exclude_from_auto_update'])->toBeTrue()
        ->and($first['skus'][1]['exclude_from_auto_update'])->toBeTrue()
        ->and($first['skus'][2]['exclude_from_auto_update'])->toBeTrue()
        ->and($first['skus'][3]['exclude_from_auto_update'])->toBeTrue()
        ->and($first['skus'][4]['exclude_from_auto_update'])->toBeFalse()
        ->and($first['skus'][5]['exclude_from_auto_update'])->toBeFalse();
});

// -----------------------------------------------------------------------------
// I7: fromPage starts pagination at the given page (resume semantics)
// -----------------------------------------------------------------------------
test('I7: fromPage=7 starts pagination at page 7', function () {
    $woo = wooIteratorClientMock([
        'products|7' => [['id' => 77, 'type' => 'simple', 'sku' => 'AT-P7']],
    ]);

    $iterator = new WooProductIterator($woo);
    $first = iterator_to_array($iterator->pages(fromPage: 7))[0];

    expect($first['page'])->toBe(7)
        ->and($first['skus'][0]['sku'])->toBe('AT-P7');
});

// -----------------------------------------------------------------------------
// I8: grouped/external products are skipped (v1 scope)
// -----------------------------------------------------------------------------
test('I8: grouped and external products are skipped (no row yielded)', function () {
    $woo = wooIteratorClientMock([
        'products|1' => [
            ['id' => 1, 'type' => 'grouped', 'sku' => 'GROUPED-1'],
            ['id' => 2, 'type' => 'external', 'sku' => 'EXT-1'],
            ['id' => 3, 'type' => 'simple', 'sku' => 'SIMPLE-KEEP'],
        ],
    ]);

    $iterator = new WooProductIterator($woo);
    $first = iterator_to_array($iterator->pages())[0];

    expect($first['skus'])->toHaveCount(1)
        ->and($first['skus'][0]['sku'])->toBe('SIMPLE-KEEP');
});
