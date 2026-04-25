<?php

declare(strict_types=1);

namespace App\Domain\Agents\Contracts;

use App\Domain\Agents\Clients\ClaudeResponse;
use App\Domain\Agents\Enums\TrustTier;

/**
 * Phase 8 Plan 03 — Guardrail contract (AGNT-06).
 *
 * Implementations declare which side of the LLM call they fire on
 * (pre-flight, post-flight, or both) and which trust tiers trigger them.
 * GuardrailEngine walks the agent's `guardrails()` collection and dispatches
 * pre/post in declared order; first violation short-circuits with
 * `GuardrailViolationException`.
 *
 * Three concrete guardrails ship in Plan 03:
 *   - PromptInjectionXmlFenceGuardrail (pre, only fires on Mixed/Untrusted)
 *   - SensitiveFieldsStripGuardrail    (per-tool I/O — operates inside ToolBus,
 *                                       not chained at agent level)
 *   - OutboundRegexFilterGuardrail     (post — catches forbidden text in response)
 */
interface Guardrail
{
    public function isPreFlight(): bool;

    public function isPostFlight(): bool;

    public function shouldRun(TrustTier $tier): bool;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>  Possibly-mutated input (e.g. XML-fenced strings).
     */
    public function pre(array $input): array;

    /**
     * @throws \App\Domain\Agents\Exceptions\GuardrailViolationException  on failure
     */
    public function post(ClaudeResponse $response): ClaudeResponse;
}
