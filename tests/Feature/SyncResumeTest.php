<?php

declare(strict_types=1);

use App\Domain\Sync\Jobs\SyncChunkJob;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Services\SupplierClient;
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

function resumeStub(array $pages, array $feed = []): void
{
    $supplier = Mockery::mock(SupplierClient::class);
    $supplier->shouldReceive('fetchAllProducts')->andReturn($feed);
    app()->instance(SupplierClient::class, $supplier);

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
// Z1: --resume={id} on aborted run starts iteration at cursor_page
// -----------------------------------------------------------------------------
test('Z1: --resume on aborted run picks up from cursor_page', function () {
    Queue::fake();

    $aborted = SyncRun::factory()->aborted()->create([
        'cursor_page' => 3,
    ]);

    resumeStub(
        pages: [
            ['page' => 1, 'skus' => [['type' => 'simple', 'sku' => 'P1', 'woo_product_id' => 1, 'woo_variation_id' => null, 'price' => '1.00', 'stock_quantity' => 1, 'manage_stock' => true, 'is_custom_ms' => false, 'exclude_from_auto_update' => false]]],
            ['page' => 3, 'skus' => [['type' => 'simple', 'sku' => 'P3', 'woo_product_id' => 3, 'woo_variation_id' => null, 'price' => '1.00', 'stock_quantity' => 1, 'manage_stock' => true, 'is_custom_ms' => false, 'exclude_from_auto_update' => false]]],
            ['page' => 4, 'skus' => [['type' => 'simple', 'sku' => 'P4', 'woo_product_id' => 4, 'woo_variation_id' => null, 'price' => '1.00', 'stock_quantity' => 1, 'manage_stock' => true, 'is_custom_ms' => false, 'exclude_from_auto_update' => false]]],
        ],
    );

    $this->artisan('sync:supplier', ['--resume' => $aborted->id])->assertSuccessful();

    // 2 chunks dispatched (pages 3, 4) — page 1 skipped since cursor_page=3.
    Queue::assertPushed(SyncChunkJob::class, 2);
});

// -----------------------------------------------------------------------------
// Z4: Resuming a completed run fails (findResumable scopes to aborted/failed/running)
// -----------------------------------------------------------------------------
test('Z4: --resume on a completed run throws ModelNotFoundException', function () {
    $completed = SyncRun::factory()->completed()->create();

    try {
        $this->withoutExceptionHandling()
            ->artisan('sync:supplier', ['--resume' => $completed->id])
            ->run();
        $this->fail('Expected ModelNotFoundException');
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        expect(true)->toBeTrue();
    }
});
