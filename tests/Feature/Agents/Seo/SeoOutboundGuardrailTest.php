<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 03 Task 2 — SeoOutboundGuardrail post-flight contract
|--------------------------------------------------------------------------
|
| Asserts (per RESEARCH §Pattern 7):
|   1. isPreFlight() === false / isPostFlight() === true
|   2. shouldRun(TrustTier::Trusted) === true (always runs for SeoAgent)
|   3. post() throws GuardrailViolationException on first forbidden pattern
|      match with $failedPatternKey + $matchedExcerpt populated
|   4. post() returns response unchanged when no forbidden patterns found
|   5. post() ignores tool calls OTHER than propose_content_patch
|   6. post() handles empty $response->steps array gracefully
|   7. Each of the 3 starter regex categories (competitor_brands /
|      price_claims_absolute / marketing_superlatives) is exercised end-to-end
|
| Why no DB: ClaudeResponse is a pure value object; guardrail walks
| $response->steps[]->toolCalls[] without touching Eloquent.
|
| Threat surface anchor: T-12-03-01..03 — every starter pattern category
| has at least one assertion proving a representative phrase fails the
| post-flight scan. T-12-03-04 (audit trail) is Plan 12-04's territory
| (mapper's createGuardrailBlockedSuggestion is what writes the Suggestion
| from the exception fields).
*/

use App\Domain\Agents\Clients\ClaudeResponse;
use App\Domain\Agents\Enums\FinishReason;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Exceptions\GuardrailViolationException;
use App\Domain\Agents\Guardrails\SeoOutboundGuardrail;
use Prism\Prism\ValueObjects\ToolCall;

/**
 * Helper — build a minimal ClaudeResponse stub whose steps[] array contains
 * an anonymous object exposing $toolCalls (the only shape SeoOutboundGuardrail
 * reads). Mirrors the Phase 10 calibration test idiom but lighter-weight
 * because the guardrail does NOT need Usage, FinishReason mapping, or
 * langfuse_trace_id — only the tool-call walk path.
 *
 * @param  array<int, array{name: string, arguments: array<string, mixed>}>  $calls
 */
