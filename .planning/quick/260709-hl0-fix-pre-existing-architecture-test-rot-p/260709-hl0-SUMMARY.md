---
phase: 260709-hl0-fix-pre-existing-architecture-test-rot-p
plan: 01
subsystem: testing / architecture-guardrails
tags: [test-rot, architecture, pricing, pins, quotes]
requires: []
provides: ["four Architecture test-rot failures cleared; pest trustworthy again (only Deptrac* remains red — separate task)"]
affects: [tests/Architecture]
tech-stack:
  added: []
  patterns: ["pin_price is Woo/storefront-only; the local sell_price is a derived mirror that recomputes on supplier sync"]
key-files:
  created:
    - .planning/quick/260709-hl0-fix-pre-existing-architecture-test-rot-p/260709-hl0-SUMMARY.md
  modified:
    - tests/Architecture/PriceCalculatorPurityTest.php
    - tests/Architecture/TradePricingNoV1ModificationTest.php
    - tests/Architecture/PinnedFieldsSurviveSyncTest.php
    - tests/Architecture/PinnedQuotePricesSurviveRuleEditTest.php
decisions:
  - "pin_price protects the Woo/storefront regular_price only; supplier sync legitimately recomputes the local sell_price mirror (SupplierPriceChanged -> RecomputePriceListener). PinnedFieldsSurviveSyncTest now documents + asserts this."
metrics:
  duration: "~20m"
  completed: "2026-07-09"
---

# Phase 260709-hl0 Plan 01: Fix Pre-Existing Architecture Test-Rot Summary

Cleared four confirmed TEST-ROT failures in `tests/Architecture` with test-only edits — no production
code changed. `pest tests/Architecture` now has only the Deptrac* cases red (handled by the separate
260709-hl0-fix-real-deptrac-architecture-violations task).

## What Changed (all test-only)

### 1. PriceCalculatorPurityTest.php — purity caps 2 -> 3
- **Root cause:** A legitimate third pure public method `addVat()` was added to `PriceCalculator`
  (ex-VAT fix, commit 5804484). The hard-coded `<=2` caps on the `config()`-read count and the
  `round()` count were stale — three pure public methods (`compute()` + `stripVat()` + `addVat()`)
  each read `pricing.rounding_mode` once and round once at their return boundary.
- **Fix:** Bumped the `config()`-count assertion and the `round()`-count assertion from
  `->toBeLessThanOrEqual(2)` to `->toBeLessThanOrEqual(3)`; updated the surrounding comments +
  Test 5's title to name the three public methods. Purity intent (integer-pennies math, single
  rounding-mode config key, no float, one round per method) is intact.

### 2. TradePricingNoV1ModificationTest.php — re-pin PriceCalculator sha256
- **Root cause:** The byte-identity pin was stale for the same additive reason — `compute()`/`stripVat()`
  bytes are unchanged; only `addVat()` was appended.
- **Fix:** Confirmed `git diff --stat -- app/Domain/Pricing/Services/PriceCalculator.php` is EMPTY
  (no uncommitted drift), then re-pinned the `$expected` literal to the live-computed hash. The value
  was computed the SAME way the test does (`hash_file('sha256', ...)`) and cross-checked against the
  committed blob (`git show HEAD:... | sha256`) — both equal
  `43efcb555c7dadc6c7ca583f8f231b82610d63d9caf6775fb0dc93ce9920ed4c`. The RuleResolver pin in the
  same file was left untouched and stays green.

### 3. PinnedFieldsSurviveSyncTest.php — decimal format + Woo-only-pin assertion
- **Root cause (case 2):** `buy_price` is `decimal(_,4)`; the cast returns 4dp, so the actual value is
  `'2100.0000'`, not `'2100.00'`.
- **Root cause (case 1):** The `sell_price == $originalSellPrice` assertion assumed the local sell_price
  is frozen by the pin. It is not — `SupplierPriceChanged` -> `RecomputePriceListener` re-derives the
  local sell_price from the new buy_price by design.
