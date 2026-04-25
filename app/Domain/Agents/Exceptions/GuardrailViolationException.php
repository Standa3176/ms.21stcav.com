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
 */
final class GuardrailViolationException extends \RuntimeException
{
    public string $guardrailClass = '';

    /** 'pre' | 'post' — RunAgentJob sets this in the engine catch-block. */
    public string $when = '';

    public static function fromGuardrail(string $guardrailClass, string $message): self
    {
        $e = new self($message);
        $e->guardrailClass = $guardrailClass;

        return $e;
    }
}
