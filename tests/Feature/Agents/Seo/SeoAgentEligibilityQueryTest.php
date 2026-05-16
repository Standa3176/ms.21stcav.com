<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 05 Task 1 — SeoAgent eligibility query in isolation
|--------------------------------------------------------------------------
|
| Pins the static query used by RunSeoAgentBatchCommand::eligibleProducts():
|
|   Product::query()
|       ->where('auto_create_status', 'pending_review')
|       ->where('completeness_score', '<', 85)
|       ->whereNotIn('id', <subselect: products with pending/applied
|                          seo_content_patch Suggestion>)
|       ->orderBy('completeness_score')   // worst-first
|       ->limit($n)
|       ->get()
|
| The query is exposed as a public static method on the command so this test
| can exercise it without invoking the full command run.
*/

use App\Domain\Agents\Console\Commands\RunSeoAgentBatchCommand;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;

it('selects only pending_review products with score < 85', function () {
    Product::factory()->create([
        'sku' => 'ELIG-IN-1',
        'auto_create_status' => 'pending_review',
        'completeness_score' => 60,
    ]);
    Product::factory()->create([
        'sku' => 'ELIG-OUT-PUBLISHED',
        'auto_create_status' => 'published',
        'completeness_score' => 50,
    ]);
    Product::factory()->create([
        'sku' => 'ELIG-OUT-SCORE-85',
        'auto_create_status' => 'pending_review',
        'completeness_score' => 85,  // boundary — exclusive
    ]);
    Product::factory()->create([
        'sku' => 'ELIG-OUT-SCORE-95',
        'auto_create_status' => 'pending_review',
        'completeness_score' => 95,
    ]);

    $eligible = RunSeoAgentBatchCommand::eligibleProductsQuery()->limit(20)->get();

    expect($eligible)->toHaveCount(1);
    expect($eligible->first()->sku)->toBe('ELIG-IN-1');
});

it('orders results worst-first (ascending completeness_score)', function () {
    foreach ([70, 60, 80, 65] as $i => $score) {
        Product::factory()->create([
            'sku' => 'ELIG-ORDER-' . $score,
            'auto_create_status' => 'pending_review',
            'completeness_score' => $score,
        ]);
    }

    $eligible = RunSeoAgentBatchCommand::eligibleProductsQuery()->limit(20)->get();

    $skus = $eligible->pluck('sku')->all();
    expect($skus)->toBe(['ELIG-ORDER-60', 'ELIG-ORDER-65', 'ELIG-ORDER-70', 'ELIG-ORDER-80']);
});

it('excludes products with a pending seo_content_patch Suggestion', function () {
    $blocked = Product::factory()->create([
        'sku' => 'ELIG-BLOCKED-PENDING',
        'auto_create_status' => 'pending_review',
        'completeness_score' => 60,
    ]);
    $clear = Product::factory()->create([
        'sku' => 'ELIG-CLEAR',
        'auto_create_status' => 'pending_review',
        'completeness_score' => 70,
    ]);

    Suggestion::create([
        'kind' => 'seo_content_patch',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'cid-pending',
        'payload' => ['product_id' => $blocked->id, 'patches' => []],
        'evidence' => [],
        'proposed_at' => now(),
    ]);

    $eligible = RunSeoAgentBatchCommand::eligibleProductsQuery()->limit(20)->get();

    expect($eligible->pluck('sku')->all())->toBe(['ELIG-CLEAR']);
});

it('excludes products with an applied seo_content_patch Suggestion', function () {
    $applied = Product::factory()->create([
        'sku' => 'ELIG-BLOCKED-APPLIED',
        'auto_create_status' => 'pending_review',
        'completeness_score' => 60,
    ]);
    $clear = Product::factory()->create([
        'sku' => 'ELIG-CLEAR-APPLIED',
        'auto_create_status' => 'pending_review',
        'completeness_score' => 70,
    ]);

    Suggestion::create([
        'kind' => 'seo_content_patch',
        'status' => Suggestion::STATUS_APPLIED,
        'correlation_id' => 'cid-applied',
        'payload' => ['product_id' => $applied->id, 'patches' => []],
        'evidence' => [],
        'proposed_at' => now(),
    ]);

    $eligible = RunSeoAgentBatchCommand::eligibleProductsQuery()->limit(20)->get();

    expect($eligible->pluck('sku')->all())->toBe(['ELIG-CLEAR-APPLIED']);
});

it('DOES include products with only a REJECTED seo_content_patch Suggestion', function () {
    $rejected = Product::factory()->create([
        'sku' => 'ELIG-REJECTED-INCLUDED',
        'auto_create_status' => 'pending_review',
        'completeness_score' => 60,
    ]);

    Suggestion::create([
        'kind' => 'seo_content_patch',
        'status' => Suggestion::STATUS_REJECTED,
        'correlation_id' => 'cid-rejected',
        'payload' => ['product_id' => $rejected->id, 'patches' => []],
        'evidence' => [],
        'proposed_at' => now(),
    ]);

    $eligible = RunSeoAgentBatchCommand::eligibleProductsQuery()->limit(20)->get();

    expect($eligible->pluck('sku')->all())->toBe(['ELIG-REJECTED-INCLUDED']);
});

it('ignores Suggestions of OTHER kinds when computing eligibility', function () {
    $product = Product::factory()->create([
        'sku' => 'ELIG-OTHER-KIND',
        'auto_create_status' => 'pending_review',
        'completeness_score' => 60,
    ]);

    // A pending margin_change Suggestion on the same product MUST NOT block
    // the SEO batch — different kind, different surface.
    Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'cid-other',
        'payload' => ['product_id' => $product->id, 'sku' => 'X'],
        'evidence' => [],
        'proposed_at' => now(),
    ]);

    $eligible = RunSeoAgentBatchCommand::eligibleProductsQuery()->limit(20)->get();

    expect($eligible->pluck('sku')->all())->toBe(['ELIG-OTHER-KIND']);
});
