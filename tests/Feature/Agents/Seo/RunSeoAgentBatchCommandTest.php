<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 05 Task 1 — RunSeoAgentBatchCommand contract surface
|--------------------------------------------------------------------------
|
| Pins the artisan command's behaviour:
|
|   - signature `agents:run-seo-batch {--limit=20} {--dry-run}` registers
|   - eligibility: auto_create_status='pending_review' AND completeness_score<85
|     AND no existing pending/applied seo_content_patch Suggestion on this product
|   - dry-run mode lists eligible products without dispatching jobs
|   - live mode dispatches one RunSeoAgentJob per eligible product
|   - --limit caps the dispatch count
|   - batchCorrelationId shared across all dispatches in one run
|
| P12-E (between-dispatch budget recheck) is covered in
| tests/Feature/Agents/Seo/BatchCommandBudgetRaceTest.php.
*/

use App\Domain\Agents\Jobs\RunSeoAgentJob;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Cache::flush();
});

function makeBatchEligibleProduct(int $score, ?string $sku = null): Product
{
    return Product::factory()->create([
        'sku' => $sku ?? ('SEO-BATCH-' . $score . '-' . uniqid()),
        'auto_create_status' => 'pending_review',
        'completeness_score' => $score,
        'completeness_missing_fields' => ['long_description'],
    ]);
}

it('registers the agents:run-seo-batch signature', function () {
    Artisan::call('list');
    $output = Artisan::output();

    expect($output)->toContain('agents:run-seo-batch');
});

it('dry-run lists eligible products without dispatching jobs', function () {
    // 5 products: 4 eligible (scores 60/65/70/80) + 1 NOT eligible (score 95)
    makeBatchEligibleProduct(60);
    makeBatchEligibleProduct(70);
    makeBatchEligibleProduct(65);
    makeBatchEligibleProduct(80);
    Product::factory()->create([
        'sku' => 'SEO-BATCH-OUT-95',
        'auto_create_status' => 'pending_review',
        'completeness_score' => 95,  // NOT eligible — score >= 85
    ]);

    $exitCode = Artisan::call('agents:run-seo-batch', ['--dry-run' => true]);

    expect($exitCode)->toBe(0);
    Queue::assertNothingPushed();
});

it('eligible products are processed in worst-first completeness order', function () {
    // Scores: 60 / 70 / 65 / 80 — worst-first means 60 → 65 → 70 → 80
    makeBatchEligibleProduct(60, 'SEO-BATCH-60');
    makeBatchEligibleProduct(70, 'SEO-BATCH-70');
    makeBatchEligibleProduct(65, 'SEO-BATCH-65');
    makeBatchEligibleProduct(80, 'SEO-BATCH-80');

    Artisan::call('agents:run-seo-batch');

    // All 4 jobs should be dispatched in worst-first order
    Queue::assertPushed(RunSeoAgentJob::class, 4);

    $expectedSkuOrder = ['SEO-BATCH-60', 'SEO-BATCH-65', 'SEO-BATCH-70', 'SEO-BATCH-80'];
    $actualOrder = [];

    Queue::assertPushed(RunSeoAgentJob::class, function (RunSeoAgentJob $job) use (&$actualOrder) {
        $product = Product::find($job->productId);
        $actualOrder[] = $product->sku;

        return true;
    });

    expect($actualOrder)->toBe($expectedSkuOrder);
});

it('excludes products that already have a pending seo_content_patch Suggestion', function () {
    $productWithSuggestion = makeBatchEligibleProduct(60, 'SEO-BATCH-EXCLUDED');
    $clearProduct = makeBatchEligibleProduct(70, 'SEO-BATCH-CLEAR');

    // Seed an existing pending seo_content_patch Suggestion for product 1
    Suggestion::create([
        'kind' => 'seo_content_patch',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'pre-existing-cid',
        'payload' => ['product_id' => $productWithSuggestion->id, 'patches' => []],
        'evidence' => [],
        'proposed_at' => now(),
    ]);

    Artisan::call('agents:run-seo-batch');

    Queue::assertPushed(RunSeoAgentJob::class, 1);
    Queue::assertPushed(RunSeoAgentJob::class, fn (RunSeoAgentJob $job) => $job->productId === $clearProduct->id);
});

