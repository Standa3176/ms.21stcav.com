---
phase: 260710-efw-fix-trade-pricing-leak-ruleresolver-must
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Pricing/Services/RuleResolver.php
  - tests/Architecture/TradePricingNoV1ModificationTest.php
must_haves:
  truths:
    - "The trade-pricing LEAK is fixed: RuleResolver's retail resolution now ignores trade rules. Each of the 4 PricingRule layer queries (Layer 1 brand_category, Layer 2 category, Layer 3 brand, Layer 4 default_tier) gains `->whereNull('customer_group_id')`, so a null-group (anonymous/retail) resolution can no longer pick up a higher-priority trade rule (customer_group_id set) that shares the pricing_rules table. AnonymousDisplayPostureTest goes green (anonymous viewer gets retail margin 2500, not trade 1500)."
    - "Retail behaviour is UNCHANGED for genuine retail rules: retail/default rules have customer_group_id = NULL, so whereNull matches them exactly as before — RuleResolverTest (golden fixtures) + RuleResolverPurityTest stay green. Layer 0 (ProductOverride, not a PricingRule) is untouched. whereNull is already used in the file (Layer 4 tier_max), so no new construct/purity concern."
    - "The D-03 byte-identity guard is re-pinned: TradePricingNoV1ModificationTest's RuleResolver sha256 (was 3b711b4a…) is updated to the new committed-file hash, computed LIVE after the edit (do NOT hard-code a guessed value). A comment is added explaining WHY the v1-frozen invariant is being corrected: the lock predated trade rules sharing the pricing_rules table; adding whereNull(customer_group_id) is the minimal correctness patch so v1 retail resolution keeps ignoring trade rules. The PriceCalculator pin in the same file is UNCHANGED."
    - "No prod behaviour change for retail; the only change is retail/anonymous no longer leaking trade prices. Latent today (operator: trade rules not yet live), so this is a pre-go-live correctness fix. TradeRuleResolver (v2) is untouched — its byte-identity + purity tests stay green (they hash TradeRuleResolver, not RuleResolver)."
  artifacts:
    - path: "app/Domain/Pricing/Services/RuleResolver.php"
      provides: "retail resolution excludes trade (customer_group) rules"
      contains: "whereNull('customer_group_id')"
    - path: "tests/Architecture/TradePricingNoV1ModificationTest.php"
      provides: "re-pinned RuleResolver sha256 + rationale"
      contains: "customer_group_id"
  key_links:
    - from: "RuleResolver 4 layer queries"
      to: "retail-only (null customer_group) rule set"
      via: "->whereNull('customer_group_id')"
      pattern: "whereNull('customer_group_id')"
---

<objective>
Close the trade-pricing leak (operator-approved: edit RuleResolver + re-pin D-03). RuleResolver's null-group
(retail/anonymous) resolution must exclude trade rules that now share the pricing_rules table, so the public can
never be shown trade-discounted prices. Minimal, careful, money-path change with full retail-parity verification.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260710-efw-fix-trade-pricing-leak-ruleresolver-must/
@CLAUDE.md
@app/Domain/Pricing/Services/RuleResolver.php
@tests/Architecture/TradePricingNoV1ModificationTest.php
@tests/Feature/TradePricing/AnonymousDisplayPostureTest.php
@tests/Unit/Pricing/RuleResolverTest.php
@tests/Unit/Pricing/RuleResolverPurityTest.php
@app/Domain/TradePricing/Services/TradeRuleResolver.php
---
Verified: RuleResolver::resolve has Layer 0 = ProductOverride (line ~42, NOT a PricingRule — leave it), then 4
PricingRule::query() layers: Layer 1 brand_category (~line 65-71), Layer 2 category (~87-92), Layer 3 brand
(~108-113), Layer 4 default_tier (~128-138). Each has `->where('active', true)` + scope/id filters +
`->orderByDesc('priority')->orderBy('id')`. whereNull is already used (Layer 4, tier_max_pennies ~134).
D-03 pin: TradePricingNoV1ModificationTest.php:35 `$expected = '3b711b4ac5c41dd7f1ea314436316a976eff1a96c099d1e3159c572ddbfb4e6c';`
Root cause (triage): TradeRuleResolver routes null-group callers to base RuleResolver::resolve, which never
filtered customer_group_id → trade rule (priority 200) beats retail (priority 100). Fix = base excludes trade rules.
</context>

<interfaces>
=== RuleResolver.php — add whereNull to the 4 PricingRule layers ===
To EACH of Layer 1, 2, 3, 4 `PricingRule::query()` chains, add `->whereNull('customer_group_id')` (place it
alongside the other where clauses, e.g. right after `->where('active', true)`). Do NOT touch Layer 0 (ProductOverride)
or any orderBy/return logic. Example (Layer 1):
```php
$rule = PricingRule::query()
    ->where('scope', PricingRule::SCOPE_BRAND_CATEGORY)
    ->where('active', true)
    ->whereNull('customer_group_id')   // 260710-efw — retail resolution ignores trade (customer-group) rules (leak fix)
    ->where('brand_id', $brandId)
    ->where('category_id', $categoryId)
    ->orderByDesc('priority')
    ->orderBy('id')
    ...
```
Repeat for Layer 2 (category), Layer 3 (brand), Layer 4 (default_tier).

