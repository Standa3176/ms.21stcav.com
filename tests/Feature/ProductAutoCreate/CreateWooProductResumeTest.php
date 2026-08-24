<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\ProductAutoCreate\Events\AutoCreateFailed;
use App\Domain\ProductAutoCreate\Events\AutoCreateSucceeded;
use App\Domain\ProductAutoCreate\Jobs\CreateWooProductJob;
use App\Domain\ProductAutoCreate\Services\CompletenessScorer;
use App\Domain\ProductAutoCreate\Services\ProductContentBuilder;
use App\Domain\ProductAutoCreate\Services\ProductMatcher;
use App\Domain\ProductAutoCreate\Services\ProductSlugGenerator;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductSupplierSku;
use App\Domain\Sync\Exceptions\WooWriteThrottleException;
use App\Domain\Sync\Services\SupplierClient;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| 260824-vkc — a throttled Woo create RESUMES; it does not strand the product
|--------------------------------------------------------------------------
|
| Reported on prod 2026-08-23: "under Suggestions I have sent products to be
| created directly in the Woo store but they do not [appear]".
|
| 260822-rmo fixed the throttle for the review/approve path (PublishProductJob)
| and added a PREFLIGHT deferral to this job — but only on entry. handle() then
| creates the local Product row BEFORE the Woo POST, and that POST stayed
| unguarded. So when the write window closed in the gap between the two:
|
|   1. local Product row created
|   2. POST throws WooWriteThrottleException
|   3. retry hits the AUTO-08 duplicate gate, which matches the job's OWN
|      half-created row, records reason=duplicate, and returns
|   4. product stays local-only forever, woo_product_id = null
|
| The same step 3 broke Replay: AutoCreateRetryApplier re-dispatches THIS job,
| so every failure occurring after the create replayed straight into 'duplicate'.
|
| The contract under test:
|   - throttled POST defers, local row survives, retry finishes the job
|   - exactly ONE product row results — a resume must never fork a second
|   - a genuine duplicate is still refused (AUTO-08 intact)
|   - an alias pointing elsewhere still refuses (260823-clp intact)
|   - a resume never reverts operator edits made in the review inbox
*/

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());

    // RuleResolver/PriceCalculator are byte-identity locked (D-03), so they are
    // driven with a real open-ended rule rather than mocked.
    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 0,
        'tier_max_pennies' => null,
        'margin_basis_points' => 4000,
    ]);
});

/**
 * Minimal queue-job double so release() can be asserted without a queue driver.
 * Named distinctly from WooWriteThrottleReleaseTest's equivalent — Pest loads
 * every test file into one process, and helper functions are global.
 */
function resumeFakeJob(): object
{
    return new class implements JobContract
    {
        public ?int $releasedWith = null;

        public bool $wasDeleted = false;

        public bool $wasFailed = false;

        public function release($delay = 0)
        {
            $this->releasedWith = (int) $delay;
        }

        public function attempts()
        {
            return 1;
        }

        public function delete()
        {
            $this->wasDeleted = true;
        }

        public function fail($e = null)
        {
            $this->wasFailed = true;
        }

        public function uuid()
        {
            return 'resume-uuid';
        }

        public function getJobId()
        {
            return 'resume-job-id';
        }

        public function payload()
        {
            return [];
        }

        public function maxTries()
        {
            return 3;
        }

        public function maxExceptions()
        {
            return 3;
        }

        public function backoff()
        {
            return null;
        }

        public function retryUntil()
        {
            return null;
        }

        public function timeout()
        {
            return 60;
        }

        public function getConnectionName()
        {
            return 'sync';
        }

        public function getQueue()
        {
            return 'woo-writes';
        }

        public function isDeleted()
        {
            return $this->wasDeleted;
        }

        public function isReleased()
        {
            return $this->releasedWith !== null;
        }

        public function isDeletedOrReleased()
        {
            return $this->wasDeleted || $this->isReleased();
        }

        public function hasFailed()
        {
            return $this->wasFailed;
        }

        public function markAsFailed()
        {
            $this->wasFailed = true;
        }

        public function getName()
        {
            return CreateWooProductJob::class;
        }

        public function resolveName()
        {
            return CreateWooProductJob::class;
        }

        public function getRawBody()
        {
            return '';
        }

        public function fire()
        {
            // Never invoked: handle() is called directly.
        }

        public function resolveQueuedJobClass()
        {
            return CreateWooProductJob::class;
        }
    };
}

/**
 * Supplier payload for a part the pipeline can fully resolve.
 *
 * @return array<string, mixed>
 */
function resumeSupplierRow(string $sku): array
{
    return [
        'sku' => $sku,
        'name' => 'Logitech Rally Bar',
        'brand' => 'Logitech',
        'category' => 'Video Conferencing',
        'price' => 1000,
    ];
}

/**
 * Drive handle() with a queue double attached, so a deferral is observable.
 */
