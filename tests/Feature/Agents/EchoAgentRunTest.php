<?php

declare(strict_types=1);

use App\Domain\Agents\Agents\EchoAgent;
use App\Domain\Agents\Clients\ClaudeResponse;
use App\Domain\Agents\Contracts\Guardrail;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Events\AgentRunCompleted;
use App\Domain\Agents\Events\AgentRunFailed;
use App\Domain\Agents\Events\AgentRunStarted;
use App\Domain\Agents\Exceptions\BudgetExceededException;
use App\Domain\Agents\Exceptions\GuardrailViolationException;
use App\Domain\Agents\Exceptions\MonthlyBudgetExceededException;
use App\Domain\Agents\Jobs\RunAgentJob;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Services\PromptRenderer;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Enums\FinishReason as PrismFinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\ValueObjects\Usage;

/*
|--------------------------------------------------------------------------
| Phase 8 Plan 04 — EchoAgent end-to-end (AGNT-12 acceptance)
|--------------------------------------------------------------------------
|
| Single E2E feature test exercising the full framework pipeline:
|   AgentRegistry → BudgetGuard → ToolBus → ClaudeClient (Prism::fake)
|   → GuardrailEngine → AgentSuggestionWriter → AgentRun row + Suggestion
|
| 9 named tests (8 happy/error paths + the 9th plan-checker iter 1 BLOCKER 3
| guardrail-violation case that verifies guardrail_failures JSON capture).
*/

/** Helper: bake a Prism::fake() stub returning a one-step "OK" response. */
function fakePrismOk(int $promptTokens = 150, int $completionTokens = 35, string $text = 'Health OK.'): void
{
    Prism::fake([
        (new ResponseBuilder)
            ->addStep(TextStepFake::make()
                ->withText($text)
                ->withFinishReason(PrismFinishReason::Stop)
                ->withUsage(new Usage(promptTokens: $promptTokens, completionTokens: $completionTokens)))
            ->toResponse(),
    ]);
}

beforeEach(function () {
    // Clear budget counters between tests — each iteration starts from zero
    // so the daily-cap assertion has a stable baseline.
    Cache::flush();
    config(['agents.write_enabled' => false]);
});

it('test_1_happy_path — agent:run echo --dry-run produces an AgentRun row + shadow Suggestion', function (): void {
    fakePrismOk(150, 35);

    $exit = $this->artisan('agent:run', ['kind' => 'echo', '--dry-run' => true])
        ->assertExitCode(0)
        ->run();

    $run = AgentRun::query()->latest('started_at')->first();
    expect($run)->not->toBeNull();
    expect($run->kind->value)->toBe('echo');
    expect($run->status->value)->toBe('completed');
    expect($run->finish_reason->value)->toBe('end_turn');
    expect($run->prompt_token_count)->toBe(150);
    expect($run->completion_token_count)->toBe(35);
    expect($run->cost_pence)->toBeGreaterThan(0);

    $suggestion = Suggestion::query()->where('kind', 'echo_health')->latest('proposed_at')->first();
    expect($suggestion)->not->toBeNull();
    expect($suggestion->status)->toBe('shadow');
    expect($suggestion->proposed_by_type)->toBe(AgentRun::class);
    expect($suggestion->proposed_by_id)->toBe($run->id);
});

it('test_2_correlation_thread — CLI correlation_id flows to AgentRun and Suggestion', function (): void {
    fakePrismOk();

    $this->artisan('agent:run', ['kind' => 'echo', '--dry-run' => true])
        ->assertExitCode(0)
        ->run();

    $run = AgentRun::query()->latest('started_at')->first();
    $suggestion = Suggestion::query()->where('kind', 'echo_health')->latest('proposed_at')->first();

    expect($run->triggering_correlation_id)->not->toBeEmpty();
    expect($suggestion->correlation_id)->toBe($run->triggering_correlation_id);
});

it('test_3_monthly_budget_blocks — pre-flight throws MonthlyBudgetExceededException', function (): void {
    fakePrismOk();
    config(['agents.monthly_ceiling_pence' => 1]);
    Cache::put('agents.monthly.'.now('Europe/London')->format('Y-m'), 100, 3600);

    expect(fn () => Bus::dispatchSync(new RunAgentJob('echo', [])))
        ->toThrow(MonthlyBudgetExceededException::class);

    $run = AgentRun::query()->latest('started_at')->first();
    expect($run->status->value)->toBe('monthly_budget_blocked');
});

it('test_4_daily_cap_blocks — pre-flight throws BudgetExceededException', function (): void {
    fakePrismOk();
    config(['agents.daily_caps.echo' => 1, 'agents.monthly_ceiling_pence' => 999999]);
    Cache::put('agents.daily.echo.'.now('Europe/London')->format('Y-m-d'), 100, 3600);

    expect(fn () => Bus::dispatchSync(new RunAgentJob('echo', [])))
        ->toThrow(BudgetExceededException::class);

    $run = AgentRun::query()->latest('started_at')->first();
    expect($run->status->value)->toBe('budget_exceeded');
});