function fakeClaudeResponseWithToolCalls(array $calls): ClaudeResponse
{
    $toolCalls = array_map(
        fn (array $c, int $i): ToolCall => new ToolCall(
            id: "call_test_{$i}",
            name: (string) $c['name'],
            arguments: json_encode($c['arguments'], JSON_THROW_ON_ERROR),
        ),
        $calls,
        array_keys($calls),
    );

    // Anonymous step object exposing $toolCalls — guardrail reads via
    // property_exists($step, 'toolCalls'). Matches Prism's Step shape
    // (steps[] is Illuminate\Support\Collection of objects with toolCalls).
    $step = new class($toolCalls)
    {
        /** @param  array<int, ToolCall>  $toolCalls */
        public function __construct(public array $toolCalls) {}
    };

    return new ClaudeResponse(
        text: 'fake',
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

it('SeoOutboundGuardrail::isPreFlight() === false', function () {
    expect((new SeoOutboundGuardrail())->isPreFlight())->toBeFalse();
});

it('SeoOutboundGuardrail::isPostFlight() === true', function () {
    expect((new SeoOutboundGuardrail())->isPostFlight())->toBeTrue();
});

it('SeoOutboundGuardrail::shouldRun(Trusted) === true (always runs for SeoAgent)', function () {
    expect((new SeoOutboundGuardrail())->shouldRun(TrustTier::Trusted))->toBeTrue();
});

it('SeoOutboundGuardrail::pre() is a pure no-op (returns input unchanged)', function () {
    $input = ['sku' => 'X', 'context' => ['note' => 'no mutation']];
    expect((new SeoOutboundGuardrail())->pre($input))->toBe($input);
});

it('throws GuardrailViolationException on marketing_superlative match with failedPatternKey + matchedExcerpt', function () {
    $response = fakeClaudeResponseWithToolCalls([
        [
            'name' => 'propose_content_patch',
            'arguments' => [
                'sku' => 'LOGI-MEETUP',
                'field' => 'long_description',
                'before' => 'Plain factual copy',
                'after' => 'This is the most revolutionary product for huddle rooms',
                'reasoning' => 'shorter, punchier opener',
            ],
        ],
    ]);

    try {
        (new SeoOutboundGuardrail())->post($response);
        $this->fail('Expected GuardrailViolationException');
    } catch (GuardrailViolationException $e) {
        expect($e->failedPatternKey)->toBe('marketing_superlatives');
        expect($e->matchedExcerpt)->toContain('revolutionary');
        expect($e->guardrailClass)->toBe(SeoOutboundGuardrail::class);
    }
});

it('throws GuardrailViolationException on competitor_brands match', function () {
    $response = fakeClaudeResponseWithToolCalls([
        [
            'name' => 'propose_content_patch',
            'arguments' => [
                'sku' => 'CAT-XYZ',
                'field' => 'short_description',
                'before' => '',
                'after' => 'A direct rival to the Cisco Webex Room platform',
                'reasoning' => 'comparison shopping',
            ],
        ],
    ]);

    try {
        (new SeoOutboundGuardrail())->post($response);
        $this->fail('Expected GuardrailViolationException');
    } catch (GuardrailViolationException $e) {
        expect($e->failedPatternKey)->toBe('competitor_brands');
        expect(strtolower($e->matchedExcerpt))->toContain('cisco');
    }
});

it('throws GuardrailViolationException on price_claims_absolute match ("best price")', function () {
    $response = fakeClaudeResponseWithToolCalls([
        [
            'name' => 'propose_content_patch',
            'arguments' => [
                'sku' => 'CAT-XYZ',
                'field' => 'meta_description',
                'before' => '',
                'after' => 'Get the best price on enterprise AV at MeetingStore',
                'reasoning' => 'punchy meta',
            ],
        ],
    ]);

    try {
        (new SeoOutboundGuardrail())->post($response);
        $this->fail('Expected GuardrailViolationException');
    } catch (GuardrailViolationException $e) {
        expect($e->failedPatternKey)->toBe('price_claims_absolute');
        expect(strtolower($e->matchedExcerpt))->toContain('best price');
    }
});

it('scans the BEFORE text too — match on before is caught (defence-in-depth)', function () {
    // Even if the LLM leaves a forbidden phrase in `before` while patching
    // another field, the guardrail must catch it (the agent should NOT be
    // copying anything forbidden, even verbatim from the existing draft).
    $response = fakeClaudeResponseWithToolCalls([
        [
            'name' => 'propose_content_patch',
            'arguments' => [
                'sku' => 'X',
                'field' => 'title',
                'before' => 'The cheapest video bar on the market',
                'after' => 'Logitech MeetUp video bar for small rooms',
                'reasoning' => 'remove price claim',
            ],
        ],
    ]);

    expect(fn () => (new SeoOutboundGuardrail())->post($response))
        ->toThrow(GuardrailViolationException::class);
});

it('returns response unchanged when no forbidden pattern matches (clean patch)', function () {
    $response = fakeClaudeResponseWithToolCalls([
        [
            'name' => 'propose_content_patch',
            'arguments' => [
                'sku' => 'LOGI-MEETUP',
                'field' => 'short_description',
                'before' => 'All-in-one ConferenceCam',
                'after' => 'Logitech MeetUp is an all-in-one ConferenceCam compatible with Zoom Rooms, Microsoft Teams Rooms, and Google Meet',
                'reasoning' => 'add platform compatibility for SEO',
            ],
        ],
    ]);

    $returned = (new SeoOutboundGuardrail())->post($response);

    expect($returned)->toBe($response);
});

it('ignores tool calls OTHER than propose_content_patch (only scans propose calls)', function () {
    // read_product_draft can legitimately return supplier copy that contains
    // marketing words — that's input from the upstream draft, not output the
    // agent generated. The guardrail must NOT scan read_* tool calls.
    $response = fakeClaudeResponseWithToolCalls([
        [
            'name' => 'read_product_draft',
            'arguments' => [
                'sku' => 'LEGACY',
                // simulate a tool-arg containing forbidden phrasing
                '_supplier_text' => 'revolutionary new conference camera',
            ],
        ],
    ]);

    $returned = (new SeoOutboundGuardrail())->post($response);

    expect($returned)->toBe($response);
});

it('returns response unchanged when steps[] is empty', function () {
    $response = new ClaudeResponse(
        text: '',
        finishReason: FinishReason::EndTurn,
        promptTokens: 0,
        completionTokens: 0,
        costPence: 0,
        langfuseTraceId: null,
        toolCalls: [],
        steps: [],
        responseMessages: [],
    );

    $returned = (new SeoOutboundGuardrail())->post($response);

    expect($returned)->toBe($response);
});

it('matchedExcerpt is capped at 200 chars (forensic excerpt bounded)', function () {
    // Forge a 500-char after value containing the forbidden phrase near the
    // start. Since the guardrail captures $m[0] only (the matched word) and
    // mb_substr's to 200 chars, the assertion confirms the bound.
    $longBenign = str_repeat('a ', 250); // 500 chars
    $response = fakeClaudeResponseWithToolCalls([
        [
            'name' => 'propose_content_patch',
            'arguments' => [
                'sku' => 'X',
                'field' => 'long_description',
                'before' => '',
                'after' => $longBenign.' revolutionary kit',
                'reasoning' => 'r',
            ],
        ],
    ]);

    try {
        (new SeoOutboundGuardrail())->post($response);
        $this->fail('Expected GuardrailViolationException');
    } catch (GuardrailViolationException $e) {
        expect(mb_strlen($e->matchedExcerpt))->toBeLessThanOrEqual(200);
        expect($e->matchedExcerpt)->toBe('revolutionary');
    }
});