function runResumeJob(string $sku, WooClient $woo, ?object $fakeJob = null): object
{
    $supplier = Mockery::mock(SupplierClient::class);
    $supplier->shouldReceive('fetchSingleProduct')->andReturn(resumeSupplierRow($sku));

    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('resolveBrand')->andReturn(42);
    $taxonomy->shouldReceive('resolveCategory')->andReturn(7);

    $fakeJob ??= resumeFakeJob();

    $job = new CreateWooProductJob($sku);
    $job->setJob($fakeJob);
    $job->handle(
        $woo,
        $supplier,
        app(ProductContentBuilder::class),
        app(ProductSlugGenerator::class),
        app(ProductMatcher::class),
        $taxonomy,
        app(RuleResolver::class),
        app(PriceCalculator::class),
        app(CompletenessScorer::class),
    );

    return $fakeJob;
}

/** A WooClient whose slug probe is clear and whose POST is throttled. */
function throttledWoo(): WooClient
{
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->andReturn([]);
    $woo->shouldReceive('post')->andThrow(new WooWriteThrottleException('rate ceiling hit', 45));

    return $woo;
}

/** A WooClient that accepts the create. */
function acceptingWoo(int $wooId = 900): WooClient
{
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->andReturn([]);
    $woo->shouldReceive('post')->once()->andReturn([
        'id' => $wooId,
        'slug' => 'logitech-rally-bar-video-conferencing',
    ]);

    return $woo;
}

// ── The reported bug, end to end ──────────────────────────────────────────

it('resumes after a throttled POST instead of failing its own row as a duplicate', function (): void {
    Event::fake();
    Queue::fake();

    // ── Run 1: the write window shuts at the POST ──────────────────────────
    $fakeJob = runResumeJob('RALLY-01', throttledWoo());

    $stranded = Product::where('sku', 'RALLY-01')->first();
    expect($stranded)->not->toBeNull()
        ->and($stranded->woo_product_id)->toBeNull();

    // Deferred, not failed — this is the 260822-rmo contract.
    expect($fakeJob->releasedWith)->toBe(45)
        ->and($fakeJob->wasFailed)->toBeFalse();
    Event::assertNotDispatched(AutoCreateFailed::class);

    // ── Run 2: the retry, with the window open ─────────────────────────────
    runResumeJob('RALLY-01', acceptingWoo(900));

    // BEFORE this fix run 2 recorded reason=duplicate and returned, leaving
    // woo_product_id null forever. That is the whole defect.
    Event::assertNotDispatched(AutoCreateFailed::class);
    Event::assertDispatched(AutoCreateSucceeded::class);

    expect(Product::where('sku', 'RALLY-01')->first()->woo_product_id)->toBe(900);
});

it('resumes into the SAME row — a retry must never fork a second product', function (): void {
    Event::fake();
    Queue::fake();

    runResumeJob('RALLY-02', throttledWoo());
    runResumeJob('RALLY-02', acceptingWoo(901));

    // A duplicate local row would surface on the storefront as a second listing
    // of one physical part — the exact class of fault 260823-clp exists to stop.
    expect(Product::where('sku', 'RALLY-02')->count())->toBe(1);
});

it('survives repeated throttling and still completes when the window reopens', function (): void {
    Event::fake();
    Queue::fake();

    // Three deferrals: the job is released each time, never failed, and the
    // local row is reused rather than re-created.
    foreach (range(1, 3) as $ignored) {
        $fakeJob = runResumeJob('RALLY-03', throttledWoo());
        expect($fakeJob->releasedWith)->toBe(45)
            ->and($fakeJob->wasFailed)->toBeFalse();
    }

    expect(Product::where('sku', 'RALLY-03')->count())->toBe(1);

    runResumeJob('RALLY-03', acceptingWoo(902));

    expect(Product::where('sku', 'RALLY-03')->first()->woo_product_id)->toBe(902);
    Event::assertNotDispatched(AutoCreateFailed::class);
});

// ── AUTO-08 must survive the change ───────────────────────────────────────

