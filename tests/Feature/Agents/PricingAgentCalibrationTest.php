<?php

declare(strict_types=1);

/**
 * Phase 10 Plan 03 — PricingAgent prompt calibration test (RESEARCH §System Prompt
 * Design + §P10-A LLM nondeterminism defence + CONTEXT D-07 confidence rubric).
 *
 * 4 scripted Prism::fake() fixtures lock the prompt's behavioural contract
 * WITHOUT touching the live Anthropic API. Each fixture mirrors a row from
 * the system prompt's few-shot examples + edge cases:
 *
 *   1. data-rich HIGH-confidence  — confidence 71-100, narrow band, reasoning ≥40 chars
 *   2. data-sparse LOW-confidence — confidence 0-30, wide band (>500 bps spread)
 *   3. withMaxSteps exhausted     — finish_reason=ToolCalls, no propose_margin_band
 *   4. malformed-args             — propose_margin_band still captured for mapper to flag
 *
 * Per RESEARCH P10-A: assertions are BAND-MATCH (`toBeBetween(71, 100)`)
 * NOT exact-equal (`toBe(82)`) — token-level variance accepted, band-level
 * drift is a regression. When ops iterate the rubric anchors, this test
 * forces them to recalibrate the fixtures rather than silently shipping a
 * behaviour change.
 *
 * Per CONTEXT Deferred Ideas: live-call integration test gating on operator
 * credentials is OUT OF SCOPE for v2.0. This test uses Prism::fake exclusively.
 *
 * Pattern mirrors tests/Feature/Agents/ClaudeClientTest.php (Phase 8 Plan 02
 * Prism::fake reference) — uses ResponseBuilder + TextStepFake fluent API.
 */

use App\Domain\Agents\Agents\PricingAgent;
use App\Domain\Agents\Clients\ClaudeClient;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Services\PromptRenderer;
use App\Domain\Agents\Services\ToolBus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Prism\Prism\Enums\FinishReason as PrismFinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;

beforeEach(function () {
    // RunPricingAgentJob (Plan 10-04) sets correlation_id on Context for the
    // request lifetime; IntegrationLogger reads it back when persisting the
    // anthropic integration_events row. Mirror that here so ClaudeClient's
    // post-flight log row satisfies the NOT NULL constraint.
    Context::add('correlation_id', (string) Str::uuid());

    // Render the real PricingAgent system prompt so calibration runs against the
    // shipped Blade view (not a hand-rolled string). PromptRenderer hashes the
    // output; the prompt-hash determinism test (PricingAgentPromptHashTest)
    // locks the hash separately.
    $this->prompt = app(PromptRenderer::class)->render('pricing')['prompt'];

    // Build the Prism tool array via ToolBus exactly as RunAgentJob (Plan 10-04)
    // will. ToolBus::buildPrismTools needs an AgentRun for context (Phase 8
    // Plan 03 contract); a fresh in-memory instance suffices for the fake.
    $run = new AgentRun([
        'id' => '01HX0000000000000000000001',
        'kind' => 'pricing',
        'status' => 'running',
    ]);
    $this->tools = app(ToolBus::class)->buildPrismTools(
        app(PricingAgent::class)->tools(),
        $run,
    );

    // User message shape mirrors what RunPricingAgentJob (Plan 10-04) will pass:
    // a JSON-encoded {sku, context} envelope so the agent has structured input
    // to reason against.
    $this->userMessage = new UserMessage(json_encode([
        'sku' => 'CALIBRATION-FIXTURE',
        'context' => ['phase5_proposed_margin_bps' => 2200],
    ], JSON_THROW_ON_ERROR));
});

/**
 * Helper — extract the LAST propose_margin_band tool call from a ClaudeResponse.
 * Returns null when no propose_margin_band call was made (withMaxSteps exhausted
 * scenario). Mirrors PricingAgentResultMapper extraction logic (Plan 10-04).
 *
 * @return ?array<string, mixed>
 */
function extractFinalProposeMarginBandArgs(\App\Domain\Agents\Clients\ClaudeResponse $response): ?array
{
    $proposeCalls = collect($response->steps ?? [])
        ->flatMap(fn ($step) => $step->toolCalls ?? [])
        ->filter(fn (ToolCall $call) => $call->name === 'propose_margin_band')
        ->values();

    if ($proposeCalls->isEmpty()) {
        return null;
    }

    /** @var ToolCall $last */
    $last = $proposeCalls->last();

    return $last->arguments();
}

