---
phase: 09-e1-trade-customer-pricing
plan: 03
subsystem: trade-pricing
tags: [trade-pricing, golden-fixture, ship-gate, regression-guard, blob-hash, pest, dataset, in-process-generator]

requires:
  - phase: 09-01
    provides: customer_groups table + 4 seeded groups (trade=1/reseller=2/education=3/nhs=4) + pricing_rules.customer_group_id FK
  - phase: 09-02
    provides: TradeRuleResolver decorator + 5-tier specificity sort + singleton binding
  - phase: 03-pricing-engine
    provides: PriceCalculator (compute API) + RuleResolver + golden-fixtures.json (50 v1 triples — UNTOUCHED) + PriceCalculatorGoldenFixtureTest (Phase 3 ship gate — count assertion bumped 50→80)
  - phase: 01-foundation
    provides: Pest 3 + RefreshDatabase trait + meetingstore_ops_testing MySQL convention + skip-on-MySQL-offline precedent
provides:
  - "tests/Architecture/GoldenFixtureV1UnchangedTest.php — sha256 blob-hash regression guard locking the v1 50-triple portion (3 it() blocks, 52 assertions PASS offline)"
  - "tests/Fixtures/Pricing/golden-fixtures.json — extended 50→80 entries; v1 portion byte-identical (sha256 unchanged)"
  - "tests/Unit/Pricing/GoldenFixtureV2TradeTest.php — penny-exact end-to-end run across all 80 fixtures (50 v1 + 30 v2) through PriceCalculator + (Trade)RuleResolver"
  - "tests/Fixtures/Pricing/generate-trade-fixtures.php — W-02 in-process trade fixture generator booting Laravel + invoking PriceCalculator (no hand-math)"
  - "tests/Fixtures/Pricing/generate-fixtures.php — extended additively with v2 delegation comment block + v1 sha256 baseline"
  - "tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php — count assertion updated 50→80 (Rule 3 deviation, blocking issue introduced by this plan); ship gate now runs 81 tests / 641 assertions"
affects: [09-04-sync-pipeline, 09-05-filament-ux, 09-06-verification, phase-11-quote-flow]

tech-stack:
  added: []  # zero composer changes — pure test + fixture + generator additions
  patterns:
    - "Blob-hash regression guard: sha256 of array_slice(0, 50) JSON_PRETTY_PRINT|UNESCAPED_SLASHES baked into a Pest test as a literal — drift fails CI"
    - "B-03 git-clean precondition: `git diff --quiet` on fixture + v1 services before hash capture so the snapshot encodes pristine state, not uncommitted drift"
    - "W-02 in-process expected_final_pennies computation: PriceCalculator booted via Laravel kernel, called from a one-shot CLI script — eliminates hand-math + rounding-error risk"
    - "Two-script split (generate-fixtures.php for v1 + generate-trade-fixtures.php for v2): regenerating one half NEVER touches the other half — preserves v1 byte-identity"
    - "Pest dataset via helper function (matches Phase 3 PriceCalculatorGoldenFixtureTest pattern) — keyed by fixture id for clear test descriptions; works around `dataset(...)` registration tripping Pest's static description tracking"
    - "Per-test rule construction from fixture metadata: rule_scope + customer_group_id + brand_id + category_id + margin_basis_points + optional ProductOverride drives factory builds"
    - "Group A row 5 fall-through detection: `expected_resolution_source === 'brand_category' && lookup_customer_group_id !== null` → rebuild rule as customer_group_id=null so v1 retail Layer-5 fall-through is exercised"

key-files:
  created:
    - tests/Architecture/GoldenFixtureV1UnchangedTest.php (74 LOC)
    - tests/Unit/Pricing/GoldenFixtureV2TradeTest.php (195 LOC)
    - tests/Fixtures/Pricing/generate-trade-fixtures.php (228 LOC, W-02 in-process generator)
  modified:
    - tests/Fixtures/Pricing/golden-fixtures.json (50 → 80 entries; v1 portion byte-identical, sha256 unchanged)
    - tests/Fixtures/Pricing/generate-fixtures.php (additive — header comment block delegating v2 emission to generate-trade-fixtures.php + v1 sha256 baseline literal for re-verification)
    - tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php (Rule 3 deviation — count assertion 50 → 80; ship gate now runs 81 tests, all green; verifies all 80 entries carry the v1 keys)

requirements: [TRDE-03]

