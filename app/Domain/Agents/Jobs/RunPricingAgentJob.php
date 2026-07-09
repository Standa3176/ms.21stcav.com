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
use App\Domain\Agents\Services\PricingAgentResultMapper;
use App\Domain\Agents\Services\PromptRenderer;
use App\Domain\Agents\Services\ToolBus;
use App\Domain\Integrations\Clients\ClaudeClient;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;

/**
 * Phase 10 Plan 04 — Path A SIBLING of Phase 8 RunAgentJob (RESEARCH §Pattern 1).
 *
 * SIBLING — NOT a subclass. Path B (extends RunAgentJob) was explicitly REJECTED
 * in RESEARCH §A9 because:
 *   - Phase 8 RunAgentJob's invariant is "single orchestration point + writes
 *     ONE shadow Suggestion via AgentSuggestionWriter". Subclassing would
 *     either widen that invariant (breaks the framework's contract) or fight
 *     it via overrides (cognitive load).
 *   - Path A keeps Phase 8 byte-identical (B-03 Phase 9 precedent: byte-
 *     identity tests catch silent regressions). Two ~50-LOC orchestrators
 *     with parallel structure are easier to reason about than one
 *     orchestrator with conditional dispatch.
 *
 * Two structural diffs from Phase 8 RunAgentJob:
 *   - $suggestionId is REQUIRED (not nullable like RunAgentJob's
 *     triggering_suggestion_id). PricingAgent ENRICHES an existing
 *     margin_change Suggestion; without one there's nothing to enrich.
 *   - Step 12: PricingAgentResultMapper::mergeIntoSuggestion REPLACES
 *     AgentSuggestionWriter::write. We never create new Suggestions; we
 *     fold the agent's `propose_margin_band` final args into the existing
 *     Suggestion.evidence agent_* keys (CONTEXT D-02 + D-06).
 *
 * Honours AGENT_WRITE_ENABLED=false by skipping the mapper merge entirely
 * (forensic-only run). The AgentRun row STILL persists regardless — admin
 * can still see what the agent would have proposed via the AgentRunResource
 * detail view (Phase 8). When ops flips the env var on, subsequent runs
 * land their enrichment via the mapper.
 *
 * Mapper failures are caught by the same \Throwable arm as Phase 8's
 * RunAgentJob — AgentRun.status=failed, exception is rethrown so Horizon
 * records the failure (RESEARCH Open Question 3 RESOLVED).
 */
