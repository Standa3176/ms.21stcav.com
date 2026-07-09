<?php

declare(strict_types=1);

namespace App\Domain\Agents\Jobs;

use App\Domain\Agents\Enums\AgentRunStatus;
use App\Domain\Agents\Events\AgentRunCompleted;
use App\Domain\Agents\Events\AgentRunFailed;
use App\Domain\Agents\Events\AgentRunStarted;
use App\Domain\Agents\Exceptions\BudgetExceededException;
use App\Domain\Agents\Exceptions\GuardrailViolationException;
use App\Domain\Agents\Exceptions\MonthlyBudgetExceededException;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Services\AgentRegistry;
use App\Domain\Agents\Services\BudgetGuard;
use App\Domain\Agents\Services\GuardrailEngine;
use App\Domain\Agents\Services\PromptRenderer;
use App\Domain\Agents\Services\SeoAgentResultMapper;
use App\Domain\Agents\Services\ToolBus;
use App\Domain\Agents\Support\BrandSlugResolver;
use App\Domain\Integrations\Clients\ClaudeClient;
use App\Domain\Products\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;

/**
 * Phase 12 Plan 04 — Path A SIBLING of Phase 8 RunAgentJob (RESEARCH §Pattern 1).
 *
 * Three structural diffs from Phase 10 RunPricingAgentJob (RESEARCH §Pattern 1):
 *
 *   1. $productId is REQUIRED (not nullable like RunAgentJob's
 *      triggering_suggestion_id). SeoAgent ENRICHES an existing Phase 6
 *      AutoCreate draft; without a Product ID there's nothing to patch.
 *   2. Step 12 invokes $mapper->createBundledSuggestion(...) which creates
 *      ONE Suggestion of kind 'seo_content_patch' aggregating ALL
 *      propose_content_patch calls. Phase 10's mapper UPDATES existing
 *      Suggestion.evidence; Phase 12 CREATES a fresh Suggestion per run.
 *   3. triggering_suggestion_id is null (Phase 12 is batch-driven, not
 *      suggestion-pull). triggering_correlation_id is per-product.
 *
 * P12-B mitigation (RESEARCH §Pattern 7 Option B + CONTEXT D-01):
 *   catch(GuardrailViolationException) calls
 *   $mapper->createGuardrailBlockedSuggestion(... $e->failedPatternKey,
 *   $e->matchedExcerpt) BEFORE rethrowing. The SeoOutboundGuardrail
 *   (Plan 12-03) is stateless (scan + throw); the audit-trail Suggestion
 *   write is the catching job's responsibility. NO partial publishing —
 *   first forbidden match aborts the entire run; the mapper writes ONE
 *   'agent_guardrail_blocked' Suggestion + exception rethrown so Horizon
 *   records the failure.
 *
 * Honours AGENT_WRITE_ENABLED=false by skipping the mapper invocation
 * entirely (forensic-only run). The AgentRun row STILL persists regardless —
 * admin can still see what the agent would have proposed via the
 * AgentRunResource detail view (Phase 8).
 *
 * Mirrors Phase 10 byte-identically for:
 *   - $tries=1 (agent failures are terminal per Phase 8 CONTEXT D-02)
 *   - $timeout=180 (covers a full multi-tool-call loop)
 *   - onQueue('agents') in constructor (Horizon agents-supervisor)
 *   - 13-step orchestration sequence (eligibility re-check, correlation
 *     thread, registry resolve, prompt render, AgentRun::create, event
 *     dispatch, BudgetGuard, GuardrailEngine pre/post, Prism::generate,
 *     tool_call extraction, AgentRun.update, BudgetGuard.recordSpend,
 *     conditional mapper invocation per AGENT_WRITE_ENABLED).
 */
