<?php

declare(strict_types=1);

use App\Domain\Agents\Exceptions\UnauthorisedToolException;
use App\Domain\Agents\Services\Tools\Tool;
use App\Domain\Agents\Services\ToolBus;

/*
|--------------------------------------------------------------------------
| Phase 8 Plan 03 Task 3 — ToolBus naming + truncation (AGNT-05)
|--------------------------------------------------------------------------
|
| Verifies the runtime naming gate (Tests 1-2) + the 4KB truncation utility
| RunAgentJob will call before persisting tool I/O onto AgentRun.tool_calls
| (Test 3 — runs via the truncate() helper instead of full Prism instrumentation
| because tool-call recording is finalised in Plan 04 from ClaudeResponse->steps).
*/

it('rejects a tool whose name() does not start with propose_/read_/search_ (Test 1)', function (): void {
    $bus = new ToolBus;
    $tool = new class extends Tool
    {
        public function name(): string
        {
            return 'create_something';
        }

        public function description(): string
        {
            return 'forbidden';
        }

        public function asPrismTool(): \Prism\Prism\Tool
        {
            return new \Prism\Prism\Tool;
        }
    };

    expect(fn () => $bus->assertNameAllowed($tool))
        ->toThrow(UnauthorisedToolException::class, "Tool 'create_something' does not start with");
});

it('accepts propose_ / read_ / search_ prefixed names (Test 2 — allow-list)', function (): void {
    $bus = new ToolBus;
    foreach (['propose_margin_change', 'read_health_check', 'search_competitor_prices'] as $name) {
        $tool = new class($name) extends Tool
        {
            public function __construct(private readonly string $n) {}

            public function name(): string
            {
                return $this->n;
            }

            public function description(): string
            {
                return 'ok';
            }

            public function asPrismTool(): \Prism\Prism\Tool
            {
                return new \Prism\Prism\Tool;
            }
        };

        expect(fn () => $bus->assertNameAllowed($tool))->not->toThrow(\Throwable::class);
    }
});

it('truncate() applies 4KB cap to long strings (Test 3a)', function (): void {
    $bus = new ToolBus;
    $long = str_repeat('a', 5000);
    $result = $bus->truncate($long, ToolBus::MAX_OUTPUT_BYTES);

    expect($result)->toBeString()
        ->and(strlen((string) $result))->toBeLessThanOrEqual(ToolBus::MAX_OUTPUT_BYTES + 20)
        ->and((string) $result)->toEndWith('...[truncated]');
});

it('truncate() preserves short strings unchanged (Test 3b)', function (): void {
    $bus = new ToolBus;
    expect($bus->truncate('short', ToolBus::MAX_OUTPUT_BYTES))->toBe('short');
});

it('truncate() converts oversize array to _truncated marker (Test 3c)', function (): void {
    $bus = new ToolBus;
    $bigArray = ['data' => str_repeat('x', 5000)];
    $result = $bus->truncate($bigArray, ToolBus::MAX_OUTPUT_BYTES);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('_truncated')
        ->and($result['_truncated'])->toBeTrue()
        ->and($result)->toHaveKey('_size_bytes')
        ->and($result)->toHaveKey('_preview');
});
