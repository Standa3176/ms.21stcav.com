<?php

declare(strict_types=1);

namespace App\Domain\Agents\Exceptions;

/**
 * Phase 8 Plan 03 — thrown by GuardrailEngine (and concrete guardrails) when
 * a pre/post-flight rule blocks the run (AGNT-06).
 *
 * The `fromGuardrail()` static factory captures which guardrail class fired
 * the violation. Plan 04 RunAgentJob reads `$exception->guardrailClass` to
 * record onto AgentRun.guardrail_failures JSON column (15th column added per
 * plan-checker iter 1 — ROADMAP success criterion #4 — surfaces in Filament
 * AgentRunResource detail view).
 *
 * The `when` property tags pre vs post for downstream filtering ("show me
 * runs blocked at pre-flight by PromptInjectionXmlFence") — Plan 04 sets
 * this when catching the throwable inside the GuardrailEngine call site.
 *
 * Phase 12 Plan 03 — ADDITIVE extension for SeoOutboundGuardrail:
 *   - $failedPatternKey: the config('seo_agent.guardrails') key whose regex
 *     matched (e.g. 'marketing_superlatives'). Plan 12-04 RunSeoAgentJob
 *     catch-block reads this to call
 *     SeoAgentResultMapper::createGuardrailBlockedSuggestion(...) (P12-B
 *     mitigation per RESEARCH §Pattern 7 Option B — guardrail does not
 *     write Suggestions directly; the catching job does).
 *   - $matchedExcerpt: up to 200 chars of $m[0] from the regex match — the
 *     forensic excerpt for the agent_guardrail_blocked Suggestion's
 *     evidence.matched_excerpt field.
 *
 * Both new fields are readonly and default to empty string so Phase 10's
 * existing construction paths (`fromGuardrail()` factory + RunPricingAgentJob
 * catch-site direct `->guardrailClass` access) continue to compile
 * byte-identically (zero Phase 10 regression).
 */
final class GuardrailViolationException extends \RuntimeException
{
    public string $guardrailClass = '';

    /** 'pre' | 'post' — RunAgentJob sets this in the engine catch-block. */
    public string $when = '';

    public readonly string $failedPatternKey;

    public readonly string $matchedExcerpt;

    public function __construct(
        string $guardrailClass = '',
        string $message = '',
        string $failedPatternKey = '',
        string $matchedExcerpt = '',
    ) {
        parent::__construct($message);
        $this->guardrailClass = $guardrailClass;
        $this->failedPatternKey = $failedPatternKey;
        $this->matchedExcerpt = $matchedExcerpt;
    }

    public static function fromGuardrail(string $guardrailClass, string $message): self
    {
        return new self($guardrailClass, $message);
    }
}
