<?php

declare(strict_types=1);

use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\SyncRunItem;
use App\Domain\Sync\Reports\SyncReportCsvGenerator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // D-10 writer persists to storage/app/private/sync-reports/ — wipe between tests.
    $dir = storage_path('app/private/sync-reports');
    if (is_dir($dir)) {
        File::cleanDirectory($dir);
    }
});

it('writes the D-10 header row with exactly 11 columns in order', function (): void {
    $run = SyncRun::factory()->completed()->create([
        'correlation_id' => (string) Str::uuid(),
    ]);

    $path = app(SyncReportCsvGenerator::class)->generate($run);

    expect($path)->toBe(storage_path("app/private/sync-reports/run-{$run->id}.csv"));
    expect(file_exists($path))->toBeTrue();

    $handle = fopen($path, 'r');
    $header = fgetcsv($handle);
    fclose($handle);

    // Strip UTF-8 BOM (spatie/simple-excel prepends by default for Excel compat).
    if ($header !== false && str_starts_with($header[0], "\xEF\xBB\xBF")) {
        $header[0] = substr($header[0], 3);
    }

    expect($header)->toBe([
        'sku',
        'woo_product_id',
        'woo_variation_id',
        'action',
        'reason',
        'old_price',
        'new_price',
        'old_stock',
        'new_stock',
        'error_message',
        'correlation_id',
    ]);
});

it('writes one data row per SyncRunItem for the run', function (): void {
    $run = SyncRun::factory()->completed()->create([
        'correlation_id' => (string) Str::uuid(),
    ]);

    SyncRunItem::factory()->count(3)->create([
        'sync_run_id' => $run->id,
        'correlation_id' => $run->correlation_id,
    ]);

    $path = app(SyncReportCsvGenerator::class)->generate($run);

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    // 1 header + 3 data
    expect($lines)->toHaveCount(4);
});

it('produces only a header row for an empty run (Pitfall P2-A still flushes)', function (): void {
    $run = SyncRun::factory()->completed()->create([
        'correlation_id' => (string) Str::uuid(),
    ]);

    $path = app(SyncReportCsvGenerator::class)->generate($run);

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect($lines)->toHaveCount(1);
});

it('persists the per-SKU values correctly (CSV round-trip)', function (): void {
    $run = SyncRun::factory()->completed()->create([
        'correlation_id' => (string) Str::uuid(),
    ]);

    SyncRunItem::factory()->create([
        'sync_run_id' => $run->id,
        'sku' => 'TEST-SKU-001',
        'woo_product_id' => 12345,
        'woo_variation_id' => null,
        'action' => SyncRunItem::ACTION_UPDATED,
        'reason' => null,
        'old_price' => '199.00',
        'new_price' => '189.00',
        'old_stock' => 5,
        'new_stock' => 7,
        'error_message' => null,
        'correlation_id' => $run->correlation_id,
    ]);

    $path = app(SyncReportCsvGenerator::class)->generate($run);

    $rows = [];
    $handle = fopen($path, 'r');
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    expect($rows)->toHaveCount(2);
    $data = $rows[1];
    expect($data[0])->toBe('TEST-SKU-001');
    expect($data[1])->toBe('12345');
    expect($data[3])->toBe(SyncRunItem::ACTION_UPDATED);
    expect($data[5])->toBe('199.00');
    expect($data[6])->toBe('189.00');
    expect($data[7])->toBe('5');
    expect($data[8])->toBe('7');
});

it('streams 1500 rows with constant memory (chunk 500) — Pitfall P2-A + memory guard', function (): void {
    $run = SyncRun::factory()->completed()->create([
        'correlation_id' => (string) Str::uuid(),
    ]);

    // Bulk-insert 1500 items via factory->count()->create() chunks
    SyncRunItem::factory()->count(1500)->create([
        'sync_run_id' => $run->id,
        'correlation_id' => $run->correlation_id,
    ]);

    $peakBefore = memory_get_peak_usage(true);
    $path = app(SyncReportCsvGenerator::class)->generate($run);
    $peakAfter = memory_get_peak_usage(true);

    $lines = count(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    expect($lines)->toBe(1501);  // 1 header + 1500 data

    // Peak growth bounded — chunk=500 should NOT cause 30MB+ allocation.
    $growthMb = ($peakAfter - $peakBefore) / 1024 / 1024;
    expect($growthMb)->toBeLessThan(30.0);
});
