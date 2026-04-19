<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\CompetitorCsvMapping;
use App\Domain\Competitor\Services\DecimalFormatDetector;

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 02 Task 1 — DecimalFormatDetector (COMP-03 + Pitfall P5-B)
|--------------------------------------------------------------------------
|
| 10-row majority heuristic on the price column. `comma` = European
| (`1.234,56`); `dot` = UK/US (`1,234.56`). Default dot when ambiguous /
| empty sample.
*/

it('returns comma for a European-decimal sample', function (): void {
    $rows = [
        ['sku', 'price'], // header
        ['ABC-1', '1.234,56'],
        ['ABC-2', '999,00'],
        ['ABC-3', '56,78'],
    ];

    expect((new DecimalFormatDetector())->detect($rows, 1))
        ->toBe(CompetitorCsvMapping::FORMAT_COMMA);
});

it('returns dot for a UK/US dot-decimal sample', function (): void {
    $rows = [
        ['sku', 'price'],
        ['ABC-1', '1,234.56'],
        ['ABC-2', '56.78'],
        ['ABC-3', '999.00'],
    ];

    expect((new DecimalFormatDetector())->detect($rows, 1))
        ->toBe(CompetitorCsvMapping::FORMAT_DOT);
});

it('defaults to dot when the sample is empty', function (): void {
    expect((new DecimalFormatDetector())->detect([], 0))
        ->toBe(CompetitorCsvMapping::FORMAT_DOT);
});

it('defaults to dot when all price values are unparseable', function (): void {
    $rows = [
        ['sku', 'price'],
        ['ABC-1', 'garbage'],
        ['ABC-2', 'more garbage'],
    ];

    expect((new DecimalFormatDetector())->detect($rows, 1))
        ->toBe(CompetitorCsvMapping::FORMAT_DOT);
});

it('skips the header row when sampling', function (): void {
    // Header row has "price,xx,yy" text shape that would otherwise fool the detector.
    $rows = [
        ['sku', '1,234.56'],  // header (skipped — even if it looks like data)
        ['ABC-1', '89,99'],
        ['ABC-2', '149,95'],
    ];

    expect((new DecimalFormatDetector())->detect($rows, 1))
        ->toBe(CompetitorCsvMapping::FORMAT_COMMA);
});

it('strips currency symbols before counting', function (): void {
    $rows = [
        ['sku', 'price'],
        ['ABC-1', '£89,99'],
        ['ABC-2', 'GBP 149,95'],
        ['ABC-3', '€ 56,78'],
    ];

    expect((new DecimalFormatDetector())->detect($rows, 1))
        ->toBe(CompetitorCsvMapping::FORMAT_COMMA);
});
