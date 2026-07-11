<?php

declare(strict_types=1);

namespace App\Domain\Agents\Agents;

use App\Domain\Agents\Contracts\Guardrail;
use App\Domain\Agents\Contracts\RunsAsAgent;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Guardrails\OutboundRegexFilterGuardrail;
use App\Domain\Agents\Guardrails\SensitiveFieldsStripGuardrail;
use App\Domain\Agents\Services\PromptRenderer;
use App\Domain\Agents\Services\Tools\Tool;
use App\Domain\Agents\Tools\Marketing\ProposeMarketingActionTool;
use App\Domain\Agents\Tools\Marketing\ReadGa4ChannelPerformanceTool;
use App\Domain\Agents\Tools\Marketing\ReadMarginOpportunityTool;
use App\Domain\Agents\ValueObjects\AgentResult;

/**
 * Phase 15 Plan 15b-01 — third REAL RunsAsAgent consumer (after Phase 10
 * PricingAgent + Phase 12 SeoAgent). ADVICE-ONLY marketing analyst.
 *
 * Reads the GA4 channel snapshot (15a) + the app's own margin / competitor /
 * stock data and emits prioritised `ad_optimisation` Suggestions (via
 * AdOptimisationResultMapper, Task 5). It runs several times a day on a
 * schedule and is a SINGLE analysis run — there is no per-product loop and no
 * $productId (the one structural diff from SeoAgent).
 *
 * Contract surface:
 *
 *   kind()='ad_optimisation'.
 *   trustTier()=Trusted — scheduled on internal snapshot + own data, no
 *     customer input. Daily budget cap 300p via BudgetGuard reading
 *     config('agents.daily_caps.ad_optimisation').
 *   tools() — 2 read_* (GA4 channel perf + margin opportunity) + 1 propose_*
 *     (propose_marketing_action).
 *   guardrails() — the same 2 the PricingAgent uses
 *     (SensitiveFieldsStripGuardrail per-tool I/O + OutboundRegexFilterGuardrail
 *     post-flight); PromptInjectionXmlFenceGuardrail is filtered out for
 *     Trusted tier inside GuardrailEngine.
 *
 * Advice-only invariant: the agent's ONLY side effect is writing
 * `ad_optimisation` Suggestions (shadow-gated). NO Google Ads writes, NO
 * ad_budget_overrides, NO GCLID, NO closed-loop (all 15c). Approving an
 * `ad_optimisation` Suggestion is acknowledgement only — no apply path.
 *
 * execute() is a forward-compat seam — RunAdOptimisationJob (Task 4) owns
 * orchestration, mirroring the PricingAgent/SeoAgent LogicException pattern.
 */
final class AdOptimisationAgent implements RunsAsAgent
{
    public function __construct(
        private readonly PromptRenderer $promptRenderer,
    ) {}

    public static function kind(): string
    {
        return 'ad_optimisation';
    }

    public static function trustTier(): TrustTier
    {
        return TrustTier::Trusted;
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [
            app(ReadGa4ChannelPerformanceTool::class),
            app(ReadMarginOpportunityTool::class),
            app(ProposeMarketingActionTool::class),
        ];
    }

    /** @param  array<string, mixed>  $context */
    public function systemPrompt(array $context = []): string
    {
        return $this->promptRenderer->render(self::kind(), $context)['prompt'];
    }

    /**
     * @return array<int, Guardrail>
     *
     * Same 2 guardrails as PricingAgent — Trusted tier skips
     * PromptInjectionXmlFenceGuardrail via GuardrailEngine's tier filter.
     * SensitiveFieldsStrip is per-tool I/O; OutboundRegex fires post-flight.
     */
    public function guardrails(): array
    {
        return [
            app(SensitiveFieldsStripGuardrail::class),
            app(OutboundRegexFilterGuardrail::class),
        ];
    }

    /**
     * Forward-compat seam — RunAdOptimisationJob (Task 4) owns orchestration
     * and never calls this method. Same pattern as PricingAgent/SeoAgent.
     *
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input, TrustTier $tier): AgentResult
    {
        throw new \LogicException(
            'AdOptimisationAgent::execute is a stub — RunAdOptimisationJob owns the orchestration.'
        );
    }
}
