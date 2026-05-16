<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 05 Task 3 — SuggestionResource hides agent_guardrail_blocked
|--------------------------------------------------------------------------
|
| Pins Open Question O-5 resolution:
|
|   - default Eloquent query EXCLUDES kind='agent_guardrail_blocked' rows
|     (audit-only Suggestions are forensic-only, not admin-actionable —
|     no SuggestionApplier is registered for that kind per Plan 12-04
|     Threat Flag T-12-04-06 "accept" disposition)
|   - default Eloquent query INCLUDES every other kind (seo_content_patch,
|     margin_change, etc.) — no regression on Phase 1/5/10 contracts
|   - explicit kind filter (admin chose 'agent_guardrail_blocked' in the
|     SelectFilter) RETURNS those rows — escape hatch for forensic review
|
| The default-query check uses Filament's getEloquentQuery() static method
| directly so the assertion is independent of HTTP-stack mocking.
*/

use App\Domain\Suggestions\Filament\Resources\SuggestionResource;
use App\Domain\Suggestions\Models\Suggestion;

beforeEach(function () {
    // Seed three Suggestions of distinct kinds; payload.product_id is the
    // canonical SEO-side link (Plan 12-04 mapper convention).
    Suggestion::create([
        'kind' => 'seo_content_patch',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'cid-seo',
        'payload' => ['product_id' => 1, 'patches' => []],
        'evidence' => [],
        'proposed_at' => now(),
    ]);
    Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'cid-margin',
        'payload' => ['sku' => 'X-001'],
        'evidence' => [],
        'proposed_at' => now(),
    ]);
    Suggestion::create([
        'kind' => 'agent_guardrail_blocked',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'cid-blocked',
        'payload' => ['product_id' => 1, 'failed_pattern_key' => 'marketing_superlatives'],
        'evidence' => ['agent_run_id' => 'r-01'],
        'proposed_at' => now(),
    ]);
});

it('default SuggestionResource query excludes agent_guardrail_blocked rows', function () {
    $rows = SuggestionResource::getEloquentQuery()->get();

    $kinds = $rows->pluck('kind')->all();

    expect($kinds)->toContain('seo_content_patch')
        ->and($kinds)->toContain('margin_change')
        ->and($kinds)->not->toContain('agent_guardrail_blocked');
});

it('default query result count is exactly 2 (excluding the 1 guardrail-blocked row)', function () {
    $count = SuggestionResource::getEloquentQuery()->count();

    expect($count)->toBe(2);  // seo_content_patch + margin_change
});

it('explicit kind filter exposes agent_guardrail_blocked rows (escape hatch)', function () {
    // Filament's SelectFilter writes the chosen value into the request as
    // `tableFilters[kind][value]`. Simulating that here lets us verify the
    // when() clause flips and the default exclusion lifts.
    request()->merge(['tableFilters' => ['kind' => ['value' => 'agent_guardrail_blocked']]]);

    try {
        $rows = SuggestionResource::getEloquentQuery()->get();
        $kinds = $rows->pluck('kind')->all();

        // The escape-hatch behaviour: when admin filters BY kind, the default
        // exclusion lifts so they can see blocked rows for forensic review.
        // The SelectFilter applies its own where('kind', ...) clause via Filament
        // table internals; here we only assert that the getEloquentQuery wrapper
        // itself does NOT exclude the row when tableFilters.kind.value is set.
        expect($kinds)->toContain('agent_guardrail_blocked');
    } finally {
        // Clean up request state — Pest reuses the request instance across tests.
        request()->replace([]);
    }
});

it('source file contains the agent_guardrail_blocked literal', function () {
    $source = (string) file_get_contents(base_path('app/Domain/Suggestions/Filament/Resources/SuggestionResource.php'));

    expect($source)->toContain('agent_guardrail_blocked');
});
