<?php

declare(strict_types=1);

use App\Domain\Agents\Contracts\RunsAsAgent;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Services\AgentRegistry;
use App\Domain\Agents\ValueObjects\AgentResult;

/*
|--------------------------------------------------------------------------
| Phase 8 Plan 03 Task 1 — AgentRegistry singleton + contract surface (AGNT-02)
|--------------------------------------------------------------------------
|
| Verifies the kind→class registry is in-memory + singleton-safe + throws
| on unknown kind. The four-method test pattern mirrors v1's
| SuggestionApplierResolver design (the Agents-domain analogue).
|
| Also smoke-tests the four exception classes exist + extend RuntimeException
| (so RunAgentJob's catch-block in Plan 04 can branch on class identity)
| and that AgentResult/SuggestionDraft are instantiable readonly value objects.
*/

it('register() then resolve() returns an instance of the registered class', function (): void {
    $registry = app(AgentRegistry::class);
    $registry->register('fixture-echo', FixtureEchoAgent::class);

    expect($registry->resolve('fixture-echo'))->toBeInstanceOf(FixtureEchoAgent::class);
});

it('resolve() throws RuntimeException with descriptive message on unknown kind', function (): void {
    $registry = app(AgentRegistry::class);

    expect(fn () => $registry->resolve('definitely-not-registered'))
        ->toThrow(RuntimeException::class, 'No agent registered');
});

it('registered() returns the kind→class map', function (): void {
    $registry = app(AgentRegistry::class);
    $registry->register('fixture-echo', FixtureEchoAgent::class);

    expect($registry->registered())->toHaveKey('fixture-echo')
        ->and($registry->registered()['fixture-echo'])->toBe(FixtureEchoAgent::class);
});

it('AgentRegistry is bound as a container singleton', function (): void {
    $a = app(AgentRegistry::class);
    $b = app(AgentRegistry::class);

    expect($a)->toBe($b);
});

it('BudgetExceededException extends RuntimeException', function (): void {
    expect(new \App\Domain\Agents\Exceptions\BudgetExceededException('test'))
        ->toBeInstanceOf(RuntimeException::class);
});

it('MonthlyBudgetExceededException extends RuntimeException (separate class for kill-switch detection)', function (): void {
    expect(new \App\Domain\Agents\Exceptions\MonthlyBudgetExceededException('test'))
        ->toBeInstanceOf(RuntimeException::class);
});

it('UnauthorisedToolException extends RuntimeException', function (): void {
    expect(new \App\Domain\Agents\Exceptions\UnauthorisedToolException('test'))
        ->toBeInstanceOf(RuntimeException::class);
});

it('GuardrailViolationException extends RuntimeException + fromGuardrail() captures class', function (): void {
    $e = \App\Domain\Agents\Exceptions\GuardrailViolationException::fromGuardrail(
        'App\\Some\\Guardrail',
        'forbidden pattern detected'
    );

    expect($e)->toBeInstanceOf(RuntimeException::class)
        ->and($e->getMessage())->toBe('forbidden pattern detected')
        ->and($e->guardrailClass)->toBe('App\\Some\\Guardrail');
});

it('AgentResult is readonly with the D-06 forensic snapshot fields', function (): void {
    $result = new AgentResult(
        suggestionDrafts: [],
        agentReasoning: 'reasoning',
        finishReason: \App\Domain\Agents\Enums\FinishReason::EndTurn,
        promptTokens: 10,
        completionTokens: 20,
        costPence: 1,
        langfuseTraceId: 'trace-abc',
        toolCalls: [],
    );

    expect($result->agentReasoning)->toBe('reasoning')
        ->and($result->promptTokens)->toBe(10)
        ->and($result->completionTokens)->toBe(20)
        ->and($result->costPence)->toBe(1)
        ->and($result->langfuseTraceId)->toBe('trace-abc');
});

it('SuggestionDraft is readonly with kind/payload/evidence', function (): void {
    $draft = new \App\Domain\Agents\ValueObjects\SuggestionDraft(
        kind: 'echo_health',
        payload: ['ok' => true],
        evidence: ['ts' => '2026-04-25'],
    );

    expect($draft->kind)->toBe('echo_health')
        ->and($draft->payload)->toBe(['ok' => true])
        ->and($draft->evidence)->toBe(['ts' => '2026-04-25']);
});

/**
 * Test fixture — minimal RunsAsAgent stub used only by the registry tests.
 * Kept anonymous-class-equivalent (declared at file scope so Reflection sees it)
 * but avoids any Anthropic / Prism dependency.
 */
final class FixtureEchoAgent implements RunsAsAgent
{
    public static function kind(): string
    {
        return 'fixture-echo';
    }

    public static function trustTier(): TrustTier
    {
        return TrustTier::Trusted;
    }

    public function tools(): array
    {
        return [];
    }

    public function systemPrompt(array $context = []): string
    {
        return 'fixture system prompt';
    }

    public function guardrails(): array
    {
        return [];
    }

    public function execute(array $input, TrustTier $tier): AgentResult
    {
        return new AgentResult(
            suggestionDrafts: [],
            agentReasoning: 'fixture',
            finishReason: \App\Domain\Agents\Enums\FinishReason::EndTurn,
            promptTokens: 0,
            completionTokens: 0,
            costPence: 0,
            langfuseTraceId: null,
            toolCalls: [],
        );
    }
}
