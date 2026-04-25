<?php

declare(strict_types=1);

namespace App\Domain\Agents\Guardrails;

use App\Domain\Agents\Clients\ClaudeResponse;
use App\Domain\Agents\Contracts\Guardrail;
use App\Domain\Agents\Enums\TrustTier;

/**
 * Phase 8 Plan 03 (AGNT-06) — per-tool I/O guardrail for sensitive fields.
 *
 * Operates at every individual tool invocation boundary (NOT chained at
 * agent level). ToolBus / RunAgentJob calls strip() on tool inputs + outputs
 * before logging onto AgentRun.tool_calls and before any data leaves the
 * server toward Anthropic.
 *
 * Strips: cost_price / supplier_price / margin / margin_bps / wholesale_price /
 * invoice_price — replaces values with the literal string '[REDACTED]'.
 *
 * Why per-tool not per-flow: the LLM should never SEE these fields. Stripping
 * post-flight is too late (the values already crossed the boundary).
 * Stripping pre-flight at the input would lose context (the agent might need
 * to reason about "this product has a margin set" without seeing the value).
 * Per-tool I/O strip is the only place that satisfies both constraints.
 *
 * isPreFlight + isPostFlight both false: this guardrail is invoked directly
 * from ToolBus, not via GuardrailEngine's chain. shouldRun returns true for
 * all tiers because the strip is a defence regardless of trust.
 */
final class SensitiveFieldsStripGuardrail implements Guardrail
{
    public const FORBIDDEN_KEYS = [
        'cost_price',
        'cost',
        'supplier_price',
        'supplier_cost',
        'margin',
        'margin_bps',
        'wholesale_price',
        'invoice_price',
    ];

    public function isPreFlight(): bool
    {
        return false;
    }

    public function isPostFlight(): bool
    {
        return false;
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
        return $response;
    }

    /**
     * Public utility called from ToolBus per-invocation. Recurses into nested
     * arrays so a deeply-nested cost_price still gets caught.
     *
     * @param  array<mixed, mixed>  $payload
     * @return array<mixed, mixed>
     */
    public function strip(array $payload): array
    {
        $out = [];
        foreach ($payload as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::FORBIDDEN_KEYS, true)) {
                $out[$k] = '[REDACTED]';

                continue;
            }
            $out[$k] = is_array($v) ? $this->strip($v) : $v;
        }

        return $out;
    }
}
