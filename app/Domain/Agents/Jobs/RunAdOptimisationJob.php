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
use App\Domain\Agents\Services\AdOptimisationResultMapper;
use App\Domain\Agents\Services\AgentRegistry;
use App\Domain\Agents\Services\BudgetGuard;
use App\Domain\Agents\Services\GuardrailEngine;
use App\Domain\Agents\Services\PromptRenderer;
use App\Domain\Agents\Services\ToolBus;
use App\Domain\Integrations\Clients\ClaudeClient;
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
 * Phase 15 Plan 15b-01 — Path A SIBLING of RunSeoAgentJob for the advice-only
 * AdOptimisationAgent (kind='ad_optimisation').
 *
 * ONE structural diff from RunSeoAgentJob: there is NO $productId. This is a
 * SINGLE analysis run over the GA4 snapshot + own margin/competitor/stock data
 * — not a per-product loop. The constructor takes only an optional batch
 * correlation id (the scheduled command supplies one per dispatch).
 *
 * Mirrors RunSeoAgentJob's orchestration verbatim otherwise:
 *   - correlation thread + Context hydration
 *   - registry resolve, PromptRenderer, AgentRun::create, events
 *   - BudgetGuard::assertHasBudget, GuardrailEngine pre/post
 *   - ClaudeClient::generate with ToolBus::buildPrismTools
 *   - tool_call extraction, AgentRun::update, BudgetGuard::recordSpend
 *   - SHADOW-GATED mapper call: config('agents.write_enabled') === false skips
 *     the Suggestion write but the AgentRun forensic row STILL persists
 *   - all four catch arms (Monthly / Budget / Guardrail / Throwable)
 *
 * ADVICE-ONLY: the only side effect is the shadow-gated ad_optimisation
 * Suggestion. No Google Ads write, no closed-loop (15c).
 *
 * $tries=1 (agent failures terminal); $timeout=180; onQueue('agents').
 */
final class RunAdOptimisationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly ?string $batchCorrelationId = null,
    ) {
        $this->onQueue('agents');
    }

    public function handle(
        AgentRegistry $registry,
        BudgetGuard $budgetGuard,
        ToolBus $toolBus,
        GuardrailEngine $guardrailEngine,
        ClaudeClient $client,
        PromptRenderer $promptRenderer,
        AdOptimisationResultMapper $mapper,
    ): void {
        // Correlation thread (Phase 8 Pitfall I2): prefer the batch-supplied id,
        // then Context (queue boundary hydration), then a fresh UUID.
        $correlationId = $this->batchCorrelationId
            ?: (string) (Context::get('correlation_id') ?? '')
            ?: (string) Str::uuid();

        // Ensure correlation_id is on the Context so IntegrationLogger (called
        // from ClaudeClient post-flight) satisfies the integration_events
        // correlation_id NOT NULL constraint.
        Context::add('correlation_id', $correlationId);

        $agent = $registry->resolve('ad_optimisation');

        // Static prompt (zero interpolation) — deterministic hash for forensic
        // continuity. The context array is harmless and sets up future tuning.
        $rendered = $promptRenderer->render('ad_optimisation', []);

        $run = AgentRun::create([
            'kind' => 'ad_optimisation',
            'status' => AgentRunStatus::Running->value,
            'triggering_suggestion_id' => null,  // scheduled, not suggestion-pull
            'triggering_correlation_id' => $correlationId,
            'system_prompt_hash' => $rendered['hash'],
            'tool_calls' => [],
            'started_at' => now(),
        ]);

        event(new AgentRunStarted($run));

        $guardrailPhase = 'pre';

        try {
            $budgetGuard->assertHasBudget('ad_optimisation');

            $tier = $agent::trustTier();
            $guardrailEngine->runPreFlight($agent, [], $tier);

            $userText = json_encode([
                'task' => 'Review recent GA4 channel/campaign performance and the app\'s high-margin in-stock products, then propose advice-only marketing actions.',
                'window_days' => (int) config('agents.ad_optimisation.data_lookback_days', 14),
            ], JSON_THROW_ON_ERROR);

            $messages = [new UserMessage($userText)];
            $prismTools = $toolBus->buildPrismTools($agent->tools(), $run);

            $response = $client->generate(
                systemPrompt: $rendered['prompt'],
                messages: $messages,
                tools: $prismTools,
                temperature: (float) config('agents.ad_optimisation.temperature', 0.3),
            );

            $guardrailPhase = 'post';
            $response = $guardrailEngine->runPostFlight($agent, $response, $tier);

            $toolCallsLog = $this->extractToolCallsFromSteps($response->steps, $toolBus);

            // Persist run completion BEFORE the mapper so the AgentRun is final
            // truth even if the mapper write fails.
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

            $budgetGuard->recordSpend('ad_optimisation', $response->costPence);

            // SHADOW-GATED bundled-Suggestion write. Skipped when write_enabled
            // is false; the AgentRun forensics STILL persist via the update above.
            if ((bool) config('agents.write_enabled', false)) {
                $mapper->createBundledSuggestion($run->fresh());
            } else {
                Log::info('AdOptimisationAgent run completed in shadow mode — Suggestion NOT created', [
                    'agent_run_id' => $run->id,
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
     * AgentRun.tool_calls JSON column. Mirrors RunSeoAgentJob verbatim so
     * AdOptimisationResultMapper's filter on tool_name='propose_marketing_action'
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
