<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 10 Plan 04 Task 1 — PricingAgentResultMapper unit tests
|--------------------------------------------------------------------------
|
| Locks the load-bearing extraction + persistence behaviour:
|   - LAST propose_margin_band call wins (CONTEXT D-06 + RESEARCH P10-C —
|     the agent may iterate and abandon early proposals; only the final one
|     is the agent's actual answer).
|   - 10-cap on evidence.agent_run_ids[] (RESEARCH P10-E — bounded JSON growth
|     across many re-runs).
|   - 3 terminal-state branches: completed / no_proposal / malformed_proposal
|     (CONTEXT D-11) — no_proposal + malformed_proposal preserve prior
|     enrichment fields so admins still see last successful agent reasoning.
|   - Sets Suggestion.proposed_by_type=AgentRun::class + proposed_by_id=$run->id
|     (Phase 1 D-14 morph activation).
|
| These four test cases are the contract this mapper guarantees to Plan 10-04
| Task 2's RunPricingAgentJob and Plan 10-04 Task 3's Filament detail view.
*/

use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Services\PricingAgentResultMapper;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makePricingMapperSuggestion(array $evidenceOverrides = []): Suggestion
{
    return Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => (string) Str::uuid(),
        'payload' => [],
        'evidence' => array_merge(['sku' => 'TEST-MAPPER-SKU'], $evidenceOverrides),
        'proposed_at' => now(),
    ]);
}

it('extracts the LAST propose_margin_band call (D-06 + RESEARCH P10-C)', function () {
    $run = AgentRun::factory()->create([
        'kind' => 'pricing',
        'status' => 'completed',
        'completed_at' => now(),
        'tool_calls' => [
            [
                'tool_name' => 'read_competitor_prices',
                'inputs' => '{"sku":"X"}',
                'outputs' => '{}',
            ],
            [
                'tool_name' => 'propose_margin_band',
                'inputs' => json_encode([
                    'sku' => 'X',
                    'proposed_bps' => 2000,
                    'reasoning' => str_repeat('a', 60),
                    'confidence_0_to_100' => 50,
                    'band_min_bps' => 1900,
                    'band_max_bps' => 2100,
                ]),
                'outputs' => '{"acknowledged":true}',
            ],
            [
                'tool_name' => 'propose_margin_band',
                'inputs' => json_encode([
                    'sku' => 'X',
                    'proposed_bps' => 2050,
                    'reasoning' => str_repeat('b', 60),
                    'confidence_0_to_100' => 75,
                    'band_min_bps' => 1980,
                    'band_max_bps' => 2120,
                ]),
                'outputs' => '{"acknowledged":true}',
            ],
        ],
    ]);
    $suggestion = makePricingMapperSuggestion(['proposed_margin_bps' => 2200]);

    app(PricingAgentResultMapper::class)->mergeIntoSuggestion($run, $suggestion);

    $suggestion->refresh();
    // LAST call wins — second propose_margin_band's args overwrite the first.
    expect((int) $suggestion->evidence['agent_proposed_bps'])->toBe(2050);
    expect((int) $suggestion->evidence['agent_confidence_0_to_100'])->toBe(75);
    expect((int) $suggestion->evidence['agent_proposed_band_min_bps'])->toBe(1980);
    expect((int) $suggestion->evidence['agent_proposed_band_max_bps'])->toBe(2120);
    expect($suggestion->evidence['agent_run_status'])->toBe('completed');
    expect($suggestion->evidence['agent_run_ids'])->toContain($run->id);
    expect($suggestion->proposed_by_id)->toBe($run->id);
    expect($suggestion->proposed_by_type)->toBe(AgentRun::class);
    // Phase 5 v1 evidence keys preserved untouched
    expect($suggestion->evidence['sku'])->toBe('TEST-MAPPER-SKU');
    expect((int) $suggestion->evidence['proposed_margin_bps'])->toBe(2200);
});

