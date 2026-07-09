<?php

declare(strict_types=1);

use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Services\AgentRunGdprScrubber;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Phase 8 Plan 05 Task 2 — AgentRunGdprScrubberTest (D-09)
|--------------------------------------------------------------------------
|
| Verifies the 8 plan-spec behaviours:
|   1. tool_calls inputs scrubbed (PII keys → REDACTED-{prefix})
|   2. agent_reasoning_summary replaced with [scrubbed per GDPR erasure {ulid}]
|   3. cost_pence / kind / langfuse_trace_id / timestamps preserved
|   4. prompt_token_count / completion_token_count / system_prompt_hash preserved
|   5. idempotent (re-scrub yields same final state)
|   6. gdpr_erasure_log row written with agent_run_ids[] in notes
|   7. unrelated rows untouched
|   8. integration with gdpr:erase-bitrix-customer command (DI wiring smoke)
*/

it('redacts customer email tokens in tool_calls (Test 1)', function (): void {
    $run = AgentRun::factory()->create([
        'tool_calls' => [
            [
                'tool_name' => 'read_customer',
                'inputs' => ['customer_email' => 'alice@example.com'],
                'outputs' => ['ok' => true],
            ],
        ],
        'agent_reasoning_summary' => 'looked up alice@example.com',
    ]);

    app(AgentRunGdprScrubber::class)->scrubForCustomer('alice@example.com', 'gdpr-ulid-001');

    $run->refresh();
    $tc = $run->tool_calls;
    expect($tc[0]['inputs']['customer_email'])->toStartWith('REDACTED-');
});

it('replaces agent_reasoning_summary with scrub marker (Test 2)', function (): void {
    $run = AgentRun::factory()->create([
        'tool_calls' => [],
        'agent_reasoning_summary' => 'discussed with alice@example.com',
    ]);

    app(AgentRunGdprScrubber::class)->scrubForCustomer('alice@example.com', 'gdpr-ulid-002');

    $run->refresh();
    expect($run->agent_reasoning_summary)->toContain('[scrubbed per GDPR erasure gdpr-ulid-002]');
});

it('preserves cost_pence, kind, langfuse_trace_id, timestamps (Test 3)', function (): void {
    $started = now()->subMinutes(5);
    $completed = now()->subMinute();
    $run = AgentRun::factory()->create([
        'kind' => 'echo',
        'cost_pence' => 42,
        'langfuse_trace_id' => 'trace-abc-123',
        'started_at' => $started,
        'completed_at' => $completed,
        'tool_calls' => [['inputs' => ['email' => 'alice@example.com']]],
        'agent_reasoning_summary' => 'alice@example.com',
    ]);

    app(AgentRunGdprScrubber::class)->scrubForCustomer('alice@example.com', 'gdpr-ulid-003');

    $run->refresh();
    expect($run->cost_pence)->toBe(42);
    expect($run->kind->value)->toBe('echo');
    expect($run->langfuse_trace_id)->toBe('trace-abc-123');
    // agent_runs.started_at/completed_at are `timestamp` columns (second
    // precision — no microseconds). Compare at the granularity the column
    // persists; asserting microsecond-equality against the in-memory Carbon
    // (which carries µs) would never pass on any driver. Intent unchanged:
    // the scrub preserves the timestamps (it never writes these columns).
    expect($run->started_at->format('Y-m-d H:i:s'))->toBe($started->format('Y-m-d H:i:s'));
    expect($run->completed_at->format('Y-m-d H:i:s'))->toBe($completed->format('Y-m-d H:i:s'));
});

it('preserves token counts and system_prompt_hash (Test 4)', function (): void {
    $hash = str_repeat('b', 64);
    $run = AgentRun::factory()->create([
        'prompt_token_count' => 100,
        'completion_token_count' => 50,
        'system_prompt_hash' => $hash,
        'tool_calls' => [['inputs' => ['email' => 'alice@example.com']]],
        'agent_reasoning_summary' => 'alice@example.com',
    ]);

    app(AgentRunGdprScrubber::class)->scrubForCustomer('alice@example.com', 'gdpr-ulid-004');

    $run->refresh();
    expect($run->prompt_token_count)->toBe(100);
    expect($run->completion_token_count)->toBe(50);
    expect($run->system_prompt_hash)->toBe($hash);
});

