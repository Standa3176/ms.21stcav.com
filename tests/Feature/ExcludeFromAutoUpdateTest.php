<?php

declare(strict_types=1);

use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Events\SupplierStockChanged;
use App\Domain\Sync\Jobs\SyncChunkJob;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\SyncRunItem;
use App\Domain\Sync\Services\AbortGuard;
use App\Domain\Sync\Services\SyncDiffEngine;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Context::add('correlation_id', (string) Str::uuid());
    config(['services.woo.write_enabled' => false]);
});

function excludeWooMock(): WooClient
{
    return new class extends WooClient
    {
        public int $putCount = 0;

        public function __construct() {}

        public function put(string $endpoint, array $payload): array
        {
            $this->putCount++;

            return ['shadow_mode' => true, 'diff_id' => $this->putCount];
        }
    };
}

// -----------------------------------------------------------------------------
// X1: exclude_from_auto_update → skipped, no Woo write, skipped_count bumps
// -----------------------------------------------------------------------------
test('X1: product with _exclude_from_auto_update is skipped — no Woo write, skipped_count bumps', function () {
    $run = SyncRun::factory()->running()->create();
    $woo = excludeWooMock();

    $skus = [[
        'type' => 'simple',
        'sku' => 'EXCLUDED-1',
        'woo_product_id' => 8888,
        'woo_variation_id' => null,
        'price' => '10.00',
        'stock_quantity' => 5,
        'manage_stock' => true,
        'is_custom_ms' => false,
        'exclude_from_auto_update' => true,  // the key
    ]];
    $feed = ['EXCLUDED-1' => ['price' => '999.99', 'stock' => 999]];  // huge diff — still skipped.

    $job = new SyncChunkJob($run->id, 1, $skus, $feed);
    $job->handle($woo, app(SyncDiffEngine::class), app(AbortGuard::class));

    expect($woo->putCount)->toBe(0);

    $run->refresh();
    expect($run->skipped_count)->toBe(1);

    $item = SyncRunItem::forRun($run->id)->first();
    expect($item)->not->toBeNull()
        ->and($item->action)->toBe('skipped')
        ->and($item->reason)->toBe('exclude_from_auto_update');
});

// -----------------------------------------------------------------------------
// X2: exclusion does NOT dispatch price/stock events
// -----------------------------------------------------------------------------
test('X2: excluded SKU does not dispatch SupplierPriceChanged or SupplierStockChanged', function () {
    Event::fake([SupplierPriceChanged::class, SupplierStockChanged::class]);
    $run = SyncRun::factory()->running()->create();
    $woo = excludeWooMock();

    $skus = [[
        'type' => 'simple',
        'sku' => 'EX-2',
        'woo_product_id' => 7777,
        'woo_variation_id' => null,
        'price' => '10.00',
        'stock_quantity' => 5,
        'manage_stock' => true,
        'is_custom_ms' => false,
        'exclude_from_auto_update' => true,
    ]];
    $feed = ['EX-2' => ['price' => '99.00', 'stock' => 99]];

    $job = new SyncChunkJob($run->id, 1, $skus, $feed);
    $job->handle($woo, app(SyncDiffEngine::class), app(AbortGuard::class));

    Event::assertNotDispatched(SupplierPriceChanged::class);
    Event::assertNotDispatched(SupplierStockChanged::class);
});