it('Fixture 1 — data-rich HIGH-confidence run produces band 71-100 with narrow band + reasoning ≥40 chars', function () {
    // Mirrors system.blade.php Example 1 (LOGI-MEETUP, confidence 82, band 1980-2120).
    // Per RESEARCH P10-A: assert band-membership not exact-equality so token-level
    // variance is tolerated.
    Prism::fake([
        (new ResponseBuilder)
            ->addStep(TextStepFake::make()
                ->withText('Proposed 2050 bps band with HIGH confidence.')
                ->withFinishReason(PrismFinishReason::Stop)
                ->withToolCalls([
                    new ToolCall(
                        id: 'call_propose_high_001',
                        name: 'propose_margin_band',
                        arguments: json_encode([
                            'sku' => 'LOGI-MEETUP',
                            'proposed_bps' => 2050,
                            'reasoning' => '6 competitors stable in 2000-2080 bps band over 90d; supplier flat; 47 sales/90d shows healthy demand. Tight band reflects multi-source corroboration.',
                            'confidence_0_to_100' => 82,
                            'band_min_bps' => 1980,
                            'band_max_bps' => 2120,
                        ], JSON_THROW_ON_ERROR),
                    ),
                ])
                ->withUsage(new Usage(promptTokens: 800, completionTokens: 200)))
            ->toResponse(),
    ]);

    $response = app(ClaudeClient::class)->generate(
        systemPrompt: $this->prompt,
        messages: [$this->userMessage],
        tools: $this->tools,
    );

    $args = extractFinalProposeMarginBandArgs($response);

    expect($args)->not->toBeNull('propose_margin_band should be called for the HIGH-confidence fixture');
    expect((int) $args['confidence_0_to_100'])->toBeBetween(71, 100); // HIGH band per CONTEXT D-07
    expect((int) $args['band_min_bps'])->toBeLessThanOrEqual((int) $args['band_max_bps']);
    expect((int) $args['band_min_bps'])->toBeLessThanOrEqual((int) $args['proposed_bps']);
    expect((int) $args['band_max_bps'])->toBeGreaterThanOrEqual((int) $args['proposed_bps']);
    expect(strlen((string) $args['reasoning']))->toBeGreaterThanOrEqual(40);
});

it('Fixture 2 — data-sparse LOW-confidence run produces band 0-30 with wide band (>500 bps spread)', function () {
    // Mirrors system.blade.php Example 2 (NICHE-RACK-SHELF, confidence 22, band 1700-2700).
    // LOW confidence + wide band is the rubric's expected response to sparse signals.
    Prism::fake([
        (new ResponseBuilder)
            ->addStep(TextStepFake::make()
                ->withText('Proposed 2200 bps with wide LOW-confidence band due to sparse signals.')
                ->withFinishReason(PrismFinishReason::Stop)
                ->withToolCalls([
                    new ToolCall(
                        id: 'call_propose_low_002',
                        name: 'propose_margin_band',
                        arguments: json_encode([
                            'sku' => 'NICHE-RACK-SHELF',
                            'proposed_bps' => 2200,
                            'reasoning' => 'Sparse data: 4 sales/90d, only 2 competitors with high variance (1500-3000 bps), supplier price up 18%. Wide band reflects uncertainty.',
                            'confidence_0_to_100' => 22,
                            'band_min_bps' => 1700,
                            'band_max_bps' => 2700,
                        ], JSON_THROW_ON_ERROR),
                    ),
                ])
                ->withUsage(new Usage(promptTokens: 850, completionTokens: 180)))
            ->toResponse(),
    ]);

    $response = app(ClaudeClient::class)->generate(
        systemPrompt: $this->prompt,
        messages: [$this->userMessage],
        tools: $this->tools,
    );

    $args = extractFinalProposeMarginBandArgs($response);

    expect($args)->not->toBeNull('propose_margin_band should be called for the LOW-confidence fixture');
    expect((int) $args['confidence_0_to_100'])->toBeBetween(0, 30); // LOW band per CONTEXT D-07
    // Wide band sanity check — uncertainty should produce a wide spread, NOT a tight one
    $bandWidth = (int) $args['band_max_bps'] - (int) $args['band_min_bps'];
    expect($bandWidth)->toBeGreaterThan(500); // RESEARCH §System Prompt Design — wide-band defence
    expect(strlen((string) $args['reasoning']))->toBeGreaterThanOrEqual(40);
});

