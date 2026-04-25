<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\Clients\ClaudeResponse;
use App\Domain\Agents\Contracts\Guardrail;
use App\Domain\Agents\Contracts\RunsAsAgent;
use App\Domain\Agents\Enums\TrustTier;

/**
 * Phase 8 Plan 03 (AGNT-06) — pre/post-flight guardrail chain orchestrator.
 *
 * Walks the agent's `guardrails()` collection in declared order, dispatching
 * `pre()` to each pre-flight guardrail before ClaudeClient::generate(...) and
 * `post()` to each post-flight guardrail after. First violation
 * short-circuits with `GuardrailViolationException`.
 *
 * TrustTier-aware: each guardrail's `shouldRun(TrustTier)` decides whether
 * it fires for the current run. PromptInjectionXmlFenceGuardrail returns
 * false for `Trusted` (skips for performance on admin-triggered Pricing/SEO
 * runs) and true for `Mixed`/`Untrusted` (Chatbot, Ad enrichment).
 *
 * Per-tool I/O guardrails (e.g. SensitiveFieldsStripGuardrail) are NOT
 * chain-driven — they fire from inside ToolBus::wrapWithInvocationLogger
 * because they operate at every individual tool invocation boundary. This
 * separation is intentional: per-flow guardrails reason about the whole
 * conversation; per-tool guardrails reason about a single I/O pair.
 */
final class GuardrailEngine
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function runPreFlight(RunsAsAgent $agent, array $input, TrustTier $tier): array
    {
        foreach ($agent->guardrails() as $guardrail) {
            if (! $guardrail instanceof Guardrail) {
                continue;
            }
            if (! $guardrail->isPreFlight() || ! $guardrail->shouldRun($tier)) {
                continue;
            }
            $input = $guardrail->pre($input);
        }

        return $input;
    }

    public function runPostFlight(RunsAsAgent $agent, ClaudeResponse $response, TrustTier $tier): ClaudeResponse
    {
        foreach ($agent->guardrails() as $guardrail) {
            if (! $guardrail instanceof Guardrail) {
                continue;
            }
            if (! $guardrail->isPostFlight() || ! $guardrail->shouldRun($tier)) {
                continue;
            }
            $response = $guardrail->post($response);
        }

        return $response;
    }
}
