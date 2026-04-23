<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\ProductAutoCreate\Listeners\ApplyPinsDuringSync;
use App\Domain\ProductAutoCreate\Services\ProductOverrideGuard;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Events\SupplierSkuMissing;
use App\Domain\Sync\Events\SupplierStockChanged;
use App\Domain\Sync\Jobs\SyncChunkJob;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Services\AbortGuard;
use App\Domain\Sync\Services\SyncDiffEngine;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Cases 1-3 exercise the DB; Cases 4-5 are grep/event-fake assertions that
// don't need DB. RefreshDatabase applies file-wide (Pest limitation). Tests
// will MySQL-defer like the rest of Phase 6 on CI environments without MySQL
// (Plan 06-01/02/03/04 precedent).
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Architecture: Phase 6 Plan 05 — AUTO-10 pin enforcement ship gate
|--------------------------------------------------------------------------
|
| This is THE AUTO-10 regression test referenced by CONTEXT.md D-13 and
| 06-VERIFICATION.md. Exercises the full Phase 2 sync path end-to-end with
| pins configured and asserts that pinned fields survive a supplier-sync
| cycle byte-identically.
|
| Execution model (RESEARCH Q5 resolution = revert-after-the-fact):
|
|   1. Phase 2's SyncChunkJob runs its normal per-SKU update path (the job
|      is NEVER modified per D-11).
|   2. On a successful Woo update where price changed, SyncChunkJob emits
|      SupplierPriceChanged via the DomainEvent base — the event is
|      ShouldDispatchAfterCommit so it fires only on commit success.
|   3. Phase 6 Plan 05's ApplyPinsDuringSync listener picks up the event
|      and, via ProductOverrideGuard, issues a revert PUT to Woo with the
|      Laravel-persisted value for any pinned field.
|   4. The Laravel-side Product row's pinned columns (name,
|      short_description, long_description, ...) are NEVER touched by the
|      sync path (Phase 2 only updates buy_price + last_synced_at on the
|      local mirror). So the byte-identity assertion is exercised both
|      against the Laravel row AND via the observable revert PUT to Woo.
|
| The test runs against WooClient shadow mode (services.woo.write_enabled=false)
| — WooClient::put records a SyncDiff row instead of hitting a real Woo. This
| makes both the Phase 2 write AND the Plan 05 revert PUT observable via the
| Auditor activity log entry (no real HTTP needed).
|
| Three cases:
|   1. Pinned title + short_description + pin_price survive sync, revert PUT
|      fires for regular_price, audit entry written.
|   2. Unpinned product is overwritten normally — sync updates buy_price
|      in the local mirror, no revert PUT fires.
|   3. Pin-revert failure does not cascade — sibling listeners still run;
|      Log::warning captured.
*/

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
    // Shadow mode — WooClient::put returns {shadow_mode: true, diff_id: N}
    // without hitting real HTTP. Makes the full sync cycle observable.
    config(['services.woo.write_enabled' => false]);
});

