<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Appliers\NewProductOpportunityApplier;
use App\Domain\ProductAutoCreate\Jobs\CreateWooProductJob;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Suggestions\Services\SuggestionApplierResolver;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 03 Task 3 — NewProductOpportunityApplier (MOVED + real body)
|--------------------------------------------------------------------------
| RESEARCH Q4 resolution: applier moved from app/Domain/Competitor/Appliers/
| to app/Domain/ProductAutoCreate/Appliers/ with a REAL body that dispatches
| CreateWooProductJob. Old file + old test under tests/Feature/Competitor/
| removed by this plan.
|
| Covers:
|   - supports() returns ['new_product_opportunity'].
|   - apply() dispatches CreateWooProductJob($sku, $suggestionId) and returns
|     {phase_6_live: true, sku, dispatched_job_class}.
|   - missing evidence.sku returns {error: missing_sku_in_evidence}.
|   - SuggestionApplierResolver resolves kind='new_product_opportunity' to
|     the NEW FQCN (ProductAutoCreate, not Competitor).
|   - OLD applier file + OLD test file DO NOT exist post-move (F4/F5 guards).
*/

it('supports() returns new_product_opportunity', function (): void {
    expect((new NewProductOpportunityApplier())->supports())
        ->toBe(['new_product_opportunity']);
});

it('apply() dispatches CreateWooProductJob with sku + suggestion id', function (): void {
    Queue::fake();

    $suggestion = Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'corr-applier-1',
        'evidence' => ['sku' => 'NEW-SKU-01'],
        'payload' => ['sku' => 'NEW-SKU-01'],
        'proposed_at' => now(),
    ]);

    $result = (new NewProductOpportunityApplier())->apply($suggestion);

    expect($result)->toMatchArray([
        'phase_6_live' => true,
        'sku' => 'NEW-SKU-01',
        'dispatched_job_class' => CreateWooProductJob::class,
    ]);

    Queue::assertPushed(CreateWooProductJob::class, function (CreateWooProductJob $job) use ($suggestion): bool {
        return $job->sku === 'NEW-SKU-01' && $job->suggestionId === (string) $suggestion->id;
    });
});

it('apply() returns error when evidence.sku missing', function (): void {
    Queue::fake();

    $suggestion = Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'corr-applier-2',
        'evidence' => [],
        'payload' => [],
        'proposed_at' => now(),
    ]);

    $result = (new NewProductOpportunityApplier())->apply($suggestion);

    expect($result['error'] ?? null)->toBe('missing_sku_in_evidence');
    Queue::assertNotPushed(CreateWooProductJob::class);
});

it('SuggestionApplierResolver resolves kind to the ProductAutoCreate FQCN (not Competitor)', function (): void {
    $resolver = app(SuggestionApplierResolver::class);

    $suggestion = Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'corr-applier-3',
        'evidence' => ['sku' => 'ANY'],
        'payload' => ['sku' => 'ANY'],
        'proposed_at' => now(),
    ]);

    $applier = $resolver->resolve($suggestion);

    expect($applier)->toBeInstanceOf(NewProductOpportunityApplier::class);
    expect($applier::class)->toStartWith('App\\Domain\\ProductAutoCreate\\');
});

it('OLD Competitor applier file is removed', function (): void {
    expect(file_exists(base_path('app/Domain/Competitor/Appliers/NewProductOpportunityApplier.php')))
        ->toBeFalse();
});

it('OLD Competitor applier test file is removed', function (): void {
    expect(file_exists(base_path('tests/Feature/Competitor/NewProductOpportunityApplierTest.php')))
        ->toBeFalse();
});
