<?php

declare(strict_types=1);

namespace App\Domain\Agents\Contracts;

use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\ValueObjects\AgentResult;

/**
 * Phase 8 Plan 03 (AGNT-01) — every agent implements this contract.
 *
 * Static methods declare compile-time properties (kind, trustTier) so
 * architecture tests can assert them without instantiation. Instance
 * methods deliver the runtime behaviour.
 *
 * Surface (6 methods):
 *   - static kind()        → 'echo' / 'pricing' / 'seo' / 'chatbot' / 'ad_optimisation'
 *   - static trustTier()   → declared per-agent (CONTEXT Claude's Discretion)
 *   - tools()              → array<Tool>
 *   - systemPrompt(ctx)    → rendered Blade view text (PromptRenderer is the helper)
 *   - guardrails()         → array<Guardrail>
 *   - execute(input, tier) → AgentResult
 *
 * Implementations live in app/Domain/Agents/Agents/{Kind}Agent.php (Plan 04
 * EchoAgent + Phase 10 PricingAgent + Phase 12 SeoAgent + Phase 14 ChatbotAgent
 * + Phase 15 AdAgent). Plan 04's RunAgentJob is the framework orchestrator;
 * concrete agents NEVER touch Eloquent — every Suggestion they propose flows
 * through AgentSuggestionWriter (Pattern 2 in 08-RESEARCH.md §Code Examples).
 */
interface RunsAsAgent
{
    /** Compile-time kind identifier — registered with AgentRegistry. */
    public static function kind(): string;

    /** Compile-time trust posture — drives GuardrailEngine selection of pre-flight checks. */
    public static function trustTier(): TrustTier;

    /** @return array<int, \App\Domain\Agents\Services\Tools\Tool> */
    public function tools(): array;

    /**
     * @param  array<string, mixed>  $context  Variables interpolated into the Blade view.
     * @return string  Rendered system prompt (Blade view → string).
     */
    public function systemPrompt(array $context = []): string;

    /** @return array<int, \App\Domain\Agents\Contracts\Guardrail> */
    public function guardrails(): array;

    /**
     * @param  array<string, mixed>  $input
     * @param  TrustTier  $tier  Caller may override; agent's own trustTier() is the default.
     */
    public function execute(array $input, TrustTier $tier): AgentResult;
}
