<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 04 Task 2 — RunSeoAgentJob guardrail-blocked path (P12-B)
|--------------------------------------------------------------------------
|
| Two fixtures pin the P12-B audit-Suggestion-on-block contract:
|
|   1. Forbidden pattern in 'after' text fires SeoOutboundGuardrail →
|      RunSeoAgentJob's catch(GuardrailViolationException) calls
|      $mapper->createGuardrailBlockedSuggestion(...) BEFORE rethrowing
|      → exactly ONE 'agent_guardrail_blocked' Suggestion exists +
|      ZERO 'seo_content_patch' Suggestions (no partial publishing per
|      CONTEXT D-01) + AgentRun.status='guardrail_blocked' +
|      AgentRun.guardrail_failures JSON populated.
|   2. (No need to test pre-flight BudgetExceededException — Phase 8
|      framework + BudgetGuard already cover this; Phase 12 inherits
|      verbatim.)
|
| Note: $tries=1 on the job (Phase 10 invariant — agent failures are
| terminal). The catch block rethrows after the mapper writes the audit
| Suggestion so Horizon records the failure.
*/

use App\Domain\Agents\Events\AgentRunCompleted;
use App\Domain\Agents\Events\AgentRunFailed;
use App\Domain\Agents\Events\AgentRunStarted;
use App\Domain\Agents\Exceptions\GuardrailViolationException;
use App\Domain\Agents\Jobs\RunSeoAgentJob;
use App\Domain\Agents\Models\AgentRun;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
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
    \Illuminate\Support\Facades\Cache::flush();
});

it('P12-B — guardrail-blocked run writes ONE agent_guardrail_blocked Suggestion + ZERO seo_content_patch', function () {
    config()->set('agents.write_enabled', true);

    // 'revolutionary' is in the marketing_superlatives starter regex set
    // (config/seo_agent.php) so SeoOutboundGuardrail will fire.
    Prism::fake([
        (new ResponseBuilder)
            ->addStep(TextStepFake::make()
                ->withText('Proposed bold new copy.')
                ->withFinishReason(PrismFinishReason::Stop)
                ->withToolCalls([
                    new ToolCall(
                        id: 'call_propose_forbidden',
                        name: 'propose_content_patch',
                        arguments: json_encode([
                            'sku' => 'BLOCKED-SKU',
                            'field' => 'long_description',
                            'before' => 'Old long description.',
                            'after' => 'A revolutionary new camera that changes everything.',
                            'reasoning' => 'fresh marketing-led rewrite',
                        ], JSON_THROW_ON_ERROR),
                    ),
                ])
                ->withUsage(new Usage(promptTokens: 500, completionTokens: 150)))
            ->toResponse(),
    ]);

    $product = Product::factory()->create([
        'sku' => 'BLOCKED-' . Str::random(4),
        'name' => 'Test Camera',
        'long_description' => 'Old long description.',
        'auto_create_status' => 'pending_review',
        'completeness_score' => 50,
    ]);

    try {
        (new RunSeoAgentJob(productId: $product->id))->handle(
            registry: app(\App\Domain\Agents\Services\AgentRegistry::class),
            budgetGuard: app(\App\Domain\Agents\Services\BudgetGuard::class),
            toolBus: app(\App\Domain\Agents\Services\ToolBus::class),
            guardrailEngine: app(\App\Domain\Agents\Services\GuardrailEngine::class),
            client: app(\App\Domain\Agents\Clients\ClaudeClient::class),
            promptRenderer: app(\App\Domain\Agents\Services\PromptRenderer::class),
            mapper: app(\App\Domain\Agents\Services\SeoAgentResultMapper::class),
        );
        $caught = null;
    } catch (GuardrailViolationException $e) {
        $caught = $e;
    }

    // The exception was rethrown after the audit Suggestion was written
    expect($caught)->not->toBeNull();
    expect($caught)->toBeInstanceOf(GuardrailViolationException::class);
    expect((string) $caught->failedPatternKey)->not->toBe('');

    // P12-B mitigation — ONE 'agent_guardrail_blocked' Suggestion exists with
    // the failed pattern key + matched excerpt populated.
    expect(Suggestion::where('kind', 'agent_guardrail_blocked')->count())->toBe(1);
    $audit = Suggestion::where('kind', 'agent_guardrail_blocked')->first();
    expect((string) data_get($audit->payload, 'failed_pattern_key'))->not->toBe('');
    expect((string) data_get($audit->payload, 'matched_excerpt'))->not->toBe('');
    expect((string) data_get($audit->payload, 'agent_kind'))->toBe('seo');
    expect((int) data_get($audit->payload, 'product_id'))->toBe($product->id);

    // CONTEXT D-01 — NO partial publishing. ZERO seo_content_patch Suggestions
    // exist after a guardrail-blocked run.
    expect(Suggestion::where('kind', 'seo_content_patch')->count())->toBe(0);

    // AgentRun row reflects guardrail_blocked status + populated guardrail_failures
    $run = AgentRun::query()->where('kind', 'seo')->latest('started_at')->first();
    expect($run)->not->toBeNull();
    expect($run->status->value)->toBe('guardrail_blocked');
    expect($run->guardrail_failures)->toBeArray();
    expect($run->guardrail_failures)->not->toBe([]);
});
