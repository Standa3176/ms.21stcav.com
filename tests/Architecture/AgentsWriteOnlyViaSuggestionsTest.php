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
        ->notPath('Jobs/RunAgentJob.php')
        // Phase 10 Plan 04 — Jobs/RunPricingAgentJob.php is the Path A SIBLING
        // of Phase 8 RunAgentJob (RESEARCH §Pattern 1). It is the second
        // sanctioned writer of AgentRun rows (kind='pricing' specifically) —
        // mirrors RunAgentJob's AgentRun::create + $run->update lifecycle so
        // the framework's forensic invariants hold for PricingAgent runs.
        // Suggestion writes flow through PricingAgentResultMapper (also exempt).
        ->notPath('Jobs/RunPricingAgentJob.php')
        // Phase 8 Plan 05 Task 3 — AgentsPruneArchiveCommand IS the
        // sanctioned writer for the activity_log audit row that records
        // agent_run_archived prunes (D-07 retention). It also DELETEs aged
        // AgentRun rows but those are the framework's OWN data, not v1 data.
        ->notPath('Console/Commands/AgentsPruneArchiveCommand.php')
        // Phase 8 Plan 05 Task 2 — AgentRunGdprScrubber IS the sanctioned
        // writer for the gdpr_erasure_log audit row (D-09). It also updates
        // AgentRun rows to scrub PII in-place; both writes are deliberate
        // audit-trail writes, not data writes through the Suggestions seam.
        ->notPath('Services/AgentRunGdprScrubber.php')
        // Phase 10 Plan 04 — PricingAgentResultMapper IS the sanctioned
        // writer for Suggestion.evidence agent_* enrichment keys (CONTEXT
        // D-02 + D-06 + D-11). It writes to existing Suggestion rows
        // (created earlier by Phase 5 ComputeMarginSuggestionJob) — never
        // creates new Suggestion rows. Mapper-as-writer pattern keeps
        // persistence side-effects testable independently of the LLM
        // round-trip; ProposeMarginBandTool stays a no-op writer per D-06.
        ->notPath('Services/PricingAgentResultMapper.php')
        // Phase 12 Plan 04 — Jobs/RunSeoAgentJob is the Path A SIBLING for
        // the SeoAgent (mirrors RunPricingAgentJob shape). Writes AgentRun
        // rows for kind='seo'; Suggestion writes flow through the mapper.
        ->notPath('Jobs/RunSeoAgentJob.php')
        // Phase 12 Plan 04 — SeoAgentResultMapper IS the sanctioned writer
        // for kind='seo_content_patch' (bundled per-product Suggestion) AND
        // kind='agent_guardrail_blocked' (audit-only forensic row). P12-A
        // last-wins dedup + P12-B catch-block audit path documented in
        // the class docblock.
        ->notPath('Services/SeoAgentResultMapper.php')
        // Phase 12 Plan 04 — SeoContentPatchApplier writes through approved
        // patches to Product.{name|*_description} + ProductOverride.pin_*
        // canonical columns + audit row. P12 CRITICAL title→name column
        // mapping fenced by SeoContentPatchApplierTitleToNameTest.
        ->notPath('Appliers/SeoContentPatchApplier.php');

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
