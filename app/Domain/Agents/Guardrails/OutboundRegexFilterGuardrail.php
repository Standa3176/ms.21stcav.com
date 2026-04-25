<?php

declare(strict_types=1);

namespace App\Domain\Agents\Guardrails;

use App\Domain\Agents\Clients\ClaudeResponse;
use App\Domain\Agents\Contracts\Guardrail;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Exceptions\GuardrailViolationException;

/**
 * Phase 8 Plan 03 (AGNT-06) — post-flight outbound text filter.
 *
 * Scans the LLM's final response text for forbidden patterns:
 *   - cost_price / supplier_price / margin_bps with numeric value
 *   - internal email domains (@meetingstore.co.uk / @21stcav.co.uk)
 *   - internal hostnames / IP ranges (192.168.*, 10.*, 127.0.0.1)
 *
 * On match: throws GuardrailViolationException via fromGuardrail() static
 * factory so $exception->guardrailClass === self::class. Plan 04 RunAgentJob
 * catches and records onto AgentRun.guardrail_failures JSON (15th column,
 * ROADMAP success criterion #4).
 *
 * Note: this is a defence-in-depth check on top of SensitiveFieldsStripGuardrail.
 * If strip() leaves a cost_price in the input despite the per-tool gate,
 * the post-flight filter still blocks the response from leaving the agent
 * boundary. Belt + braces.
 */
final class OutboundRegexFilterGuardrail implements Guardrail
{
    public const FORBIDDEN_PATTERNS = [
        '/cost_price\s*[:=]\s*\d+/i',
        '/supplier_price\s*[:=]\s*\d+/i',
        '/margin_bps\s*[:=]\s*\d+/i',
        '/@(meetingstore|21stcav)\.co\.uk/i',
        '/192\.168\.|10\.|127\.0\.0\.1/',
    ];

    public function isPreFlight(): bool
    {
        return false;
    }

    public function isPostFlight(): bool
    {
        return true;
    }

    public function shouldRun(TrustTier $tier): bool
    {
        return true;
    }

    public function pre(array $input): array
    {
        return $input;
    }

    public function post(ClaudeResponse $response): ClaudeResponse
    {
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $response->text)) {
                throw GuardrailViolationException::fromGuardrail(
                    self::class,
                    "Outbound regex filter caught forbidden pattern in response text: {$pattern}"
                );
            }
        }

        return $response;
    }
}
