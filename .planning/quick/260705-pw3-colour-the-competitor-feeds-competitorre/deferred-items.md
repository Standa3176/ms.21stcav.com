# Deferred items — 260705-pw3

Out-of-scope pre-existing failures observed while running `pest tests/Feature/Competitor`
during this display-only colour task. NOT caused by 260705-pw3 (which touched only the
Competitor Filament Resource, `config/competitor.php`, and a unit test — none of which can
affect Shield policy files or the Foundation directory tree). Left untouched per scope boundary.

## tests/Feature/Competitor/ShieldRestorationProtocolTest.php — 2 failing

1. **`no Shield-generated IntegrationEventPolicy stub exists at app/Foundation/Integration/Policies/`**
   - Cause: the directory `app/Foundation/Integration/Policies/` currently EXISTS on `main`.
   - Fix (per the test's own message, Phase 4 Plan 04 decision): `rm -rf app/Foundation/Integration/Policies`
     and re-assert `CrmPushLogPolicy` via the `Gate::policy` binding in `AppServiceProvider`.

2. **`no Policy file contains a Shield {{ Placeholder }} literal (feature-level guardrail)`**
   - Cause: a Shield-generated `{{ ... }}` placeholder literal has leaked into a Policy file under
     one of the scanned `app/**/Policies` paths.
   - Fix (per the test's own message): re-run the P5-F restoration protocol —
     `git checkout HEAD -- <path>` for each leaked Policy file.

Both are RBAC/Shield-restoration guardrails unrelated to competitor feed colouring.
The 202 other competitor feature tests + the new 8 unit tests all pass.
