<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 10 Plan 04 Task 3 — MarginChangeApplier byte-identity gate (B-03)
|--------------------------------------------------------------------------
|
| Phase 9 Plan 03 established the precedent (B-03): Phase 5's MarginChangeApplier
| is the deterministic approve path that runs whether or not the Suggestion
| has been agent-enriched. PRCAGT-04 invariant: "Approve action UNCHANGED" —
| Phase 10 may add OUT-OF-BAND detection + approve-with-reason modal in the
| Filament UI, but the underlying applier MUST stay byte-identical so v1's
| approve workflow keeps working without the agent in the loop.
|
| Captured at Plan 10-04 Task 3 execution time:
|   sha256 = 63cc7936d691064801e1a0d65a353d88150a97735979114ed408a588252da994
|
| When this test fails, the file changed — investigate to confirm intent.
| Legitimate changes (e.g. fixing a Phase 5 bug, dependency-injection refactor)
| should bump the baseline AND document the reason in a SUMMARY/changelog.
| Accidental changes (tooling auto-format, copy-paste error) get caught early.
*/

it('hash of MarginChangeApplier.php matches recorded baseline (Phase 5 byte-identity)', function () {
    $path = app_path('Domain/Competitor/Appliers/MarginChangeApplier.php');

    expect(file_exists($path))->toBeTrue('Phase 5 MarginChangeApplier.php must exist at the canonical path');

    $actual = hash('sha256', file_get_contents($path));
    $expected = '63cc7936d691064801e1a0d65a353d88150a97735979114ed408a588252da994';

    expect($actual)->toBe(
        $expected,
        "MarginChangeApplier.php has been modified (sha256 mismatch). Phase 5 byte-identity invariant ".
        "(B-03 precedent) is broken. If this change was intentional, bump the baseline + document ".
        "the reason. Actual: {$actual}"
    );
});
