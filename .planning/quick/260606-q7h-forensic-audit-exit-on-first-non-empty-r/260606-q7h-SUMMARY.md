---
phase: 260606-q7h
plan: 01
status: complete
type: execute
wave: 1
audit_headline: "0 HIGH / 1 MEDIUM / 4 LOW / 4 N/A / 1 SANITY"
sanity_check: "TaxonomyResolver 73ac682 fix intact (native /products/brands primary, pa_brand fallback)"
tests:
  baseline: "1,826 passed / 219 failed / 3 skipped (260606-p4q)"
  result:   "1,826 passed / 219 failed / 3 skipped (260606-q7h)"
  delta:    "+0 passed / +0 failed / +0 skipped — zero new failures"
  canonical:
    - "tests/Architecture/EnvUsageTest.php — GREEN (3 assertions)"
    - "tests/Architecture/AutoCreatedPredicateTest.php — GREEN (2 assertions)"
commits:
  - hash: 320c290
    type: docs
    message: "docs(audit): exit-on-first-non-empty resolver findings (260606-q7h)"
files_modified:
  - .planning/quick/260606-q7h-forensic-audit-exit-on-first-non-empty-r/260606-q7h-FINDINGS.md
  - .planning/quick/260606-q7h-forensic-audit-exit-on-first-non-empty-r/260606-q7h-SUMMARY.md
requirements_satisfied:
  - STATE-LESSON-2-2026-05-31
---

# Summary — 260606-q7h forensic audit

Forensic audit of `app/Domain/` for the "exit on first non-empty source"
anti-pattern that caused the 2026-05-31 Huddlecamhd disaster — sweeping
the other 8 scouted resolvers/registries for the same shape, scoring
severity, and fixing HIGH findings inline.

## Outcome

- **HIGH findings: 0**
- **Fixes shipped: 0 — clean bill**
- **Pest suite: 1,826 passed / 219 failed / 3 skipped** (baseline:
  1,826 / 219 / 3) — **delta: +0 / +0 / +0, zero new failures**
- **Canonical pinning tests:**
  - `tests/Architecture/EnvUsageTest.php` — GREEN (5 assertions in 8.94s)
  - `tests/Architecture/AutoCreatedPredicateTest.php` — GREEN (2 assertions in 0.99s)

> Note: the PLAN referred to `tests/Feature/EnvUsageTest.php` and
> `tests/Unit/Domain/ProductAutoCreate/AutoCreatedPredicateTest.php`, but
> the canonical pinning tests live under `tests/Architecture/` (consistent
> with the 260606-c4o + 260606-o63 ship commits). Both files were located
> and run; both pass.

## Findings (link)

See [`260606-q7h-FINDINGS.md`](./260606-q7h-FINDINGS.md) for the full
table. Headline counts:

| Severity | Count | Sites |
|---|---|---|
| HIGH | 0 | — |
| MEDIUM | 1 | IntegrationCredentialResolver (documented D-06 DB-wins-then-env) |
| LOW | 4 | BrandSlugResolver, RuleResolver, TradeRuleResolver, CutoverChecklistReporter (grep-discovered) |
| N/A — single source | 4 | AgentRegistry, ProductBrandTermResolver, SuggestionApplierResolver, SupplierSkuRegistry |
| SANITY | 1 | TaxonomyResolver::allBrands — 73ac682 intact |

## TaxonomyResolver SANITY check

Commit **73ac682** fix verified intact:

- `TaxonomyResolver::allBrands()` at line 256.
- Primary source (line 261): `paginate('products/brands')` — Woo native
  Brands taxonomy with 100+ real terms.
- Fallback (line 273): `pa_brand` global attribute, only reached when
  primary returned empty or threw.
- Docblock dated 2026-05-31 explains the inversion and references the
  Huddlecamhd miss explicitly.
- `git show 73ac682 --stat` confirms the original fix lived in this file
  and no later commit has reverted it.

## Fix commits (CASE B only — N/A here)

No fix commits — CASE A (0 HIGH findings) per Task 2 decision. The audit
doc is the deliverable.

## DEFERRED list

None — no finding exceeds the 5-files scope cap. The MEDIUM
(IntegrationCredentialResolver) and LOWs are documented-intent or
specificity-chain semantics and intentionally untouched.

One **out-of-audit-scope observation** worth flagging for a future quick:
`ProductImageFetcher` → `IcecatClient` → `WebImageSearchClient` is a
multi-source pipeline (structured supplier data → web search → Claude
vision validation) that is NOT the Huddlecamhd shape (each step does
its own validation), but it IS a chained resolver. If image-quality
incidents surface, that pipeline deserves its own targeted audit. Not a
finding for this task.

## Notes for retrospective

- The Huddlecamhd shape needs three conditions: (1) two sources both
  legitimately hold data for the same identity, (2) resolver exits on
  first non-empty without sanity-checking the loser, (3) downstream
  consumer trusts the result silently. Across 9 scouted resolvers +
  grep-discovered extras, only `TaxonomyResolver::allBrands()` ever met
  all three — and that was fixed in 73ac682.
- Every other multi-source path in `app/Domain/` is one of:
  authoritative-vs-degraded (BrandSlugResolver), most-specific-wins
  specificity chain (RuleResolver, TradeRuleResolver), documented-intent
  priority (IntegrationCredentialResolver, CutoverChecklistReporter), or
  pipeline-chain-with-validation (image sourcing). The pattern that
  bites is specifically "competing sources, first non-empty wins, no
  volume sanity check" — and that pattern is rare in this codebase.
- The grep sweep added value: pattern A (`if ($terms !== []) { return
  $terms; }`) found exactly 2 hits, both inside the already-fixed
  TaxonomyResolver. That's a useful negative signal — the shape is not
  proliferating.
- Process-wise: writing the FINDINGS doc BEFORE editing code (Task 1
  = read-only) made the audit feel disciplined. Task 2's CASE A/CASE B
  decision gate gave a clean off-ramp once HIGH count came out zero —
  no temptation to fish for fixes that weren't needed.

## Per-task commit hashes

| Task | Action | Commit |
|---|---|---|
| 1 | Write FINDINGS.md (audit doc) | `320c290` |
| 2 | Decision: CASE A (skip Task 3) | no commit (decision recorded inline in FINDINGS.md) |
| 3 | Per-HIGH fix + Pest test | **SKIPPED** (CASE A) |
| 4 | Run full Pest suite + write SUMMARY.md | (this file — committed by orchestrator) |

## Self-Check

- FINDINGS.md exists at expected path: FOUND
- Commit `320c290` exists in git log: FOUND
- Canonical pinning tests green: GREEN
- Full Pest suite delta vs baseline: zero new failures (1,826 / 219 / 3 → 1,826 / 219 / 3)

## Self-Check: PASSED