it('test_5_queue_routing — RunAgentJob is queued onto agents with tries=1 timeout=180', function (): void {
    $job = new RunAgentJob('echo', []);
    expect($job->queue)->toBe('agents');
    expect($job->tries)->toBe(1);
    expect($job->timeout)->toBe(180);
});

it('test_6_events_fire — AgentRunStarted + AgentRunCompleted dispatch on success', function (): void {
    Event::fake([AgentRunStarted::class, AgentRunCompleted::class, AgentRunFailed::class]);
    fakePrismOk();

    Bus::dispatchSync(new RunAgentJob('echo', []));

    Event::assertDispatched(AgentRunStarted::class);
    Event::assertDispatched(AgentRunCompleted::class);
    Event::assertNotDispatched(AgentRunFailed::class);
});

it('test_7_tool_calls_recorded — AgentRun.tool_calls populated when tools fire', function (): void {
    // Build a fake Prism response that includes a tool call + result so the
    // tool_calls JSON column gets populated. Single step with a tool call
    // mirrors the EchoAgent's expected one-call pattern.
    Prism::fake([
        (new ResponseBuilder)
            ->addStep(TextStepFake::make()
                ->withText('Health OK')
                ->withFinishReason(PrismFinishReason::Stop)
                ->withToolCalls([
                    new \Prism\Prism\ValueObjects\ToolCall(
                        id: 'call_001',
                        name: 'read_health_check',
                        arguments: '{}',
                    ),
                ])
                ->withToolResults([
                    new \Prism\Prism\ValueObjects\ToolResult(
                        toolCallId: 'call_001',
                        toolName: 'read_health_check',
                        args: [],
                        result: ['timestamp' => '2026-04-25T00:00:00Z', 'git_sha' => 'abc123', 'app_version' => 'v2.0'],
                    ),
                ])
                ->withUsage(new Usage(promptTokens: 10, completionTokens: 5)))
            ->toResponse(),
    ]);

    Bus::dispatchSync(new RunAgentJob('echo', []));

    $run = AgentRun::query()->latest('started_at')->first();
    expect($run->tool_calls)->toBeArray();
    expect($run->tool_calls)->toHaveCount(1);
    expect($run->tool_calls[0]['tool_name'])->toBe('read_health_check');
});

it('test_8_system_prompt_hash — AgentRun.system_prompt_hash is sha256 of rendered prompt', function (): void {
    fakePrismOk();
    $expectedHash = app(PromptRenderer::class)->render('echo')['hash'];

    Bus::dispatchSync(new RunAgentJob('echo', []));

    $run = AgentRun::query()->latest('started_at')->first();
    expect($run->system_prompt_hash)->toBe($expectedHash);
    expect($run->system_prompt_hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('test_9_guardrail_violation — captures guardrail_failures JSON + writes agent_guardrail_blocked Suggestion (BLOCKER 3)', function (): void {
    fakePrismOk();

    // Bind a test-only EchoAgent variant whose guardrails() returns the
    // AlwaysReject fixture. Container resolution flows through this binding
    // so RunAgentJob's `app($class)` call lands on the variant.
    $this->app->bind(EchoAgent::class, function ($app): EchoAgent {
        return new class($app->make(PromptRenderer::class)) extends EchoAgent
        {
            public function guardrails(): array
            {
                return [new AlwaysRejectGuardrail];
            }
        };
    });

    Event::fake([AgentRunFailed::class]);

    expect(fn () => Bus::dispatchSync(new RunAgentJob('echo', [])))
        ->toThrow(GuardrailViolationException::class);

    $run = AgentRun::query()->where('kind', 'echo')->latest('started_at')->first();
    expect($run)->not->toBeNull();
    expect($run->status->value)->toBe('guardrail_blocked');
    expect($run->guardrail_failures)->toBeArray();
    expect($run->guardrail_failures[0]['guardrail'])->toContain('AlwaysRejectGuardrail');
    expect($run->guardrail_failures[0]['message'])->toBe('test guardrail rejection');
    expect($run->guardrail_failures[0]['when'])->toBe('post');
    expect($run->guardrail_failures[0]['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');

    $blocked = Suggestion::query()->where('kind', 'agent_guardrail_blocked')->latest('proposed_at')->first();
    expect($blocked)->not->toBeNull();
    expect($blocked->proposed_by_type)->toBe(AgentRun::class);
    expect($blocked->proposed_by_id)->toBe($run->id);
    expect($blocked->evidence['run_id'] ?? null)->toBe($run->id);

    Event::assertDispatched(AgentRunFailed::class);
});

/**
 * Test fixture: post-flight guardrail that always throws via fromGuardrail().
 * Lives in this file (no separate fixture file) per Pest convention — the
 * class is referenced only inside Test 9 via container binding.
 */
final class AlwaysRejectGuardrail implements Guardrail
{
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
        throw GuardrailViolationException::fromGuardrail(self::class, 'test guardrail rejection');
    }
}
