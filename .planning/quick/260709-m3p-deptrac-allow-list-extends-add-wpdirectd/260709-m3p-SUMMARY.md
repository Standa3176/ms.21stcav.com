---
phase: 260709-m3p-deptrac-allow-list-extends-add-wpdirectd
plan: 01
subsystem: infra
tags: [deptrac, architecture-lint, config, dual-config-sync]

# Dependency graph
requires: []
provides:
  - "5 operator-approved Deptrac allow-list extends applied identically to deptrac.yaml + depfile.yaml"
  - "64 sanctioned-pattern violations cleared (88 → 24); remaining 24 are real refactor breaches"
affects: [260709-m3p-deptrac-refactors]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "WpDirectDb-in-allow-list for read-only app-DB reads (precedent: Dashboard/Pricing/Agents/Cutover/Quotes)"
    - "Bidirectional credential-resolver arrow (ProductAutoCreate <-> Integrations) mirroring Integrations<->Sync/CRM"

key-files:
  created:
    - .planning/quick/260709-m3p-deptrac-allow-list-extends-add-wpdirectd/260709-m3p-SUMMARY.md
  modified:
    - deptrac.yaml
    - depfile.yaml

key-decisions:
  - "Applied exactly the 5 approved extends — did NOT add extra entries to force the whole-project positive test green; the remaining 24 are refactor-only."
  - "Documented each new token with an inline 260709 sanctioned-pattern comment; kept the array lines byte-identical across both YAMLs."

patterns-established:
  - "Dual-config-sync: deptrac.yaml (CLI) + depfile.yaml (arch tests) ruleset array lines kept identical."

requirements-completed: []

# Metrics
duration: ~10min
completed: 2026-07-09
---

# Phase 260709-m3p Plan 01: Deptrac Allow-List Extends Summary

**Applied 5 operator-approved Deptrac allow-list extends (3x WpDirectDb + the ProductAutoCreate<->Integrations credential-resolver pair) identically to deptrac.yaml and depfile.yaml, clearing 64 of 88 sanctioned-pattern violations with zero application-code change.**

## Performance

- **Duration:** ~10 min
- **Tasks:** 1
- **Files modified:** 2 (deptrac.yaml, depfile.yaml)

## Accomplishments

- Deptrac violations dropped from **88 → 24** (exactly the expected 64 cleared).
- Competitor layer's own cross-domain violations fully cleared (0 Competitor-as-source violations remain).
- Both YAML rulesets kept byte-identical on the array-definition lines (dual-config-sync preserved).
- Every new token carries an inline `260709:` comment documenting the sanctioned-pattern rationale.

## Exact allow-list lines added

