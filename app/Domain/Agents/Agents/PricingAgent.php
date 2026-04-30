<?php

declare(strict_types=1);

namespace App\Domain\Agents\Agents;

use App\Domain\Agents\Contracts\RunsAsAgent;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Guardrails\OutboundRegexFilterGuardrail;
use App\Domain\Agents\Guardrails\SensitiveFieldsStripGuardrail;
use App\Domain\Agents\Services\PromptRenderer;
use App\Domain\Agents\Tools\Pricing\ProposeMarginBandTool;
use App\Domain\Agents\Tools\Pricing\ReadCompetitorPricesTool;
use App\Domain\Agents\Tools\Pricing\ReadMarginHistoryTool;
use App\Domain\Agents\Tools\Pricing\ReadSalesVolume90dTool;
use App\Domain\Agents\Tools\Pricing\ReadSupplierPriceTrendTool;
use App\Domain\Agents\ValueObjects\AgentResult;

/**
 * Phase 10 — first REAL RunsAsAgent consumer of the Phase 8 framework.
 *
 * Replaces Phase 8's EchoAgent (deleted in the Plan 10-01 P10-H sweep —
 * EchoAgent was a smoke-test fixture, not a business consumer). The
 * Phase 8 framework primitives (AgentRegistry, BudgetGuard, ToolBus,
 * GuardrailEngine, ClaudeClient, RunAgentJob, AgentSuggestionWriter,
 * AgentRun) all stay byte-identical — PricingAgent simply implements the
 * RunsAsAgent contract and registers itself.
 *
 * Contract surface:
 *
 *   PRCAGT-01 — kind='pricing', triggered admin-pull only (Filament button
 *               on margin_change Suggestion detail; per CONTEXT D-01).
 *               Plan 10-04 ships the Filament button + RunPricingAgentJob.
 *
 *   PRCAGT-02 — 5 tools (4 read_* + 1 propose_*); naming gate enforced
 *               compile-time by AgentToolsNamingTest + runtime by ToolBus
 *               + container-level by PricingToolStubsContractTest. Plan
 *               10-01 ships compile-time stubs; Plan 10-02 swaps the
 *               using() callable bodies for real DB queries (90d windows
 *               + 3 KB caps + _truncated hints).
 *
 *   PRCAGT-05 — TrustTier::Trusted (admin-triggered only); pricing daily
 *               cap 500p via BudgetGuard reading config('agents.daily_caps.pricing').
 *               PricingAgentRegistrationTest test 7 locks the value at 500p.
 *
 * Architectural notes:
 *
 *   execute() is a forward-compat seam — Plan 10-04's RunPricingAgentJob
 *   owns orchestration via the framework's RunAgentJob pattern (per
 *   RESEARCH §Pattern 2). EchoAgent's deleted execute() did the same;
 *   PricingAgent inherits the pattern verbatim. Throwing LogicException
 *   here makes the architectural choice literal — any caller mistakenly
 *   invoking execute() trips immediately.
 *
 *   guardrails() returns 2 of 3 framework guardrails — PromptInjectionXmlFenceGuardrail
 *   is skipped because GuardrailEngine's TrustTier filter excludes it for
 *   Trusted tier (admin-triggered, no customer input). SensitiveFieldsStripGuardrail
 *   strips margin/cost fields per-tool I/O so the LLM never SEES them.
 *   OutboundRegexFilterGuardrail fires post-flight on the Anthropic
 *   response.
 *
 *   System prompt lives at resources/views/agents/pricing/system.blade.php
 *   (Plan 10-03 ships the real view per CONTEXT Claude's Discretion).
 *   Plan 10-01 ships only a `.gitkeep` placeholder so the directory exists.
 *   PromptRenderer throws RuntimeException on missing view — this is
 *   intentional and safe because systemPrompt() is invoked by RunAgentJob
 *   (Plan 10-04), not during Plan 10-01's container-resolution tests.
 */
final class PricingAgent implements RunsAsAgent
{
    public function __construct(
        private readonly PromptRenderer $promptRenderer,
    ) {}

    public static function kind(): string
    {
        return 'pricing';
    }

    public static function trustTier(): TrustTier
    {
        return TrustTier::Trusted;
    }

    /** @return array<int, \App\Domain\Agents\Services\Tools\Tool> */
    public function tools(): array
    {
        return [
            app(ReadMarginHistoryTool::class),
            app(ReadCompetitorPricesTool::class),
            app(ReadSupplierPriceTrendTool::class),
            app(ReadSalesVolume90dTool::class),
            app(ProposeMarginBandTool::class),
        ];
    }

    /** @param  array<string, mixed>  $context */
    public function systemPrompt(array $context = []): string
    {
        return $this->promptRenderer->render(self::kind(), $context)['prompt'];
    }

    /** @return array<int, \App\Domain\Agents\Contracts\Guardrail> */
    public function guardrails(): array
    {
        // Trusted tier — PromptInjectionXmlFenceGuardrail skipped via shouldRun()
        // (see PromptInjectionXmlFenceGuardrail Phase 8 Plan 03). Sensitive-field
        // strip is per-tool (not chain-driven) but listed here for
        // architecture-test discoverability. OutboundRegex fires post-flight.
        return [
            app(SensitiveFieldsStripGuardrail::class),
            app(OutboundRegexFilterGuardrail::class),
        ];
    }

    /**
     * Forward-compat seam — Plan 10-04 RunPricingAgentJob owns orchestration
     * and never calls this method. Same pattern as deleted EchoAgent::execute()
     * per RESEARCH §Pattern 2.
     *
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input, TrustTier $tier): AgentResult
    {
        throw new \LogicException(
            'PricingAgent::execute is a stub — RunPricingAgentJob (Plan 10-04) owns the orchestration.'
        );
    }
}
