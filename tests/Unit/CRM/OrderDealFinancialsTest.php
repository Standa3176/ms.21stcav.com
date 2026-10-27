<?php

declare(strict_types=1);

use App\Domain\CRM\Services\OrderDealFinancials;

function dfLine(string $sku, int $qty, string $total, string $name = 'Item'): array
{
    return ['sku' => $sku, 'quantity' => $qty, 'total' => $total, 'name' => $name];
}

it('computes cost, net sales and predicted margin in pence', function (): void {
    $r = (new OrderDealFinancials)->compute(
        [dfLine('A', 2, '100.00'), dfLine('B', 1, '50.00')],
        ['A' => '30.0000', 'B' => '20.5000'],
    );

    expect($r->complete)->toBeTrue()
        ->and($r->costPence)->toBe(8050)
        ->and($r->netSalesPence)->toBe(15000)
        ->and($r->predictedMarginPence)->toBe(6950)
        ->and($r->missingSkus)->toBe([]);
});

it('never partial: a missing buy price blanks both values', function (): void {
    $r = (new OrderDealFinancials)->compute([dfLine('A', 1, '10.00'), dfLine('B', 1, '5.00')], ['A' => '3.0000', 'B' => null]);

    expect($r->complete)->toBeFalse()
        ->and($r->costPence)->toBeNull()
        ->and($r->predictedMarginPence)->toBeNull()
        ->and($r->missingSkus)->toBe(['B']);
});

it('treats a zero buy price and an unknown sku as unresolvable', function (): void {
    $r = (new OrderDealFinancials)->compute([dfLine('A', 1, '10.00'), dfLine('Z', 1, '5.00')], ['A' => '0.0000']);

    expect($r->missingSkus)->toBe(['A', 'Z']);
});

it('flags a line with no sku by name', function (): void {
    $r = (new OrderDealFinancials)->compute([dfLine('', 1, '10.00', 'Mystery cable')], []);

    expect($r->complete)->toBeFalse()->and($r->missingSkus)->toBe(['(no sku) Mystery cable']);
});

it('returns a negative margin as-is', function (): void {
    $r = (new OrderDealFinancials)->compute([dfLine('A', 1, '10.00')], ['A' => '15.0000']);

    expect($r->predictedMarginPence)->toBe(-500);
});

it('rounds line cost half-up on ten-thousandths x qty', function (): void {
    $r = (new OrderDealFinancials)->compute([dfLine('A', 3, '100.00')], ['A' => '12.3456']);

    expect($r->costPence)->toBe(3704);
});

it('shipping is not part of the inputs and so excluded (line items only)', function (): void {
    $order = ['shipping_total' => '9.95', 'line_items' => [dfLine('A', 1, '100.00')]];
    $r = (new OrderDealFinancials)->compute($order['line_items'], ['A' => '40.0000']);

    expect($r->netSalesPence)->toBe(10000)->and($r->predictedMarginPence)->toBe(6000);
});

it('formats money-typed fields with currency and others plain', function (): void {
    $f = new OrderDealFinancials;
    $r = $f->compute([dfLine('A', 1, '100.00')], ['A' => '30.0000']);

    $out = $f->fieldsFor($r, 'UF_CRM_COST', 'UF_CRM_PM', ['UF_CRM_COST' => ['type' => 'money'], 'UF_CRM_PM' => ['type' => 'double']], 'gbp', false);

    expect($out)->toBe(['UF_CRM_COST' => '30.00|GBP', 'UF_CRM_PM' => '70.00']);
});

it('blanks on update and omits on create when incomplete', function (): void {
    $f = new OrderDealFinancials;
    $r = $f->compute([dfLine('A', 1, '100.00')], []);

    expect($f->fieldsFor($r, 'C', 'P', [], 'GBP', false))->toBe([])
        ->and($f->fieldsFor($r, 'C', 'P', [], 'GBP', true))->toBe(['C' => '', 'P' => '']);
});

it('returns nothing when a code is unset or is the Actual Margin field', function (): void {
    $f = new OrderDealFinancials;
    $r = $f->compute([dfLine('A', 1, '100.00')], ['A' => '30.0000']);

    expect($f->fieldsFor($r, null, 'P', [], 'GBP', false))->toBe([])
        ->and($f->fieldsFor($r, 'C', '', [], 'GBP', true))->toBe([])
        ->and($f->fieldsFor($r, 'UF_CRM_1610623973323', 'P', [], 'GBP', false))->toBe([])
        ->and($f->fieldsFor($r, 'C', 'uf_crm_1610623973323', [], 'GBP', true))->toBe([]);
});