commits:
  - 5e4a224 test(09-03): GoldenFixtureV1UnchangedTest blob-hash regression guard (TRDE-03 Task 1)
  - adc8b3f feat(09-03): extend golden fixture 50→80 + W-02 in-process generator (TRDE-03 Task 2)
  - 5472e48 test(09-03): GoldenFixtureV2TradeTest penny-exact across all 80 fixtures (TRDE-03 Task 3)

deferred-tests:
  - "GoldenFixtureV2TradeTest 81 cases — deferred to MySQL-online run. Inherited skip-pattern limitation: RefreshDatabase trait fires migrations before the beforeEach() probe so MySQL-offline currently produces QueryException rather than markTestSkipped (Plan 09-02 + Phase 6/7/8 precedent). Pest discovery is clean offline (81 cases discovered) — execution unblocks once meetingstore_ops_testing MySQL is online (cutover Gate 3)."
  - "GoldenFixtureV1UnchangedTest 3 cases — VERIFIED PASSING OFFLINE (52 assertions, 2.85s). No DB required."
  - "PriceCalculatorGoldenFixtureTest 81 cases — VERIFIED PASSING OFFLINE (641 assertions, 28.54s). Phase 3 ship gate intact across all 80 entries."
---

# Plan 09-03 — Golden fixture 50→80 + dual ship-gate guardrails (TRDE-03)

## What was built

The Phase 9 ship gate. Three commits, three new files, two existing files extended additively, one Phase 3 test count assertion bumped (Rule 3 deviation).

The fixture file is the canonical Phase 3 + Phase 9 ship-gate truth: any drift fails CI. Plan 09-03 ADDS 30 customer-group triples while locking the original 50 byte-identical via a sha256 blob hash. The v1 ship gate Phase 3 paid for stays paid — no regression possible.

### 1. `tests/Architecture/GoldenFixtureV1UnchangedTest` (74 LOC, commit `5e4a224`)

Three `it()` blocks lock the v1 invariant:

1. **`v1 50-triple golden fixture is byte-identical to pre-Phase-9 snapshot`** — recomputes sha256 of `array_slice($fixtures, 0, 50)` JSON_PRETTY_PRINT|UNESCAPED_SLASHES and asserts equality with the literal `f222b48912d0d1211a6d8737f9d4fa58fbc452e3b862342af9818e4df200e967`. **Captured AFTER B-03 git-clean precondition** (`git diff --quiet` on golden-fixtures.json AND v1 services exited 0).
2. **`total fixture count is 80 (50 v1 retail + 30 v2 trade)`** — guards against accidental over-/under-merge.
3. **`v1 entries fx-001..fx-050 do NOT contain customer_group_id field`** — defensive guard against mid-array insert that would shift v1 IDs (T-09-03-02 mitigation).

**Verified offline: 3 tests / 52 assertions PASS in 2.85s (MySQL-independent).**

### 2. `tests/Fixtures/Pricing/golden-fixtures.json` (50 → 80 entries, commit `adc8b3f`)

CONTEXT.md D-05 distribution achieved across the 30 new v2 trade triples (fx-051..fx-080):

| Range          | Count | Description                                         | Source distribution                   |
| -------------- | ----- | --------------------------------------------------- | ------------------------------------- |
| fx-051..fx-070 | 20    | **Group A** — basic group calculation, 5×4 groups   | trade_brand_category(4) + trade_brand(4) + trade_category(4) + trade_default_tier(4) + brand_category fall-through(4) |
| fx-071..fx-075 | 5     | **Group B** — brand+group precedence                | trade_brand_category(4) + trade_brand(1) |
| fx-076..fx-078 | 3     | **Group C** — NULL handling per Pitfall B1          | brand_category(3) — null/0/non-existent lookup all fall through to v1 retail |
| fx-079..fx-080 | 2     | **Group D** — override+group (Pitfall 3 invariant)  | override(2) — beats trade rules with priority+100 bias |

**Source totals across all 30 v2 triples:**
- `trade_brand_category`: **8** (4 group A + 4 group B precedence)
- `trade_brand`: **5** (4 group A + 1 group B fx-075)
- `trade_category`: **4** (group A only)
- `trade_default_tier`: **4** (group A only)
- `brand_category` (v1 fall-through): **7** (4 group A row 5 + 3 group C NULL handling)
- `override`: **2** (group D)
- **Total: 30 ✓**

V1 byte-identity preserved: `array_slice(0, 50)` sha256 still equals `f222b48912d0d1211a6d8737f9d4fa58fbc452e3b862342af9818e4df200e967` — verified BOTH by GoldenFixtureV1UnchangedTest (3 tests, 52 assertions PASS) AND by an explicit pre-merge / post-merge hash comparison during the fixture build.

