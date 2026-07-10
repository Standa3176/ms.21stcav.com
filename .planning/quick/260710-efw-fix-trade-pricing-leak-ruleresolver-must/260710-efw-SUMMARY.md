---
phase: 260710-efw-fix-trade-pricing-leak-ruleresolver-must
plan: 01
subsystem: pricing
tags: [pricing, trade-pricing, security, money-path, leak-fix, byte-lock]
requires:
  - RuleResolver v1 retail resolution (Phase 3)
  - TradeRuleResolver v2 (Phase 9) routing null-group callers to RuleResolver
provides:
  - retail/anonymous price resolution that excludes trade (customer-group) rules
affects:
  - app/Domain/Pricing/Services/RuleResolver.php
  - tests/Architecture/TradePricingNoV1ModificationTest.php
tech-stack:
  added: []
  patterns:
    - "whereNull('customer_group_id') scoping on all 4 v1 PricingRule layer queries"
key-files:
  created: []
  modified:
    - app/Domain/Pricing/Services/RuleResolver.php
    - tests/Architecture/TradePricingNoV1ModificationTest.php
decisions:
  - "Re-pinned the D-03 v1 byte-lock (operator-approved) rather than routing the exclusion elsewhere — the fix belongs in the base retail resolver so no caller can bypass it."
metrics:
  duration: ~15m
  completed: 2026-07-10
---

# Phase 260710-efw Plan 01: Fix Trade-Pricing Leak in RuleResolver Summary

Closed a latent money-path leak where `RuleResolver` (v1 retail/anonymous resolution) did not
filter `customer_group_id`, so a higher-priority trade rule sharing the `pricing_rules` table
could out-rank the retail rule and surface a trade-discounted price to the public. Added
`->whereNull('customer_group_id')` to all four v1 PricingRule layer queries and re-pinned the
D-03 byte-identity guard with documented rationale.

## The Leak

`TradeRuleResolver` (v2) routes null-group (anonymous/retail) callers to base
`RuleResolver::resolve`. That base resolver never filtered `customer_group_id`. Once trade rules
began sharing the `pricing_rules` table, a trade rule (e.g. `customer_group_id=trade`,
`priority=200`) would beat the retail rule (`customer_group_id=NULL`, `priority=100`) on the same
brand/category — leaking the trade margin (1500 bps) to anonymous viewers instead of retail
(2500 bps). Latent today (operator confirms no live trade rules yet); this lands the guard
before trade pricing goes live.

## The Fix — 4 retail layers scoped to null-group

Added `->whereNull('customer_group_id')` immediately after `->where('active', true)` in each of
the four v1 `PricingRule::query()` layers (with a `260710-efw` leak-fix comment):

1. **Layer 1 — brand_category** (SCOPE_BRAND_CATEGORY)
2. **Layer 2 — category** (SCOPE_CATEGORY)
3. **Layer 3 — brand** (SCOPE_BRAND)
4. **Layer 4 — default_tier** (SCOPE_DEFAULT_TIER)

Layer 0 (ProductOverride — not a PricingRule) and all `orderBy`/return logic were untouched.
`whereNull` was already an established construct in the file (Layer 4 `tier_max_pennies`), so no
new purity or construct concern. Retail behaviour is unchanged: genuine retail/default rules have
`customer_group_id = NULL`, so `whereNull` matches them exactly as before.

## D-03 Byte-Lock Re-Pin

The D-03 guard (`tests/Architecture/TradePricingNoV1ModificationTest.php`) pins RuleResolver's
sha256. It was intentionally re-pinned (operator-approved) with a rationale comment explaining the
lock predated trade rules sharing `pricing_rules`; the `whereNull(customer_group_id)` is the
minimal correctness patch keeping v1 retail resolution trade-free.

| | sha256 |
|---|---|
| Old (pre-fix) | `3b711b4ac5c41dd7f1ea314436316a976eff1a96c099d1e3159c572ddbfb4e6c` |
| New (post-fix) | `d40af7e7ff07f20424fcd9203f5d89058b0b13d27b98ca810767889bd6a32a23` |

Hash computed live via `hash_file('sha256', ...)` and cross-checked against the staged git blob
(`git show :app/Domain/Pricing/Services/RuleResolver.php`) — both match. The **PriceCalculator pin
in the same file is UNCHANGED**, and the v2 **TradeRuleResolver was not touched** (its byte-identity
+ purity tests hash TradeRuleResolver, not RuleResolver, and stay green).

## Verification

| Check | Result |
|---|---|
| `AnonymousDisplayPostureTest` | GREEN — 5 passed (anonymous/retail resolves margin 2500, trade 1500 no longer leaks) |
| `RuleResolverTest` + `RuleResolverPurityTest` | GREEN — 18 passed (retail golden fixtures unchanged; purity intact) |
| `TradePricingNoV1ModificationTest` (D-03) + `TradeRuleResolverByteIdentityTest` | GREEN — 6 passed (RuleResolver re-pinned; PriceCalculator + TradeRuleResolver pins unchanged) |
| `tests/Feature/TradePricing tests/Unit/TradePricing tests/Unit/Pricing` | 257 passed / 24 failed — **0 NEW failures** (see below) |
| `pint` RuleResolver.php | PASS |

### Deviations from Plan

None — plan executed exactly as written.

### Pre-existing failures (NOT caused by this change)

The full trade+pricing run shows 24 failing tests in `GoldenFixtureV2TradeTest` and
`TradeRuleResolverPurityTest`. **All 24 are `QueryException`s during test data setup** —
`no such table: customer_groups` and `FOREIGN KEY constraint failed` on `pricing_rules` inserts —
i.e. a SQLite test-isolation / migration-ordering issue in the combined multi-suite run, not
assertion failures about pricing values. A baseline run of these two suites against the
**original unmodified RuleResolver** produced the **identical 24 failed / 61 passed**, proving the
failures are pre-existing and independent of this change. Adding a `whereNull` to a SELECT cannot
produce a missing-table or FK-insert error. These pre-existing failures are logged here for the
operator; they are out of scope for this leak fix.

## Operator Notes

- **Deploy:** push `main` → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration).
- Security/pricing-correctness fix: retail + anonymous resolution now ignores trade
  (customer-group) rules, so trade-discounted prices can never leak to the public even when a
  trade rule out-ranks the retail rule on the same brand/category. Latent today (no live trade
  rules) — this lands the guard before trade pricing goes live.
- The v1 RuleResolver byte-lock (D-03) was intentionally re-pinned (documented). PriceCalculator +
  the v2 TradeRuleResolver are unchanged.

## Self-Check: PASSED