it('excludes products with an APPLIED seo_content_patch Suggestion', function () {
    $productWithApplied = makeBatchEligibleProduct(60, 'SEO-BATCH-APPLIED');
    $clearProduct = makeBatchEligibleProduct(70, 'SEO-BATCH-FRESH');

    Suggestion::create([
        'kind' => 'seo_content_patch',
        'status' => Suggestion::STATUS_APPLIED,
        'correlation_id' => 'applied-cid',
        'payload' => ['product_id' => $productWithApplied->id, 'patches' => []],
        'evidence' => [],
        'proposed_at' => now(),
    ]);

    Artisan::call('agents:run-seo-batch');

    Queue::assertPushed(RunSeoAgentJob::class, 1);
    Queue::assertPushed(RunSeoAgentJob::class, fn (RunSeoAgentJob $job) => $job->productId === $clearProduct->id);
});

it('does NOT exclude products with a REJECTED seo_content_patch Suggestion', function () {
    $productWithRejected = makeBatchEligibleProduct(60, 'SEO-BATCH-REJECTED');

    Suggestion::create([
        'kind' => 'seo_content_patch',
        'status' => Suggestion::STATUS_REJECTED,
        'correlation_id' => 'rejected-cid',
        'payload' => ['product_id' => $productWithRejected->id, 'patches' => []],
        'evidence' => [],
        'proposed_at' => now(),
    ]);

    Artisan::call('agents:run-seo-batch');

    // Rejected Suggestions do NOT block re-runs (admin rejected, can be re-attempted)
    Queue::assertPushed(RunSeoAgentJob::class, 1);
    Queue::assertPushed(RunSeoAgentJob::class, fn (RunSeoAgentJob $job) => $job->productId === $productWithRejected->id);
});

it('respects the --limit option', function () {
    makeBatchEligibleProduct(60);
    makeBatchEligibleProduct(65);
    makeBatchEligibleProduct(70);
    makeBatchEligibleProduct(75);
    makeBatchEligibleProduct(80);

    Artisan::call('agents:run-seo-batch', ['--limit' => 2]);

    Queue::assertPushed(RunSeoAgentJob::class, 2);
});

it('all dispatches in one batch run share the same batchCorrelationId', function () {
    makeBatchEligibleProduct(60);
    makeBatchEligibleProduct(65);
    makeBatchEligibleProduct(70);

    Artisan::call('agents:run-seo-batch');

    $seenIds = [];
    Queue::assertPushed(RunSeoAgentJob::class, function (RunSeoAgentJob $job) use (&$seenIds) {
        $seenIds[] = $job->batchCorrelationId;

        return true;
    });

    expect(count(array_unique($seenIds)))->toBe(1);  // all share one UUID
    expect($seenIds[0])->not->toBeNull();
});

it('exits with SUCCESS when there are no eligible products', function () {
    // No products seeded — eligibility query returns empty
    $exitCode = Artisan::call('agents:run-seo-batch');

    expect($exitCode)->toBe(0);
    Queue::assertNothingPushed();
});

it('does NOT match products with non-pending_review auto_create_status', function () {
    Product::factory()->create([
        'sku' => 'SEO-BATCH-PUBLISHED',
        'auto_create_status' => 'published',  // NOT pending_review
        'completeness_score' => 60,
    ]);
    Product::factory()->create([
        'sku' => 'SEO-BATCH-NEEDS-BRAND',
        'auto_create_status' => 'needs_brand_or_category_assignment',
        'completeness_score' => 60,
    ]);

    Artisan::call('agents:run-seo-batch');

    Queue::assertNothingPushed();
});