### 3. `tests/Fixtures/Pricing/generate-trade-fixtures.php` (228 LOC, W-02 generator)

Boots Laravel via `bootstrap/app.php` + Console\Kernel, resolves `app(PriceCalculator::class)`, and computes each `expected_final_pennies` IN-PROCESS. Eliminates hand-math drift risk entirely — every value emitted is what the production calculator actually produces on the same `(supplier_pennies, margin_basis_points, vat_basis_points)` input.

**Run via:** `php tests/Fixtures/Pricing/generate-trade-fixtures.php > /tmp/trade-triples.json`

The script does NOT touch the DB (PriceCalculator is pure, config('pricing.rounding_mode') reads from config/pricing.php). It runs to completion offline against MySQL-offline — verified during this plan execution: emitted 30 entries with valid expected_resolution_source distribution.

`buildGroupA(string $groupName, int $groupId, int $supplierBase, int $idStart): array` helper builds the 5-shape pattern per group; the four group calls produce 20 contiguous fx-051..fx-070 entries.

### 4. `tests/Fixtures/Pricing/generate-fixtures.php` (additive header comment block)

Extended after the `echo json_encode(...)` line with:
```
// ── Phase 9 Plan 03 — 30 v2 trade triples (fx-051..fx-080) ────────────────────
// CONTEXT.md D-05 distribution: 5x4=20 basic group + 5 brand+group precedence
//   + 3 NULL handling + 2 override+group
// W-02 — those triples are emitted by tests/Fixtures/Pricing/generate-trade-fixtures.php
// ...
// Expected resulting v1 sha256 (array_slice(0, 50), JSON_PRETTY_PRINT|UNESCAPED_SLASHES):
//   f222b48912d0d1211a6d8737f9d4fa58fbc452e3b862342af9818e4df200e967
```

The split (this script for v1 + generate-trade-fixtures.php for v2) preserves the byte-identical v1 invariant: regenerating v1 alone never touches v2 entries and vice versa.

### 5. `tests/Unit/Pricing/GoldenFixtureV2TradeTest` (195 LOC, commit `5472e48`)

Pest dataset over all 80 fixtures + 1 total-count check = 81 it() cases. Each fixture entry routes:
- **v1 (no `customer_group_id` key, fx-001..fx-050)** → build a default_tier retail PricingRule, resolve via `app(RuleResolver::class)`, assert PriceCalculator output matches `expected_final_pennies`.
- **v2 (has `customer_group_id` key, fx-051..fx-080)** → build the trade or retail rule from fixture metadata (`rule_scope`, `customer_group_id`, `brand_id`, `category_id`, `margin_basis_points`), optionally a ProductOverride for Group D, resolve via `app(TradeRuleResolver::class)` passing `lookup_customer_group_id`, assert BOTH `resolution.source` matches `expected_resolution_source` AND calculator output matches `expected_final_pennies`.

**Group A row 5 fall-through detection** (lines 96-103): when `expected_resolution_source === 'brand_category' && lookup_customer_group_id !== null`, the test rebuilds the rule as `customer_group_id = null` (retail rule) so the resolver's Layer-5 fall-through hits an actual v1 retail rule. This proves Pitfall B1 + Layer 5 contract holds for the row-5 specs.

**Pest discovery clean offline** — 81 cases recognised. Execution requires meetingstore_ops_testing MySQL online (cutover Gate 3); inherited skip-pattern limitation matches Plan 09-02 + Phase 6/7/8 precedent.

## Why this matters

- **v1 RuleResolver + PriceCalculator remain BYTE-IDENTICAL.** Both files are unchanged. The 50 v1 triples in golden-fixtures.json are unchanged. Phase 3's ship gate is the same gate.
- **The v1 50-triple snapshot is now CI-locked.** Future PRs that accidentally edit fx-001..fx-050 fail GoldenFixtureV1UnchangedTest; the test even runs offline (no DB).
- **30 new triples cover every D-05 scenario.** Group A's 5×4 = 20 covers basic group calculation across all 4 groups; Group B's 5 covers precedence; Group C's 3 covers NULL handling (Pitfall B1); Group D's 2 covers override invariant (Pitfall 3).
- **Phase 9 ship gate is penny-exact end-to-end.** GoldenFixtureV2TradeTest runs the production stack — TradeRuleResolver + PriceCalculator — across all 80 fixtures and asserts byte-for-byte equality.
- **W-02 — hand-math eliminated.** generate-trade-fixtures.php calls PriceCalculator IN-PROCESS; no human can drift the expected values via off-by-rounding-mode arithmetic.
- **B-03 — snapshot integrity.** The v1 sha256 was captured ONLY after `git diff --quiet` confirmed clean working tree on both golden-fixtures.json AND the v1 services. The captured hash is provably encoding pristine pre-Phase-9 state.

