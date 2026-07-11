<?php

declare(strict_types=1);

use App\Console\Commands\DraftFromSuggestionsCommand;
use App\Domain\ProductAutoCreate\Models\AutoPublishLogEntry;
use App\Domain\Products\Models\Product;

/*
|--------------------------------------------------------------------------
| Quick task 260711-aps Task 2 — auto_publish_log audit write
|--------------------------------------------------------------------------
| The --auto-approve loop records ONE row per REAL successful live publish,
| capturing competitor_count (2 or 3) so the operator sees the split. The write
| happens AFTER PublishProductJob::dispatchSync returns and ONLY when the product
| is confirmed published (re-read auto_create_status==='published' AND
| woo_product_id present).
|
| CRITICAL: in shadow mode (WOO_WRITE_ENABLED=false → PublishProductJob no-ops,
| leaves the row un-published with no woo_product_id) NO audit row is written.
|
| The supplier walk uses a live mysqli connection that cannot be faked
| in-process, so the audit write is covered via the pure, testable
| recordAutoPublish() seam — the exact confirmation-gated write the loop drives.
*/

function auditCommand(): DraftFromSuggestionsCommand
{
    return app(DraftFromSuggestionsCommand::class);
}

it('writes exactly one audit row for a confirmed live publish', function (): void {
    $product = Product::factory()->create([
        'sku' => 'LIVE-2COMP',
        'auto_create_status' => 'published',
        'woo_product_id' => 4321,
    ]);

    $wrote = auditCommand()->recordAutoPublish($product, 2, null, 'corr-123');

    expect($wrote)->toBeTrue();
    expect(AutoPublishLogEntry::count())->toBe(1);

    $row = AutoPublishLogEntry::first();
    expect($row->sku)->toBe('LIVE-2COMP')
        ->and($row->product_id)->toBe($product->id)
        ->and($row->woo_product_id)->toBe(4321)
        ->and($row->competitor_count)->toBe(2)
        ->and($row->source)->toBe(AutoPublishLogEntry::SOURCE_SCHEDULED)
        ->and($row->batch_correlation_id)->toBe('corr-123')
        ->and($row->published_at)->not->toBeNull();
});

it('writes NO audit row in shadow mode (not published, no woo id)', function (): void {
    // PublishProductJob shadow-mode outcome: row stays un-published, no woo id.
    $product = Product::factory()->create([
        'sku' => 'SHADOW-SKU',
        'auto_create_status' => 'draft',
        'woo_product_id' => null,
    ]);

    $wrote = auditCommand()->recordAutoPublish($product, 3, null, 'corr-shadow');

    expect($wrote)->toBeFalse();
    expect(AutoPublishLogEntry::count())->toBe(0);
});

it('writes NO audit row when woo id present but status not published (partial/defensive)', function (): void {
    $product = Product::factory()->create([
        'sku' => 'PARTIAL-SKU',
        'auto_create_status' => 'draft',
        'woo_product_id' => 9999,
    ]);

    expect(auditCommand()->recordAutoPublish($product, 2, null, null))->toBeFalse();
    expect(AutoPublishLogEntry::count())->toBe(0);
});

it('writes NO audit row when published but woo id missing (defensive)', function (): void {
    $product = Product::factory()->create([
        'sku' => 'NOWOO-SKU',
        'auto_create_status' => 'published',
        'woo_product_id' => null,
    ]);

    expect(auditCommand()->recordAutoPublish($product, 3, null, null))->toBeFalse();
    expect(AutoPublishLogEntry::count())->toBe(0);
});

it('records the 2-vs-3 competitor split across two publishes', function (): void {
    $two = Product::factory()->create([
        'sku' => 'SPLIT-2',
        'auto_create_status' => 'published',
        'woo_product_id' => 111,
    ]);
    $three = Product::factory()->create([
        'sku' => 'SPLIT-3',
        'auto_create_status' => 'published',
        'woo_product_id' => 222,
    ]);

    auditCommand()->recordAutoPublish($two, 2, null, 'batch-1');
    auditCommand()->recordAutoPublish($three, 3, null, 'batch-1');

    expect(AutoPublishLogEntry::count())->toBe(2);
    expect(AutoPublishLogEntry::where('sku', 'SPLIT-2')->value('competitor_count'))->toBe(2);
    expect(AutoPublishLogEntry::where('sku', 'SPLIT-3')->value('competitor_count'))->toBe(3);
});