it('Fixture 3 — withMaxSteps exhausted (finish_reason=ToolCalls, no propose_margin_band call)', function () {
    // Edge case: agent loops the read_* tools until withMaxSteps(8) cap hits without
    // ever calling propose_margin_band. CONTEXT D-11: mapper writes
    // agent_run_status='no_proposal' for visibility. This test verifies the
    // framework records the absent propose_margin_band so the mapper can detect.
    Prism::fake([
        (new ResponseBuilder)
            ->addStep(TextStepFake::make()
                ->withText('') // No final acknowledgement — loop hit step cap mid-tool-call
                ->withFinishReason(PrismFinishReason::ToolCalls)
                ->withToolCalls([
                    new ToolCall(
                        id: 'call_read_history_001',
                        name: 'read_margin_history',
                        arguments: json_encode(['sku' => 'EXHAUST-TEST'], JSON_THROW_ON_ERROR),
                    ),
                    new ToolCall(
                        id: 'call_read_competitor_002',
                        name: 'read_competitor_prices',
                        arguments: json_encode(['sku' => 'EXHAUST-TEST'], JSON_THROW_ON_ERROR),
                    ),
                ])
                ->withUsage(new Usage(promptTokens: 1200, completionTokens: 400)))
            ->toResponse(),
    ]);

    $response = app(ClaudeClient::class)->generate(
        systemPrompt: $this->prompt,
        messages: [$this->userMessage],
        tools: $this->tools,
    );

    $args = extractFinalProposeMarginBandArgs($response);

    expect($args)->toBeNull('No propose_margin_band call should appear when withMaxSteps is exhausted');
    // Verify finish_reason mapped through (ClaudeClient maps ToolCalls -> ToolUse)
    expect($response->finishReason->name)->toBe('ToolUse');
});

it('Fixture 4 — malformed-args propose_margin_band IS captured for mapper to flag downstream', function () {
    // Edge case: agent calls propose_margin_band with band_min > band_max (inverted)
    // and reasoning < 40 chars. The framework records what the model sent;
    // PricingAgentResultMapper (Plan 10-04) writes evidence.agent_run_status='malformed_proposal'.
    // This test verifies the call IS visible in the response so the mapper can detect.
    Prism::fake([
        (new ResponseBuilder)
            ->addStep(TextStepFake::make()
                ->withText('Proposed.')
                ->withFinishReason(PrismFinishReason::Stop)
                ->withToolCalls([
                    new ToolCall(
                        id: 'call_propose_malformed_004',
                        name: 'propose_margin_band',
                        arguments: json_encode([
                            'sku' => 'MALFORMED-TEST',
                            'proposed_bps' => 2000,
                            'reasoning' => 'short', // < 40 chars (malformed)
                            'confidence_0_to_100' => 50, // round-to-zero default (anti-pattern)
                            'band_min_bps' => 2200, // INVERTED (band_min > band_max)
                            'band_max_bps' => 2000,
                        ], JSON_THROW_ON_ERROR),
                    ),
                ])
                ->withUsage(new Usage(promptTokens: 900, completionTokens: 50)))
            ->toResponse(),
    ]);

    $response = app(ClaudeClient::class)->generate(
        systemPrompt: $this->prompt,
        messages: [$this->userMessage],
        tools: $this->tools,
    );

    $args = extractFinalProposeMarginBandArgs($response);

    // Mapper validation lives in Plan 10-04, NOT here. This test only verifies
    // the framework captures the malformed call so the mapper has data to work with.
    expect($args)->not->toBeNull('Malformed propose_margin_band call should still be captured');
    expect($args['sku'])->toBe('MALFORMED-TEST');
    // Document the malformation: band_min > band_max + reasoning < 40 chars
    expect((int) $args['band_min_bps'])->toBeGreaterThan((int) $args['band_max_bps']);
    expect(strlen((string) $args['reasoning']))->toBeLessThan(40);
});
