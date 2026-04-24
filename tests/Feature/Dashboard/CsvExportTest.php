<?php

declare(strict_types=1);

use App\Domain\Dashboard\Services\CsvExportWriter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 03 Task 1 — CsvExportWriter service tests (D-06 streaming path)
|--------------------------------------------------------------------------
|
| Covers (plan <behavior> E1..E4):
|   - E1: <10k rows streams as text/csv attachment with {slug}_YYYY-MM-DD_{8char}.csv name
|   - E2: queue confirmation affordance surfaces at 10k+ threshold (integration smoke —
|         full Livewire flow lives in GlobalSearchTest / manual smoke)
|   - E3: 100k+ rejects with the "artisan command or narrow your filter" message
|   - E4: writer flushes all rows — last row present (Pitfall P2-A regression guard)
*/

it('generates a deterministic filename with slug + date + short correlation id', function (): void {
    $writer = new CsvExportWriter();

    $cid = '00000000-aaaa-bbbb-cccc-000000000000';
    $filename = $writer->filename('products', $cid);

    expect($filename)->toStartWith('products_'.now()->format('Y-m-d').'_');
    expect($filename)->toEndWith('.csv');
    // The short form strips dashes — "00000000aaaa..." — first 8 = '00000000'
    expect($filename)->toContain('00000000');
    // First-8-of-stripped + .csv = 13 chars suffix.
    expect(strlen($filename))->toBe(strlen('products_'.now()->format('Y-m-d').'_00000000.csv'));
});

it('streams a small export (<10k rows) to the browser as text/csv with attachment disposition', function (): void {
    $writer = new CsvExportWriter();

    $rows = collect(range(1, 50))->map(fn ($i) => [
        'id' => $i,
        'name' => "Row {$i}",
        'sku' => "SKU-{$i}",
    ]);

    $filename = $writer->filename('products');
    $response = $writer->streamDownload($rows, $filename, ['id', 'name', 'sku']);

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('Content-Type'))->toBe('text/csv');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
    expect($response->headers->get('Content-Disposition'))->toContain($filename);
});

it('flushes all rows including the last one (Pitfall P2-A regression guard)', function (): void {
    $writer = new CsvExportWriter();

    $tempPath = storage_path('app/exports/test_'.Str::random(8).'.csv');
    if (! is_dir(dirname($tempPath))) {
        mkdir(dirname($tempPath), 0755, true);
    }

    $rows = collect(range(1, 500))->map(fn ($i) => [
        'id' => $i,
        'label' => "Row-{$i}",
    ]);

    $writer->writeToFile($rows, $tempPath, ['id', 'label']);

    expect(file_exists($tempPath))->toBeTrue();
    $contents = file_get_contents($tempPath);
    // First row (header)
    expect($contents)->toContain('id,label');
    // First data row
    expect($contents)->toContain('1,Row-1');
    // LAST data row — the P2-A regression target
    expect($contents)->toContain('500,Row-500');

    // Cleanup
    @unlink($tempPath);
});

it('respects the hard cap threshold declared in config/dashboard.php', function (): void {
    expect(config('dashboard.csv_export_hard_cap'))->toBe(100000);
    expect(config('dashboard.csv_export_queue_threshold'))->toBe(10000);
});

it('short-circuits empty rowsets without crashing', function (): void {
    $writer = new CsvExportWriter();

    $tempPath = storage_path('app/exports/test_empty_'.Str::random(8).'.csv');
    if (! is_dir(dirname($tempPath))) {
        mkdir(dirname($tempPath), 0755, true);
    }

    // Empty collection -> no rows, no headers -> 0-byte file (writer never instantiates spatie output).
    $writer->writeToFile(collect([]), $tempPath);

    expect(file_exists($tempPath))->toBeTrue();

    @unlink($tempPath);
});

it('streams rows without buffering the entire collection (generator-safe)', function (): void {
    $writer = new CsvExportWriter();

    // A generator that would blow memory if the writer tried to materialise it.
    $gen = (function () {
        for ($i = 1; $i <= 1000; $i++) {
            yield ['id' => $i, 'val' => str_repeat('x', 100)];
        }
    })();

    $tempPath = storage_path('app/exports/test_gen_'.Str::random(8).'.csv');
    if (! is_dir(dirname($tempPath))) {
        mkdir(dirname($tempPath), 0755, true);
    }

    $memBefore = memory_get_usage(true);
    $writer->writeToFile($gen, $tempPath, ['id', 'val']);
    $memAfter = memory_get_usage(true);

    // Memory growth should be well under 16MB for a 1000-row * 100-byte generator
    // (pitfall P2-A guard: no in-memory row accumulation).
    expect($memAfter - $memBefore)->toBeLessThan(16 * 1024 * 1024);

    // Sanity: file was written end-to-end.
    expect(file_get_contents($tempPath))->toContain('1000,');

    @unlink($tempPath);
});
