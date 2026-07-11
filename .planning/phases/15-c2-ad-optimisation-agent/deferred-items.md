# Phase 15 — Deferred / out-of-scope items

Items discovered during execution that are NOT caused by the current slice's
changes. Logged, not fixed (scope boundary).

## Pre-existing Pint style failures in the Integrations domain (found 15a-02)

`vendor/bin/pint --test` flags style violations in files this slice did NOT
touch. They pre-date 15a-02 and are unrelated to the GA4 pull/viewer work:

- `app/Domain/Integrations/Services/IntegrationCredentialResolver.php`
  (concat_space, unary_operator_spaces, not_operator_with_successor_space)
- `tests/Feature/Integrations/IntegrationCredentialKindEnumTest.php`
- `tests/Feature/Integrations/IntegrationCredentialModelTest.php`
- `tests/Feature/Integrations/IntegrationCredentialResolverTest.php`
- `tests/Feature/Integrations/IntegrationCredentialResourceTest.php`
- `tests/Feature/Integrations/IntegrationHealthWidgetTest.php`
- `tests/Feature/Integrations/WooClientResolverIntegrationTest.php`

All files created/modified by 15a-02 pass `pint --test` cleanly. Fix these in a
dedicated style-only pass (do not bundle with feature commits).

## Pre-existing working-tree noise (left untouched per plan guardrails)

- `storage/app/research/supplier-probe.json` (staged deletion in working tree)
- `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php` (modified)
- untracked `.claude/`
