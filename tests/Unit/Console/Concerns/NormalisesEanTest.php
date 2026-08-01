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

/*
|--------------------------------------------------------------------------
| Quick task 260726-egr — isValidGtinChecksum() (the missing check-digit gate)
|--------------------------------------------------------------------------
|
| normaliseEan() length-checks + rejects all-zero/all-nine sentinels but does NO
| check-digit validation, so precision-mangled 13-digit values (e.g. A30-020's
| local `6938820000000`) passed as "valid" while Woo held the real GTIN
| `0841885115294`. isValidGtinChecksum() adds the standard GTIN mod-10 check
| digit (right-to-left weights 3,1,3,1…) for GTIN-8/12/13/14. It is ADDED
| alongside normaliseEan() — the latter's behaviour is unchanged (asserted by the
| 15 cases above, which are byte-identical to 260607-cgd).
*/

it('accepts a real EAN-13 (Woo GTIN for A30-020)', function (): void {
    expect($this->sut->isValidGtinChecksum('0841885115294'))->toBeTrue();
});

it('accepts a second real EAN-13', function (): void {
    expect($this->sut->isValidGtinChecksum('4006381333931'))->toBeTrue();
});

it('rejects the corrupted A30-020 local ean (bad check digit)', function (): void {
    expect($this->sut->isValidGtinChecksum('6938820000000'))->toBeFalse();
});

it('rejects a second corrupted 13-digit value (bad check digit)', function (): void {
    expect($this->sut->isValidGtinChecksum('6936420000000'))->toBeFalse();
});

it('accepts a valid GTIN-8', function (): void {
    expect($this->sut->isValidGtinChecksum('12345670'))->toBeTrue();
});

it('accepts a valid GTIN-12 (UPC-A)', function (): void {
    expect($this->sut->isValidGtinChecksum('036000291452'))->toBeTrue();
});

it('accepts a valid GTIN-14', function (): void {
    expect($this->sut->isValidGtinChecksum('00841885115294'))->toBeTrue();
});

it('rejects a GTIN-8 with a bad check digit', function (): void {
    expect($this->sut->isValidGtinChecksum('12345671'))->toBeFalse();
});

it('rejects an empty string', function (): void {
    expect($this->sut->isValidGtinChecksum(''))->toBeFalse();
});

it('rejects a non-digit value', function (): void {
    expect($this->sut->isValidGtinChecksum('084188511529X'))->toBeFalse();
});

it('rejects a too-short (7-digit) value', function (): void {
    expect($this->sut->isValidGtinChecksum('1234567'))->toBeFalse();
});

it('rejects a non-standard length (11 digits) even if mod-10 would pass', function (): void {
    // 11 is not a real GTIN length (8/12/13/14 only) — reject regardless of checksum.
    expect($this->sut->isValidGtinChecksum('12345678901'))->toBeFalse();
});