final class RunSeoAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly int $productId,
        public readonly ?string $batchCorrelationId = null,
    ) {
        // Horizon agents-supervisor (Phase 8 Plan 01 config/horizon.php).
        $this->onQueue('agents');
    }

    public function handle(
        AgentRegistry $registry,
        BudgetGuard $budgetGuard,
        ToolBus $toolBus,
        GuardrailEngine $guardrailEngine,
        ClaudeClient $client,
        PromptRenderer $promptRenderer,
        SeoAgentResultMapper $mapper,
    ): void {
        $product = Product::findOrFail($this->productId);

        // Defensive eligibility re-check — a competing job/admin action may have
        // flipped the status to published/rejected since dispatch. We refuse to
        // run the agent on anything other than a pending_review draft.
        if ($product->auto_create_status !== 'pending_review') {
            Log::info('SeoAgent: product no longer eligible — skipping run', [
                'product_id' => $product->id,
                'auto_create_status' => (string) $product->auto_create_status,
            ]);

            return;
        }

        // Correlation thread (Phase 8 Pitfall I2): prefer the batch-supplied
        // correlation_id (RunSeoAgentBatchCommand passes one per batch),
        // then Context (queue boundary hydration), then a fresh UUID.
        $correlationId = $this->batchCorrelationId
            ?: (string) (Context::get('correlation_id') ?? '')
            ?: (string) Str::uuid();

        // Ensure correlation_id is on the Context so IntegrationLogger
        // (called from ClaudeClient post-flight) satisfies the
        // integration_events.correlation_id NOT NULL constraint.
        Context::add('correlation_id', $correlationId);

        $agent = $registry->resolve('seo');

        // Render the system prompt with product context. Plan 12-03's prompt
        // is currently static (zero `{{ $variable }}` interpolation) so the
        // hash stays deterministic; the context array is harmless here but
        // sets up future per-product personalisation cleanly.
        $rendered = $promptRenderer->render('seo', [
            'product_id' => $product->id,
            'sku' => (string) $product->sku,
            'brand_slug' => $this->brandSlug($product),
        ]);

        $run = AgentRun::create([
            'kind' => 'seo',
            'status' => AgentRunStatus::Running->value,
            'triggering_suggestion_id' => null,  // batch-driven
            'triggering_correlation_id' => $correlationId,
            'system_prompt_hash' => $rendered['hash'],
            'tool_calls' => [],
            'started_at' => now(),
        ]);

        event(new AgentRunStarted($run));

        // Track which guardrail phase we're in so the GuardrailViolationException
        // catch records `when: 'pre' | 'post'` correctly (Phase 8 BLOCKER 1 pattern).
        $guardrailPhase = 'pre';

        try {
            $budgetGuard->assertHasBudget('seo');

            $tier = $agent::trustTier();
            // Trusted tier — pre-flight chain is essentially a sanity check.
            $sanitisedInput = $guardrailEngine->runPreFlight($agent, [], $tier);

            $userText = json_encode([
                'product_id' => $product->id,
                'sku' => (string) $product->sku,
                'context' => [
                    'completeness_score' => (int) ($product->completeness_score ?? 0),
                    'completeness_missing_fields' => (array) ($product->completeness_missing_fields ?? []),
                    'brand_slug' => $this->brandSlug($product),
                ],
            ], JSON_THROW_ON_ERROR);

            $messages = [new UserMessage($userText)];
            $prismTools = $toolBus->buildPrismTools($agent->tools(), $run);

            $response = $client->generate(
                systemPrompt: $rendered['prompt'],
                messages: $messages,
                tools: $prismTools,
                temperature: (float) config('agents.seo.temperature', 0.4),
            );

            // Post-flight guardrails — including SeoOutboundGuardrail (Plan
            // 12-03 index 2 in SeoAgent::guardrails()) which scans every
            // propose_content_patch call's before+after against the 13-pattern
            // starter library at config('seo_agent.guardrails').
            $guardrailPhase = 'post';
            $response = $guardrailEngine->runPostFlight($agent, $response, $tier);

            // Mirror Phase 10 RunPricingAgentJob:178 — extract tool_calls JSON.
            $toolCallsLog = $this->extractToolCallsFromSteps($response->steps, $toolBus);

            // Persist run completion BEFORE invoking the mapper so the AgentRun
            // is final truth even if the mapper write fails.
            $run->update([
                'status' => AgentRunStatus::Completed->value,
                'completed_at' => now(),
                'finish_reason' => $response->finishReason->value,
                'tool_calls' => $toolCallsLog,
                'agent_reasoning_summary' => mb_substr($response->text ?? '', 0, 8192),
                'prompt_token_count' => $response->promptTokens,
                'completion_token_count' => $response->completionTokens,
                'cost_pence' => $response->costPence,
                'langfuse_trace_id' => $response->langfuseTraceId,
            ]);

            $budgetGuard->recordSpend('seo', $response->costPence);

            // Step 12 — variable-cardinality bundled-Suggestion write (Path A
            // diff #2 vs Phase 10). Skipped in shadow mode; AgentRun forensics
            // STILL persist via the $run->update above.
            if ((bool) config('agents.write_enabled', false)) {
                $mapper->createBundledSuggestion($run->fresh(), $product);
            } else {
                Log::info('SeoAgent run completed in shadow mode — Suggestion NOT created', [
                    'agent_run_id' => $run->id,
                    'product_id' => $product->id,
                ]);
            }

            event(new AgentRunCompleted($run->fresh()));
        } catch (MonthlyBudgetExceededException $e) {
            $run->update([
                'status' => AgentRunStatus::MonthlyBudgetBlocked->value,
                'completed_at' => now(),
                'agent_reasoning_summary' => mb_substr($e->getMessage(), 0, 8192),
            ]);
            event(new AgentRunFailed($run->fresh(), $e));
            throw $e;
        } catch (BudgetExceededException $e) {
            $run->update([
                'status' => AgentRunStatus::BudgetExceeded->value,
                'completed_at' => now(),
                'agent_reasoning_summary' => mb_substr($e->getMessage(), 0, 8192),
            ]);
            event(new AgentRunFailed($run->fresh(), $e));
            throw $e;
        } catch (GuardrailViolationException $e) {
            // P12-B CRITICAL — write the audit Suggestion BEFORE rethrowing.
            // Plan 12-03 extended GuardrailViolationException with
            // $failedPatternKey + $matchedExcerpt readonly fields populated
            // by SeoOutboundGuardrail::post(). Honour the contract regardless
            // of shadow-mode (audit-trail integrity does NOT depend on
            // AGENT_WRITE_ENABLED — the audit Suggestion is forensic).
            try {
                $mapper->createGuardrailBlockedSuggestion(
                    $run->fresh(),
                    $product,
                    $e->failedPatternKey,
                    $e->matchedExcerpt,
                );
            } catch (\Throwable $auditFail) {
                // Defensive — never let the audit-write failure mask the
                // original guardrail violation. Log + continue.
                Log::error('SeoAgent: createGuardrailBlockedSuggestion failed', [
                    'agent_run_id' => $run->id,
                    'audit_error' => $auditFail->getMessage(),
                ]);
            }

            $run->update([
                'status' => AgentRunStatus::GuardrailBlocked->value,
                'completed_at' => now(),
                'guardrail_failures' => [[
                    'guardrail' => $e->guardrailClass !== '' ? $e->guardrailClass : GuardrailViolationException::class,
                    'message' => $e->getMessage(),
                    'when' => $guardrailPhase,
                    'failed_pattern_key' => $e->failedPatternKey,
                    'matched_excerpt' => $e->matchedExcerpt,
                    'occurred_at' => now()->toIso8601String(),
                ]],
                'agent_reasoning_summary' => mb_substr($e->getMessage(), 0, 8192),
            ]);
            event(new AgentRunFailed($run->fresh(), $e));
            throw $e;
        } catch (\Throwable $e) {
            $run->update([
                'status' => AgentRunStatus::Failed->value,
                'completed_at' => now(),
                'agent_reasoning_summary' => mb_substr($e->getMessage(), 0, 8192),
            ]);
            event(new AgentRunFailed($run->fresh(), $e));
            throw $e;
        }
    }

    /**
     * Delegate to BrandSlugResolver (Plan 12-02 deliverable) so this job and
     * the ReadProductDraftTool use the SAME slug-derivation path (P12-C
     * mitigation — single source of truth for brand_id → slug routing).
     */
    private function brandSlug(Product $product): string
    {
        return BrandSlugResolver::forBrandId(
            $product->brand_id === null ? null : (int) $product->brand_id
        );
    }

    /**
     * Walk Prism's Step collection and extract tool calls + results for the
     * AgentRun.tool_calls JSON column. Mirrors Phase 10 RunPricingAgentJob
     * verbatim so SeoAgentResultMapper's filter on tool_name='propose_content_patch'
     * works identically against agent_runs created by either job.
     *
     * @param  iterable<int, mixed>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function extractToolCallsFromSteps(iterable $steps, ToolBus $toolBus): array
    {
        $log = [];
        foreach ($steps as $step) {
            $toolCalls = $this->readStepProperty($step, 'toolCalls') ?? [];
            $toolResults = $this->readStepProperty($step, 'toolResults') ?? [];

            foreach ($toolCalls as $i => $call) {
                $name = $this->readStepProperty($call, 'name') ?? 'unknown';
                $args = $this->readStepProperty($call, 'arguments') ?? '{}';

                if (is_array($args)) {
                    $args = (string) json_encode($args);
                }

                $result = $toolResults[$i] ?? null;
                $resultValue = $result === null
                    ? ''
                    : ($this->readStepProperty($result, 'result') ?? '');
                if (is_array($resultValue) || is_object($resultValue)) {
                    $resultValue = (string) json_encode($resultValue);
                }

                $log[] = [
                    'tool_name' => (string) $name,
                    'inputs' => $toolBus->truncate((string) $args, ToolBus::MAX_INPUT_BYTES),
                    'outputs' => $toolBus->truncate((string) $resultValue, ToolBus::MAX_OUTPUT_BYTES),
                    'tokens_used' => 0,
                    'latency_ms' => 0,
                ];
            }
        }

        return $log;
    }

    private function readStepProperty(mixed $obj, string $name): mixed
    {
        if (is_object($obj)) {
            // Prism v0.100.1 ToolCall stores arguments via arguments() method.
            if ($obj instanceof ToolCall && $name === 'arguments') {
                return $obj->arguments();
            }

            return property_exists($obj, $name) ? $obj->{$name} : null;
        }
        if (is_array($obj)) {
            return $obj[$name] ?? null;
        }

        return null;
    }
}