it('writes no_proposal status when no propose_margin_band in tool_calls and PRESERVES prior enrichment', function () {
    $run = AgentRun::factory()->create([
        'kind' => 'pricing',
        'status' => 'completed',
        'completed_at' => now(),
        'tool_calls' => [
            ['tool_name' => 'read_competitor_prices', 'inputs' => '{}', 'outputs' => '{}'],
            ['tool_name' => 'read_margin_history', 'inputs' => '{}', 'outputs' => '{}'],
        ],
    ]);
    $suggestion = makePricingMapperSuggestion([
        'agent_reasoning' => 'previous run reasoning preserved',
        'agent_confidence_0_to_100' => 65,
        'agent_proposed_band_min_bps' => 1900,
        'agent_proposed_band_max_bps' => 2100,
        'agent_proposed_bps' => 2000,
    ]);

    app(PricingAgentResultMapper::class)->mergeIntoSuggestion($run, $suggestion);
    $suggestion->refresh();

    expect($suggestion->evidence['agent_run_status'])->toBe('no_proposal');
    expect($suggestion->evidence['agent_run_ids'])->toContain($run->id);
    // Prior enrichment PRESERVED so admin still sees the last successful proposal.
    expect((int) $suggestion->evidence['agent_confidence_0_to_100'])->toBe(65);
    expect($suggestion->evidence['agent_reasoning'])->toBe('previous run reasoning preserved');
    expect((int) $suggestion->evidence['agent_proposed_band_min_bps'])->toBe(1900);
    expect((int) $suggestion->evidence['agent_proposed_band_max_bps'])->toBe(2100);
    expect((int) $suggestion->evidence['agent_proposed_bps'])->toBe(2000);
});

it('writes malformed_proposal status when band_min_bps > band_max_bps', function () {
    $run = AgentRun::factory()->create([
        'kind' => 'pricing',
        'status' => 'completed',
        'completed_at' => now(),
        'tool_calls' => [
            [
                'tool_name' => 'propose_margin_band',
                'inputs' => json_encode([
                    'sku' => 'X',
                    'proposed_bps' => 2000,
                    'reasoning' => str_repeat('a', 60),
                    'confidence_0_to_100' => 50,
                    'band_min_bps' => 2200,  // INVERTED — band_min > band_max
                    'band_max_bps' => 2000,
                ]),
                'outputs' => '{"acknowledged":true}',
            ],
        ],
    ]);
    $suggestion = makePricingMapperSuggestion();

    app(PricingAgentResultMapper::class)->mergeIntoSuggestion($run, $suggestion);
    $suggestion->refresh();

    expect($suggestion->evidence['agent_run_status'])->toBe('malformed_proposal');
    expect($suggestion->evidence['agent_run_ids'])->toContain($run->id);
    // No agent_proposed_bps written when malformed — admin sees only the prior enrichment (none here)
    expect($suggestion->evidence)->not->toHaveKey('agent_proposed_bps');
});

it('writes malformed_proposal when reasoning is shorter than 40 chars', function () {
    $run = AgentRun::factory()->create([
        'kind' => 'pricing',
        'status' => 'completed',
        'completed_at' => now(),
        'tool_calls' => [
            [
                'tool_name' => 'propose_margin_band',
                'inputs' => json_encode([
                    'sku' => 'X',
                    'proposed_bps' => 2000,
                    'reasoning' => 'too short',  // < 40 chars
                    'confidence_0_to_100' => 50,
                    'band_min_bps' => 1900,
                    'band_max_bps' => 2100,
                ]),
                'outputs' => '{"acknowledged":true}',
            ],
        ],
    ]);
    $suggestion = makePricingMapperSuggestion();

    app(PricingAgentResultMapper::class)->mergeIntoSuggestion($run, $suggestion);
    $suggestion->refresh();

    expect($suggestion->evidence['agent_run_status'])->toBe('malformed_proposal');
});

it('caps agent_run_ids[] at 10 latest entries (RESEARCH P10-E)', function () {
    // Seed evidence with 10 prior run IDs already at the cap.
    $priorIds = collect(range(1, 10))->map(fn (int $i) => sprintf('01HX-OLD-RUN-%02d', $i))->all();

    $suggestion = makePricingMapperSuggestion(['agent_run_ids' => $priorIds]);

    // 11th run.
    $run = AgentRun::factory()->create([
        'kind' => 'pricing',
        'status' => 'completed',
        'completed_at' => now(),
        'tool_calls' => [
            [
                'tool_name' => 'propose_margin_band',
                'inputs' => json_encode([
                    'sku' => 'X',
                    'proposed_bps' => 2000,
                    'reasoning' => str_repeat('a', 60),
                    'confidence_0_to_100' => 50,
                    'band_min_bps' => 1900,
                    'band_max_bps' => 2100,
                ]),
                'outputs' => '{"acknowledged":true}',
            ],
        ],
    ]);
    app(PricingAgentResultMapper::class)->mergeIntoSuggestion($run, $suggestion);

    $suggestion->refresh();
    $runIds = $suggestion->evidence['agent_run_ids'];
    expect(count($runIds))->toBe(10);
    // Latest run is the LAST entry in the trimmed array
    expect(end($runIds))->toBe($run->id);
    // Oldest entry (index 0 of the seed) was dropped
    expect($runIds)->not->toContain('01HX-OLD-RUN-01');
});
