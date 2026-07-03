<?php

declare(strict_types=1);

use App\Console\Commands\DraftFromSuggestionsCommand;

/*
|--------------------------------------------------------------------------
| Quick task 260703-rk3 — DraftFromSuggestionsCommand::indexSupplierRows()
|--------------------------------------------------------------------------
|
| The supplier feed stores suppliersku/mpn as space-PADDED CHAR columns
| (e.g. mpn '49XE4F-M            '). The old chunk closure built its in-feed
| membership set + manufacturer map with strtolower() but NOT trim(), so the
| padded key '49xe4f-m            ' never equalled the trimmed evidence SKU
| '49xe4f-m' — the row was wrongly classed not_sourceable and skipped, even
| though the SQL IN/= matched it (MySQL ignores trailing spaces) and
| supplier_sku_cache (LOWER(TRIM())) showed it sourceable.
|
| indexSupplierRows() is the extracted PURE helper: it lowercases AND trims
| the keys so a padded regression can't recur. Touches no database, so we
| construct the command through the container and call it directly.
*/

beforeEach(function (): void {
    $this->command = app(DraftFromSuggestionsCommand::class);
});

it('trims padded CHAR-column keys (THE 49XE4F-M guard)', function (): void {
    $out = $this->command->indexSupplierRows([
        ['suppliersku' => 'CC99220     ', 'mpn' => '49XE4F-M            ', 'manufacturer' => 'LG ELECTRONICS      '],
    ]);

    // Trimmed keys are present …
    expect(isset($out['seen']['cc99220']))->toBeTrue();
    expect(isset($out['seen']['49xe4f-m']))->toBeTrue();
    // … and the padded key is NOT (the bug guard).
    expect(isset($out['seen']['49xe4f-m            ']))->toBeFalse();
    // Manufacturer is trimmed too.
    expect($out['mfrs']['49xe4f-m'])->toBe(['LG ELECTRONICS']);
});

it('adds a blank-manufacturer row to seen but not to mfrs', function (): void {
    $out = $this->command->indexSupplierRows([
        ['suppliersku' => 'BLANKMFR    ', 'mpn' => 'BM-001      ', 'manufacturer' => '   '],
    ]);

    expect(isset($out['seen']['blankmfr']))->toBeTrue();
    expect(isset($out['seen']['bm-001']))->toBeTrue();
    expect(isset($out['mfrs']['blankmfr']))->toBeFalse();
    expect(isset($out['mfrs']['bm-001']))->toBeFalse();
});

it('collects both manufacturers for two rows sharing an mpn', function (): void {
    $out = $this->command->indexSupplierRows([
        ['suppliersku' => 'SKU-A       ', 'mpn' => 'HD226       ', 'manufacturer' => 'Yealink     '],
        ['suppliersku' => 'SKU-B       ', 'mpn' => 'HD226       ', 'manufacturer' => 'Protect Plus'],
    ]);

    expect($out['mfrs']['hd226'])->toBe(['Yealink', 'Protect Plus']);
});

it('does not create an empty-string key for blank suppliersku/mpn', function (): void {
    $out = $this->command->indexSupplierRows([
        ['suppliersku' => '   ', 'mpn' => '', 'manufacturer' => 'Ghost Brand'],
    ]);

    expect(isset($out['seen']['']))->toBeFalse();
    expect(isset($out['mfrs']['']))->toBeFalse();
    expect($out['seen'])->toBe([]);
});