## Notable deviations from plan

The plan's `<interfaces>` section described a hypothetical fixture shape (`buy_price_pence`, `vat_rate_basis_points`, `expected_sell_price_pence`, `customer_group_id` only) that did NOT match the actual on-disk Phase 3 fixture. Reconciled by reading the real fixture + PriceCalculator API + RuleResolver before any edit.

### Rule 1 — Bug fixes

None — no live bugs discovered.

### Rule 2 — Auto-added critical functionality

None.

### Rule 3 — Auto-fixed blocking issues

**1. [Rule 3 — Blocking] Phase 3 ship-gate count assertion bumped 50→80**
- **Found during:** Task 2 (after extending golden-fixtures.json).
- **Issue:** `tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php` line 79 asserts `expect($fixtures)->toHaveCount(50)` — would fail after Plan 09-03 lands 80 entries. Plan's verification clause requires "Phase 3 ship gate intact: vendor/bin/pest tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php still exits 0".
- **Fix:** Updated count assertion to 80 with documenting comment that explains the 50 v1 retail + 30 v2 trade split, references GoldenFixtureV1UnchangedTest as the v1 byte-identity guard, and references GoldenFixtureV2TradeTest for v2-only key verification. The `->toHaveKeys` assertion was preserved verbatim — all 80 entries (verified) carry the v1 keys.
- **Files modified:** tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php
- **Commit:** adc8b3f (bundled into Task 2 commit since the change is the direct mechanical consequence of fixture extension).
- **Result:** PriceCalculatorGoldenFixtureTest now runs 81 tests (80 dataset + 1 count) / 641 assertions PASS.

**2. [Rule 3 — Blocking] Pest dataset registration tripped static description tracking**
- **Found during:** Task 3 (initial test run).
- **Issue:** Initial GoldenFixtureV2TradeTest used `dataset('golden_fixtures_v1_v2', fn() => ...)` + `->with('golden_fixtures_v1_v2')` — Pest threw `"Typed static property P\Tests\Unit\Pricing\GoldenFixtureV2TradeTest::$__latestDescription must not be accessed before initialization"` and refused to discover the test.
- **Fix:** Refactored to the proven Phase 3 helper-function pattern: `goldenFixturesV1V2(): array` returns rows keyed by fixture id (`$rows[$fx['id']] = [$fx]`), passed directly to `->with(goldenFixturesV1V2())`. Pest discovery clean afterwards (81 cases recognised).
- **Files modified:** tests/Unit/Pricing/GoldenFixtureV2TradeTest.php (during build, before commit)
- **Commit:** 5472e48 (committed only the working version)

### Rule 4 — Architectural decisions

None.

### Authentication gates

None.

## What this enables

- **Plan 09-04 (Filament UX):** Has a CI-blocking ship gate that proves the new `customer_group_id` Select on PricingRuleResource doesn't drift trade resolution. Filament edits land green or fail loudly.
- **Plan 09-05 (retail-parity guardrails):** Can build directly on GoldenFixtureV1UnchangedTest as the canonical v1 byte-identity assertion. The verification plan can lift this test as proof rather than re-deriving the hash.
- **Plan 09-06 (verification):** TRDE-03 ship verdict reads "PASS" once MySQL-online run of GoldenFixtureV2TradeTest exits 0. The architecture test (V1Unchanged) already runs offline so the offline portion of the ship gate is already green.
- **Phase 11 E2 Quote flow:** Gets a 30-triple regression baseline for trade-pricing path correctness. Future quote-generator changes that drift trade resolution fail GoldenFixtureV2TradeTest before reaching production.

## Verification snapshot

