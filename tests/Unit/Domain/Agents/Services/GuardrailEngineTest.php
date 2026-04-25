<?php

declare(strict_types=1);

use App\Domain\Agents\Clients\ClaudeResponse;
use App\Domain\Agents\Contracts\Guardrail;
use App\Domain\Agents\Contracts\RunsAsAgent;
use App\Domain\Agents\Enums\FinishReason;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Exceptions\GuardrailViolationException;
use App\Domain\Agents\Guardrails\OutboundRegexFilterGuardrail;
use App\Domain\Agents\Guardrails\PromptInjectionXmlFenceGuardrail;
use App\Domain\Agents\Guardrails\SensitiveFieldsStripGuardrail;
use App\Domain\Agents\Services\GuardrailEngine;
use App\Domain\Agents\ValueObjects\AgentResult;

/*
|--------------------------------------------------------------------------
| Phase 8 Plan 03 Task 3 — GuardrailEngine + 3 concrete guardrails (AGNT-06)
|--------------------------------------------------------------------------
*/

function fixtureClaudeResponse(string $text = 'response'): ClaudeResponse
{
    return new ClaudeResponse(
        text: $text,
        finishReason: FinishReason::EndTurn,
        promptTokens: 10,
        completionTokens: 20,
        costPence: 1,
        langfuseTraceId: null,
        toolCalls: [],
        steps: [],
        responseMessages: [],
    );
}

function fixtureAgentWithGuardrails(array $guardrails): RunsAsAgent
{
    return new class($guardrails) implements RunsAsAgent
    {
        public function __construct(private readonly array $g) {}

        public static function kind(): string
        {
            return 'fixture';
        }

        public static function trustTier(): TrustTier
        {
            return TrustTier::Untrusted;
        }

        public function tools(): array
        {
            return [];
        }

        public function systemPrompt(array $context = []): string
        {
            return 'ok';
        }

        public function guardrails(): array
        {
            return $this->g;
        }

        public function execute(array $input, TrustTier $tier): AgentResult
        {
            return new AgentResult(
                suggestionDrafts: [],
                agentReasoning: '',
                finishReason: FinishReason::EndTurn,
                promptTokens: 0,
                completionTokens: 0,
                costPence: 0,
                langfuseTraceId: null,
                toolCalls: [],
            );
        }
    };
}

it('runPreFlight chains guardrails in declared order (Test 4)', function (): void {
    $a = new class implements Guardrail
    {
        public function isPreFlight(): bool
        {
            return true;
        }

        public function isPostFlight(): bool
        {
            return false;
        }

        public function shouldRun(TrustTier $t): bool
        {
            return true;
        }

        public function pre(array $input): array
        {
            return $input + ['a' => 'a-ran'];
        }

        public function post(ClaudeResponse $r): ClaudeResponse
        {
            return $r;
        }
    };
    $b = new class implements Guardrail
    {
        public function isPreFlight(): bool
        {
            return true;
        }

        public function isPostFlight(): bool
        {
            return false;
        }

        public function shouldRun(TrustTier $t): bool
        {
            return true;
        }

        public function pre(array $input): array
        {
            return $input + ['b_saw_a' => $input['a'] ?? 'no-a'];
        }

        public function post(ClaudeResponse $r): ClaudeResponse
        {
            return $r;
        }
    };

    $engine = new GuardrailEngine;
    $agent = fixtureAgentWithGuardrails([$a, $b]);
    $result = $engine->runPreFlight($agent, [], TrustTier::Untrusted);

    expect($result['a'])->toBe('a-ran')
        ->and($result['b_saw_a'])->toBe('a-ran');
});

it('runPostFlight short-circuits on first violation (Test 5)', function (): void {
    $a = new class implements Guardrail
    {
        public function isPreFlight(): bool
        {
            return false;
        }

        public function isPostFlight(): bool
        {
            return true;
        }

        public function shouldRun(TrustTier $t): bool
        {
            return true;
        }

        public function pre(array $input): array
        {
            return $input;
        }

        public function post(ClaudeResponse $r): ClaudeResponse
        {
            throw GuardrailViolationException::fromGuardrail(static::class, 'A blocked');
        }
    };
    $b = new class implements Guardrail
    {
        public bool $ran = false;

        public function isPreFlight(): bool
        {
            return false;
        }

        public function isPostFlight(): bool
        {
            return true;
        }

        public function shouldRun(TrustTier $t): bool
        {
            return true;
        }

        public function pre(array $input): array
        {
            return $input;
        }

        public function post(ClaudeResponse $r): ClaudeResponse
        {
            $this->ran = true;

            return $r;
        }
    };

    $engine = new GuardrailEngine;
    $agent = fixtureAgentWithGuardrails([$a, $b]);

    expect(fn () => $engine->runPostFlight($agent, fixtureClaudeResponse(), TrustTier::Untrusted))
        ->toThrow(GuardrailViolationException::class, 'A blocked');
    expect($b->ran)->toBeFalse();
});

it('PromptInjectionXmlFence shouldRun is true for Mixed/Untrusted, false for Trusted (Test 6)', function (): void {
    $g = new PromptInjectionXmlFenceGuardrail;

    expect($g->shouldRun(TrustTier::Untrusted))->toBeTrue()
        ->and($g->shouldRun(TrustTier::Mixed))->toBeTrue()
        ->and($g->shouldRun(TrustTier::Trusted))->toBeFalse();
});

it('PromptInjectionXmlFence wraps strings + prepends preamble (Test 7)', function (): void {
    $g = new PromptInjectionXmlFenceGuardrail;
    $out = $g->pre(['user_message' => 'hello']);

    expect($out)->toHaveKey('_guardrail_preamble')
        ->and($out['_guardrail_preamble'])->toBe(PromptInjectionXmlFenceGuardrail::PREAMBLE)
        ->and($out['user_message'])->toBe('<untrusted_user_input>hello</untrusted_user_input>');
});

it('OutboundRegexFilter throws fromGuardrail on forbidden pattern (Test 8)', function (): void {
    $g = new OutboundRegexFilterGuardrail;
    $response = fixtureClaudeResponse('the cost_price: 250 was the supplier rate');

    try {
        $g->post($response);
        $this->fail('expected GuardrailViolationException');
    } catch (GuardrailViolationException $e) {
        expect($e->guardrailClass)->toBe(OutboundRegexFilterGuardrail::class);
    }
});

it('SensitiveFieldsStrip strip() redacts forbidden keys recursively (Test 9)', function (): void {
    $g = new SensitiveFieldsStripGuardrail;
    $stripped = $g->strip([
        'sku' => 'X',
        'cost_price' => 250,
        'margin' => 30,
        'nested' => [
            'supplier_price' => 100,
            'name' => 'Acme',
        ],
    ]);

    expect($stripped['sku'])->toBe('X')
        ->and($stripped['cost_price'])->toBe('[REDACTED]')
        ->and($stripped['margin'])->toBe('[REDACTED]')
        ->and($stripped['nested']['supplier_price'])->toBe('[REDACTED]')
        ->and($stripped['nested']['name'])->toBe('Acme');
});
