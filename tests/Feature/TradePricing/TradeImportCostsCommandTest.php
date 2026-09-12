<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\TradePricing\Models\TradeCostAdjustment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260912-f8x — trade:import-costs
|--------------------------------------------------------------------------
|
| Fixtures use the REAL shape of the August Yealink platinum list, because
| that shape is the whole reason this command exists:
|
|   MidwichSku       Manu Code               Trade
|   YEAA24           A24**1303161            773
|   YEAMB65PRO       MB65PRO-A02**1203670    2103
|
| Midwich prefix with YEA; the manufacturer code carries an internal id after
| a double asterisk. Matching by hand on 2026-09-10 got 70 of 109 by trying
| the manufacturer code, the supplier SKU, then the supplier SKU minus the
| prefix — which is what this command automates.
*/

function writeCsv(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'costs-').'.csv';
    file_put_contents($path, $body);

    return $path;
}

/**
 * Options go in as an ARRAY, never as a command string.
 *
 * tempnam() returns a Windows path on this machine, and the string form of
 * artisan() treats its separators as escapes — every case here failed with a
 * garbled --file while the command ran perfectly from a real shell. The array
 * form skips that parsing altogether.
 *
 * @param  array<string, mixed>  $options
 */
function importCosts(array $options): PendingCommand
{
    return test()->artisan('trade:import-costs', $options);
}

it('registers the command', function (): void {
    expect(array_keys(app(Kernel::class)->all()))->toContain('trade:import-costs');
});

it('writes NOTHING without --apply', function (): void {
    Product::factory()->create(['sku' => 'A24']);
    $csv = writeCsv("MidwichSku,Manu Code,Trade\nYEAA24,A24**1303161,773\n");

    importCosts(['--file' => $csv, '--sku-column' => 'Manu Code', '--price-column' => 'Trade'])
        ->assertExitCode(0)
        ->expectsOutputToContain('DRY-RUN')
        ->expectsOutputToContain('Nothing was written.');

    expect(TradeCostAdjustment::count())->toBe(0);
});

it('matches on the manufacturer code, splitting the internal id off', function (): void {
    Product::factory()->create(['sku' => 'MB65PRO-A02']);
    $csv = writeCsv("MidwichSku,Manu Code,Trade\nYEAMB65PRO,MB65PRO-A02**1203670,2103\n");

    importCosts(['--file' => $csv, '--sku-column' => 'Manu Code', '--price-column' => 'Trade', '--apply' => true])
        ->assertExitCode(0);

    $row = TradeCostAdjustment::first();
    expect($row->sku)->toBe('MB65PRO-A02')
        ->and((float) $row->absolute_cost)->toBe(2103.0)
        ->and($row->adjustment_pct)->toBeNull();
});

it('falls back to the supplier SKU, then to the SKU minus its prefix', function (): void {
    // Carried under the Midwich code rather than the manufacturer's.
    Product::factory()->create(['sku' => 'YEAMBFSLIFT']);
    // Carried under the bare code, with the supplier prefix stripped.
    Product::factory()->create(['sku' => 'ROOMCASTE2']);

    $csv = writeCsv(
        "MidwichSku,Manu Code,Trade\n".
        "YEAMBFSLIFT,MB-FS-LIFT**999,583\n".
        "YEAROOMCASTE2,NOT-A-MATCH**1,287\n"
    );

    importCosts([
        '--file' => $csv, '--sku-column' => 'Manu Code', '--alt-sku-column' => 'MidwichSku',
        '--price-column' => 'Trade', '--strip-prefix' => 'YEA', '--apply' => true,
    ])->assertExitCode(0);

    expect(TradeCostAdjustment::pluck('sku')->sort()->values()->all())
        ->toBe(['ROOMCASTE2', 'YEAMBFSLIFT']);
});

it('reports rows it cannot match rather than guessing at them', function (): void {
    $csv = writeCsv("MidwichSku,Manu Code,Trade\nYEAGHOST,GHOST-1**5,99\n");

    importCosts(['--file' => $csv, '--sku-column' => 'Manu Code', '--price-column' => 'Trade', '--apply' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Not found in the catalogue');

    expect(TradeCostAdjustment::count())->toBe(0);
});

it('re-importing next month REPLACES the arrangement instead of stacking a second row', function (): void {
    Product::factory()->create(['sku' => 'A24']);

    $aug = writeCsv("Manu Code,Trade\nA24**1303161,773\n");
    importCosts(['--file' => $aug, '--sku-column' => 'Manu Code', '--price-column' => 'Trade', '--apply' => true])->run();

    $sep = writeCsv("Manu Code,Trade\nA24**1303161,749\n");
    importCosts(['--file' => $sep, '--sku-column' => 'Manu Code', '--price-column' => 'Trade', '--apply' => true])->run();

    expect(TradeCostAdjustment::count())->toBe(1)
        ->and((float) TradeCostAdjustment::first()->absolute_cost)->toBe(749.0);
});

it('carries the dates and reason through, so a dated deal lapses on its own', function (): void {
    Product::factory()->create(['sku' => 'A24']);
    $csv = writeCsv("Manu Code,Trade\nA24**1,773\n");

    importCosts([
        '--file' => $csv, '--sku-column' => 'Manu Code', '--price-column' => 'Trade',
        '--valid-until' => '2026-12-31', '--reason' => 'Yealink platinum, August list', '--apply' => true,
    ])->run();

    $row = TradeCostAdjustment::first();
    expect($row->valid_until->toDateString())->toBe('2026-12-31')
        ->and($row->reason)->toBe('Yealink platinum, August list');
});

it('skips rows with no usable price rather than importing a zero', function (): void {
    Product::factory()->create(['sku' => 'A24']);
    Product::factory()->create(['sku' => 'B25']);
    $csv = writeCsv("Manu Code,Trade\nA24**1,\nB25**1,0\n");

    importCosts(['--file' => $csv, '--sku-column' => 'Manu Code', '--price-column' => 'Trade', '--apply' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('2 without a usable price');

    expect(TradeCostAdjustment::count())->toBe(0);
});

it('handles money as a spreadsheet exports it — currency symbols and thousands separators', function (): void {
    Product::factory()->create(['sku' => 'BIG-1']);
    $csv = writeCsv("Manu Code,Trade\nBIG-1**1,\"£1,234.56\"\n");

    importCosts(['--file' => $csv, '--sku-column' => 'Manu Code', '--price-column' => 'Trade', '--apply' => true])->run();

    expect((float) TradeCostAdjustment::first()->absolute_cost)->toBe(1234.56);
});

it('survives a UTF-8 BOM on the first header, which Excel adds', function (): void {
    Product::factory()->create(['sku' => 'A24']);
    $csv = writeCsv("\u{FEFF}Manu Code,Trade\nA24**1,773\n");

    importCosts(['--file' => $csv, '--sku-column' => 'Manu Code', '--price-column' => 'Trade', '--apply' => true])
        ->assertExitCode(0);

    expect(TradeCostAdjustment::count())->toBe(1);
});

it('fails clearly when a named column is not in the file', function (): void {
    $csv = writeCsv("Manu Code,Trade\nA24**1,773\n");

    importCosts(['--file' => $csv, '--sku-column' => 'Nonexistent', '--price-column' => 'Trade'])
        ->assertExitCode(1)
        ->expectsOutputToContain('Headers found');
});

it('fails when the file is missing rather than importing nothing quietly', function (): void {
    importCosts(['--file' => sys_get_temp_dir().'/does-not-exist-'.uniqid().'.csv'])
        ->assertExitCode(1);
});
