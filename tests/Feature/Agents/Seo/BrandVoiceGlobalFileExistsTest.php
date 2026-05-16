<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 02 Task 1 — Mandatory _global.md file invariant
|--------------------------------------------------------------------------
|
| Per RESEARCH §Tool 2 + CONTEXT D-02: _global.md is MANDATORY. If absent,
| ReadBrandStyleGuideTool degrades silently to content="" — the agent
| would have no voice anchor and all proposed patches would drift to
| generic copy. This test makes the file invariant enforced at CI time.
*/

it('mandatory _global.md brand-voice file exists at resource_path', function () {
    expect(is_file(resource_path('agents/brand-voice/_global.md')))->toBeTrue();
});
