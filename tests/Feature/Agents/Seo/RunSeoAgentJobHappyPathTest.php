<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 04 Task 2 — RunSeoAgentJob happy path + shadow mode + eligibility
|--------------------------------------------------------------------------
|
| Three fixtures pin the Path A sibling job contract surface:
|
|   1. Happy path with AGENT_WRITE_ENABLED=true — Prism returns 2
|      propose_content_patch calls → mapper writes ONE
|      'seo_content_patch' Suggestion with 2 patches in payload;
|      AgentRun row persists kind='seo' / status='completed' /
|      cost_pence>0.
|   2. Shadow mode AGENT_WRITE_ENABLED=false — AgentRun persists,
|      ZERO Suggestions created (forensic-only run).
|   3. Eligibility re-check — Product whose auto_create_status
|      changed to 'published' between dispatch and handle exits early
|      with NO AgentRun row created.
*/

use App\Domain\Agents\Events\AgentRunCompleted;
use App\Domain\Agents\Events\AgentRunFailed;
use App\Domain\Agents\Events\AgentRunStarted;
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
    // Plan 12-04 — Prism::fake() short-circuits the HTTP call but
    // ClaudeClient still calls IntegrationCredentialResolver::for(AnthropicApi)
    // to pass an api_key into Prism's provider config. Set the env fallback
    // so the resolver returns a non-empty payload (the value itself is unused
    // because Prism::fake intercepts the request).
    config()->set('prism.providers.anthropic.api_key', 'sk-test-fake-key');
    \Illuminate\Support\Facades\Cache::flush();
});

function makeSeoEligibleProduct(array $overrides = []): Product
{
    return Product::factory()->create(array_merge([
        'sku' => 'SEO-AGENT-E2E-' . Str::random(4),
        'name' => 'Logitech MeetUp',
        'short_description' => 'A camera',
        'long_description' => '',
        'meta_description' => '',
        'brand_id' => 5,
        'auto_create_status' => 'pending_review',
        'completeness_score' => 64,
        'completeness_missing_fields' => ['long_description', 'meta_description'],
    ], $overrides));
}

function fakeSeoAgentTwoPatches(): void
{
    Prism::fake([
        (new ResponseBuilder)
            ->addStep(TextStepFake::make()
                ->withText('Proposed 2 patches based on supplier copy + brand voice.')
                ->withFinishReason(PrismFinishReason::Stop)
                ->withToolCalls([
                    new ToolCall(
                        id: 'call_propose_title',
                        name: 'propose_content_patch',
                        arguments: json_encode([
                            'sku' => 'SEO-AGENT-E2E',
                            'field' => 'title',
                            'before' => 'Logitech MeetUp',
                            'after' => 'Logitech MeetUp Conference Camera for Huddle Rooms',
                            'reasoning' => 'Adds form factor + room type for SEO + clarity',
                        ], JSON_THROW_ON_ERROR),
                    ),
                    new ToolCall(
                        id: 'call_propose_meta',
                        name: 'propose_content_patch',
                        arguments: json_encode([
                            'sku' => 'SEO-AGENT-E2E',
                            'field' => 'meta_description',
                            'before' => '',
                            'after' => 'Logitech MeetUp huddle-room camera with RightSense framing.',
                            'reasoning' => 'Fills missing meta with per-brand voice anchor',
                        ], JSON_THROW_ON_ERROR),
                    ),
                ])
                ->withUsage(new Usage(promptTokens: 600, completionTokens: 200)))
            ->toResponse(),
    ]);
}

it('Fixture 1 — happy path with AGENT_WRITE_ENABLED=true: one seo_content_patch Suggestion with 2 patches', function () {
    config()->set('agents.write_enabled', true);
    fakeSeoAgentTwoPatches();

    $product = makeSeoEligibleProduct();

    (new RunSeoAgentJob(productId: $product->id))->handle(
        registry: app(\App\Domain\Agents\Services\AgentRegistry::class),
        budgetGuard: app(\App\Domain\Agents\Services\BudgetGuard::class),
        toolBus: app(\App\Domain\Agents\Services\ToolBus::class),
        guardrailEngine: app(\App\Domain\Agents\Services\GuardrailEngine::class),
        client: app(\App\Domain\Agents\Clients\ClaudeClient::class),
        promptRenderer: app(\App\Domain\Agents\Services\PromptRenderer::class),
        mapper: app(\App\Domain\Agents\Services\SeoAgentResultMapper::class),
    );

    $run = AgentRun::query()->where('kind', 'seo')->latest('started_at')->first();
    expect($run)->not->toBeNull();
    expect($run->status->value)->toBe('completed');
    expect((string) $run->system_prompt_hash)->toHaveLength(64);
    expect($run->tool_calls)->toBeArray();

    // ONE Suggestion of kind 'seo_content_patch' with 2 patches in payload
    expect(Suggestion::where('kind', 'seo_content_patch')->count())->toBe(1);
    $suggestion = Suggestion::where('kind', 'seo_content_patch')->first();
    $patches = (array) data_get($suggestion->payload, 'patches', []);
    expect($patches)->toHaveCount(2);
    expect((int) data_get($suggestion->payload, 'product_id'))->toBe($product->id);
});

it('Fixture 2 — shadow mode AGENT_WRITE_ENABLED=false: AgentRun persists, ZERO Suggestions', function () {
    config()->set('agents.write_enabled', false);
    fakeSeoAgentTwoPatches();

    $product = makeSeoEligibleProduct();

    (new RunSeoAgentJob(productId: $product->id))->handle(
        registry: app(\App\Domain\Agents\Services\AgentRegistry::class),
        budgetGuard: app(\App\Domain\Agents\Services\BudgetGuard::class),
        toolBus: app(\App\Domain\Agents\Services\ToolBus::class),
        guardrailEngine: app(\App\Domain\Agents\Services\GuardrailEngine::class),
        client: app(\App\Domain\Agents\Clients\ClaudeClient::class),
        promptRenderer: app(\App\Domain\Agents\Services\PromptRenderer::class),
        mapper: app(\App\Domain\Agents\Services\SeoAgentResultMapper::class),
    );

    $run = AgentRun::query()->where('kind', 'seo')->latest('started_at')->first();
    expect($run)->not->toBeNull();
    expect($run->status->value)->toBe('completed');

    // ZERO Suggestions
    expect(Suggestion::count())->toBe(0);
});

it('Fixture 3 — eligibility re-check: product no longer pending_review exits without creating AgentRun', function () {
    config()->set('agents.write_enabled', true);
    fakeSeoAgentTwoPatches();

    // Product status flipped to 'published' between dispatch and handle
    $product = makeSeoEligibleProduct(['auto_create_status' => 'published']);

    (new RunSeoAgentJob(productId: $product->id))->handle(
        registry: app(\App\Domain\Agents\Services\AgentRegistry::class),
        budgetGuard: app(\App\Domain\Agents\Services\BudgetGuard::class),
        toolBus: app(\App\Domain\Agents\Services\ToolBus::class),
        guardrailEngine: app(\App\Domain\Agents\Services\GuardrailEngine::class),
        client: app(\App\Domain\Agents\Clients\ClaudeClient::class),
        promptRenderer: app(\App\Domain\Agents\Services\PromptRenderer::class),
        mapper: app(\App\Domain\Agents\Services\SeoAgentResultMapper::class),
    );

    // No AgentRun row created — eligibility re-check fired before AgentRun::create
    expect(AgentRun::query()->where('kind', 'seo')->count())->toBe(0);
    expect(Suggestion::count())->toBe(0);
});
