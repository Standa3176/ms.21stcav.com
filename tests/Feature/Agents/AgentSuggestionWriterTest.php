<?php

declare(strict_types=1);

use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\Services\AgentSuggestionWriter;
use App\Domain\Agents\ValueObjects\SuggestionDraft;
use App\Domain\Suggestions\Models\Suggestion;

/*
|--------------------------------------------------------------------------
| Phase 8 Plan 03 Task 3 — AgentSuggestionWriter (AGNT-12 + AGNT-13)
|--------------------------------------------------------------------------
|
| Sole DB-write seam tests. RefreshDatabase auto-applies (tests/Feature/
| location). Verifies:
|   - shadow-mode flag (AGENT_WRITE_ENABLED=false → status='shadow')
|   - pending-mode flag (AGENT_WRITE_ENABLED=true  → status='pending')
|   - morph activation (proposed_by_type=AgentRun::class + proposed_by_id={run.id})
|   - correlation_id thread (Suggestion.correlation_id == AgentRun.triggering_correlation_id)
*/

it('write() sets status=shadow when AGENT_WRITE_ENABLED=false (Test 10)', function (): void {
    config(['agents.write_enabled' => false]);
    $run = AgentRun::factory()->create([
        'triggering_correlation_id' => 'corr-001',
    ]);
    $writer = app(AgentSuggestionWriter::class);
    $draft = new SuggestionDraft(
        kind: 'echo_health',
        payload: ['ok' => true],
        evidence: ['ts' => '2026-04-25'],
    );

    $suggestion = $writer->write($draft, $run);

    expect($suggestion->status)->toBe('shadow');
});

it('write() sets status=pending when AGENT_WRITE_ENABLED=true (Test 11)', function (): void {
    config(['agents.write_enabled' => true]);
    $run = AgentRun::factory()->create([
        'triggering_correlation_id' => 'corr-002',
    ]);
    $writer = app(AgentSuggestionWriter::class);
    $draft = new SuggestionDraft('echo_health', ['ok' => true], []);

    $suggestion = $writer->write($draft, $run);

    expect($suggestion->status)->toBe(Suggestion::STATUS_PENDING);
});

it('write() activates the proposed_by morph: type=AgentRun + id=run.id (Test 12)', function (): void {
    $run = AgentRun::factory()->create([
        'triggering_correlation_id' => 'corr-morph',
    ]);
    $writer = app(AgentSuggestionWriter::class);
    $draft = new SuggestionDraft('echo_health', ['ok' => true], []);

    $suggestion = $writer->write($draft, $run);
    $reloaded = Suggestion::find($suggestion->id);

    expect($reloaded->proposed_by_type)->toBe(AgentRun::class)
        ->and($reloaded->proposed_by_id)->toBe($run->id)
        ->and($reloaded->proposedBy)->toBeInstanceOf(AgentRun::class)
        ->and($reloaded->proposedBy->id)->toBe($run->id);
});

it('write() copies AgentRun.triggering_correlation_id onto Suggestion.correlation_id (Test 13)', function (): void {
    $run = AgentRun::factory()->create([
        'triggering_correlation_id' => 'corr-thread-xyz',
    ]);
    $writer = app(AgentSuggestionWriter::class);
    $draft = new SuggestionDraft('echo_health', ['ok' => true], []);

    $suggestion = $writer->write($draft, $run);

    expect($suggestion->correlation_id)->toBe('corr-thread-xyz');
});

it('write() persists payload + evidence as JSON-castable arrays', function (): void {
    $run = AgentRun::factory()->create([
        'triggering_correlation_id' => 'corr-payload',
    ]);
    $writer = app(AgentSuggestionWriter::class);
    $draft = new SuggestionDraft(
        kind: 'echo_health',
        payload: ['action' => 'noop', 'count' => 3],
        evidence: ['source' => 'echo', 'sha' => 'abc123'],
    );

    $reloaded = Suggestion::find($writer->write($draft, $run)->id);

    expect($reloaded->payload)->toBe(['action' => 'noop', 'count' => 3])
        ->and($reloaded->evidence)->toBe(['source' => 'echo', 'sha' => 'abc123']);
});
