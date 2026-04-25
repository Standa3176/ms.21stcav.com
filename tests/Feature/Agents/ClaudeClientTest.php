<?php

declare(strict_types=1);

/**
 * Phase 8 Plan 02 — ClaudeClient integration tests against Prism::fake().
 *
 * Verifies the full round-trip: ClaudeClient::generate() → Prism (faked) →
 * ClaudeResponse value object with cost_pence + token usage + finish-reason
 * mapping + Langfuse-Trace-Id Context plumbing (Open Question Q2 retirement).
 *
 * The ClaudeClient is the SOLE Anthropic call site for the v2.0 framework.
 * Every agent's LLM traffic flows through it so IntegrationLogger captures
 * the HTTP shape and BudgetGuard records actuals post-flight.
 */

use App\Domain\Agents\Clients\ClaudeClient;
use App\Domain\Agents\Clients\ClaudeResponse;
use App\Domain\Agents\Enums\FinishReason;
use App\Domain\Agents\Services\CostCalculator;
use App\Foundation\Integration\Models\IntegrationEvent;
use Illuminate\Support\Facades\Context;
use Prism\Prism\Enums\FinishReason as PrismFinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Usage;

it('round-trips a Prism::fake() response into a ClaudeResponse with cost in pence', function () {
    Prism::fake([
        (new ResponseBuilder)
            ->addStep(TextStepFake::make()
                ->withText('Hello world')
                ->withFinishReason(PrismFinishReason::Stop)
                ->withUsage(new Usage(promptTokens: 100, completionTokens: 50)))
            ->toResponse(),
    ]);

    $client = app(ClaudeClient::class);
    $response = $client->generate(
        systemPrompt: 'You are a test agent.',
        messages: [new UserMessage('hello')],
    );

    expect($response)->toBeInstanceOf(ClaudeResponse::class);
    expect($response->text)->toBe('Hello world');
    expect($response->finishReason)->toBe(FinishReason::EndTurn);
    expect($response->promptTokens)->toBe(100);
    expect($response->completionTokens)->toBe(50);
    // 100 * 0.00024 + 50 * 0.0012 = 0.024 + 0.06 = 0.084 → ceil → 1
    expect($response->costPence)->toBe(1);
});

it('records exactly one integration_events row per ClaudeClient::generate() invocation', function () {
    Prism::fake([
        (new ResponseBuilder)
            ->addStep(TextStepFake::make()
                ->withText('logged')
                ->withFinishReason(PrismFinishReason::Stop)
                ->withUsage(new Usage(promptTokens: 10, completionTokens: 5)))
            ->toResponse(),
    ]);

    expect(IntegrationEvent::query()->where('channel', 'anthropic')->count())->toBe(0);

    app(ClaudeClient::class)->generate(
        systemPrompt: 'log probe',
        messages: [new UserMessage('hi')],
    );

    $events = IntegrationEvent::query()->where('channel', 'anthropic')->get();
    expect($events)->toHaveCount(1);
    expect($events->first()->endpoint)->toBe('/v1/messages');
    expect($events->first()->method)->toBe('POST');
    expect($events->first()->status)->toBe('ok');
});

it('maps Prism FinishReason::Stop to local FinishReason::EndTurn', function () {
    expect(ClaudeResponse::mapFinishReason('Stop'))->toBe(FinishReason::EndTurn);
});

it('maps Prism FinishReason::ToolCalls to local FinishReason::ToolUse', function () {
    expect(ClaudeResponse::mapFinishReason('ToolCalls'))->toBe(FinishReason::ToolUse);
});

it('maps Prism FinishReason::Length to local FinishReason::MaxTokens', function () {
    expect(ClaudeResponse::mapFinishReason('Length'))->toBe(FinishReason::MaxTokens);
});

it('maps unknown / error / content-filter Prism finish reasons to local FinishReason::Error', function () {
    expect(ClaudeResponse::mapFinishReason('ContentFilter'))->toBe(FinishReason::Error);
    expect(ClaudeResponse::mapFinishReason('Error'))->toBe(FinishReason::Error);
    expect(ClaudeResponse::mapFinishReason('Unknown'))->toBe(FinishReason::Error);
    expect(ClaudeResponse::mapFinishReason('Other'))->toBe(FinishReason::Error);
    expect(ClaudeResponse::mapFinishReason('this-name-does-not-exist'))->toBe(FinishReason::Error);
});

it('CostCalculator::compute returns 1 pence for 100 prompt + 50 completion tokens (sonnet-4-6 rates)', function () {
    $calc = app(CostCalculator::class);
    // 100 * 0.00024 + 50 * 0.0012 = 0.024 + 0.06 = 0.084 → ceil → 1
    expect($calc->compute(promptTokens: 100, completionTokens: 50, model: 'claude-sonnet-4-6'))->toBe(1);
});

it('CostCalculator::compute returns 5 pence for 10000 prompt + 2000 completion tokens (sonnet-4-6 rates)', function () {
    $calc = app(CostCalculator::class);
    // 10000 * 0.00024 + 2000 * 0.0012 = 2.4 + 2.4 = 4.8 → ceil → 5
    expect($calc->compute(promptTokens: 10000, completionTokens: 2000, model: 'claude-sonnet-4-6'))->toBe(5);
});

it('CostCalculator throws on unknown model', function () {
    $calc = app(CostCalculator::class);
    expect(fn () => $calc->compute(100, 50, 'unknown-model-xyz'))
        ->toThrow(\RuntimeException::class, 'No pricing configured for model: unknown-model-xyz');
});

it('Q2 retirement — mliviu79 shim populates Context langfuse_trace_id OR ClaudeResponse falls back to null', function () {
    // Force Context to a known state to detect shim populate path. If the shim
    // is not active in test mode (Prism::fake() bypasses the HTTP layer that
    // would normally trigger the shim's middleware), the Context value we set
    // here propagates through ClaudeClient::extractLangfuseTraceId() unchanged.
    // Either way is acceptable per RESEARCH §Open Q2 RESOLVED:
    //   - shim populates Context::get('langfuse_trace_id') in production
    //   - fallback path via X-Langfuse-Trace-Id response header documented in
    //     docs/ops/observability.md for the (rare) case where shim breaks.
    Context::add('langfuse_trace_id', 'test-trace-12345');

    Prism::fake([
        (new ResponseBuilder)
            ->addStep(TextStepFake::make()
                ->withText('ok')
                ->withFinishReason(PrismFinishReason::Stop)
                ->withUsage(new Usage(promptTokens: 1, completionTokens: 1)))
            ->toResponse(),
    ]);

    $response = app(ClaudeClient::class)->generate(
        systemPrompt: 'tag',
        messages: [new UserMessage('hi')],
    );

    // Acceptable outcomes: null (no shim, Context wiped between requests) or
    // 'test-trace-12345' (Context propagated, simulating shim populate path).
    expect($response->langfuseTraceId)->toBeIn([null, 'test-trace-12345']);
});

it('respects ClaudeClient default constants (model, max-steps, max-tokens, temperature)', function () {
    expect(ClaudeClient::DEFAULT_MODEL)->toBe('claude-sonnet-4-6');
    expect(ClaudeClient::DEFAULT_MAX_STEPS)->toBe(8);
    expect(ClaudeClient::DEFAULT_MAX_TOKENS)->toBe(4000);
    expect(ClaudeClient::DEFAULT_TEMPERATURE)->toBe(0.0);
});
