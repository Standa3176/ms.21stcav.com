<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 03 Task 3 — End-to-end SEO guardrail chain integration test
|--------------------------------------------------------------------------
|
| Exercises the FULL post-flight guardrail chain via GuardrailEngine — not
| just SeoOutboundGuardrail in isolation. Asserts that when invoked via
| `GuardrailEngine::runPostFlight($seoAgent, $response, TrustTier::Trusted)`:
|
|   1. A forbidden-pattern response throws GuardrailViolationException with
|      the correct failedPatternKey (proves the chain reaches SeoOutbound
|      AND that SeoOutbound's contract is honoured end-to-end).
|   2. A clean response passes through (no throw — chain returns response
|      unchanged).
|   3. Guardrail order pinned: [SensitiveFieldsStrip, OutboundRegex,
|      SeoOutbound] — Plan 12-04 RunSeoAgentJob's catch-block + downstream
|      tests rely on this index order being byte-identical to SeoAgent's
|      guardrails() return.
|   4. Multiple propose_content_patch calls — only the first forbidden
|      match short-circuits (no partial publishing per D-01); the second
|      clean call is irrelevant once the first matches.
|
| Threat-model anchor: T-12-03-01..03 mitigations VERIFIED via the actual
| GuardrailEngine code path that Plan 12-04 RunSeoAgentJob will invoke.
| Without this test, SeoOutboundGuardrail could be green in isolation but
| silently bypass the engine if the wiring breaks.
*/

use App\Domain\Agents\Agents\SeoAgent;
use App\Domain\Agents\Clients\ClaudeResponse;
use App\Domain\Agents\Enums\FinishReason;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Exceptions\GuardrailViolationException;
use App\Domain\Agents\Guardrails\OutboundRegexFilterGuardrail;
use App\Domain\Agents\Guardrails\SeoOutboundGuardrail;
use App\Domain\Agents\Guardrails\SensitiveFieldsStripGuardrail;
use App\Domain\Agents\Services\GuardrailEngine;
use Prism\Prism\ValueObjects\ToolCall;

/**
 * Helper — build a ClaudeResponse stub with steps[].toolCalls[] populated.
 * Matches the Prism Step shape — anonymous object exposing $toolCalls so
 * SeoOutboundGuardrail's property_exists() check works.
 *
 * @param  array<int, array{name: string, arguments: array<string, mixed>}>  $calls
 */
function makeFakeClaudeResponseWithToolCalls(array $calls): ClaudeResponse
{
    $toolCalls = array_map(
        fn (array $c, int $i): ToolCall => new ToolCall(
            id: "call_int_{$i}",
            name: (string) $c['name'],
            arguments: json_encode($c['arguments'], JSON_THROW_ON_ERROR),
        ),
        $calls,
        array_keys($calls),
    );

    $step = new class($toolCalls)
    {
        /** @param  array<int, ToolCall>  $toolCalls */
        public function __construct(public array $toolCalls) {}
    };

    return new ClaudeResponse(
        text: 'integration-test',
        finishReason: FinishReason::EndTurn,
        promptTokens: 0,
        completionTokens: 0,
        costPence: 0,
        langfuseTraceId: null,
        toolCalls: $toolCalls,
        steps: [$step],
        responseMessages: [],
    );
}

it('GuardrailEngine::runPostFlight short-circuits when SeoOutbound catches a marketing_superlative', function () {
    $agent = app(SeoAgent::class);
    $engine = app(GuardrailEngine::class);

    $response = makeFakeClaudeResponseWithToolCalls([
        [
            'name' => 'propose_content_patch',
            'arguments' => [
                'sku' => 'LOGI-MEETUP',
                'field' => 'title',
                'before' => 'Plain factual title',
                'after' => "Logitech MeetUp — the world's best video bar",
                'reasoning' => 'punchier',
            ],
        ],
    ]);

    try {
        $engine->runPostFlight($agent, $response, TrustTier::Trusted);
        $this->fail('Expected GuardrailViolationException from runPostFlight');
    } catch (GuardrailViolationException $e) {
        expect($e->failedPatternKey)->toBe('marketing_superlatives');
        expect($e->guardrailClass)->toBe(SeoOutboundGuardrail::class);
    }
});

it('GuardrailEngine::runPostFlight short-circuits when SeoOutbound catches a competitor_brand', function () {
    $agent = app(SeoAgent::class);
    $engine = app(GuardrailEngine::class);

    $response = makeFakeClaudeResponseWithToolCalls([
        [
            'name' => 'propose_content_patch',
            'arguments' => [
                'sku' => 'CAT-X',
                'field' => 'long_description',
                'before' => '',
                'after' => 'Drop-in replacement for your Poly Studio kit',
                'reasoning' => 'comparison',
            ],
        ],
    ]);

    expect(fn () => $engine->runPostFlight($agent, $response, TrustTier::Trusted))
        ->toThrow(GuardrailViolationException::class);
});

it('GuardrailEngine::runPostFlight passes a clean response through unchanged', function () {
    $agent = app(SeoAgent::class);
    $engine = app(GuardrailEngine::class);

    $response = makeFakeClaudeResponseWithToolCalls([
        [
            'name' => 'propose_content_patch',
            'arguments' => [
                'sku' => 'LOGI-MEETUP',
                'field' => 'short_description',
                'before' => 'All-in-one ConferenceCam',
                'after' => 'Logitech MeetUp is an all-in-one ConferenceCam compatible with Zoom Rooms, Microsoft Teams Rooms, and Google Meet',
                'reasoning' => 'add platform compatibility statements',
            ],
        ],
    ]);

    // Clean response — no throw. Engine returns the same response object
    // (passes it through each guardrail's post() which returns it unchanged).
    $returned = $engine->runPostFlight($agent, $response, TrustTier::Trusted);

    expect($returned)->toBe($response);
});

it('SeoAgent::guardrails() order is deterministic — [SensitiveFieldsStrip, OutboundRegex, SeoOutbound]', function () {
    // Plan 12-04 RunSeoAgentJob's catch-block + suggestion-writer tests
    // rely on this index ordering being byte-identical to what SeoAgent
    // returns. Locks it forensically.
    $guardrails = app(SeoAgent::class)->guardrails();

    expect($guardrails[0])->toBeInstanceOf(SensitiveFieldsStripGuardrail::class);
    expect($guardrails[1])->toBeInstanceOf(OutboundRegexFilterGuardrail::class);
    expect($guardrails[2])->toBeInstanceOf(SeoOutboundGuardrail::class);
});

it('GuardrailEngine short-circuits on FIRST forbidden match — second clean patch is irrelevant (no partial publishing)', function () {
    // Per CONTEXT D-01: first match → fail entire run. The engine MUST NOT
    // continue evaluating subsequent patches once one fails.
    $agent = app(SeoAgent::class);
    $engine = app(GuardrailEngine::class);

    $response = makeFakeClaudeResponseWithToolCalls([
        [
            'name' => 'propose_content_patch',
            'arguments' => [
                'sku' => 'X',
                'field' => 'title',
                'before' => 'A',
                'after' => 'The revolutionary new MeetingBar', // FORBIDDEN — marketing_superlatives
                'reasoning' => 'short',
            ],
        ],
        [
            'name' => 'propose_content_patch',
            'arguments' => [
                'sku' => 'X',
                'field' => 'short_description',
                'before' => '',
                'after' => 'Compatible with Zoom Rooms and Microsoft Teams Rooms', // CLEAN
                'reasoning' => 'platform compat',
            ],
        ],
    ]);

    try {
        $engine->runPostFlight($agent, $response, TrustTier::Trusted);
        $this->fail('Expected GuardrailViolationException — first forbidden patch should short-circuit even though second is clean');
    } catch (GuardrailViolationException $e) {
        // The first forbidden patch ("revolutionary") fired the throw — no
        // partial publishing. Plan 12-04 mapper writes ONE
        // agent_guardrail_blocked Suggestion (not one per patch).
        expect($e->failedPatternKey)->toBe('marketing_superlatives');
        expect(strtolower($e->matchedExcerpt))->toContain('revolutionary');
    }
});
