<?php

declare(strict_types=1);

use App\Domain\CRM\Services\OrderProductRowBuilder;

function rbLine(array $o = []): array
{
    return array_merge([
        'name' => 'Poly Studio X50', 'product_id' => 10, 'variation_id' => 0, 'sku' => 'SKU-A',
        'quantity' => 2, 'subtotal' => '100.00', 'total' => '100.00', 'total_tax' => '20.00',
    ], $o);
}

function rbSum(array $rows): int
{
    return (int) round(array_sum(array_map(fn ($r) => (float) $r['PRICE'] * (int) $r['QUANTITY'] * 100, $rows)));
}

it('builds a product row in the quote row shape', function (): void {
    $rows = (new OrderProductRowBuilder)->build(['line_items' => [rbLine()]]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toBe([
            'PRODUCT_ID' => 0,
            'PRODUCT_NAME' => 'Poly Studio X50',
            'PRICE' => '60.00',
            'PRICE_EXCLUSIVE' => '50.00',
            'PRICE_NETTO' => '50.00',
            'PRICE_BRUTTO' => '60.00',
            'QUANTITY' => '2',
            'TAX_RATE' => '20',
            'TAX_INCLUDED' => 'Y',
            'CUSTOMIZED' => 'Y',
            'MEASURE_CODE' => 796,
            'MEASURE_NAME' => 'pcs',
            'SORT' => 10,
        ]);
});

it('derives prices from the discounted total, not subtotal', function (): void {
    $rows = (new OrderProductRowBuilder)->build(['line_items' => [rbLine(['subtotal' => '120.00', 'quantity' => 1])]]);

    expect($rows[0]['PRICE_EXCLUSIVE'])->toBe('100.00')->and($rows[0]['PRICE'])->toBe('120.00');
});

it('adds a shipping row only when shipping_total > 0', function (): void {
    $b = new OrderProductRowBuilder;
    $with = $b->build([
        'line_items' => [rbLine()],
        'shipping_total' => '9.95', 'shipping_tax' => '1.99',
        'shipping_lines' => [['method_title' => 'DPD Next Day']],
    ]);
    expect($with)->toHaveCount(2)
        ->and($with[1]['PRODUCT_NAME'])->toBe('Shipping: DPD Next Day')
        ->and($with[1]['PRICE'])->toBe('11.94')
        ->and($with[1]['PRICE_EXCLUSIVE'])->toBe('9.95')
        ->and($with[1]['QUANTITY'])->toBe('1')
        ->and($with[1]['SORT'])->toBe(20)
        ->and($with[1]['TAX_RATE'])->toBe('20');

    expect($b->build(['line_items' => [rbLine()], 'shipping_total' => '0.00']))->toHaveCount(1);
});

it('D-16: zero-rated line gets TAX_RATE 0', function (): void {
    $rows = (new OrderProductRowBuilder)->build(['line_items' => [rbLine(['quantity' => 1, 'total' => '50.00', 'total_tax' => '0.00'])]]);

    expect($rows[0]['TAX_RATE'])->toBe('0')->and($rows[0]['PRICE'])->toBe($rows[0]['PRICE_EXCLUSIVE']);
});

it('D-16: export order has every row at 0 and reconciles with the total', function (): void {
    $rows = (new OrderProductRowBuilder)->build([
        'total' => '109.95',
        'line_items' => [rbLine(['quantity' => 1, 'total' => '100.00', 'total_tax' => '0.00'])],
        'shipping_total' => '9.95', 'shipping_tax' => '0.00',
    ]);

    expect(array_column($rows, 'TAX_RATE'))->toBe(['0', '0'])->and(rbSum($rows))->toBe(10995);
});

it('D-16: mixed order yields per-row rates', function (): void {
    $rows = (new OrderProductRowBuilder)->build([
        'line_items' => [
            rbLine(['quantity' => 1, 'total' => '100.00', 'total_tax' => '20.00']),
            rbLine(['quantity' => 1, 'total' => '40.00', 'total_tax' => '0.00', 'sku' => 'B', 'product_id' => 11]),
        ],
        'shipping_total' => '10.00', 'shipping_tax' => '2.00',
    ]);

    expect(array_column($rows, 'TAX_RATE'))->toBe(['20', '0', '20']);
});

it('D-16: reduced rate and rounding snap', function (): void {
    $b = new OrderProductRowBuilder;
    $rate = fn (string $total, string $tax) => $b->build(['line_items' => [rbLine(['quantity' => 1, 'total' => $total, 'total_tax' => $tax])]])[0]['TAX_RATE'];

    expect($rate('100.00', '5.00'))->toBe('5')
        ->and($rate('33.33', '6.67'))->toBe('20')
        ->and($rate('10.00', '1.73'))->toBe('17');
});

it('reconciles with the order total for whole-penny unit prices', function (): void {
    $rows = (new OrderProductRowBuilder)->build([
        'line_items' => [rbLine(['total' => '100.00', 'total_tax' => '20.00'])],
        'shipping_total' => '9.95', 'shipping_tax' => '1.99',
    ]);

    expect(rbSum($rows))->toBe(13194);
});

it('rounds per unit half-up and documents the residue', function (): void {
    $rows = (new OrderProductRowBuilder)->build(['line_items' => [rbLine(['quantity' => 3, 'total' => '10.00', 'total_tax' => '2.00'])]]);

    // 12.00 / 3 = 4.00 exactly; ex 10.00/3 = 3.33 -> residue of 1p across 3 units on the ex-VAT side.
    expect($rows[0]['PRICE'])->toBe('4.00')->and($rows[0]['PRICE_EXCLUSIVE'])->toBe('3.33');
});

it('skips zero-quantity lines', function (): void {
    expect((new OrderProductRowBuilder)->build(['line_items' => [rbLine(['quantity' => 0])]]))->toBe([]);
});

it('hashes line items order-independently and only on relevant changes', function (): void {
    $b = new OrderProductRowBuilder;
    $a = rbLine();
    $c = rbLine(['sku' => 'SKU-C', 'product_id' => 12]);
    $base = ['line_items' => [$a, $c], 'shipping_total' => '5.00', 'shipping_tax' => '1.00', 'status' => 'processing'];

    $h = $b->lineItemsHash($base);
    expect($b->lineItemsHash(array_merge($base, ['line_items' => [$c, $a]])))->toBe($h)
        ->and($b->lineItemsHash(array_merge($base, ['status' => 'completed', 'customer_note' => 'x', 'meta_data' => [1]])))->toBe($h)
        ->and($b->lineItemsHash(array_merge($base, ['line_items' => [rbLine(['quantity' => 3]), $c]])))->not->toBe($h)
        ->and($b->lineItemsHash(array_merge($base, ['line_items' => [rbLine(['total' => '90.00']), $c]])))->not->toBe($h)
        ->and($b->lineItemsHash(array_merge($base, ['line_items' => [rbLine(['sku' => 'Z']), $c]])))->not->toBe($h)
        ->and($b->lineItemsHash(array_merge($base, ['shipping_total' => '6.00'])))->not->toBe($h);
});
