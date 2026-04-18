<?php

declare(strict_types=1);

use App\Domain\Sync\Events\SupplierSkuMissing;
use App\Domain\Sync\Jobs\MarkMissingSkusJob;
use App\Domain\Sync\Models\ImportIssue;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\SyncRunItem;
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

/**
 * Woo mock capturing every put() call so tests can assert endpoint + payload.
 */
function capturingWooClient(): WooClient
{
    return new class extends WooClient
    {
        public array $calls = [];

        public function __construct() {}

        public function put(string $endpoint, array $payload): array
        {
            $this->calls[] = ['endpoint' => $endpoint, 'payload' => $payload];

            return ['shadow_mode' => true, 'diff_id' => count($this->calls)];
        }
    };
}

// -----------------------------------------------------------------------------
// Ms1: simple product without custom-ms → status=pending (Woo write)
// -----------------------------------------------------------------------------
test('Ms1: missing simple product (no custom-ms) flips to status=pending', function () {
    Event::fake([SupplierSkuMissing::class]);
    $run = SyncRun::factory()->running()->create();
    $woo = capturingWooClient();

    $missingRows = [[
        'sku' => 'GONE-1',
        'type' => 'simple',
        'woo_product_id' => 1001,
        'woo_variation_id' => null,
        'is_custom_ms' => false,
        'woo_price' => '50.00',
        'woo_stock' => 2,
    ]];

    $job = new MarkMissingSkusJob($run->id, $missingRows);
    $job->handle($woo);

    expect($woo->calls)->toHaveCount(1)
        ->and($woo->calls[0]['endpoint'])->toBe('products/1001')
        ->and($woo->calls[0]['payload'])->toBe(['status' => 'pending']);

    Event::assertDispatched(SupplierSkuMissing::class, function (SupplierSkuMissing $e) {
        return $e->sku === 'GONE-1'
            && $e->newStatus === 'pending'
            && $e->hadCustomMsTag === false;
    });
});

// -----------------------------------------------------------------------------
// Ms2: simple product WITH custom-ms → no Woo write, event still fired
// -----------------------------------------------------------------------------
test('Ms2: missing simple product WITH custom-ms — no Woo write, event still dispatched', function () {
    Event::fake([SupplierSkuMissing::class]);
    $run = SyncRun::factory()->running()->create();
    $woo = capturingWooClient();

    $missingRows = [[
        'sku' => 'CUSTOM-1',
        'type' => 'simple',
        'woo_product_id' => 2002,
        'woo_variation_id' => null,
        'is_custom_ms' => true,
        'woo_price' => '50.00',
        'woo_stock' => 2,
    ]];

    $job = new MarkMissingSkusJob($run->id, $missingRows);
    $job->handle($woo);

    expect($woo->calls)->toBeEmpty();

    Event::assertDispatched(SupplierSkuMissing::class, function (SupplierSkuMissing $e) {
        return $e->sku === 'CUSTOM-1'
            && $e->newStatus === 'publish'
            && $e->hadCustomMsTag === true;
    });
});

// -----------------------------------------------------------------------------
// Ms3: variation missing → status=private (regardless of parent's custom-ms)
// -----------------------------------------------------------------------------
test('Ms3: missing variation flips to status=private (D-03 granular, ignores parent custom-ms)', function () {
    Event::fake([SupplierSkuMissing::class]);
    $run = SyncRun::factory()->running()->create();
    $woo = capturingWooClient();

    $missingRows = [[
        'sku' => 'VAR-GONE',
        'type' => 'variation',
        'woo_product_id' => 3003,
        'woo_variation_id' => 4004,
        'is_custom_ms' => true,  // parent IS custom-ms — but variation flips anyway.
        'woo_price' => '25.00',
        'woo_stock' => 1,
    ]];

    $job = new MarkMissingSkusJob($run->id, $missingRows);
    $job->handle($woo);

    expect($woo->calls)->toHaveCount(1)
        ->and($woo->calls[0]['endpoint'])->toBe('products/3003/variations/4004')
        ->and($woo->calls[0]['payload'])->toBe(['status' => 'private']);

    Event::assertDispatched(SupplierSkuMissing::class, fn ($e) => $e->newStatus === 'private');
});

// -----------------------------------------------------------------------------
// Ms4: ImportIssue row created for every missing SKU
// -----------------------------------------------------------------------------
test('Ms4: ImportIssue row created for every missing SKU (issue_type=missing_at_supplier)', function () {
    $run = SyncRun::factory()->running()->create();
    $woo = capturingWooClient();

    $missingRows = [
        ['sku' => 'I-1', 'type' => 'simple', 'woo_product_id' => 10, 'woo_variation_id' => null, 'is_custom_ms' => false, 'woo_price' => '1.00', 'woo_stock' => 0],
        ['sku' => 'I-2', 'type' => 'variation', 'woo_product_id' => 20, 'woo_variation_id' => 21, 'is_custom_ms' => false, 'woo_price' => '2.00', 'woo_stock' => 0],
    ];

    $job = new MarkMissingSkusJob($run->id, $missingRows);
    $job->handle($woo);

    expect(ImportIssue::where('issue_type', ImportIssue::TYPE_MISSING_AT_SUPPLIER)->count())->toBe(2);
});

// -----------------------------------------------------------------------------
// Ms5 (Warning 6 fix): SyncRunItem row has old_price + old_stock populated
// -----------------------------------------------------------------------------
test('Ms5: SyncRunItem for action=missing populates old_price + old_stock from Woo-side state (D-10 11-col)', function () {
    $run = SyncRun::factory()->running()->create();
    $woo = capturingWooClient();

    $missingRows = [[
        'sku' => 'COL-CHECK',
        'type' => 'simple',
        'woo_product_id' => 500,
        'woo_variation_id' => null,
        'is_custom_ms' => false,
        'woo_price' => '123.45',
        'woo_stock' => 9,
    ]];

    $job = new MarkMissingSkusJob($run->id, $missingRows);
    $job->handle($woo);

    $item = SyncRunItem::forRun($run->id)->first();
    expect($item)->not->toBeNull()
        ->and($item->action)->toBe(SyncRunItem::ACTION_MISSING)
        ->and($item->old_price)->toBe('123.45')
        ->and($item->old_stock)->toBe(9)
        ->and($item->new_price)->toBeNull()
        ->and($item->new_stock)->toBeNull()
        ->and($item->correlation_id)->toBe($run->correlation_id);
});