it('still refuses a part that is genuinely already on Woo', function (): void {
    Event::fake();
    Queue::fake();

    // woo_product_id set — this is a live listing, not an interrupted run.
    Product::factory()->create(['sku' => 'DUPE-01', 'woo_product_id' => 12345]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('post');

    runResumeJob('DUPE-01', $woo);

    Event::assertDispatched(AutoCreateFailed::class,
        fn (AutoCreateFailed $e) => $e->reason === 'duplicate');
    Event::assertNotDispatched(AutoCreateSucceeded::class);
});

it('still refuses a legacy manual product, however incomplete it looks', function (): void {
    Event::fake();
    Queue::fake();

    // No woo_product_id, so it LOOKS like an orphan — but auto_create_status
    // 'manual' is the migration's marker for a pre-auto-create row (260606-mx9).
    // Adopting one would let the pipeline overwrite hand-maintained catalogue.
    Product::factory()->create([
        'sku' => 'LEGACY-01',
        'woo_product_id' => null,
        'auto_create_status' => 'manual',
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('post');

    runResumeJob('LEGACY-01', $woo);

    Event::assertDispatched(AutoCreateFailed::class,
        fn (AutoCreateFailed $e) => $e->reason === 'duplicate');
});

it('refuses to resume when an alternative SKU maps the part to another product', function (): void {
    Event::fake();
    Queue::fake();

    // 260823-clp: supplier B lists one physical part under its own code. If an
    // alias says this SKU belongs to a DIFFERENT product, the orphan is itself
    // the mistake and resuming would put a second listing on the storefront.
    $realProduct = Product::factory()->create(['sku' => 'RALLY-BAR', 'woo_product_id' => 555]);

    Product::factory()->create([
        'sku' => 'ALT-9911',
        'woo_product_id' => null,
        'auto_create_status' => 'draft',
    ]);

    ProductSupplierSku::create([
        'product_id' => $realProduct->id,
        'supplier_sku' => 'ALT-9911',
        'normalised_sku' => ProductSupplierSku::normalise('ALT-9911'),
        'source' => ProductSupplierSku::SOURCE_DERIVED_MPN,
        'confidence' => 90,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('post');

    runResumeJob('ALT-9911', $woo);

    Event::assertDispatched(AutoCreateFailed::class,
        fn (AutoCreateFailed $e) => $e->reason === 'duplicate');
});

// ── A resume must not undo human work ─────────────────────────────────────

it('preserves copy an operator edited in the review inbox', function (): void {
    Event::fake();
    Queue::fake();

    runResumeJob('RALLY-04', throttledWoo());

    // The operator rewrites the title while the row sits in the inbox.
    $product = Product::where('sku', 'RALLY-04')->first();
    $product->forceFill([
        'name' => 'Logitech Rally Bar — Certified for Microsoft Teams',
        'short_description' => 'Hand-written by ops.',
    ])->save();

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->andReturn([]);
    $woo->shouldReceive('post')->once()->with('/products', Mockery::on(
        // Content is recompiled from supplier data every run, so a naive
        // re-fill would both revert the row AND push the machine copy to Woo.
        fn ($payload) => $payload['name'] === 'Logitech Rally Bar — Certified for Microsoft Teams'
            && $payload['short_description'] === 'Hand-written by ops.'
    ))->andReturn(['id' => 903, 'slug' => 'logitech-rally-bar-video-conferencing']);

    runResumeJob('RALLY-04', $woo);

    $fresh = Product::where('sku', 'RALLY-04')->first();
    expect($fresh->name)->toBe('Logitech Rally Bar — Certified for Microsoft Teams')
        ->and($fresh->short_description)->toBe('Hand-written by ops.')
        ->and($fresh->woo_product_id)->toBe(903);
});

it('keeps a brand/category the operator assigned by hand and un-parks the row', function (): void {
    Event::fake();
    Queue::fake();

    // Parked because taxonomy could not be resolved automatically.
    $supplier = Mockery::mock(SupplierClient::class);
    $supplier->shouldReceive('fetchSingleProduct')->andReturn(resumeSupplierRow('PARKED-01'));

    $unresolved = Mockery::mock(TaxonomyResolver::class);
    $unresolved->shouldReceive('resolveBrand')->andReturn(null);
    $unresolved->shouldReceive('resolveCategory')->andReturn(null);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->andReturn([]);
    $woo->shouldNotReceive('post');

    $job = new CreateWooProductJob('PARKED-01');
    $job->setJob(resumeFakeJob());
    $job->handle(
        $woo, $supplier, app(ProductContentBuilder::class), app(ProductSlugGenerator::class),
        app(ProductMatcher::class), $unresolved, app(RuleResolver::class),
        app(PriceCalculator::class), app(CompletenessScorer::class),
    );

    $parked = Product::where('sku', 'PARKED-01')->first();
    expect($parked->auto_create_status)->toBe('needs_brand_or_category_assignment');

    // Operator assigns taxonomy in the inbox, then hits Replay — which
    // re-dispatches THIS job. Automatic resolution still returns null, so
    // without the carry-over the assignment would be wiped and the row re-parked.
    $parked->forceFill(['brand_id' => 42, 'category_id' => 7])->save();

    $accepting = Mockery::mock(WooClient::class);
    $accepting->shouldReceive('get')->andReturn([]);
    $accepting->shouldReceive('post')->once()->andReturn([
        'id' => 904,
        'slug' => 'logitech-rally-bar-video-conferencing',
    ]);

    $replay = new CreateWooProductJob('PARKED-01');
    $replay->setJob(resumeFakeJob());
    $replay->handle(
        $accepting, $supplier, app(ProductContentBuilder::class), app(ProductSlugGenerator::class),
        app(ProductMatcher::class), $unresolved, app(RuleResolver::class),
        app(PriceCalculator::class), app(CompletenessScorer::class),
    );

    $fresh = Product::where('sku', 'PARKED-01')->first();
    expect($fresh->brand_id)->toBe(42)
        ->and($fresh->category_id)->toBe(7)
        ->and($fresh->auto_create_status)->toBe('draft')
        ->and($fresh->woo_product_id)->toBe(904);
});