=== TradePricingNoV1ModificationTest.php — re-pin ===
After editing RuleResolver, compute the new hash: `hash_file('sha256', base_path('app/Domain/Pricing/Services/RuleResolver.php'))`
(and cross-check `git show :app/Domain/Pricing/Services/RuleResolver.php | sha256sum` once staged). Replace the
`$expected = '3b711b4a…'` literal (line 35) with the new value. Add a comment above it: the v1 byte-lock is
intentionally re-pinned because trade rules now share pricing_rules; the added whereNull(customer_group_id) is the
minimal correctness patch keeping v1 retail resolution trade-free. Leave the PriceCalculator pin untouched.
</interfaces>

<tasks>

<task type="auto" tdd="false">
  <name>Task 1: whereNull the 4 retail layers + re-pin D-03</name>
  <files>
    app/Domain/Pricing/Services/RuleResolver.php,
    tests/Architecture/TradePricingNoV1ModificationTest.php
  </files>
  <behavior>
    Add `->whereNull('customer_group_id')` to the 4 PricingRule layer queries; re-pin the RuleResolver sha256
    (computed live). tdd=false — the fix is proven by the existing (currently-failing) AnonymousDisplayPostureTest;
    the re-pin is verified by the D-03 test itself. Change ONLY these two files.
  </behavior>
  <action>
    Edit RuleResolver (4 whereNull); compute + set the new sha in TradePricingNoV1ModificationTest. Run the full
    verification set below.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/TradePricing/AnonymousDisplayPostureTest.php 2>&1 | tail -6</automated>
    Expected: GREEN — anonymous/retail viewer now resolves the retail rule (margin 2500), trade rule (1500) no longer leaks.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Unit/Pricing/RuleResolverTest.php tests/Unit/Pricing/RuleResolverPurityTest.php 2>&1 | tail -8</automated>
    Expected: GREEN — retail golden fixtures unchanged (retail rules are null-group), purity intact.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Architecture/TradePricingNoV1ModificationTest.php tests/Architecture/TradeRuleResolverByteIdentityTest.php 2>&1 | tail -8</automated>
    Expected: GREEN — RuleResolver re-pinned (D-03 passes with the new hash); PriceCalculator + TradeRuleResolver pins unchanged.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/TradePricing tests/Unit/TradePricing tests/Unit/Pricing 2>&1 | tail -8</automated>
    Expected: no NEW failures — the whole trade + pricing surface stays green (trade resolution for real customer-group callers still works via TradeRuleResolver).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint app/Domain/Pricing/Services/RuleResolver.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - 4 retail layers whereNull(customer_group_id); AnonymousDisplayPostureTest green; retail golden + purity + trade suites green; D-03 re-pinned (rationale comment); PriceCalculator/TradeRuleResolver untouched; pint clean.
  </done>
</task>

</tasks>

<verification>
1. AnonymousDisplayPostureTest → GREEN (leak closed)
2. RuleResolverTest + RuleResolverPurityTest → GREEN (retail parity)
3. D-03 (re-pinned) + TradeRuleResolver byte-identity → GREEN
4. tests/Feature/TradePricing + tests/Unit/TradePricing + tests/Unit/Pricing → no new failures
5. pint → PASS

Operator notes (for SUMMARY.md):
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration).
- Security/pricing correctness fix: retail + anonymous price resolution now ignores trade (customer-group) rules,
  so trade-discounted prices can never leak to the public even when a trade rule outranks the retail rule on the
  same brand/category. Latent today (no live trade rules) — this lands the guard before trade pricing goes live.
- The v1 RuleResolver byte-lock (D-03) was intentionally re-pinned (documented) because trade rules now share the
  pricing_rules table; the whereNull(customer_group_id) is the minimal correctness patch. PriceCalculator + the v2
  TradeRuleResolver are unchanged.
</verification>

<success_criteria>
- The trade-pricing leak is closed (4 retail layers whereNull(customer_group_id)); AnonymousDisplayPostureTest green; retail golden fixtures + purity + the full trade/pricing suites show no new failures; D-03 re-pinned with rationale; PriceCalculator + TradeRuleResolver untouched; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260710-efw-fix-trade-pricing-leak-ruleresolver-must/260710-efw-SUMMARY.md` documenting the leak, the 4-layer whereNull fix, the D-03 re-pin (old→new hash + rationale), the retail-parity verification, and that it's a pre-go-live guard (trade rules not yet live).
</output>