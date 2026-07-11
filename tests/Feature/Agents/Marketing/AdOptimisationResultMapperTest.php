<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15b-01 Task 5 — AdOptimisationResultMapper (bundled writer)
|--------------------------------------------------------------------------
|
| Reads AgentRun.tool_calls where tool_name='propose_marketing_action' and
| creates ONE bundled Suggestion of kind 'ad_optimisation'. Advice-only:
| approving it fires no apply path (no applier registered). Idempotent by
| agent_run_id; returns null + marks state when nothing was proposed.
*/

use App\Domain\Agents\Enums\AgentRunStatus;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Services\AdOptimisationResultMapper;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Str;

function makeAdOptRun(array $toolCalls = [], ?string $reasoning = 'Reviewed 30d GA4 + margin data.'): AgentRun
{
    return AgentRun::create([
        'kind' => 'ad_optimisation',
        'status' => AgentRunStatus::Completed->value,
        'triggering_suggestion_id' => null,
        'triggering_correlation_id' => (string) Str::uuid(),
        'system_prompt_hash' => str_repeat('b', 64),
        'tool_calls' => $toolCalls,
        'agent_reasoning_summary' => $reasoning,
        'started_at' => now()->subSeconds(5),
        'completed_at' => now(),
        'cost_pence' => 7,
    ]);
}

function adOptCall(string $actionType, string $target, string $rationale, string $metrics = '{"sessions":900}', string $confidence = 'medium'): array
{
    return [
        'tool_name' => 'propose_marketing_action',
        'inputs' => json_encode([
            'action_type' => $actionType,
            'target' => $target,
            'rationale' => $rationale,
            'supporting_metrics' => $metrics,
            'confidence' => $confidence,
        ], JSON_THROW_ON_ERROR),
        'outputs' => '{"acknowledged":true}',
        'tokens_used' => 0,
        'latency_ms' => 0,
    ];
}

it('bundles 2 propose_marketing_action calls into ONE ad_optimisation Suggestion', function () {
    $run = makeAdOptRun([
        adOptCall('reduce_spend', 'Paid Search / Generic', 'Generic converted 0 of 900 sessions in 30 days.'),
        adOptCall('add_coverage', 'MARGIN-HIGH', 'High-margin SKU with strong demand and no paid coverage.', '{"margin_gbp":400}', 'high'),
    ]);

    $suggestion = app(AdOptimisationResultMapper::class)->createBundledSuggestion($run);

    expect($suggestion)->not->toBeNull();
    expect($suggestion->kind)->toBe('ad_optimisation');
    expect($suggestion->status)->toBe(Suggestion::STATUS_PENDING);

    $proposals = (array) data_get($suggestion->payload, 'proposals', []);
    expect($proposals)->toHaveCount(2);
    expect(collect($proposals)->pluck('action_type')->all())->toBe(['reduce_spend', 'add_coverage']);
    expect($proposals[1]['confidence'])->toBe('high');

    // Morph + correlation + agent_run linkage
    expect($suggestion->proposed_by_type)->toBe(AgentRun::class);
    expect($suggestion->proposed_by_id)->toBe($run->id);
    expect($suggestion->correlation_id)->toBe($run->triggering_correlation_id);
    expect((string) data_get($suggestion->payload, 'agent_run_id'))->toBe($run->id);
    expect((int) data_get($suggestion->evidence, 'cost_pence'))->toBe(7);
    expect((string) data_get($suggestion->evidence, 'agent_kind'))->toBe('ad_optimisation');
});

it('silently drops proposals with an invalid action_type or blank target/rationale', function () {
    $run = makeAdOptRun([
        adOptCall('teleport_budget', 'X', 'invalid action type dropped'),
        adOptCall('shift_budget', '', 'blank target dropped'),
        adOptCall('increase_investment', 'Brand UK', 'Valid, kept.'),
    ]);

    $suggestion = app(AdOptimisationResultMapper::class)->createBundledSuggestion($run);

    $proposals = (array) data_get($suggestion->payload, 'proposals', []);
    expect($proposals)->toHaveCount(1);
    expect($proposals[0]['action_type'])->toBe('increase_investment');
});

it('returns null AND marks no-proposals state when zero propose calls present', function () {
    $run = makeAdOptRun([
        ['tool_name' => 'read_ga4_channel_performance', 'inputs' => '{}', 'outputs' => '{}', 'tokens_used' => 0, 'latency_ms' => 0],
    ]);

    $suggestion = app(AdOptimisationResultMapper::class)->createBundledSuggestion($run);

    expect($suggestion)->toBeNull();
    expect(Suggestion::query()->where('kind', 'ad_optimisation')->count())->toBe(0);

    $run->refresh();
    expect((string) $run->agent_reasoning_summary)->toContain('[mapper: no_proposals]');
});

it('is idempotent — re-mapping the same run returns the existing Suggestion, no double-write', function () {
    $run = makeAdOptRun([
        adOptCall('pause_target', 'Display / Remarketing', 'No transactions in 30 days from display.'),
    ]);

    $mapper = app(AdOptimisationResultMapper::class);
    $first = $mapper->createBundledSuggestion($run);
    $second = $mapper->createBundledSuggestion($run->fresh());

    expect($first->id)->toBe($second->id);
    expect(Suggestion::query()->where('kind', 'ad_optimisation')->count())->toBe(1);
});