it('is idempotent — re-scrub yields same final state (Test 5)', function (): void {
    $run = AgentRun::factory()->create([
        'tool_calls' => [['inputs' => ['email' => 'alice@example.com']]],
        'agent_reasoning_summary' => 'reasoning about alice@example.com',
    ]);

    $scrubber = app(AgentRunGdprScrubber::class);
    $scrubber->scrubForCustomer('alice@example.com', 'gdpr-ulid-005');
    $run->refresh();
    $stateAfterFirst = [
        'tool_calls' => $run->tool_calls,
        'summary' => $run->agent_reasoning_summary,
    ];

    // Second pass — alice@example.com is no longer in the row, so the scrub
    // is a no-op for THIS run row. The summary keeps its scrubbed marker.
    $scrubber->scrubForCustomer('alice@example.com', 'gdpr-ulid-005');
    $run->refresh();
    expect($run->tool_calls)->toBe($stateAfterFirst['tool_calls']);
    expect($run->agent_reasoning_summary)->toBe($stateAfterFirst['summary']);
});

it('writes a gdpr_erasure_log row with agent_run_ids in notes (Test 6)', function (): void {
    $run1 = AgentRun::factory()->create([
        'tool_calls' => [['inputs' => ['email' => 'alice@example.com']]],
        'agent_reasoning_summary' => null,
    ]);
    $run2 = AgentRun::factory()->create([
        'tool_calls' => [],
        'agent_reasoning_summary' => 'alice@example.com cost analysis',
    ]);

    $countBefore = DB::table('gdpr_erasure_log')->count();
    app(AgentRunGdprScrubber::class)->scrubForCustomer('alice@example.com', 'gdpr-ulid-006');
    $countAfter = DB::table('gdpr_erasure_log')->count();

    expect($countAfter - $countBefore)->toBe(1);

    $logRow = DB::table('gdpr_erasure_log')->orderByDesc('id')->first();
    expect($logRow->status)->toBe('applied');
    $notes = json_decode((string) $logRow->notes, true);
    expect($notes['context'])->toBe('agent_runs');
    expect($notes['agent_run_ids'])->toContain($run1->id, $run2->id);
});

it('leaves unrelated rows untouched (Test 7)', function (): void {
    $unrelated = AgentRun::factory()->create([
        'tool_calls' => [['inputs' => ['email' => 'bob@other.com']]],
        'agent_reasoning_summary' => 'unrelated reasoning',
    ]);
    AgentRun::factory()->create([
        'tool_calls' => [['inputs' => ['email' => 'alice@example.com']]],
        'agent_reasoning_summary' => 'alice@example.com',
    ]);

    app(AgentRunGdprScrubber::class)->scrubForCustomer('alice@example.com', 'gdpr-ulid-007');

    $unrelated->refresh();
    expect($unrelated->tool_calls[0]['inputs']['email'])->toBe('bob@other.com');
    expect($unrelated->agent_reasoning_summary)->toBe('unrelated reasoning');
});

it('is wired into gdpr:erase-bitrix-customer via constructor DI (Test 8)', function (): void {
    // Reflection check — the v1 command's constructor accepts an
    // AgentRunGdprScrubber dependency. The container resolves it cleanly
    // (no circular dependency / missing binding).
    $command = app(\App\Domain\CRM\Console\Commands\GdprEraseBitrixCustomerCommand::class);
    expect($command)->toBeInstanceOf(\App\Domain\CRM\Console\Commands\GdprEraseBitrixCustomerCommand::class);

    $reflection = new ReflectionClass($command);
    $constructor = $reflection->getConstructor();
    expect($constructor)->not->toBeNull();
    $params = $constructor->getParameters();
    $hasScrubber = collect($params)->contains(
        fn (ReflectionParameter $p) => (string) $p->getType() === \App\Domain\Agents\Services\AgentRunGdprScrubber::class
    );
    expect($hasScrubber)->toBeTrue();
});