Both `deptrac.yaml` and `depfile.yaml` received the identical five lines (only the token + trailing comment were added to each layer's existing array):

```yaml
Products:     [Foundation, ProductAutoCreate, WpDirectDb]  # 260709: +WpDirectDb operator-approved sanctioned pattern — read-only app-DB reads (csv_parse_errors / category_audit_findings), no WP/Woo writes
Competitor:   [Foundation, Pricing, Products, Suggestions, Webhooks, Alerting, WpDirectDb]  # 260709: +WpDirectDb operator-approved sanctioned pattern — read-only app-DB metrics/driver-probe reads (supplier_sku_cache / driver detection), no WP/Woo writes
Suggestions:  [Foundation, WpDirectDb]  # 260709: +WpDirectDb operator-approved sanctioned pattern — read-only app-DB reads (suggestions table), no WP/Woo writes
ProductAutoCreate: [Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Integrations]  # 260709: +Integrations operator-approved sanctioned pattern — credential-resolver (enrichment clients resolve creds via IntegrationCredentialResolver + return IntegrationTestResult)
Integrations: [Foundation, Sync, CRM, Agents, ProductAutoCreate]  # 260709: +ProductAutoCreate operator-approved sanctioned pattern — credential-resolver reverse arrow (TestIntegrationAction dispatches to enrichment clients' testConnection())
```

Rationale per extend (matches existing precedent):

- **Competitor / Products / Suggestions +WpDirectDb** — read-only metrics / driver-probe reads on the app's OWN MySQL tables (csv_parse_errors, category_audit_findings, supplier_sku_cache, suggestions, driver detection). None touch WordPress/Woo data, so the CLAUDE.md "Woo REST only, never direct WP DB writes" hard constraint is NOT breached. Mirrors the existing Dashboard/Pricing/Agents/Cutover/Quotes WpDirectDb precedent.
- **ProductAutoCreate +Integrations / Integrations +ProductAutoCreate** — the two arrows of the credential-resolver pattern: enrichment clients (EanSearchClient/IcecatClient/WebImageSearchClient) resolve credentials via IntegrationCredentialResolver + return IntegrationTestResult, and TestIntegrationAction dispatches to their testConnection(). Mirrors the existing Integrations<->Sync and Integrations<->CRM symmetry.

## Verification

- **Deptrac:** `88 → 24` violations (64 cleared). Skipped=0, Warnings=0, Errors=0.
- **Ruleset parity:** Array-definition lines in deptrac.yaml and depfile.yaml are IDENTICAL (verified by diffing the `Layer: [...]` lines).
  - Note: the plan's verify command `diff <(sed -n '/^ruleset:/,$p' ...)` is a no-op false-positive — the `^ruleset:` anchor never matches the 2-space-indented `  ruleset:` key, so it compares empty-vs-empty. A meaningful diff of the actual array lines was used instead and confirms identity.
- **DeptracCompetitorLayerTest:** 3 passed, 1 failed (8 assertions). See Deviations.

## Remaining 24 violations (all refactor-only — for 260709-m3p-deptrac-refactors)

| Count | Violation |
|-------|-----------|
| 5 | Sync\CheckStaleSuppliersCommand -> DB (WpDirectDb) — Sync keeps its `-WpDirectDb` hard-deny; fix = Sync->Eloquent |
| 2 | Sync\ScanSupplierAddCandidatesCommand -> Pricing\PricingOpsReport |
| 2 | Suggestions\SuggestionResource -> ProductAutoCreate\RunAutoCreatePipelineJob |
| 2 | Suggestions\SuggestionResource -> Competitor\Models\Competitor |
| 2 | Products\WooGtinPublisher -> Sync\WooClient |
| 2 | Products\WooGalleryPublisher -> Sync\WooClient |
| 2 | Products\PushProductFieldsToWoo -> Sync\WooProductWriter |
| 2 | ProductAutoCreate\ProductImageVisionValidator -> Agents\ClaudeClient |
| 2 | ProductAutoCreate\EditAutoCreateReview -> Agents\SeoContentPatchApplier |
| 2 | Pricing\PricingOperationsPage -> Competitor\Models\CompetitorPrice |
| 1 | Products\SupplierOfferSnapshot -> Sync\SupplierFreshnessResolver |

These are real breaches (relocate publishers/ClaudeClient/commands, route Suggestions Filament reads via Http, Sync->Eloquent) handled in the sibling refactor task. Deliberately NOT allow-listed here.

## Decisions Made

- Applied exactly the 5 approved extends; did not add anything beyond them.
- Used inline trailing comments on each new token so the loosening is documented, not silent.

## Deviations from Plan

### 1. DeptracCompetitorLayerTest positive test stays RED (plan expected GREEN)

- **Found during:** Task 1 verification.
- **Issue:** The plan predicted `DeptracCompetitorLayerTest` would go fully green because "its only violations were the WpDirectDb extend." In fact its first test — `it('Competitor domain has zero cross-domain import violations (positive)')` — shells out to a **whole-project** `deptrac analyse --config-file=depfile.yaml` and asserts exit code 0. That is a project-wide clean gate (misleadingly named after the Competitor layer), not a Competitor-layer-scoped check. It stays red while any of the 24 refactor violations remain.
- **Resolution:** No action taken — this is expected and correct. The Competitor layer's OWN violations ARE cleared (0 Competitor-as-source violations remain), and the other 3 tests in the file (2 negatives + the depfile grep) pass. I did NOT add extra allow-list entries to force the positive gate green, per the plan's explicit instruction. The positive test will turn green once 260709-m3p-deptrac-refactors clears the remaining 24.
- **Files modified:** none.

---

**Total deviations:** 1 (documentation/expectation correction — no code or config change beyond the 5 approved extends).
**Impact on plan:** None on scope. The 88→24 target landed exactly; the only surprise was the arch test's whole-project semantics, which is now documented.

## Issues Encountered

- The plan's ruleset-diff verify command uses a `^ruleset:` anchor that cannot match the indented `  ruleset:` key, yielding a trivially-passing empty comparison. Substituted a meaningful array-line diff (still confirms the two files are identical where it matters).

## User Setup Required

None — arch-lint config only, no runtime effect. Ships harmlessly with the next deploy.

## Next Phase Readiness

- Ready for **260709-m3p-deptrac-refactors**, which clears the remaining 24 real breaches and will finally flip the whole-project positive arch tests (including DeptracCompetitorLayerTest) green.

---
*Phase: 260709-m3p-deptrac-allow-list-extends-add-wpdirectd*
*Completed: 2026-07-09*
