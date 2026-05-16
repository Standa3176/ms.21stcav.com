<?php

declare(strict_types=1);

namespace App\Domain\Agents\Agents;

use App\Domain\Agents\Contracts\RunsAsAgent;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Services\PromptRenderer;
use App\Domain\Agents\Tools\Seo\ProposeContentPatchTool;
use App\Domain\Agents\Tools\Seo\ReadBrandStyleGuideTool;
use App\Domain\Agents\Tools\Seo\ReadProductDraftTool;
use App\Domain\Agents\Tools\Seo\ReadSimilarShippedProductsTool;
use App\Domain\Agents\ValueObjects\AgentResult;

/**
 * Phase 12 — second REAL RunsAsAgent consumer of the Phase 8 framework
 * (after Phase 10 PricingAgent).
 *
 * Replaces no prior fixture — Phase 8's EchoAgent was deleted in Phase 10
 * Plan 01's P10-H sweep. The Phase 8 framework primitives (AgentRegistry,
 * BudgetGuard, ToolBus, GuardrailEngine, ClaudeClient, AgentRun, the shared
 * relocated TruncatingTool) all stay byte-identical. SeoAgent simply
 * implements RunsAsAgent and registers itself under kind 'seo'.
 *
 * Contract surface:
 *
 *   SEOAGT-01 — kind='seo', triggered batch nightly at 04:30 London
 *               (Plan 12-05 ships RunSeoAgentBatchCommand). Eligibility
 *               query: Product::where('auto_create_status', 'pending_review')
 *               ->where('completeness_score', '<', 85)
 *               ->whereDoesntHave('suggestions', fn ($q) => $q
 *                   ->where('kind', 'seo_content_patch')
 *                   ->whereIn('status', ['pending', 'applied'])
 *               )->limit(20).
 *
 *   SEOAGT-02 — 4 tools (3 read_* + 1 propose_*). Plan 12-01 ships
 *               compile-time stubs; Plan 12-02 swaps the using() callable
 *               bodies for real DB queries / file reads (3 KB caps +
 *               _truncated hints where applicable). propose_content_patch
 *               is the variable-cardinality bundled-Suggestion writer
 *               (called 1-4 times per run — see Plan 12-04 mapper).
 *
 *   SEOAGT-05 — TrustTier::Trusted (batch-triggered on internal supplier
 *               data + AutoCreate drafts; no customer input). Daily budget
 *               cap 300p via BudgetGuard reading config('agents.daily_caps.seo').
 *
 * Architectural notes:
 *
 *   execute() is a forward-compat seam — Plan 12-04's RunSeoAgentJob owns
 *   orchestration via the framework's RunAgentJob-style pattern (RESEARCH
 *   §Pattern 1). PricingAgent::execute() inherits the same LogicException
 *   pattern — SeoAgent mirrors verbatim.
 *
 *   guardrails() returns [] in Plan 12-01 — Plan 12-03 fills with
 *   [SensitiveFieldsStripGuardrail, OutboundRegexFilterGuardrail,
 *   SeoOutboundGuardrail]. The empty array here keeps the framework happy
 *   during Plan 12-01 container-resolution tests (guardrails are filtered
 *   per trust tier inside GuardrailEngine; an empty list bypasses cleanly).
 *
 *   System prompt lives at resources/views/agents/seo/system.blade.php
 *   (Plan 12-03 ships the real view). Plan 12-01 does NOT ship the Blade
 *   view — systemPrompt() is invoked by RunSeoAgentJob (Plan 12-04), not
 *   during Plan 12-01's container-resolution smoke tests. PromptRenderer's
 *   RuntimeException on missing view is intentional and safe here.
 */
final class SeoAgent implements RunsAsAgent
{
    public function __construct(
        private readonly PromptRenderer $promptRenderer,
    ) {}

    public static function kind(): string
    {
        return 'seo';
    }

    public static function trustTier(): TrustTier
    {
        return TrustTier::Trusted;
    }

    /** @return array<int, \App\Domain\Agents\Services\Tools\Tool> */
    public function tools(): array
    {
        return [
            app(ReadProductDraftTool::class),
            app(ReadBrandStyleGuideTool::class),
            app(ReadSimilarShippedProductsTool::class),
            app(ProposeContentPatchTool::class),
        ];
    }

    /** @param  array<string, mixed>  $context */
    public function systemPrompt(array $context = []): string
    {
        return $this->promptRenderer->render(self::kind(), $context)['prompt'];
    }

    /**
     * @return array<int, \App\Domain\Agents\Contracts\Guardrail>
     *
     * Plan 12-01 returns [] — Plan 12-03 adds:
     *   - SensitiveFieldsStripGuardrail (per-tool I/O strip)
     *   - OutboundRegexFilterGuardrail (post-flight base regex)
     *   - SeoOutboundGuardrail (post-flight SEO brand-voice regex)
     */
    public function guardrails(): array
    {
        return [];
    }

    /**
     * Forward-compat seam — Plan 12-04 RunSeoAgentJob owns orchestration
     * and never calls this method. Same pattern as PricingAgent::execute()
     * per RESEARCH §Pattern 1.
     *
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input, TrustTier $tier): AgentResult
    {
        throw new \LogicException(
            'SeoAgent::execute is a stub — RunSeoAgentJob (Plan 12-04) owns the orchestration.'
        );
    }
}
