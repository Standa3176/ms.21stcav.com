<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 04 Task 1 — SeoAgentResultMapper (variable-cardinality
| bundled-Suggestion writer + guardrail-blocked Suggestion writer)
|--------------------------------------------------------------------------
|
| Six fixtures pin the mapper's contract surface:
|
|   1. P12-A LAST-WINS dedup — two calls for field='title' → second call wins
|      (the mapper does UNCONDITIONAL $patchesByField[$field] = ..., not
|      isset guard). Critical defence per RESEARCH §P12-A.
|   2. 4 distinct fields → 4 patches in payload.patches[].
|   3. Invalid field name is silently ignored.
|   4. No-op patch (before === after) is silently ignored.
|   5. Zero propose_content_patch calls → mapper returns null AND
|      AgentRun.agent_reasoning_summary contains "[mapper: no_patches]".
|   6. createGuardrailBlockedSuggestion writes kind='agent_guardrail_blocked'
|      with payload.failed_pattern_key + payload.matched_excerpt populated.
*/

use App\Domain\Agents\Enums\AgentRunStatus;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Services\SeoAgentResultMapper;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Str;

function makeSeoAgentRun(array $toolCalls = []): AgentRun
{
    return AgentRun::create([
        'kind' => 'seo',
        'status' => AgentRunStatus::Completed->value,
        'triggering_suggestion_id' => null,
        'triggering_correlation_id' => (string) Str::uuid(),
        'system_prompt_hash' => str_repeat('a', 64),
        'tool_calls' => $toolCalls,
        'started_at' => now()->subSeconds(5),
        'completed_at' => now(),
        'cost_pence' => 5,
    ]);
}

function makeSeoProduct(array $overrides = []): Product
{
    return Product::factory()->create(array_merge([
        'sku' => 'LOGI-MEETUP-' . Str::random(4),
        'name' => 'Logitech MeetUp',
        'short_description' => 'Conference camera',
        'long_description' => 'A long description.',
        'meta_description' => 'Logitech MeetUp camera',
        'brand_id' => 5,
        'auto_create_status' => 'pending_review',
        'completeness_score' => 64,
    ], $overrides));
}

function seoToolCall(string $field, string $before, string $after, string $reasoning = 'short reasoning here'): array
{
    return [
        'tool_name' => 'propose_content_patch',
        'inputs' => json_encode([
            'sku' => 'LOGI-MEETUP',
            'field' => $field,
            'before' => $before,
            'after' => $after,
            'reasoning' => $reasoning,
        ], JSON_THROW_ON_ERROR),
        'outputs' => '{"acknowledged":true}',
        'tokens_used' => 0,
        'latency_ms' => 0,
    ];
}

it('P12-A LAST-WINS — second call for the same field overrides the first', function () {
    $run = makeSeoAgentRun([
        seoToolCall('title', 'Logitech MeetUp', 'FIRST PROPOSAL'),
        seoToolCall('title', 'Logitech MeetUp', 'SECOND PROPOSAL'),
    ]);
    $product = makeSeoProduct();

    $suggestion = app(SeoAgentResultMapper::class)->createBundledSuggestion($run, $product);

    expect($suggestion)->not->toBeNull();
    expect($suggestion->kind)->toBe('seo_content_patch');
    $patches = (array) data_get($suggestion->payload, 'patches', []);
    expect($patches)->toHaveCount(1);
    expect($patches[0]['field'])->toBe('title');
    expect($patches[0]['after'])->toBe('SECOND PROPOSAL');
});

