<?php

declare(strict_types=1);

use App\Domain\CRM\Support\OrderPaidGate;

const ORDER_PAID_DEFAULTS = ['processing', 'on-hold', 'completed', 'ywraq-new', 'ywraq-pending'];

it('treats deal-creating statuses as paid', function (string $status): void {
    expect(OrderPaidGate::isPaid($status, ORDER_PAID_DEFAULTS))->toBeTrue();
})->with(['processing', 'on-hold', 'completed', 'ywraq-new', 'ywraq-pending']);

it('treats never-paid statuses as not paid', function (string $status): void {
    expect(OrderPaidGate::isPaid($status, ORDER_PAID_DEFAULTS))->toBeFalse();
})->with(['pending', 'failed', 'cancelled', 'checkout-draft', 'refunded', 'ywraq-expired', '']);

it('normalises wc- prefix, case and whitespace', function (): void {
    expect(OrderPaidGate::isPaid('wc-processing', ORDER_PAID_DEFAULTS))->toBeTrue()
        ->and(OrderPaidGate::isPaid('Processing', ORDER_PAID_DEFAULTS))->toBeTrue()
        ->and(OrderPaidGate::isPaid(' on-hold ', ORDER_PAID_DEFAULTS))->toBeTrue()
        ->and(OrderPaidGate::normalise(' WC-On-Hold '))->toBe('on-hold');
});

it('takes the status list as a parameter', function (): void {
    expect(OrderPaidGate::isPaid('processing', ['completed']))->toBeFalse()
        ->and(OrderPaidGate::isPaid('ywraq-new', ['processing']))->toBeFalse();
});

it('exposes the default status list via config', function (): void {
    expect(config('services.bitrix.order_paid_statuses'))->toBe(ORDER_PAID_DEFAULTS);
});
