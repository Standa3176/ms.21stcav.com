<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HOTFIX 260712-pbi — proposed_by_id must hold AgentRun ULIDs
|--------------------------------------------------------------------------
|
| Regression guard for the exact path that failed in prod: agent mappers set
| proposed_by_type = AgentRun::class + proposed_by_id = $run->id (a 26-char
| ULID). Before the widening migration, proposed_by_id was an UNSIGNED BIGINT
| (from nullableMorphs) and the INSERT died on MariaDB with
| SQLSTATE 1265 "Data truncated for column 'proposed_by_id'".
|
| SQLite ignores column typing so the INSERT itself never failed here — the
| column-type assertion below is what catches the integer-column regression.
| The DEFINITIVE MariaDB proof is the prod re-run of "Review with Claude".
*/

use App\Domain\Agents\Models\AgentRun;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\Schema;

it('round-trips an AgentRun-proposed Suggestion via the ULID morph', function () {
    $run = AgentRun::factory()->create();

    // ULID primary key — the value that used to truncate against an int column.
    expect($run->id)->toBeString()->toHaveLength(26);

    $suggestion = Suggestion::create([
        'kind' => 'ad_optimisation',
        'payload' => ['proposals' => []],
        'proposed_at' => now(),
        'proposed_by_type' => AgentRun::class,
        'proposed_by_id' => $run->id,
    ]);

    // Persisted intact (no truncation / no data loss).
    expect($suggestion->proposed_by_id)->toBe($run->id);

    // Morph resolves back to the exact AgentRun after a DB round-trip.
    $resolved = $suggestion->fresh()->proposedBy;
    expect($resolved)->toBeInstanceOf(AgentRun::class);
    expect($resolved->id)->toBe($run->id);
});

it('stores proposed_by_id as a non-integer (ULID-capable) column post-migration', function () {
    // Driver-aware: MariaDB -> char; SQLite maps char(26) to its varchar
    // affinity. Either way it must NOT be an integer type (the prod bug).
    $type = Schema::getColumnType('suggestions', 'proposed_by_id');

    expect($type)->not->toBeIn(['integer', 'bigint', 'biginteger']);
});
