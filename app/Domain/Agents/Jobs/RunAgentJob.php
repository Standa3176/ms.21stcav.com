<?php

declare(strict_types=1);

namespace App\Domain\Agents\Jobs;

use App\Domain\Agents\Clients\ClaudeClient;
use App\Domain\Agents\Enums\AgentRunStatus;
use App\Domain\Agents\Events\AgentRunCompleted;
use App\Domain\Agents\Events\AgentRunFailed;
use App\Domain\Agents\Events\AgentRunStarted;
use App\Domain\Agents\Exceptions\BudgetExceededException;
use App\Domain\Agents\Exceptions\GuardrailViolationException;
use App\Domain\Agents\Exceptions\MonthlyBudgetExceededException;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Services\AgentRegistry;
use App\Domain\Agents\Services\AgentSuggestionWriter;
use App\Domain\Agents\Services\BudgetGuard;
use App\Domain\Agents\Services\GuardrailEngine;
use App\Domain\Agents\Services\PromptRenderer;
use App\Domain\Agents\Services\ToolBus;
use App\Domain\Agents\ValueObjects\SuggestionDraft;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Phase 8 Plan 04 (AGNT-12) — the framework's single orchestration point.
 *
 * Sequence (per CONTEXT D-01..D-09 + plan-checker iter 1 BLOCKER 1):
 *   1. Resolve agent class from AgentRegistry
 *   2. Render system prompt via PromptRenderer (returns prompt + sha256 hash)
 *   3. Persist AgentRun row with status='running' + started_at
 *   4. event(AgentRunStarted)
 *   5. BudgetGuard::assertHasBudget — throws on cap breach
 *   6. GuardrailEngine::runPreFlight  — sanitises input
 *   7. ClaudeClient::generate          — actual Anthropic call (or Prism::fake)
 *   8. GuardrailEngine::runPostFlight — sanitises response (may throw)
 *   9. Walk response->steps to extract tool_calls JSON (4KB caps via ToolBus)
 *  10. Update AgentRun row with cost + tokens + finish_reason + langfuse_trace_id
 *  11. BudgetGuard::recordSpend (post-flight per CONTEXT D-01)
 *  12. AgentSuggestionWriter::write (one Suggestion per agent draft)
 *  13. event(AgentRunCompleted)
 *
 * Catch-blocks (each writes its own AgentRun terminal status before rethrowing):
 *   - MonthlyBudgetExceededException → status=monthly_budget_blocked
 *   - BudgetExceededException        → status=budget_exceeded
 *   - GuardrailViolationException    → status=guardrail_blocked + writes
 *                                       guardrail_failures JSON array (BLOCKER 1)
 *                                       + writes agent_guardrail_blocked Suggestion
 *   - \Throwable                     → status=failed
 *
 * Each catch dispatches AgentRunFailed and rethrows so Horizon's failed-job
 * pipeline records it. tries=1 (no retry) means failure is terminal —
 * agents:prune-archive (Plan 05) handles old failed rows.
 *
 * Queue routing: 'agents' supervisor (Plan 01 Horizon config). maxProcesses=2
 * bounds concurrency, which is the load-bearing assumption behind BudgetGuard's
 * decision to skip Cache::lock (acceptable ≤ 1-run × 5p overshoot per CONTEXT
 * D-01).
 */
