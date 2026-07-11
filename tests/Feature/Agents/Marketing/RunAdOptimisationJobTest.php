<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15b-01 Task 4 — RunAdOptimisationJob (advice-only orchestration)
|--------------------------------------------------------------------------
|
| Path A sibling of RunSeoAgentJob, minus $productId (single analysis run).
|
|   1. Happy path AGENT_WRITE_ENABLED=true — Prism returns 2
|      propose_marketing_action calls → mapper writes ONE ad_optimisation
|      Suggestion; AgentRun kind='ad_optimisation'/status='completed'.
|   2. Shadow mode AGENT_WRITE_ENABLED=false — AgentRun persists, ZERO
|      Suggestions (forensic-only run).
|   3. Budget-exceeded — status flips + exception rethrown.
*/

use App\Domain\Agents\Events\AgentRunCompleted;
use App\Domain\Agents\Events\AgentRunFailed;
use App\Domain\Agents\Events\AgentRunStarted;
use App\Domain\Agents\Exceptions\BudgetExceededException;
use App\Domain\Agents\Jobs\RunAdOptimisationJob;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Services\AdOptimisationResultMapper;
use App\Domain\Agents\Services\AgentRegistry;
use App\Domain\Agents\Services\BudgetGuard;
use App\Domain\Agents\Services\GuardrailEngine;
use App\Domain\Agents\Services\PromptRenderer;
use App\Domain\Agents\Services\ToolBus;
use App\Domain\Integrations\Clients\ClaudeClient;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Prism\Prism\Enums\FinishReason as PrismFinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;

beforeEach(function () {
    Context::add('correlation_id', (string) Str::uuid());
    Event::fake([AgentRunStarted::class, AgentRunCompleted::class, AgentRunFailed::class]);
    config()->set('prism.providers.anthropic.api_key', 'sk-test-fake-key');
    Cache::flush();
});

function fakeAdOptTwoProposals(): void
{
    Prism::fake([
        (new ResponseBuilder)
            ->addStep(TextStepFake::make()
                ->withText('Proposed reducing generic paid spend and adding coverage for a high-margin SKU.')
                ->withFinishReason(PrismFinishReason::Stop)
                ->withToolCalls([
                    new ToolCall(
                        id: 'call_reduce',
                        name: 'propose_marketing_action',
                        arguments: json_encode([
                            'action_type' => 'reduce_spend',
                            'target' => 'Paid Search / Generic',
                            'rationale' => 'Generic converted 0 of 900 sessions over 30 days.',
                            'supporting_metrics' => '{"sessions":900,"transactions":0}',
                            'confidence' => 'high',
                        ], JSON_THROW_ON_ERROR),
                    ),
                    new ToolCall(
                        id: 'call_add',
                        name: 'propose_marketing_action',
                        arguments: json_encode([
                            'action_type' => 'add_coverage',
                            'target' => 'MARGIN-HIGH',
                            'rationale' => 'High-margin in-stock SKU with strong demand and no paid coverage.',
                            'supporting_metrics' => '{"margin_gbp":400,"sales_90d":27}',
                            'confidence' => 'medium',
                        ], JSON_THROW_ON_ERROR),
                    ),
                ])
                ->withUsage(new Usage(promptTokens: 700, completionTokens: 220)))
            ->toResponse(),
    ]);
}

function runAdOptJob(): void
{
    (new RunAdOptimisationJob)->handle(
        registry: app(AgentRegistry::class),
        budgetGuard: app(BudgetGuard::class),
        toolBus: app(ToolBus::class),
        guardrailEngine: app(GuardrailEngine::class),
        client: app(ClaudeClient::class),
        promptRenderer: app(PromptRenderer::class),
        mapper: app(AdOptimisationResultMapper::class),
    );
}

it('Fixture 1 — happy path write_enabled=true: one ad_optimisation Suggestion with 2 proposals', function () {
    config()->set('agents.write_enabled', true);
    fakeAdOptTwoProposals();

    runAdOptJob();

    $run = AgentRun::query()->where('kind', 'ad_optimisation')->latest('started_at')->first();
    expect($run)->not->toBeNull();
    expect($run->status->value)->toBe('completed');
    expect((string) $run->system_prompt_hash)->toHaveLength(64);
    expect($run->tool_calls)->toBeArray();

    expect(Suggestion::where('kind', 'ad_optimisation')->count())->toBe(1);
    $suggestion = Suggestion::where('kind', 'ad_optimisation')->first();
    $proposals = (array) data_get($suggestion->payload, 'proposals', []);
    expect($proposals)->toHaveCount(2);
    expect((string) data_get($suggestion->payload, 'agent_run_id'))->toBe($run->id);
});

it('Fixture 2 — shadow mode write_enabled=false: AgentRun persists, ZERO Suggestions', function () {
    config()->set('agents.write_enabled', false);
    fakeAdOptTwoProposals();

    runAdOptJob();

    $run = AgentRun::query()->where('kind', 'ad_optimisation')->latest('started_at')->first();
    expect($run)->not->toBeNull();
    expect($run->status->value)->toBe('completed');

    expect(Suggestion::count())->toBe(0);
});

it('Fixture 3 — budget exceeded: status flips to budget_exceeded and rethrows', function () {
    config()->set('agents.write_enabled', true);
    fakeAdOptTwoProposals();

    // Drive the daily cap to zero so assertHasBudget throws immediately.
    config()->set('agents.daily_caps.ad_optimisation', 0);
    config()->set('agents.default_daily_cap_pence', 0);

    expect(fn () => runAdOptJob())->toThrow(BudgetExceededException::class);

    $run = AgentRun::query()->where('kind', 'ad_optimisation')->latest('started_at')->first();
    expect($run)->not->toBeNull();
    expect($run->status->value)->toBe('budget_exceeded');
    expect(Suggestion::count())->toBe(0);
});
