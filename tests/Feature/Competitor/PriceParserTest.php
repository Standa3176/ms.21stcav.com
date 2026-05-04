<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\CompetitorCsvMapping;
use App\Domain\Competitor\Services\PriceParser;

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 02 Task 1 — PriceParser (COMP-06 seam: stripVat is Phase 3)
|--------------------------------------------------------------------------
|
| Converts raw CSV price strings to integer gross pennies. No VAT math
| here — this service outputs gross pennies; PriceCalculator::stripVat
| (Phase 3) handles VAT-strip. Returns null on unparseable input (no
| silent zero).
*/

it('parses dot-decimal GBP with thousands separator', function (): void {
    expect((new PriceParser())->toGrossPennies('£1,234.56', CompetitorCsvMapping::FORMAT_DOT))
        ->toBe(123456);
});

it('parses comma-decimal European with dot thousands separator', function (): void {
    expect((new PriceParser())->toGrossPennies('1.234,56 GBP', CompetitorCsvMapping::FORMAT_COMMA))
        ->toBe(123456);
});

it('returns null for non-numeric garbage', function (): void {
    expect((new PriceParser())->toGrossPennies('not a number', CompetitorCsvMapping::FORMAT_DOT))
        ->toBeNull();
});

it('returns null for empty input', function (): void {
    expect((new PriceParser())->toGrossPennies('', CompetitorCsvMapping::FORMAT_DOT))
        ->toBeNull();
});

// Quick task 260504-edk — sale-price marketing strings (avparts pattern).
it('extracts post-Save sale price from "Was£X Save Y% £Z" pattern', function (): void {
    expect((new PriceParser())->toGrossPennies('Was£5,525.57Save 19%£4,499.00', CompetitorCsvMapping::FORMAT_DOT))
        ->toBe(449900);
});

it('extracts post-Save sale price with spaces around tokens', function (): void {
    expect((new PriceParser())->toGrossPennies('Was £2,659.00 Save 25% £1,999.00', CompetitorCsvMapping::FORMAT_DOT))
        ->toBe(199900);
});

it('extracts post-Save sale price case-insensitively', function (): void {
    expect((new PriceParser())->toGrossPennies('WAS £100 SAVE 10% £90.00', CompetitorCsvMapping::FORMAT_DOT))
        ->toBe(9000);
});

it('parses a simple 89.99 GBP value', function (): void {
    expect((new PriceParser())->toGrossPennies('89.99', CompetitorCsvMapping::FORMAT_DOT))
        ->toBe(8999);
});

it('parses a simple 89,99 European value', function (): void {
    expect((new PriceParser())->toGrossPennies('89,99', CompetitorCsvMapping::FORMAT_COMMA))
        ->toBe(8999);
});

it('strips £ GBP € currency symbols and whitespace', function (): void {
    expect((new PriceParser())->toGrossPennies('  €1299.00  ', CompetitorCsvMapping::FORMAT_DOT))
        ->toBe(129900);
});

it('rounds only once at the return boundary', function (): void {
    // 89.995 → 8999.5 pennies → round half-up → 9000
    expect((new PriceParser())->toGrossPennies('89.995', CompetitorCsvMapping::FORMAT_DOT))
        ->toBe(9000);
});
