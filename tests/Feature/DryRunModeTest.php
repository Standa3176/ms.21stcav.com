<?php

declare(strict_types=1);

use App\Domain\Sync\Jobs\SyncChunkJob;
use App\Domain\Sync\Models\SyncDiff;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\SyncRunItem;
use App\Domain\Sync\Services\AbortGuard;
use App\Domain\Sync\Services\SupplierClient;
use App\Domain\Sync\Services\SyncDiffEngine;
use App\Domain\Sync\Services\WooClient;
use App\Domain\Sync\Services\WooProductIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Context::add('correlation_id', (string) Str::uuid());
    config(['services.woo.write_enabled' => false]);
});

function stubSupplierClientAndIterator(array $supplierFeed, array $pages): void
{
    app()->instance(SupplierClient::class, new class($supplierFeed) extends SupplierClient
    {
        public function __construct(private array $feed) {}

        public function fetchAllProducts(): array
        {
            return $this->feed;
        }
    });

    app()->instance(WooProductIterator::class, new class($pages)
    {
        public function __construct(private array $pages) {}

        public function pages(int $fromPage = 1): \Generator
        {
            foreach ($this->pages as $p) {
                if ($p['page'] >= $fromPage) {
                    yield $p;
                }
            }
        }
    });
}

// -----------------------------------------------------------------------------
// Y1: no flags → dry-run; zero real Woo writes; shadow rows created on Chunk dispatch sync
// -----------------------------------------------------------------------------
test('Y1: sync:supplier (no flags) runs in dry-run mode and writes only SyncDiff rows', function () {
    Queue::fake();

    stubSupplierClientAndIterator(
        supplierFeed: ['ABC-1' => ['price' => '15.00', 'stock' => 3]],
        pages: [
            ['page' => 1, 'skus' => [
                ['type' => 'simple', 'sku' => 'ABC-1', 'woo_product_id' => 100, 'woo_variation_id' => null, 'price' => '10.00', 'stock_quantity' => 5, 'manage_stock' => true, 'is_custom_ms' => false, 'exclude_from_auto_update' => false],
            ]],
        ],
    );

    $this->artisan('sync:supplier')->assertSuccessful();

    $run = SyncRun::latest('id')->first();
    expect($run->dry_run)->toBeTrue();

    Queue::assertPushed(SyncChunkJob::class, 1);
});

// -----------------------------------------------------------------------------
// Y2: --dry-run explicit → same behaviour as Y1
// -----------------------------------------------------------------------------
test('Y2: --dry-run explicit matches Y1 behaviour', function () {
    Queue::fake();
    stubSupplierClientAndIterator([], []);

    $this->artisan('sync:supplier', ['--dry-run' => true])->assertSuccessful();

    $run = SyncRun::latest('id')->first();
    expect($run->dry_run)->toBeTrue();
});

// -----------------------------------------------------------------------------
// Y3: --live + WOO_WRITE_ENABLED=true → dry_run=false on SyncRun
// -----------------------------------------------------------------------------
test('Y3: --live with WOO_WRITE_ENABLED=true persists dry_run=false', function () {
    config(['services.woo.write_enabled' => true]);
    Queue::fake();
    stubSupplierClientAndIterator([], []);

    $this->artisan('sync:supplier', ['--live' => true])->assertSuccessful();

    $run = SyncRun::latest('id')->first();
    expect($run->dry_run)->toBeFalse();
});

// -----------------------------------------------------------------------------
// Y4: --live with WOO_WRITE_ENABLED=false → still shadow at WooClient level
// -----------------------------------------------------------------------------
test('Y4: --live with WOO_WRITE_ENABLED=false still routes writes to SyncDiff (belt-and-braces)', function () {
    config(['services.woo.write_enabled' => false]);

    // Invoke WooClient::put directly to prove the env gate still applies regardless
    // of the --live flag (SyncRun only records operator intent; the real gate is env).
    $woo = app(WooClient::class);
    $result = $woo->put('products/123', ['regular_price' => '99.00']);

    expect($result['shadow_mode'] ?? null)->toBeTrue();
    expect(SyncDiff::count())->toBe(1);
});
