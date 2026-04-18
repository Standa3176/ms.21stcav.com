<?php

declare(strict_types=1);

use App\Domain\Sync\Exceptions\SyncAbortException;
use App\Domain\Sync\Jobs\SyncChunkJob;
use App\Domain\Sync\Models\SyncError;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\SyncRunItem;
use App\Domain\Sync\Services\AbortGuard;
use App\Domain\Sync\Services\SyncDiffEngine;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Context::add('correlation_id', (string) Str::uuid());
    config(['services.woo.write_enabled' => false]);
});

function alwaysFailingWooClient(): WooClient
{
    return new class extends WooClient
    {
        public function __construct() {}

        public function put(string $endpoint, array $payload): array
        {
            throw new \RuntimeException("Simulated Woo failure for {$endpoint}");
        }
    };
}

/**
 * Row builder — same shape as the real iterator output.
 */
function failRow(string $sku, int $pid): array
{
    return [
        'type' => 'simple',
        'sku' => $sku,
        'woo_product_id' => $pid,
        'woo_variation_id' => null,
        'price' => '10.00',
        'stock_quantity' => 5,
        'manage_stock' => true,
        'is_custom_ms' => false,
        'exclude_from_auto_update' => false,
    ];
}

// -----------------------------------------------------------------------------
// F1: A single Woo failure → sync_errors row + chunk continues
// -----------------------------------------------------------------------------
test('F1: WooClient failure records sync_errors row + chunk continues processing remaining SKUs', function () {
    $run = SyncRun::factory()->running()->create();

    $skus = [
        failRow('FAIL-1', 100),
        failRow('FAIL-2', 200),
        failRow('FAIL-3', 300),
    ];
    $feed = [
        'FAIL-1' => ['price' => '20.00', 'stock' => 5],
        'FAIL-2' => ['price' => '20.00', 'stock' => 5],
        'FAIL-3' => ['price' => '20.00', 'stock' => 5],
    ];

    $job = new SyncChunkJob($run->id, 1, $skus, $feed);
    $job->handle(alwaysFailingWooClient(), app(SyncDiffEngine::class), app(AbortGuard::class));

    expect(SyncError::forRun($run->id)->count())->toBe(3);
    expect(SyncRunItem::forRun($run->id)->where('action', 'failed')->count())->toBe(3);
});

// -----------------------------------------------------------------------------
// F2: 50+ consecutive failures → AbortGuard bubbles SyncAbortException
// -----------------------------------------------------------------------------
test('F2: 50 consecutive failures cause SyncAbortException to surface from SyncChunkJob', function () {
    $run = SyncRun::factory()->running()->create();

    $skus = [];
    $feed = [];
    for ($i = 1; $i <= 51; $i++) {
        $sku = "F2-{$i}";
        $skus[] = failRow($sku, 1000 + $i);
        $feed[$sku] = ['price' => '20.00', 'stock' => 5];
    }

    $job = new SyncChunkJob($run->id, 1, $skus, $feed);

    try {
        $job->handle(alwaysFailingWooClient(), app(SyncDiffEngine::class), app(AbortGuard::class));
        $this->fail('Expected SyncAbortException');
    } catch (SyncAbortException $e) {
        expect($e->reason)->toBe(SyncRun::ABORT_CONSECUTIVE);
    }
});

// -----------------------------------------------------------------------------
// F3: Mixed success/failure — run.failed_count reflects failures via AbortGuard
// -----------------------------------------------------------------------------
test('F3: mixed success/failure chunk — failed_count reflects only failures', function () {
    $run = SyncRun::factory()->running()->create();

    // Woo client that fails for SKUs starting with "F-", succeeds otherwise.
    $woo = new class extends WooClient
    {
        public function __construct() {}

        public function put(string $endpoint, array $payload): array
        {
            // Simulate failure for products/200 (FAIL) and products/400 (FAIL);
            // everything else returns shadow success.
            if (str_contains($endpoint, '200') || str_contains($endpoint, '400')) {
                throw new \RuntimeException("Simulated failure on {$endpoint}");
            }

            return ['shadow_mode' => true, 'diff_id' => 0];
        }
    };

    $skus = [
        failRow('OK-1', 100),
        failRow('FAIL-1', 200),
        failRow('OK-2', 300),
        failRow('FAIL-2', 400),
    ];
    $feed = [
        'OK-1' => ['price' => '15.00', 'stock' => 5],
        'FAIL-1' => ['price' => '15.00', 'stock' => 5],
        'OK-2' => ['price' => '15.00', 'stock' => 5],
        'FAIL-2' => ['price' => '15.00', 'stock' => 5],
    ];

    $job = new SyncChunkJob($run->id, 1, $skus, $feed);
    $job->handle($woo, app(SyncDiffEngine::class), app(AbortGuard::class));

    $run->refresh();
    // AbortGuard owns failed_count; 2 failures recorded.
    expect($run->failed_count)->toBe(2)
        // 2 successes + 2 failures = 4 total samples
        ->and($run->total_skus)->toBe(4)
        ->and($run->updated_count)->toBe(2);  // via $run->incrementCounter('updated')
});