- **Fix:** case 2 `->toBe('2100.00')` -> `->toBe('2100.0000')`. case 1: replaced the stale frozen-price
  assertion with `expect((string) $fresh->sell_price)->not->toBe($originalSellPrice)` (proves the local
  mirror recomputes) and added a comment documenting the decision. The existing Woo-side pin-revert
  expectation (the `pin_reverted` auditor mock asserting the `regular_price` revert PUT) is kept — that
  is the behaviour the pin actually guarantees. No pricing code (`PriceRecomputer` etc.) was touched.

### 4. PinnedQuotePricesSurviveRuleEditTest.php — RefreshDatabase wiring
- **Root cause:** The trailing chained `})->uses(RefreshDatabase::class)` form does not migrate the
  in-memory DB before the test body runs -> `no such table: customer_groups`.
- **Fix:** Added file-top `uses(RefreshDatabase::class);` after the imports (matching sibling
  Architecture DB-tests like PinnedFieldsSurviveSyncTest) and removed the trailing chained form. The
  `skipIfMySqlOfflineShipGate()` guard and `CustomerGroup::factory()` setup are unchanged.

## Decision recorded: pin_price is Woo-only

`pin_price` protects the Woo/storefront `regular_price`; the local `sell_price` is a derived mirror
that recomputes on supplier sync. No pricing behaviour changed — this is a documentation/assertion
correction, not a behaviour change. The `PriceCalculator` money path is confirmed unchanged (only the
additive pure `addVat()` method; golden fixture green).

## Verification

- **The four files:** `Tests: 14 passed (85 assertions)` — all GREEN.
- **git diff (production code):** `git diff --name-only` excluding pre-existing tree noise
  (`supplier-probe.json`, `CompetitorIngestFreshnessColorTest.php`) prints "ONLY TEST FILES CHANGED".
  No production file touched.
- **`pest tests/Architecture`:** `14 failed, 106 passed`. All 14 reds are Deptrac* cases
  (`DeptracAgentsLayerTest`, `DeptracCompetitorLayerTest`, `DeptracCrmLayerTest`,
  `DeptracCutoverLayerTest`, `DeptracDashboardLayerTest`, `DeptracPricingLayerTest`,
  `DeptracProductAutoCreateLayerTest`, `DeptracQuotesLayerTest`, `DeptracSyncLayerTest`,
  `DeptracTest`, `DeptracTradePricingLayerTest`) — handled by the separate 260709 Deptrac task.
  The four target files are no longer red.

## Deviations from Plan

- **pint --test not clean (pre-existing, out of scope):** `pint --test tests/Architecture` fails across
  ~15 files (AgentToolsNamingTest, EnvUsageTest, etc.) that this task never touched. Verified the pint
  drift on the four target files is IDENTICAL between the committed HEAD versions and my edited versions
  (same files, same fixers: `fully_qualified_strict_types`, `unary_operator_spaces`,
  `not_operator_with_successor_space`, `no_multiline_whitespace_around_double_arrow`). My edits introduced
  ZERO new pint violations — the entire `tests/Architecture` directory is pre-existing pint-dirty.
  Per the scope boundary I did NOT auto-reformat pre-existing code (that would touch lines unrelated to
  this task and break the test-only minimal-change requirement). PriceCalculatorPurityTest is pint-clean.

## Deploy

No deploy needed — test-only. These were pre-existing test-rot failures unrelated to any feature.

## Self-Check: PASSED
- tests/Architecture/PriceCalculatorPurityTest.php — FOUND
- tests/Architecture/TradePricingNoV1ModificationTest.php — FOUND
- tests/Architecture/PinnedFieldsSurviveSyncTest.php — FOUND
- tests/Architecture/PinnedQuotePricesSurviveRuleEditTest.php — FOUND
- 260709-hl0-SUMMARY.md — FOUND