| Check | Status |
|---|---|
| `php -l tests/Architecture/GoldenFixtureV1UnchangedTest.php` | PASS (no syntax errors) |
| `php -l tests/Fixtures/Pricing/generate-trade-fixtures.php` | PASS |
| `php -l tests/Fixtures/Pricing/generate-fixtures.php` | PASS |
| `php -l tests/Unit/Pricing/GoldenFixtureV2TradeTest.php` | PASS |
| `php -l tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php` | PASS |
| B-03 git-clean precondition (golden-fixtures.json + RuleResolver.php + PriceCalculator.php) | PASS (all `git diff --quiet` exited 0 BEFORE hash capture) |
| V1 sha256 baseline | `f222b48912d0d1211a6d8737f9d4fa58fbc452e3b862342af9818e4df200e967` |
| V1 sha256 post-Task-2 | `f222b48912d0d1211a6d8737f9d4fa58fbc452e3b862342af9818e4df200e967` ✓ identical |
| `count(json_decode(file_get_contents("tests/Fixtures/Pricing/golden-fixtures.json"), true))` | 80 ✓ |
| `count(array_filter(v2, fn($e)=>array_key_exists("customer_group_id",$e)))` (v2 = entries 50..79) | 30 ✓ |
| `count(array_filter(v1, fn($e)=>array_key_exists("customer_group_id",$e)))` (v1 = entries 0..49) | 0 ✓ |
| `grep -c "expected_resolution_source.*override"` | 2 ✓ (≥2 required) |
| `grep -c "expected_resolution_source.*trade_brand_category"` | 8 ✓ (≥5 required) |
| `vendor/bin/pest tests/Architecture/GoldenFixtureV1UnchangedTest.php` | 3 PASS / 52 assertions / 2.85s |
| `vendor/bin/pest tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php` | 81 PASS / 641 assertions / 28.54s — Phase 3 ship gate INTACT across all 80 entries |
| `vendor/bin/pest tests/Unit/Pricing/GoldenFixtureV2TradeTest.php` (offline) | 81 cases discovered, all FAIL with QueryException — inherited Plan 09-02 / Phase 6/7/8 deferred-tests posture; unblocks once meetingstore_ops_testing MySQL online |
| W-02 generator runs in-process | YES — `php tests/Fixtures/Pricing/generate-trade-fixtures.php` emits 30 entries with PriceCalculator-computed expected_final_pennies values |

## Threat surface scan

Reviewed all files created/modified against Plan 09-03 `<threat_model>` STRIDE register. No new attack surface beyond what's documented:

- **T-09-03-01 (v1 50-triple drift)** — mitigated by GoldenFixtureV1UnchangedTest sha256 (3 tests / 52 assertions PASS offline).
- **T-09-03-02 (mid-array insert shifting v1 IDs)** — mitigated by `it('v1 entries fx-001..fx-050 do NOT contain customer_group_id field')`.
- **T-09-03-03 (wrong PricingResolution.source still passing penny-exact)** — mitigated by GoldenFixtureV2TradeTest asserting BOTH source AND penny-exact for v2 entries.
- **T-09-03-04 (regenerator drift)** — mitigated by two-script split: generate-fixtures.php for v1, generate-trade-fixtures.php for v2.
- **T-09-03-05 (hand-math rounding drift)** — mitigated by W-02: generate-trade-fixtures.php calls PriceCalculator in-process; values are by definition what the production calculator emits.
- **T-09-03-06 (hash captured against dirty tree)** — mitigated by Task 1 STEP 0 git-clean precondition (verified PASS before STEP 1 hash capture).

No threat-flag types introduced.

## Self-Check: PASSED

**Files:**
- FOUND: `tests/Architecture/GoldenFixtureV1UnchangedTest.php` (74 LOC)
- FOUND: `tests/Unit/Pricing/GoldenFixtureV2TradeTest.php` (195 LOC)
- FOUND: `tests/Fixtures/Pricing/generate-trade-fixtures.php` (228 LOC)
- FOUND: `tests/Fixtures/Pricing/golden-fixtures.json` modified (80 entries, v1 sha256 unchanged)
- FOUND: `tests/Fixtures/Pricing/generate-fixtures.php` modified (additive comment block)
- FOUND: `tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php` modified (Rule 3 — count 50 → 80)

**Commits:**
- FOUND: `5e4a224` (Task 1)
- FOUND: `adc8b3f` (Task 2)
- FOUND: `5472e48` (Task 3)

**Invariants:**
- FOUND: V1 sha256 `f222b48912d0d1211a6d8737f9d4fa58fbc452e3b862342af9818e4df200e967` matches BOTH the literal in GoldenFixtureV1UnchangedTest.php AND the post-Task-2 recompute → v1 portion BYTE-IDENTICAL
- FOUND: Phase 3 ship gate (PriceCalculatorGoldenFixtureTest) PASSES with 81 tests / 641 assertions across all 80 entries — zero regression
- FOUND: GoldenFixtureV1UnchangedTest PASSES with 3 tests / 52 assertions offline — v1 invariant CI-locked even with MySQL down
- FOUND: 30 v2 entries have `customer_group_id` key; 0 v1 entries do
- FOUND: Source distribution acceptance criteria met (override ≥2, trade_brand_category ≥5)
- FOUND: B-03 git-clean precondition verified before hash capture