final class RunPricingAgentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /** No retries — agent failures are terminal per Phase 8 CONTEXT D-02. */
    public int $tries = 1;

    /** 180s — covers a full multi-tool-call loop without timing out a normal Anthropic round-trip. */
    public int $timeout = 180;

    public function __construct(
        public readonly string $suggestionId,
        public readonly ?int $userId = null,
        public readonly ?string $triggeringCorrelationId = null,
    ) {
        // Horizon agents-supervisor (Phase 8 Plan 01 config/horizon.php).
        // PHP 8.4 trait-property type-compat: Queueable::$queue is untyped
        // public, so a typed property override is incompatible. onQueue()
        // sets the same property at construction time without the conflict.
        $this->onQueue('agents');
    }

    public function handle(
        AgentRegistry $registry,
        BudgetGuard $budgetGuard,
        ToolBus $toolBus,
        GuardrailEngine $guardrailEngine,
        ClaudeClient $client,
        PromptRenderer $promptRenderer,
        PricingAgentResultMapper $mapper,
    ): void {
        $suggestion = Suggestion::findOrFail($this->suggestionId);
        if ($suggestion->kind !== 'margin_change') {
            throw new \InvalidArgumentException(
                "PricingAgent only enriches margin_change suggestions; got kind={$suggestion->kind}"
            );
        }

        // Correlation thread (Phase 8 Pitfall I2): prefer the constructor-provided
        // value (Filament action passes the suggestion's correlation_id), then
        // Context (queue boundary hydration), then a fresh UUID.
        $correlationId = $this->triggeringCorrelationId
            ?: (string) (Context::get('correlation_id') ?? '')
            ?: (string) Str::uuid();

        // Ensure correlation_id is on the Context so IntegrationLogger
        // (called from ClaudeClient post-flight) satisfies the
        // integration_events.correlation_id NOT NULL constraint.
        Context::add('correlation_id', $correlationId);

        $agent = $registry->resolve('pricing');

        // Render the system prompt with the suggestion's sku + Phase 5
        // deterministic context as Blade variables. Plan 10-03's prompt is
        // currently static (zero {{ $variable }} interpolation) so the hash
        // stays deterministic; the context array is harmless here.
        $rendered = $promptRenderer->render('pricing', [
            'suggestion_id' => $suggestion->id,
            'sku' => (string) data_get($suggestion->evidence, 'sku', ''),
        ]);

        $run = AgentRun::create([
            'kind' => 'pricing',
            'status' => AgentRunStatus::Running->value,
            'triggering_suggestion_id' => $this->suggestionId,
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
            $budgetGuard->assertHasBudget('pricing');

            $tier = $agent::trustTier();
            // PricingAgent runs against trusted admin-pull input — the
            // SensitiveFieldsStrip + OutboundRegex guardrails on the agent
            // do the per-flight work; the input array here is meta-context.
            $sanitisedInput = $guardrailEngine->runPreFlight($agent, [], $tier);

            $userText = json_encode([
                'suggestion_id' => $suggestion->id,
                'sku' => (string) data_get($suggestion->evidence, 'sku', ''),
                'context' => [
                    'phase5_proposed_margin_bps' => (int) data_get($suggestion->evidence, 'proposed_margin_bps', 0),
                    'phase5_current_margin_bps' => (int) data_get($suggestion->evidence, 'our_current_margin_bps', 0),
                    'pricing_rule_scope' => (string) data_get($suggestion->evidence, 'pricing_rule.scope', 'global'),
                ],
            ], JSON_THROW_ON_ERROR);

            $messages = [new UserMessage($userText)];
            $prismTools = $toolBus->buildPrismTools($agent->tools(), $run);

            $response = $client->generate(
                systemPrompt: $rendered['prompt'],
                messages: $messages,
                tools: $prismTools,
            );

            // Post-flight guardrails (OutboundRegexFilter etc.)
            $guardrailPhase = 'post';
            $response = $guardrailEngine->runPostFlight($agent, $response, $tier);

            // Extract tool_calls JSON from response steps. Mirrors Phase 8
            // RunAgentJob::extractToolCallsFromSteps verbatim — same shape
            // (tool_name, inputs, outputs, tokens_used, latency_ms) so the
            // mapper's filter on tool_name='propose_margin_band' works
            // identically against agent_runs created by either job.
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

            $budgetGuard->recordSpend('pricing', $response->costPence);

            // Step 12 — Path A diff vs Phase 8 RunAgentJob.
            // Honour AGENT_WRITE_ENABLED=false (forensic-only run; AgentRun
            // forensics still persist via the update above).
            if ((bool) config('agents.write_enabled', false)) {
                $mapper->mergeIntoSuggestion($run->fresh(), $suggestion);
            } else {
                Log::info('PricingAgent run completed in shadow mode — enrichment NOT merged', [
                    'agent_run_id' => $run->id,
                    'suggestion_id' => $suggestion->id,
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
            $run->update([
                'status' => AgentRunStatus::GuardrailBlocked->value,
                'completed_at' => now(),
                'guardrail_failures' => [[
                    'guardrail' => $e->guardrailClass !== '' ? $e->guardrailClass : GuardrailViolationException::class,
                    'message' => $e->getMessage(),
                    'when' => $guardrailPhase,
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
     * Walk Prism's Step collection and extract tool calls + results for the
     * AgentRun.tool_calls JSON column. Mirrors Phase 8 RunAgentJob's verbatim
     * extraction so the mapper's filter on tool_name='propose_margin_band'
     * works identically.
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
