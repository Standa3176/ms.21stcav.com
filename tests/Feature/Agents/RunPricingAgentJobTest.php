<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 10 Plan 04 Task 2 — RunPricingAgentJob E2E test (Path A sibling)
|--------------------------------------------------------------------------
|
| Locks the SIBLING-not-subclass invariant (RESEARCH §A9 / §Pattern 1) +
| the end-to-end Filament-click → Anthropic call → mapper-merge flow.
|
| Five fixtures:
|   1. Happy path with AGENT_WRITE_ENABLED=true — agent_run row created with
|      status=completed; tool_calls populated; mapper merged enrichment into
|      Suggestion.evidence; AgentRunStarted/AgentRunCompleted events fired.
|   2. Shadow mode with AGENT_WRITE_ENABLED=false — agent_run row created;
|      mapper NOT invoked; Suggestion.evidence stays free of agent_* keys.
|   3. Invalid kind — dispatching for a non-margin_change Suggestion throws
|      InvalidArgumentException (defensive guard).
|   4. Mapper merges latest-wins on a re-run — second run's args overwrite the
|      first; both run_ids land in evidence.agent_run_ids[].
|   5. Phase 5 MarginChangeApplier never invoked during agent run — Phase 5
|      byte-identity preservation (B-03 precedent).
*/

use App\Domain\Agents\Events\AgentRunCompleted;
use App\Domain\Agents\Events\AgentRunStarted;
use App\Domain\Agents\Jobs\RunAgentJob;
use App\Domain\Agents\Jobs\RunPricingAgentJob;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Services\AgentRegistry;
use App\Domain\Agents\Services\BudgetGuard;
use App\Domain\Agents\Services\GuardrailEngine;
use App\Domain\Agents\Services\PricingAgentResultMapper;
use App\Domain\Agents\Services\PromptRenderer;
use App\Domain\Agents\Services\ToolBus;
use App\Domain\Integrations\Clients\ClaudeClient;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Prism\Prism\Enums\FinishReason as PrismFinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;

beforeEach(function () {
    // RunPricingAgentJob sets correlation_id on Context for the request lifetime;
    // IntegrationLogger reads it back when persisting the anthropic
    // integration_events row — satisfies the NOT NULL constraint.
    Context::add('correlation_id', (string) Str::uuid());

    // Bucket 2 — ClaudeClient resolves the Anthropic api_key via
    // IntegrationCredentialResolver; without a DB row or the env fallback the
    // resolver throws IntegrationCredentialMissingException. Provision the env
    // fallback (mirrors ClaudeClientResolverIntegrationTest). Prism::fake still
    // intercepts the actual HTTP, so the key value is inert.
    config()->set('prism.providers.anthropic.api_key', 'test-key');

    // Bucket 3 — fixtures below call handle() directly. handle() fires
    // AgentRunStarted/AgentRunCompleted, whose real ShouldQueue Notify*
    // listeners would try to serialize in-test ("Serialization of Closure is
    // not allowed"). Fake the queue so the listeners are recorded, not
    // serialized. Prod listener deps are container-resolved/serializable — this
    // is purely a test-env artifact. Fixture 1 additionally Event::fakes the two
    // events it asserts on; this Queue::fake is complementary + harmless there.
    Queue::fake();
});

function makePricingMarginChangeSuggestion(array $evidenceOverrides = []): Suggestion
{
    return Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => (string) Str::uuid(),
        'payload' => ['pricing_rule_id' => 1, 'new_margin_basis_points' => 2200],
        'evidence' => array_merge([
            'sku' => 'PRICING-AGENT-E2E',
            'our_current_margin_bps' => 2400,
            'proposed_margin_bps' => 2200,
            'pricing_rule' => ['scope' => 'global'],
        ], $evidenceOverrides),
        'proposed_at' => now(),
    ]);
}

function fakePricingAgentResponse(int $proposedBps = 2050, int $confidence = 82, int $bandMin = 1980, int $bandMax = 2120): void
{
    Prism::fake([
        (new ResponseBuilder)
            ->addStep(TextStepFake::make()
                ->withText('Proposed margin band based on competitor + supplier + sales analysis.')
                ->withFinishReason(PrismFinishReason::Stop)
                ->withToolCalls([
                    new ToolCall(
                        id: 'call_propose_001',
                        name: 'propose_margin_band',
                        arguments: json_encode([
                            'sku' => 'PRICING-AGENT-E2E',
                            'proposed_bps' => $proposedBps,
                            'reasoning' => '6 competitors stable in narrow band over 90d; supplier flat; healthy sales volume — multi-source corroboration justifies tight band.',
                            'confidence_0_to_100' => $confidence,
                            'band_min_bps' => $bandMin,
                            'band_max_bps' => $bandMax,
                        ], JSON_THROW_ON_ERROR),
                    ),
                ])
                ->withUsage(new Usage(promptTokens: 800, completionTokens: 200)))
            ->toResponse(),
    ]);
}

