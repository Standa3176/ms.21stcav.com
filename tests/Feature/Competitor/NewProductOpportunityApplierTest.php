<?php

declare(strict_types=1);

use App\Domain\Competitor\Appliers\NewProductOpportunityApplier;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Suggestions\Services\SuggestionApplierResolver;

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 02 Task 2 — NewProductOpportunityApplier (stub, D-08)
|--------------------------------------------------------------------------
|
| Phase 6 ships the real applier; Plan 05-02 registers the no-op so the
| Approve action in Filament is clickable and the kind is recognised.
*/

it('supports() returns the new_product_opportunity kind', function (): void {
    expect((new NewProductOpportunityApplier())->supports())->toBe(['new_product_opportunity']);
});

it('apply() returns a phase_5_stub marker and includes the SKU', function (): void {
    $suggestion = Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'test-corr',
        'evidence' => ['sku' => 'STUB-SKU-1', 'supporting_competitors' => 1],
        'payload' => ['sku' => 'STUB-SKU-1'],
    ]);

    $result = (new NewProductOpportunityApplier())->apply($suggestion);

    expect($result)->toBeArray();
    expect($result['phase_5_stub'] ?? null)->toBeTrue();
    expect($result['sku'] ?? null)->toBe('STUB-SKU-1');
});

it('is registered in AppServiceProvider::boot for kind new_product_opportunity', function (): void {
    $resolver = app(SuggestionApplierResolver::class);

    $suggestion = Suggestion::create([
        'kind' => 'new_product_opportunity',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'boot-test',
        'evidence' => ['sku' => 'ANY'],
        'payload' => ['sku' => 'ANY'],
    ]);

    $applier = $resolver->resolve($suggestion);

    expect($applier)->toBeInstanceOf(NewProductOpportunityApplier::class);
});