final class RunAgentJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /** Horizon agents-supervisor (Plan 01 config/horizon.php). */
    public string $queue = 'agents';

    /** No retries — agent failures are terminal per CONTEXT D-02. */
    public int $tries = 1;

    /** 180s — covers a full multi-tool-call loop without timing out a normal Anthropic round-trip. */
    public int $timeout = 180;

    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $input = [],
        public readonly ?string $triggeringSuggestionId = null,
        public readonly ?string $triggeringCorrelationId = null,
    ) {}

    public function handle(
        AgentRegistry $registry,
        BudgetGuard $budgetGuard,
        ToolBus $toolBus,
        GuardrailEngine $guardrailEngine,
        ClaudeClient $client,
        AgentSuggestionWriter $writer,
        PromptRenderer $promptRenderer,
    ): void {
        // Correlation thread (Pitfall I2): prefer the constructor-provided
        // value (CLI passes one), then Context (queue boundary hydration), then
        // a fresh UUID. Filament + integration_events join on this string.
        $correlationId = $this->triggeringCorrelationId
            ?: (string) (Context::get('correlation_id') ?? '')
            ?: (string) Str::uuid();

        $agent = $registry->resolve($this->kind);
        $rendered = $promptRenderer->render($this->kind, $this->input);

        $run = AgentRun::create([
            'kind' => $this->kind,
            'status' => AgentRunStatus::Running->value,
            'triggering_suggestion_id' => $this->triggeringSuggestionId,
            'triggering_correlation_id' => $correlationId,
            'system_prompt_hash' => $rendered['hash'],
            'tool_calls' => [],
            'started_at' => now(),
        ]);

        event(new AgentRunStarted($run));

        // Track which guardrail phase we're in so the GuardrailViolationException
        // catch records `when: 'pre' | 'post'` correctly (plan-checker iter 1
        // BLOCKER 1).
        $guardrailPhase = 'pre';

        try {
            $budgetGuard->assertHasBudget($this->kind);

            $tier = $agent::trustTier();
            $sanitisedInput = $guardrailEngine->runPreFlight($agent, $this->input, $tier);

            // Single-user-message convention for v2.0; multi-turn deferred.
            // EchoAgent runs against an empty input → trim leaves '[]'/'{}'
            // which Prism handles fine.
            $userText = trim(json_encode($sanitisedInput, JSON_THROW_ON_ERROR));
            if ($userText === '' || $userText === '[]' || $userText === '{}') {
                $userText = 'Run the framework health check.';
            }
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

            // Extract tool_calls JSON from response steps.
            $toolCallsLog = $this->extractToolCallsFromSteps($response->steps, $toolBus);

            // Persist run completion BEFORE writing suggestion so AgentRun is
            // final truth even if downstream write fails.
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

            $budgetGuard->recordSpend($this->kind, $response->costPence);

            // EchoAgent produces one shadow Suggestion of kind=echo_health
            // with the framework health-check evidence. Future agents will
            // produce drafts of their domain-specific kinds.
            $draft = new SuggestionDraft(
                kind: 'echo_health',
                payload: ['summary' => $response->text ?? ''],
                evidence: [
                    'agent_run_id' => $run->id,
                    'tool_calls' => $toolCallsLog,
                    'cost_pence' => $response->costPence,
                    'finish_reason' => $response->finishReason->value,
                ],
            );
            $writer->write($draft, $run);

            event(new AgentRunCompleted($run));
        } catch (MonthlyBudgetExceededException $e) {
            $run->update([
                'status' => AgentRunStatus::MonthlyBudgetBlocked->value,
                'completed_at' => now(),
                'agent_reasoning_summary' => mb_substr($e->getMessage(), 0, 8192),
            ]);
            event(new AgentRunFailed($run, $e));
            throw $e;
        } catch (BudgetExceededException $e) {
            $run->update([
                'status' => AgentRunStatus::BudgetExceeded->value,
                'completed_at' => now(),
                'agent_reasoning_summary' => mb_substr($e->getMessage(), 0, 8192),
            ]);
            event(new AgentRunFailed($run, $e));
            throw $e;
        } catch (GuardrailViolationException $e) {
            // Plan-checker iter 1 BLOCKER 1 — capture violation reason on
            // agent_runs.guardrail_failures (15th column shipped by Plan 01).
            // Falls back to GuardrailViolationException::class when the
            // throwing guardrail didn't use the fromGuardrail() factory.
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
            // Plan-checker iter 1 BLOCKER 3 — write a guardrail_blocked
            // Suggestion (kind=agent_guardrail_blocked) so admins see the
            // failure in the inbox and can open the Filament AgentRunResource
            // detail view directly from the Suggestion.
            $writer->write(new SuggestionDraft(
                kind: 'agent_guardrail_blocked',
                payload: ['violation' => $e->getMessage()],
                evidence: [
                    'run_id' => $run->id,
                    'guardrail' => $e->guardrailClass,
                    'when' => $guardrailPhase,
                ],
            ), $run);
            event(new AgentRunFailed($run, $e));
            throw $e;
        } catch (\Throwable $e) {
            $run->update([
                'status' => AgentRunStatus::Failed->value,
                'completed_at' => now(),
                'agent_reasoning_summary' => mb_substr($e->getMessage(), 0, 8192),
            ]);
            event(new AgentRunFailed($run, $e));
            throw $e;
        }
    }

    /**
     * Walk Prism's Step collection and extract tool calls + results for the
     * AgentRun.tool_calls JSON column (CONTEXT D-06 4KB-cap per entry).
     *
     * Defensive shape-handling — Prism's Step exposes `toolCalls` (ToolCall[])
     * + `toolResults` (ToolResult[]) as readonly properties, but we tolerate
     * array shape too in case test fakes substitute plain arrays.
     *
     * @param  iterable<int, mixed>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function extractToolCallsFromSteps(iterable $steps, ToolBus $toolBus): array
    {
        $log = [];
        foreach ($steps as $step) {
            $toolCalls = $this->readStepProperty($step, 'toolCalls');
            $toolResults = $this->readStepProperty($step, 'toolResults');

            foreach ($toolCalls as $i => $call) {
                $name = $this->readStepProperty($call, 'name') ?? 'unknown';
                $args = $this->readStepProperty($call, 'arguments') ?? '{}';

                // Normalise arguments to a string for the truncate call so the
                // tool_calls JSON is a stable shape regardless of Prism arg form.
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
                    'tokens_used' => 0,  // Prism aggregates per-step, not per-tool
                    'latency_ms' => 0,
                ];
            }
        }

        return $log;
    }

    /**
     * Read a property from a Prism object or an array fixture.
     */
    private function readStepProperty(mixed $obj, string $name): mixed
    {
        if (is_object($obj)) {
            return property_exists($obj, $name) ? $obj->{$name} : null;
        }
        if (is_array($obj)) {
            return $obj[$name] ?? null;
        }

        return null;
    }
}
