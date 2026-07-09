---
phase: 260709-rca-deptrac-presentation-layer-bring-app-dom
plan: 01
subsystem: architecture-lint
tags: [deptrac, filament, presentation-layer, dual-config-sync, arch-tests]
requires:
  - deptrac.yaml + depfile.yaml dual-config-sync convention (Phase 5 Plan 05-05)
  - Http presentation-layer allow-list precedent (Phase 8 Plan 01, Phase 11 Plan 01)
provides:
  - Domain-embedded Filament (app/Domain/*/Filament/*) modelled as the Http PRESENTATION layer
  - Deptrac at 0 violations across CLI (deptrac.yaml) + arch tests (depfile.yaml)
affects:
  - deptrac.yaml
  - depfile.yaml
  - tests/Architecture/DeptracCrmLayerTest.php
  - tests/Architecture/DeptracQuotesLayerTest.php
  - tests/Architecture/DeptracTradePricingLayerTest.php
tech-stack:
  added: []
  patterns:
    - "Filament resources are PRESENTATION: they may read across domains, so they live in the Http layer, not their domain layer."
    - "bool collector (must domain / must_not Filament subdir) to carve Filament out of each domain layer."
key-files:
  created: []
  modified:
    - deptrac.yaml
    - depfile.yaml
    - tests/Architecture/DeptracCrmLayerTest.php
    - tests/Architecture/DeptracQuotesLayerTest.php
    - tests/Architecture/DeptracTradePricingLayerTest.php
decisions:
  - "Model domain-embedded Filament as presentation (Http layer) rather than baselining the 8 violations or refactoring UI code — operator-approved, config-only."
  - "Http allow-list extended by exactly the two tokens deptrac reported after the move: Integrations + WpDirectDb. Nothing speculative."
  - "Repointed the stale CRM negative test violator from Pricing (now allowed) to Products (still denied) so the negative genuinely proves the domain rule fires."
metrics:
  duration: "~20 min"
  completed: "2026-07-09"
---

# Phase 260709-rca Plan 01: Deptrac Presentation-Layer Modelling of Domain Filament — Summary

Modelled domain-embedded Filament (`app/Domain/*/Filament/*`) as the Http PRESENTATION layer — config-only, no code, no baseline — taking `deptrac analyse` from 8 violations to 0 with all Deptrac architecture tests green.

## What changed and why

The 8 remaining Deptrac violations were all Filament resources reading across domains
(`PricingOperationsPage → CompetitorPrice`, `EditAutoCreateReview → SeoContentPatchApplier`,
`SuggestionResource → Competitor` + `→ RunAutoCreatePipelineJob`). Filament resources are
presentation: they legitimately pull from many domains to render a UI — exactly the behaviour
the Http layer already permits and its comments explicitly anticipated for Filament. The fix
re-layers domain-embedded Filament into the Http presentation layer rather than baselining the
violations or refactoring the UI. This is correct layering: domain SERVICES remain strictly
layered (verified by the Allowed-count guard and the still-firing negative tests).

This completes the Deptrac 88 → 0 cleanup: extends (a502860, 64) + clean refactors (3e2943e, 16)
+ this presentation-layer modelling (8).

## Config changes (identical in deptrac.yaml AND depfile.yaml)

### 1. Http layer — second collector added

```yaml
    - name: Http
      collectors:
        - type: directory
          regex: app/Http/.*
        # 260709: Filament resources are presentation — they may read across domains...
        - type: directory
          regex: app/Domain/.*/Filament/.*
```

### 2. Twelve domain layers — Filament subdir excluded via `bool` collector

For each of the 12 domains that HAVE a Filament dir — Agents, Alerting, CRM, Competitor,
Integrations, Pricing, ProductAutoCreate, Products, Quotes, Suggestions, Sync, TradePricing —
the plain `directory` collector was converted to a `type: bool` collector:

```yaml
    - name: <X>
      collectors:
        - type: bool
          must:
            - type: directory
              regex: app/Domain/<X>/.*
          must_not:
            - type: directory
              regex: app/Domain/<X>/Filament/.*
```

`type: bool` parsed cleanly in this deptrac version, so the negative-lookahead fallback was not
needed. Domains WITHOUT a Filament dir (Webhooks, Feeds, Dashboard, Cutover) were left unchanged.
Each Filament file now belongs to Http ONLY, not its domain layer.

### 3. Http allow-list — extended by exactly the tokens deptrac reported

After the move, deptrac reported 62 violations, ALL attributed to the Http layer and ALL from
Filament files (0 from non-Filament sources — verified). The distinct missing tokens were:

| Token       | Count | Why it was needed (presentation read on app-own data) |
|-------------|-------|-------------------------------------------------------|
| Integrations | 50   | `IntegrationCredentialResource` reads its own `Integrations\Models\IntegrationCredential` + `IntegrationCredentialKind`/`IntegrationTestStatus` enums — the credential-admin UI. |
| WpDirectDb   | 12   | `SuggestionResource` + `Quotes\ApproveQuoteAction` use the `DB::` facade for read-only app-DB queries / a local transactional boundary. SYNC-04's WP-write ban stays on the Sync domain only (`Sync` keeps `-WpDirectDb`). |

Both were added to Http's allow-list with an inline comment. Nothing speculative was added — these
are exactly the two tokens the plan predicted (`WpDirectDb`, possibly `Integrations`).

Final Http allow-list:
`[Foundation, Products, Pricing, Competitor, Sync, Webhooks, CRM, Suggestions, Alerting, Feeds,
ProductAutoCreate, Dashboard, Cutover, Agents, TradePricing, Quotes, Integrations, WpDirectDb]`

## Over-exclusion guard result

| Stage                           | Violations | Allowed |
|---------------------------------|-----------|---------|
| Baseline                        | 8         | 942     |
| After layer re-modelling        | 62        | 1455    |
| After Http allow-list extension | **0**     | **1517**|

Allowed went UP (942 → 1517), never collapsed — well above the ≥850 guard. The rise is expected:
Filament files reading their OWN domain models were previously intra-domain (uncovered), and are
now cross-layer Http→Domain edges that count as Allowed. No domain SERVICE was un-layered — the
exclusion regex matches only the `Filament/` subdir, and the negative tests still fire.

## Deviations from Plan

### [Rule 1 - Bug] Repointed a stale CRM negative test that was passing for the wrong reason

- **Found during:** arch-test verification (task 1)
- **Issue:** `DeptracCrmLayerTest > catches a deliberate Pricing import from CRM (negative)` planted
  a violator importing `Pricing\Services\PriceCalculator` and asserted deptrac exits non-zero.
  But **Pricing was added to CRM's allow-list in Phase 11 Plan 04** — so CRM→Pricing is now
  ALLOWED and the planted violator produces no violation. The test was only passing because of the
  8 ambient Filament violations (exit non-zero for the wrong reason). Bringing deptrac to 0
  unmasked this stale token. The plan explicitly requires the negatives to genuinely prove domain
  rules catch real violations for non-Filament code.
- **Fix:** Repointed the violator from `Pricing\Services\PriceCalculator` (now allowed) to
  `Products\Models\Product` (genuinely denied to CRM). The violator still lands in
  `app/Domain/CRM/Services/` (non-Filament), so it proves the CRM domain rule fires on a real
  cross-domain read. Header comment + test name + assertion message updated to match.
- **Files modified:** tests/Architecture/DeptracCrmLayerTest.php

### [Rule 3 - Blocking] Updated two structural arch-test assertions to the new bool collector shape

- **Found during:** arch-test verification (task 1)
- **Issue:** `DeptracQuotesLayerTest` and `DeptracTradePricingLayerTest` asserted
  `collectors[0]['type'] === 'directory'` and `collectors[0]['regex'] === 'app/Domain/<X>/.*'`.
  The intentional conversion to a `bool` collector makes `collectors[0]['type']` = `'bool'` and
  moves the domain regex under `must[0]['regex']`, so these structural assertions failed.
- **Fix:** Updated both assertions to validate the new bool shape — `type` = `'bool'`,
  `must[0]['regex']` = the domain regex, `must_not[0]['regex']` = the `Filament/` subdir regex.
  Test intent preserved (the collector covers the domain but excludes its Filament subdir). The
  positive/negative deptrac-run assertions in these files were untouched.
- **Files modified:** tests/Architecture/DeptracQuotesLayerTest.php, tests/Architecture/DeptracTradePricingLayerTest.php

> Note on staging scope: the plan text said "stage only deptrac.yaml + depfile.yaml + PLAN/SUMMARY"
> under a config-only assumption. The three arch-test edits above are part of this plan's atomic
> deliverable (the tests encode the very config structure that changed, and the CRM negative had to
> be un-masked), so they are staged with the config. The genuinely UNRELATED pre-existing working-tree
> changes (storage/app/research/supplier-probe.json, tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php,
> untracked .claude/) were NOT touched or staged.

## Verification

- `deptrac analyse` → **Violations 0, Allowed 1517, Warnings 0, Errors 0** (via `~/.config/herd/bin/php84/php.exe`).
- `pest tests/Architecture` → **120 passed (545 assertions), 0 failed** — all Deptrac*LayerTest +
  DeptracTest positives green at 0, and every planted-violator NEGATIVE still fires (proving domain
  rules still catch real non-Filament violations).
- deptrac.yaml / depfile.yaml: Http layer collector block IDENTICAL, Http allow-list line IDENTICAL,
  all 12 bool domain collector blocks structurally IDENTICAL (12 bool collectors in each file).
  (Pre-existing historical comment differences between the two files are unchanged and out of scope.)
- No leftover `__CrmDeptracViolator.php` temp file after the negative test run.

## Deploy notes

No runtime effect — arch-lint config only. No application code, behaviour, or migration changed.

## Self-Check: PASSED

- deptrac.yaml, depfile.yaml, and the 3 arch-test files all exist with the described edits.
- deptrac analyse = 0 violations / 1517 allowed confirmed by direct run.
- pest tests/Architecture = 120 passed confirmed by direct run.
