<?php

declare(strict_types=1);

namespace App\Domain\Agents\Clients;

use App\Domain\Agents\Services\CostCalculator;
use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Support\Facades\Context;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

/**
 * Phase 8 Plan 02 (AGNT-07) — sole wrapper around prism-php/prism for Anthropic
 * calls. Every agent's LLM traffic flows through here so:
 *
 *   1. IntegrationLogger captures the HTTP shape into integration_events
 *      (matches the v1 WooClient + BitrixClient pattern; satisfies
 *      "Audit everything" PROJECT.md constraint).
 *   2. BudgetGuard (Plan 03) records actuals post-flight via the cost_pence
 *      that this client computes inline.
 *   3. The mliviu79/laravel-langfuse-prism shim auto-instruments the underlying
 *      Prism HTTP request so the Langfuse trace_id ends up on Laravel Context;
 *      ClaudeClient reads it back here for the AgentRun row's
 *      langfuse_trace_id column (D-06 schema).
 *
 * Default tunables match AGNT-07: claude-sonnet-4-6, temperature=0.0,
 * withMaxSteps(8), withMaxTokens(4000). Per-call overrides for advanced agents
 * (Phase 14 chatbot may want temperature ≠ 0); Phase 10 PricingAgent + Phase 12
 * SeoAgent should stay at defaults.
 *
 * Architecture invariant: the AgentsWriteOnlyViaSuggestionsTest greps every file
 * under app/Domain/Agents/** for `Http::post` against any anthropic.* URL —
 * matches anywhere except inside this file's vendor traffic via Prism. The
 * grep allow-list explicitly carves out `Prism::text()` calls so this is the
 * one and only place the Anthropic API gets hit.
 */
final class ClaudeClient
{
    public const DEFAULT_MODEL = 'claude-sonnet-4-6';

    public const DEFAULT_MAX_STEPS = 8;

    public const DEFAULT_MAX_TOKENS = 4000;

    public const DEFAULT_TEMPERATURE = 0.0;

    public function __construct(
        private readonly CostCalculator $costCalculator,
        private readonly IntegrationLogger $logger,
    ) {}

    /**
     * @param  array<int, \Prism\Prism\Contracts\Message>  $messages  UserMessage/AssistantMessage/ToolResultMessage chain
     * @param  array<int, \Prism\Prism\Tool>  $tools     Optional Prism tool definitions (Plan 03 ToolBus wraps these)
     * @param  ?string  $model     Override default model — usually null; agents lock to claude-sonnet-4-6
     */
    public function generate(
        string $systemPrompt,
        array $messages,
        array $tools = [],
        ?string $model = null,
        int $maxSteps = self::DEFAULT_MAX_STEPS,
        int $maxTokens = self::DEFAULT_MAX_TOKENS,
        float $temperature = self::DEFAULT_TEMPERATURE,
    ): ClaudeResponse {
        $model ??= (string) config('agents.default_model', self::DEFAULT_MODEL);

        $request = Prism::text()
            ->using(Provider::Anthropic, $model)
            ->withSystemPrompt($systemPrompt)
            ->withMessages($messages)
            ->withMaxSteps($maxSteps)
            ->withMaxTokens($maxTokens)
            ->usingTemperature($temperature)
            // 2 retries with 100ms delay — Prism native; covers the transient
            // Anthropic 5xx blip without burning a second tool-loop iteration.
            ->withClientRetry(2, 100);

        if (! empty($tools)) {
            $request = $request->withTools($tools);
        }

        $startedAt = microtime(true);
        $response = $request->asText();
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $promptTokens = (int) ($response->usage->promptTokens ?? 0);
        $completionTokens = (int) ($response->usage->completionTokens ?? 0);
        $costPence = $this->costCalculator->compute($promptTokens, $completionTokens, $model);

        // Log into integration_events. IntegrationLogger auto-attaches the
        // correlation_id from Context (set by RunAgentJob in Plan 04) and
        // redacts the Authorization header before persistence (T-08-02-01).
        $this->logger->log([
            'channel' => 'anthropic',
            'operation' => 'messages.create',
            'endpoint' => '/v1/messages',
            'method' => 'POST',
            'http_status' => 200,
            'latency_ms' => $latencyMs,
            'status' => 'ok',
            'response_body' => [
                'model' => $model,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'cost_pence' => $costPence,
                'finish_reason' => $response->finishReason->name,
            ],
        ]);

        return new ClaudeResponse(
            text: (string) ($response->text ?? ''),
            finishReason: ClaudeResponse::mapFinishReason($response->finishReason->name),
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            costPence: $costPence,
            langfuseTraceId: $this->extractLangfuseTraceId(),
            toolCalls: $response->toolCalls ?? [],
            steps: $response->steps ?? collect(),
            responseMessages: $response->messages ?? collect(),
        );
    }

    /**
     * mliviu79/laravel-langfuse-prism pushes the Langfuse trace_id onto
     * Laravel Context after each Prism call. We read it back here so the
     * AgentRun row's langfuse_trace_id column gets populated (deep-link from
     * Filament AgentRunResource detail view → Langfuse trace UI).
     *
     * Open Question Q2 (RESOLVED) — RESEARCH §Open Questions:
     *   - Plan 02 Task 2 Test 10 verifies this Context::get path against
     *     Prism::fake(). When the shim is absent (or test mode bypasses HTTP
     *     instrumentation), this method returns null and the AgentRun row
     *     records a null trace_id — not a hard failure.
     *   - Fallback path: if the shim breaks (single-maintainer 115-installs
     *     bus-factor risk), ops can swap to AGENTS_OBSERVABILITY_DRIVER=custom-otel
     *     per docs/ops/observability.md. Custom-OTel exporter pulls the trace
     *     id from the Prism HTTP response's X-Langfuse-Trace-Id header via a
     *     Prism middleware hook.
     */
    private function extractLangfuseTraceId(): ?string
    {
        $traceId = Context::get('langfuse_trace_id');

        return is_string($traceId) ? $traceId : null;
    }
}