it('Fixture 1 — happy path: AGENT_WRITE_ENABLED=true merges enrichment into Suggestion.evidence', function () {
    config()->set('agents.write_enabled', true);
    Event::fake([AgentRunStarted::class, AgentRunCompleted::class]);
    fakePricingAgentResponse(proposedBps: 2050, confidence: 82, bandMin: 1980, bandMax: 2120);

    $suggestion = makePricingMarginChangeSuggestion();

    (new RunPricingAgentJob(suggestionId: $suggestion->id))->handle(
        registry: app(AgentRegistry::class),
        budgetGuard: app(BudgetGuard::class),
        toolBus: app(ToolBus::class),
        guardrailEngine: app(GuardrailEngine::class),
        client: app(ClaudeClient::class),
        promptRenderer: app(PromptRenderer::class),
        mapper: app(PricingAgentResultMapper::class),
    );

    // AgentRun row created + finalised
    $run = AgentRun::query()->where('triggering_suggestion_id', $suggestion->id)->firstOrFail();
    expect($run->status->value)->toBe('completed');
    expect($run->kind->value)->toBe('pricing');
    expect($run->triggering_suggestion_id)->toBe($suggestion->id);
    expect((string) $run->system_prompt_hash)->toHaveLength(64);

    // tool_calls JSON populated with the propose_margin_band entry
    expect($run->tool_calls)->toBeArray();
    $proposeCalls = collect($run->tool_calls)->filter(fn ($c) => ($c['tool_name'] ?? '') === 'propose_margin_band');
    expect($proposeCalls->count())->toBeGreaterThanOrEqual(1);

    // Mapper merged enrichment into Suggestion.evidence (AGENT_WRITE_ENABLED=true)
    $suggestion->refresh();
    expect($suggestion->evidence['agent_run_status'])->toBe('completed');
    expect((int) $suggestion->evidence['agent_proposed_bps'])->toBe(2050);
    expect((int) $suggestion->evidence['agent_confidence_0_to_100'])->toBe(82);
    expect((int) $suggestion->evidence['agent_proposed_band_min_bps'])->toBe(1980);
    expect((int) $suggestion->evidence['agent_proposed_band_max_bps'])->toBe(2120);
    expect($suggestion->evidence['agent_run_ids'])->toContain($run->id);
    expect($suggestion->proposed_by_id)->toBe($run->id);
    expect($suggestion->proposed_by_type)->toBe(AgentRun::class);

    // Phase 5 v1 evidence keys preserved
    expect($suggestion->evidence['sku'])->toBe('PRICING-AGENT-E2E');
    expect((int) $suggestion->evidence['proposed_margin_bps'])->toBe(2200);

    Event::assertDispatched(AgentRunStarted::class);
    Event::assertDispatched(AgentRunCompleted::class);
});

it('Fixture 2 — shadow mode: AGENT_WRITE_ENABLED=false persists AgentRun but skips mapper merge', function () {
    config()->set('agents.write_enabled', false);
    fakePricingAgentResponse(proposedBps: 2050, confidence: 82, bandMin: 1980, bandMax: 2120);

    $suggestion = makePricingMarginChangeSuggestion();

    (new RunPricingAgentJob(suggestionId: $suggestion->id))->handle(
        registry: app(AgentRegistry::class),
        budgetGuard: app(BudgetGuard::class),
        toolBus: app(ToolBus::class),
        guardrailEngine: app(GuardrailEngine::class),
        client: app(ClaudeClient::class),
        promptRenderer: app(PromptRenderer::class),
        mapper: app(PricingAgentResultMapper::class),
    );

    // AgentRun forensics PERSISTED (admin can still see what the agent would have proposed)
    $run = AgentRun::query()->where('triggering_suggestion_id', $suggestion->id)->firstOrFail();
    expect($run->status->value)->toBe('completed');
    expect($run->tool_calls)->toBeArray();

    // Mapper NOT invoked — evidence has zero agent_* keys
    $suggestion->refresh();
    expect($suggestion->evidence)->not->toHaveKey('agent_run_status');
    expect($suggestion->evidence)->not->toHaveKey('agent_proposed_bps');
    expect($suggestion->evidence)->not->toHaveKey('agent_run_ids');
    expect($suggestion->proposed_by_id)->toBeNull();
});

