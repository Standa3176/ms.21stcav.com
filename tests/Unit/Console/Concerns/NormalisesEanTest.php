<?php

declare(strict_types=1);

use App\Console\Concerns\NormalisesEan;

/*
|--------------------------------------------------------------------------
| Quick task 260607-cgd — NormalisesEan trait coverage
|--------------------------------------------------------------------------
|
| Single source of truth for EAN/GTIN validation. The trait widens visibility
| (private → public) so both GenerateProductDraftsCommand and the new
| BackfillMerchantFeedCommand consume byte-identical logic. Drift gate in
| Task 1 verify step: `grep "private function normaliseEan"` returns 0 hits
| across app/Console/Commands.
|
| We consume the trait via an anonymous class wrapper so the test doesn't
| boot a Symfony Command (no DI, no signature, no service container).
*/

beforeEach(function (): void {
    $this->sut = new class
    {
        use NormalisesEan;
    };
});

it('passes a real EAN-13 through unchanged', function (): void {
    expect($this->sut->normaliseEan('5033588057222'))->toBe('5033588057222');
});

it('strips dashes from a dashed EAN-13', function (): void {
    expect($this->sut->normaliseEan('123-456-7890123'))->toBe('1234567890123');
});

it('accepts the 8-digit lower bound', function (): void {
    expect($this->sut->normaliseEan('12345678'))->toBe('12345678');
});

it('accepts the 14-digit upper bound', function (): void {
    expect($this->sut->normaliseEan('12345678901234'))->toBe('12345678901234');
});

it('returns null for "N/A"', function (): void {
    expect($this->sut->normaliseEan('N/A'))->toBeNull();
});

it('returns null for an empty string', function (): void {
    expect($this->sut->normaliseEan(''))->toBeNull();
});

it('returns null for actual null', function (): void {
    expect($this->sut->normaliseEan(null))->toBeNull();
});

it('returns null for a non-digit symbol (em-dash)', function (): void {
    expect($this->sut->normaliseEan('—'))->toBeNull();
});

it('returns null for too-short (7 digits)', function (): void {
    expect($this->sut->normaliseEan('1234567'))->toBeNull();
});

it('returns null for too-long (15 digits)', function (): void {
    expect($this->sut->normaliseEan('123456789012345'))->toBeNull();
});

it('returns null for a single zero', function (): void {
    expect($this->sut->normaliseEan('0'))->toBeNull();
});

it('returns null for all-zero 14-digit placeholder', function (): void {
    expect($this->sut->normaliseEan('00000000000000'))->toBeNull();
});

it('returns null for all-nine 14-digit placeholder', function (): void {
    expect($this->sut->normaliseEan('99999999999999'))->toBeNull();
});

it('returns null for 13-nines placeholder', function (): void {
    expect($this->sut->normaliseEan('9999999999999'))->toBeNull();
});

it('accepts an integer (mixed input via (string) cast)', function (): void {
    expect($this->sut->normaliseEan(12345678))->toBe('12345678');
});
