# Deferred / Out-of-Scope Items — 260707-gsy

These failures were **verified failing on the committed baseline BEFORE** the
260707-gsy change (I temporarily reverted `SuggestionResource.php` to HEAD and
re-ran them). They are unrelated to the Readiness/display work and are part of
the known test-infra debt tracked in `.planning/STATE.md` → "Test-suite
remediation". Left untouched per the executor scope boundary.

| # | Test | Failure | Cause (pre-existing) |
|---|------|---------|----------------------|
| 1 | `tests/Feature/ProductAutoCreate/SuggestionResourceAutoCreateKindsTest` › approve_new_product… | `QueryException: NOT NULL constraint failed: suggestions.correlation_id` | Test creates a Suggestion without `correlation_id`; DB requires it. Test-data issue, not display code. |
| 2 | same file › replay_auto_create (auto_create_failed) | same | same |
| 3 | same file › replay_auto_create (margin_change) | same | same |
| 4 | same file › approve_new_product (FAIL-1) | same | same |
| 5 | `tests/Feature/Competitor/NewProductOpportunityApproveActionTest` › it running… | `BindingResolutionException` | Unresolved container binding in the test's setup path. |

Baseline evidence: reverting the resource to committed HEAD and running the
same 9 files produced **5 failed, 41 passed** — exactly these 5. With the
260707-gsy change (and the test-filter fixes) the regression set returns to the
same 5 pre-existing failures; every test I could have affected is green.