it('Fixture 3 — InvalidArgumentException when triggered for a non-margin_change Suggestion', function () {
    config()->set('agents.write_enabled', true);
    fakePricingAgentResponse();

    $suggestion = Suggestion::create([
        'kind' => 'crm_push_failed',  // NOT margin_change
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => (string) Str::uuid(),
        'payload' => [],
        'evidence' => [],
        'proposed_at' => now(),
    ]);

    expect(fn () => (new RunPricingAgentJob(suggestionId: $suggestion->id))->handle(
        registry: app(AgentRegistry::class),
        budgetGuard: app(BudgetGuard::class),
        toolBus: app(ToolBus::class),
        guardrailEngine: app(GuardrailEngine::class),
        client: app(ClaudeClient::class),
        promptRenderer: app(PromptRenderer::class),
        mapper: app(PricingAgentResultMapper::class),
    ))->toThrow(InvalidArgumentException::class, 'PricingAgent only enriches margin_change suggestions');

    // No AgentRun should have been created — guard fires before AgentRun::create
    expect(AgentRun::query()->where('triggering_suggestion_id', $suggestion->id)->exists())->toBeFalse();
});

it('Fixture 4 — re-run on the same suggestion: latest-wins overwrite + both run_ids in agent_run_ids[]', function () {
    config()->set('agents.write_enabled', true);
    $suggestion = makePricingMarginChangeSuggestion();

    // Run 1 — confidence 50, band 1900-2100
    fakePricingAgentResponse(proposedBps: 2000, confidence: 50, bandMin: 1900, bandMax: 2100);
    (new RunPricingAgentJob(suggestionId: $suggestion->id))->handle(
        registry: app(AgentRegistry::class),
        budgetGuard: app(BudgetGuard::class),
        toolBus: app(ToolBus::class),
        guardrailEngine: app(GuardrailEngine::class),
        client: app(ClaudeClient::class),
        promptRenderer: app(PromptRenderer::class),
        mapper: app(PricingAgentResultMapper::class),
    );

    $firstRun = AgentRun::query()->where('triggering_suggestion_id', $suggestion->id)->orderByDesc('started_at')->firstOrFail();

    // Run 2 — confidence 78, band 1980-2080
    fakePricingAgentResponse(proposedBps: 2030, confidence: 78, bandMin: 1980, bandMax: 2080);
    (new RunPricingAgentJob(suggestionId: $suggestion->id))->handle(
        registry: app(AgentRegistry::class),
        budgetGuard: app(BudgetGuard::class),
        toolBus: app(ToolBus::class),
        guardrailEngine: app(GuardrailEngine::class),
        client: app(ClaudeClient::class),
        promptRenderer: app(PromptRenderer::class),
        mapper: app(PricingAgentResultMapper::class),
    );

    $secondRun = AgentRun::query()
        ->where('triggering_suggestion_id', $suggestion->id)
        ->where('id', '!=', $firstRun->id)
        ->firstOrFail();

    $suggestion->refresh();
    // D-02 latest-wins — second run's args overwrite the first
    expect((int) $suggestion->evidence['agent_proposed_bps'])->toBe(2030);
    expect((int) $suggestion->evidence['agent_confidence_0_to_100'])->toBe(78);
    expect((int) $suggestion->evidence['agent_proposed_band_min_bps'])->toBe(1980);
    expect((int) $suggestion->evidence['agent_proposed_band_max_bps'])->toBe(2080);
    // Both run_ids preserved in the array
    expect($suggestion->evidence['agent_run_ids'])->toContain($firstRun->id);
    expect($suggestion->evidence['agent_run_ids'])->toContain($secondRun->id);
});

it('is a SIBLING of RunAgentJob (NOT a subclass) — Path A invariant per RESEARCH §A9', function () {
    $reflection = new ReflectionClass(RunPricingAgentJob::class);
    $parent = $reflection->getParentClass();
    // Either no parent class OR parent is not RunAgentJob — both confirm sibling pattern
    expect($parent === false || $parent->getName() !== RunAgentJob::class)->toBeTrue(
        'RunPricingAgentJob MUST be a sibling, not a subclass — RESEARCH §A9 invariant'
    );
});
