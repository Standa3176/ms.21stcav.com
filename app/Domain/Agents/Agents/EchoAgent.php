<?php

declare(strict_types=1);

namespace App\Domain\Agents\Agents;

use App\Domain\Agents\Contracts\RunsAsAgent;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Guardrails\OutboundRegexFilterGuardrail;
use App\Domain\Agents\Guardrails\SensitiveFieldsStripGuardrail;
use App\Domain\Agents\Services\PromptRenderer;
use App\Domain\Agents\Services\Tools\ReadHealthCheckTool;
use App\Domain\Agents\ValueObjects\AgentResult;

/**
 * Phase 8 Plan 04 — EchoAgent (the framework smoke test).
 *
 * NOT a business consumer. Proves the framework end-to-end:
 *   AgentRegistry → BudgetGuard → ToolBus → ClaudeClient → Langfuse
 *   → AgentSuggestionWriter → SuggestionApplierResolver → Filament.
 *
 * Per CONTEXT §specifics: EchoAgent stays in the repo as the canonical
 * "framework health" pattern even after Phase 10's PricingAgent ships.
 *
 * Architectural note (RESEARCH §Pattern 2 + plan-checker iter 1):
 *   `RunAgentJob::handle()` is the single orchestration point — it calls the
 *   contract's `tools() / systemPrompt() / guardrails()` getters directly and
 *   does NOT invoke `execute()`. The contract's `execute()` is reserved as a
 *   forward-compatibility seam for future agents that need to wrap the
 *   orchestration. EchoAgent::execute() therefore throws LogicException.
 *
 * Trust posture: Trusted (admin-triggered, no customer input). PromptInjection
 * XML-fence is skipped (TrustTier-aware in GuardrailEngine); SensitiveFields
 * + OutboundRegex still fire as defence-in-depth.
 */
final class EchoAgent implements RunsAsAgent
{
    public function __construct(
        private readonly PromptRenderer $promptRenderer,
    ) {}

    public static function kind(): string
    {
        return 'echo';
    }

    public static function trustTier(): TrustTier
    {
        return TrustTier::Trusted;
    }

    /** @return array<int, \App\Domain\Agents\Services\Tools\Tool> */
    public function tools(): array
    {
        return [app(ReadHealthCheckTool::class)];
    }

    /** @param  array<string, mixed>  $context */
    public function systemPrompt(array $context = []): string
    {
        return $this->promptRenderer->render(self::kind(), $context)['prompt'];
    }

    /** @return array<int, \App\Domain\Agents\Contracts\Guardrail> */
    public function guardrails(): array
    {
        // Trusted tier — PromptInjectionXmlFence skipped via shouldRun()
        // (see PromptInjectionXmlFenceGuardrail Plan 03). Sensitive-field
        // strip is per-tool (not chain-driven) but listed here for
        // architecture-test discoverability. OutboundRegex fires post-flight.
        return [
            app(SensitiveFieldsStripGuardrail::class),
            app(OutboundRegexFilterGuardrail::class),
        ];
    }

    /**
     * Forward-compat seam — Plan 04 RunAgentJob owns orchestration and never
     * calls this method. Documented in 08-04-SUMMARY.md.
     *
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input, TrustTier $tier): AgentResult
    {
        throw new \LogicException(
            'EchoAgent::execute is a stub — RunAgentJob owns the orchestration.'
        );
    }
}
