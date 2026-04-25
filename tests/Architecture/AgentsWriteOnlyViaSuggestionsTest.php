<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Architecture: Phase 8 Plan 01 — Agents domain DB-write prohibition (AGNT-10)
|--------------------------------------------------------------------------
|
| Pitfall A3 (CRITICAL — DB-write bypass). The C4 agent framework's load-
| bearing invariant is that NO agent code under app/Domain/Agents/* writes
| directly to v1 tables. Every data change must flow through the Suggestions
| seam (Plan 03 ships AgentSuggestionWriter). The framework's own AgentRun
| persistence is the ONLY exception — that's why Models/AgentRun.php is
| excluded from the scan.
|
| Catches at architectural test layer rather than runtime so a regression
| introduced by a future plan trips CI on first push, before any tokens are
| burnt. Architecture-test pattern matches the v1 invariant test approach
| (Phase 5 + 7 established) and the dual-YAML grep pattern used by
| DeptracDashboardLayerTest.
|
| Currently passes vacuously (only Models + Enums + Policy under
| app/Domain/Agents at end of Plan 01). Plan 03 ships Services + Clients
| which the test then guards as real coverage; Plan 04 adds Jobs / Console
| commands which must also stay clean.
*/

it('forbids direct DB writes from app/Domain/Agents (only Models/AgentRun* may write)', function (): void {
    $agentsDir = app_path('Domain/Agents');
    if (! is_dir($agentsDir)) {
        // Agents directory not yet present — Plan 03+04 ship the bulk of code.
        // Test passes vacuously until then; never a false-green because the
        // existence of the directory is forced by Plan 01's models/enums/policy.
        test()->markTestSkipped('app/Domain/Agents not present yet (Plan 03+04 land service code).');
    }

    $finder = (new Finder)
        ->in($agentsDir)
        ->name('*.php')
        ->files()
        // Models/AgentRun.php IS the framework-write seam — its $table = 'agent_runs'
        // is the legitimate exception. Factory writes are also legitimate.
        ->notPath('Models/AgentRun.php')
        // Phase 8 Plan 03 — Services/AgentSuggestionWriter.php IS the sanctioned
        // write path from Agents → Suggestions (AGNT-12 + AGNT-13). It calls
        // Suggestion::create with the proposed_by morph activation; the architecture
        // contract permits exactly this one writer outside Models/AgentRun.php.
        ->notPath('Services/AgentSuggestionWriter.php')
        // Phase 8 Plan 04 — Jobs/RunAgentJob.php IS the framework orchestrator
        // and the sole framework writer for AgentRun rows (AGNT-12). Its
        // AgentRun::create / $run->update calls persist agent forensics state
        // per CONTEXT D-06; everything else in the Agents domain stays gated.
        // Per AgentRun.php docblock: "Writes flow ONLY through Plan 04's
        // RunAgentJob (the framework writer)".
        ->notPath('Jobs/RunAgentJob.php');

    // Catches: Eloquent ::create() / save() / update() / delete() and
    // raw DB facade insert/update/delete (including DB::table()->op chains).
    // Anchors on `(` to avoid matching property/variable names like ->save_state.
    $forbidden = '/(::create\(|->save\(|::update\(|::delete\(|DB::insert|DB::update|DB::delete|DB::table\([^)]+\)->insert|DB::table\([^)]+\)->update|DB::table\([^)]+\)->delete)/';

    $violations = [];
    foreach ($finder as $file) {
        $content = $file->getContents();
        if (preg_match($forbidden, $content, $m)) {
            $violations[] = $file->getRelativePathname().' — matched: '.$m[0];
        }
    }

    expect($violations)->toBe(
        [],
        'Agents domain may only write via Suggestions seam (AgentSuggestionWriter — Plan 03). '
            .'Direct writes outside Models/AgentRun.php detected: '.implode(' | ', $violations)
    );
});