it('bundles 4 distinct fields into Suggestion.payload.patches[]', function () {
    $run = makeSeoAgentRun([
        seoToolCall('title', 'Old', 'New title'),
        seoToolCall('short_description', 'Old short', 'New short'),
        seoToolCall('long_description', 'Old long', 'New long'),
        seoToolCall('meta_description', 'Old meta', 'New meta'),
    ]);
    $product = makeSeoProduct();

    $suggestion = app(SeoAgentResultMapper::class)->createBundledSuggestion($run, $product);

    expect($suggestion)->not->toBeNull();
    $patches = (array) data_get($suggestion->payload, 'patches', []);
    expect($patches)->toHaveCount(4);
    $fields = collect($patches)->pluck('field')->toArray();
    expect($fields)->toEqualCanonicalizing(['title', 'short_description', 'long_description', 'meta_description']);

    // Suggestion morph + correlation propagated
    expect($suggestion->proposed_by_type)->toBe(AgentRun::class);
    expect($suggestion->proposed_by_id)->toBe($run->id);
    expect($suggestion->correlation_id)->toBe($run->triggering_correlation_id);

    // Payload metadata
    expect((int) data_get($suggestion->payload, 'product_id'))->toBe($product->id);
    expect((string) data_get($suggestion->payload, 'agent_run_id'))->toBe($run->id);
    expect((string) data_get($suggestion->payload, 'sku'))->toBe($product->sku);

    // Evidence
    expect((int) data_get($suggestion->evidence, 'cost_pence'))->toBe(5);
    expect((string) data_get($suggestion->evidence, 'agent_kind'))->toBe('seo');
});

it('silently ignores invalid field names', function () {
    $run = makeSeoAgentRun([
        seoToolCall('invented', 'before', 'after'),
        seoToolCall('title', 'Old', 'New title'),
    ]);
    $product = makeSeoProduct();

    $suggestion = app(SeoAgentResultMapper::class)->createBundledSuggestion($run, $product);

    expect($suggestion)->not->toBeNull();
    $patches = (array) data_get($suggestion->payload, 'patches', []);
    expect($patches)->toHaveCount(1);
    expect($patches[0]['field'])->toBe('title');
});

it('silently ignores no-op patches where before === after', function () {
    $run = makeSeoAgentRun([
        seoToolCall('title', 'Same value', 'Same value'),
        seoToolCall('short_description', 'Old', 'Genuinely new short'),
    ]);
    $product = makeSeoProduct();

    $suggestion = app(SeoAgentResultMapper::class)->createBundledSuggestion($run, $product);

    expect($suggestion)->not->toBeNull();
    $patches = (array) data_get($suggestion->payload, 'patches', []);
    expect($patches)->toHaveCount(1);
    expect($patches[0]['field'])->toBe('short_description');
});

it('returns null AND marks no-patches state when zero propose_content_patch calls present', function () {
    $run = makeSeoAgentRun([
        // Only read_* calls; no propose_content_patch
        [
            'tool_name' => 'read_product_draft',
            'inputs' => '{"sku":"LOGI-MEETUP"}',
            'outputs' => '{}',
            'tokens_used' => 0,
            'latency_ms' => 0,
        ],
    ]);
    $product = makeSeoProduct();

    $suggestion = app(SeoAgentResultMapper::class)->createBundledSuggestion($run, $product);

    expect($suggestion)->toBeNull();
    expect(Suggestion::query()->where('kind', 'seo_content_patch')->count())->toBe(0);

    $run->refresh();
    expect((string) $run->agent_reasoning_summary)->toContain('[mapper: no_patches]');
});

it('createGuardrailBlockedSuggestion writes agent_guardrail_blocked with pattern key + excerpt', function () {
    $run = makeSeoAgentRun([]);
    $product = makeSeoProduct();

    $suggestion = app(SeoAgentResultMapper::class)->createGuardrailBlockedSuggestion(
        $run,
        $product,
        'marketing_superlatives',
        'revolutionary',
    );

    expect($suggestion->kind)->toBe('agent_guardrail_blocked');
    expect($suggestion->status)->toBe(Suggestion::STATUS_PENDING);
    expect((string) data_get($suggestion->payload, 'failed_pattern_key'))->toBe('marketing_superlatives');
    expect((string) data_get($suggestion->payload, 'matched_excerpt'))->toBe('revolutionary');
    expect((string) data_get($suggestion->payload, 'agent_kind'))->toBe('seo');
    expect((int) data_get($suggestion->payload, 'product_id'))->toBe($product->id);
    expect($suggestion->proposed_by_type)->toBe(AgentRun::class);
    expect($suggestion->proposed_by_id)->toBe($run->id);
});
