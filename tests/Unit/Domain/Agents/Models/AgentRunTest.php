<?php

declare(strict_types=1);

use App\Domain\Agents\Enums\AgentKind;
use App\Domain\Agents\Enums\AgentRunStatus;
use App\Domain\Agents\Enums\FinishReason;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Models\AgentRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('AgentRun PK is a 26-character ULID', function () {
    $run = AgentRun::factory()->create();
    expect($run->id)->toBeString()->toHaveLength(26);
});

it('status casts to AgentRunStatus enum and defaults to running', function () {
    $run = AgentRun::factory()->create();
    expect($run->status)->toBeInstanceOf(AgentRunStatus::class)
        ->and($run->status)->toBe(AgentRunStatus::Running);
});

it('kind casts to AgentKind enum', function () {
    $run = AgentRun::factory()->create();
    expect($run->kind)->toBeInstanceOf(AgentKind::class)
        ->and($run->kind)->toBe(AgentKind::Echo);
});

it('tool_calls casts to array', function () {
    $run = AgentRun::factory()->create(['tool_calls' => [['tool_name' => 'read_health_check']]]);
    expect($run->tool_calls)->toBeArray()
        ->and($run->tool_calls[0]['tool_name'])->toBe('read_health_check');
});

it('finish_reason casts to FinishReason enum (nullable)', function () {
    $run = AgentRun::factory()->create(['finish_reason' => 'end_turn']);
    expect($run->finish_reason)->toBeInstanceOf(FinishReason::class)
        ->and($run->finish_reason)->toBe(FinishReason::EndTurn);

    $nullRun = AgentRun::factory()->create();
    expect($nullRun->finish_reason)->toBeNull();
});

it('LogsActivity captures only status and completed_at dirty fields', function () {
    $run = AgentRun::factory()->create();
    // Mutating cost_pence MUST NOT log (not in logOnly).
    $run->cost_pence = 250;
    $run->save();
    $costActivity = Activity::where('subject_id', $run->id)
        ->where('subject_type', AgentRun::class)
        ->latest()
        ->first();
    // Either no activity entry, or properties.attributes excludes cost_pence.
    if ($costActivity !== null) {
        $attrs = $costActivity->properties['attributes'] ?? [];
        expect($attrs)->not->toHaveKey('cost_pence');
    }

    // Mutating status MUST log status.
    $run->status = AgentRunStatus::Completed;
    $run->completed_at = now();
    $run->save();
    $statusActivity = Activity::where('subject_id', $run->id)
        ->where('subject_type', AgentRun::class)
        ->latest()
        ->first();
    expect($statusActivity)->not->toBeNull();
    $attrs = $statusActivity->properties['attributes'] ?? [];
    expect($attrs)->toHaveKey('status');
});

it('factory builds a valid running AgentRun with kind echo', function () {
    $run = AgentRun::factory()->create();
    expect($run->kind)->toBe(AgentKind::Echo)
        ->and($run->status)->toBe(AgentRunStatus::Running)
        ->and($run->started_at)->not->toBeNull();
});

it('AgentKind enum values are exactly echo, pricing, seo, chatbot, ad_optimisation', function () {
    $values = array_map(fn ($c) => $c->value, AgentKind::cases());
    expect($values)->toBe(['echo', 'pricing', 'seo', 'chatbot', 'ad_optimisation']);
});

it('AgentRunStatus enum has the 6 lifecycle values', function () {
    $values = array_map(fn ($c) => $c->value, AgentRunStatus::cases());
    expect($values)->toBe([
        'running',
        'completed',
        'failed',
        'budget_exceeded',
        'guardrail_blocked',
        'monthly_budget_blocked',
    ]);
});

it('FinishReason enum has the 5 Prism stop-reason values', function () {
    $values = array_map(fn ($c) => $c->value, FinishReason::cases());
    expect($values)->toBe(['end_turn', 'tool_use', 'max_tokens', 'stop_sequence', 'error']);
});

it('TrustTier enum has the 3 trust-tier values', function () {
    $values = array_map(fn ($c) => $c->value, TrustTier::cases());
    expect($values)->toBe(['trusted', 'mixed', 'untrusted']);
});

it('guardrail_failures casts to array (nullable; null on fresh row)', function () {
    $fresh = AgentRun::factory()->create();
    expect($fresh->guardrail_failures)->toBeNull();

    $failures = [[
        'guardrail' => 'App\\Domain\\Agents\\Guardrails\\OutboundRegexFilterGuardrail',
        'message' => 'pattern matched: /cost_price\s*[:=]\s*\d+/i',
        'when' => 'post',
        'occurred_at' => '2026-04-25T12:34:56+01:00',
    ]];
    $blocked = AgentRun::factory()->create(['guardrail_failures' => $failures]);
    expect($blocked->guardrail_failures)->toBeArray()
        ->and($blocked->guardrail_failures[0]['guardrail'])->toContain('OutboundRegexFilterGuardrail');
});