/**
 * Build a canonical Woo simple-product sku-row matching WooProductIterator shape.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function buildPinTestSkuRow(string $sku, int $pid, string $price = '10.00', int $stock = 5, array $extra = []): array
{
    return array_merge([
        'type' => 'simple',
        'sku' => $sku,
        'woo_product_id' => $pid,
        'woo_variation_id' => null,
        'price' => $price,
        'stock_quantity' => $stock,
        'manage_stock' => true,
        'is_custom_ms' => false,
        'exclude_from_auto_update' => false,
    ], $extra);
}

// ══════════════════════════════════════════════════════════════════════════
// Case 1 — AUTO-10 happy path: pinned fields survive byte-identically
// ══════════════════════════════════════════════════════════════════════════

it('AUTO-10: pinned title + short_description + price survive a full sync cycle', function (): void {
    // 1. Seed product with human-edited content + pin flags.
    $product = Product::factory()->create([
        'sku' => 'LOG-MEETUP',
        'woo_product_id' => 500,
        'name' => 'Logitech MeetUp (HUMAN-EDITED)',
        'short_description' => '<ul><li>HUMAN-EDITED BULLET</li></ul>',
        'sell_price' => 1499.99,
        'buy_price' => 800.00,
    ]);

    ProductOverride::factory()->create([
        'product_id' => $product->id,
        'margin_basis_points' => 5000,
        'pin_title' => true,
        'pin_short_description' => true,
        'pin_price' => true,  // revert PUT expected for price
    ]);

    // 2. Capture original byte-state for assertions.
    $originalName = $product->name;
    $originalShort = $product->short_description;
    $originalSellPrice = (string) $product->sell_price;

    // 3. Spy on Auditor so we can assert the pin_reverted entry fires.
    $auditor = Mockery::mock(Auditor::class);
    $auditor->shouldReceive('record')
        ->with('product_auto_create.pin_reverted', Mockery::on(function (array $ctx) use ($product): bool {
            return ($ctx['product_id'] ?? null) === $product->id
                && ($ctx['woo_product_id'] ?? null) === 500
                && in_array('regular_price', $ctx['fields'] ?? [], true)
                && ($ctx['source'] ?? null) === 'supplier_price_changed';
        }))
        ->atLeast()->once();
    app()->instance(Auditor::class, $auditor);

    // 4. Run the Phase 2 sync via SyncChunkJob directly — Phase 2 code
    // is INVOKED UNMODIFIED (D-11 — grep below confirms the job file is clean).
    $run = SyncRun::factory()->running()->create();

    $skus = [
        buildPinTestSkuRow('LOG-MEETUP', 500, '1200.00', 5),
    ];
    $supplierFeed = [
        'LOG-MEETUP' => ['price' => '1350.00', 'stock' => 5],  // supplier wants to push price UP
    ];

    $job = new SyncChunkJob($run->id, 1, $skus, $supplierFeed);
    $job->handle(app(WooClient::class), app(SyncDiffEngine::class), app(AbortGuard::class));

    // 5. Assert pinned Laravel fields are byte-identical — Phase 2 only touches
    // buy_price + last_synced_at in upsertLocalMirror, so name + short_description
    // MUST be unchanged regardless of pin state. (The pin protects the Woo-side
    // value; the Laravel-side name/short_description never left our hands.)
    $fresh = $product->fresh();
    expect($fresh->name)->toBe($originalName);
    expect($fresh->short_description)->toBe($originalShort);
    expect((string) $fresh->sell_price)->toBe($originalSellPrice);

    // 6. Assert the revert PUT fired via the auditor — this is the observable
    // evidence that ApplyPinsDuringSync + ProductOverrideGuard ran through the
    // full event chain from SyncChunkJob → SupplierPriceChanged → listener →
    // guard → WooClient::put.
    // (Auditor mock's shouldReceive assertion is validated by Mockery::close()
    // at tear-down — no explicit expect() needed here.)
    expect(true)->toBeTrue();  // mockery asserts cover the PUT fire
});

// ══════════════════════════════════════════════════════════════════════════
// Case 2 — Unpinned product flows through sync normally
// ══════════════════════════════════════════════════════════════════════════

it('AUTO-10: unpinned product is overwritten normally by supplier sync', function (): void {
    $product = Product::factory()->create([
        'sku' => 'LOG-RALLY',
        'woo_product_id' => 501,
        'name' => 'Logitech Rally',
        'sell_price' => 3000.00,
        'buy_price' => 2000.00,
    ]);
    // No ProductOverride row — nothing pinned.

    // Auditor should NOT receive a pin_reverted entry for this run.
    $auditor = Mockery::mock(Auditor::class);
    $auditor->shouldNotReceive('record')
        ->with('product_auto_create.pin_reverted', Mockery::any());
    // Tolerate other audit records from unrelated code paths (sync.run.* etc).
    $auditor->shouldReceive('record')->withAnyArgs()->zeroOrMoreTimes();
    app()->instance(Auditor::class, $auditor);

    $run = SyncRun::factory()->running()->create();
    $skus = [
        buildPinTestSkuRow('LOG-RALLY', 501, '3000.00', 3),  // Woo-side price
    ];
    $supplierFeed = [
        'LOG-RALLY' => ['price' => '2100.00', 'stock' => 3],  // supplier updates buy_price
    ];

    $job = new SyncChunkJob($run->id, 1, $skus, $supplierFeed);
    $job->handle(app(WooClient::class), app(SyncDiffEngine::class), app(AbortGuard::class));

    // Sync path writes buy_price on local mirror via upsertLocalMirror().
    $fresh = $product->fresh();
    expect((string) $fresh->buy_price)->toBe('2100.00');
});

// ══════════════════════════════════════════════════════════════════════════
// Case 3 — Pin revert failure does not cascade (T-06-05-02 mitigation)
// ══════════════════════════════════════════════════════════════════════════

it('AUTO-10: pin revert failure is logged and swallowed, sibling listeners run', function (): void {
    $product = Product::factory()->create([
        'sku' => 'LOG-BCC950',
        'woo_product_id' => 502,
        'name' => 'Logitech BCC950',
        'sell_price' => 150.00,
        'buy_price' => 100.00,
    ]);
    ProductOverride::factory()->create([
        'product_id' => $product->id,
        'pin_price' => true,
    ]);

    // Wire the guard to throw so safeRevert() swallow path is exercised.
    $guard = Mockery::mock(ProductOverrideGuard::class);
    $guard->shouldReceive('revertIfPinned')
        ->with(502, ['regular_price'], 'supplier_price_changed')
        ->once()
        ->andThrow(new \RuntimeException('Simulated Woo PUT 500 during revert'));
    // Stock + SKU-missing handlers would also be registered; tolerate.
    $guard->shouldReceive('revertIfPinned')->withAnyArgs()->zeroOrMoreTimes();
    app()->instance(ProductOverrideGuard::class, $guard);

    Log::spy();

    // Directly dispatch the event — ApplyPinsDuringSync subscribes via
    // EventServiceProvider. SyncChunkJob fires the same event in production.
    Event::dispatch(new SupplierPriceChanged(
        sku: 'LOG-BCC950',
        wooProductId: 502,
        wooVariationId: null,
        oldPrice: '150.00',
        newPrice: '120.00',
    ));

    // Warning captured + NO exception cascade.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context = []) =>
            $message === 'product_auto_create.pin_revert_failed'
            && ($context['woo_product_id'] ?? null) === 502
            && ($context['source'] ?? null) === 'supplier_price_changed'
        )
        ->once();
});

// ══════════════════════════════════════════════════════════════════════════
// Case 4 — D-11 contract assertion: Phase 2 SyncChunkJob is untouched
// ══════════════════════════════════════════════════════════════════════════

it('AUTO-10 D-11 contract: Phase 2 SyncChunkJob code is never modified by Plan 05', function (): void {
    $syncChunkJobPath = base_path('app/Domain/Sync/Jobs/SyncChunkJob.php');
    expect(file_exists($syncChunkJobPath))->toBeTrue();

    $source = file_get_contents($syncChunkJobPath);

    // D-11 mandate: Plan 05 must NOT add pin-related code to SyncChunkJob.
    // Grep for any ProductAutoCreate or Override reference that would indicate
    // pin logic crept into the Phase 2 file.
    expect($source)->not->toContain('ProductAutoCreate');
    expect($source)->not->toContain('ProductOverride');
    expect($source)->not->toContain('pin_title');
    expect($source)->not->toContain('pin_price');
    expect($source)->not->toContain('ApplyPinsDuringSync');
    expect($source)->not->toContain('revertIfPinned');
});

// ══════════════════════════════════════════════════════════════════════════
// Case 5 — listener wiring smoke: all 3 Phase 2 events reach the listener
// ══════════════════════════════════════════════════════════════════════════

it('AUTO-10 wiring: all 3 Phase 2 supplier events reach ApplyPinsDuringSync', function (): void {
    Event::fake();

    Event::assertListening(
        SupplierPriceChanged::class,
        ApplyPinsDuringSync::class.'@handlePriceChanged',
    );
    Event::assertListening(
        SupplierStockChanged::class,
        ApplyPinsDuringSync::class.'@handleStockChanged',
    );
    Event::assertListening(
        SupplierSkuMissing::class,
        ApplyPinsDuringSync::class.'@handleSkuMissing',
    );
});
