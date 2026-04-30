<?php

declare(strict_types=1);

use App\Domain\Agents\Contracts\RunsAsAgent;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Services\AgentRegistry;
use App\Domain\Agents\ValueObjects\AgentResult;

/*
|--------------------------------------------------------------------------
| Phase 10 Plan 01 — Framework smoke test (replaces Phase 8 EchoAgentRunTest)
|--------------------------------------------------------------------------
|
| EchoAgent (Phase 8 Plan 04 framework smoke fixture) was deleted in the
| Phase 10 Plan 01 P10-H sweep — PricingAgent is now the first REAL
| RunsAsAgent consumer. The Phase 8 framework-integrity contract migrates
| here via an INLINE fixture stub agent class so the AgentRegistry seam
| stays exercised in CI without keeping a deployed demo agent in repo.
|
| Contract preserved (Phase 8 framework invariants):
|   1. AgentRegistry::register(kind, class) → resolve(kind) returns the
|      registered RunsAsAgent instance via container resolution.
|   2. AgentRegistry::resolve(unknown) throws RuntimeException — the
|      explicit no-silent-fallback semantic enforced since Plan 03.
|
| Reference: 10-RESEARCH.md §P10-H "EchoAgent deletion breaks Phase 8
| architectural assertions" — this fixture stub is the load-bearing
| replacement for the deleted EchoAgent class.
*/

/**
 * Inline fixture stub — never touches Eloquent, never makes Anthropic calls.
 *
 * Lives in this single test file (Pest convention — see Phase 8
 * EchoAgentRunTest's AlwaysRejectGuardrail precedent for the same pattern).
 * Container-bound via AgentRegistry::register() inside the it() block, so
 * no AppServiceProvider edit is required for the fixture to resolve.
 */
final class StubFrameworkAgent implements RunsAsAgent
{
    public static function kind(): string
    {
        return 'framework_smoke';
    }

    public static function trustTier(): TrustTier
    {
        return TrustTier::Trusted;
    }

    /** @return array<int, \App\Domain\Agents\Services\Tools\Tool> */
    public function tools(): array
    {
        return [];
    }

    /** @param  array<string, mixed>  $context */
    public function systemPrompt(array $context = []): string
    {
        return 'smoke';
    }

    /** @return array<int, \App\Domain\Agents\Contracts\Guardrail> */
    public function guardrails(): array
    {
        return [];
    }

    /** @param  array<string, mixed>  $input */
    public function execute(array $input, TrustTier $tier): AgentResult
    {
        throw new \LogicException(
            'StubFrameworkAgent::execute is a fixture stub — RunAgentJob owns orchestration.'
        );
    }
}

it('AgentRegistry resolves a registered agent kind to a RunsAsAgent instance', function (): void {
    $registry = app(AgentRegistry::class);
    $registry->register('framework_smoke', StubFrameworkAgent::class);

    $resolved = $registry->resolve('framework_smoke');

    expect($resolved)->toBeInstanceOf(StubFrameworkAgent::class);
    expect($resolved)->toBeInstanceOf(RunsAsAgent::class);
});

it('AgentRegistry throws RuntimeException on unknown kind', function (): void {
    app(AgentRegistry::class)->resolve('definitely-not-a-real-kind-'.uniqid());
})->throws(\RuntimeException::class);
