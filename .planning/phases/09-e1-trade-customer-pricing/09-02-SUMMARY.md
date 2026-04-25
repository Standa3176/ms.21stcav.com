---
phase: 09-e1-trade-customer-pricing
plan: 02
subsystem: trade-pricing
tags: [trade-pricing, resolver, decorator, customer-groups, pricing-rules, pest, determinism, purity-contract, singleton]

requires:
  - phase: 09-01
    provides: customer_groups table + CustomerGroup model + pricing_rules.customer_group_id FK + factory + 4 seeded groups + Deptrac TradePricing layer
  - phase: 03-pricing-engine
    provides: RuleResolver service (decorator target — UNTOUCHED, byte-identical) + PricingResolution DTO + ProductOverride Layer 0 invariant + 5-tier specificity sort convention
  - phase: 01-foundation
    provides: Pest 3 + RefreshDatabase trait + meetingstore_ops_testing DB convention + skip-on-MySQL-offline precedent (Phase 6/7/8)
provides:
  - "App\\Domain\\TradePricing\\Services\\TradeRuleResolver — decorator wrapping v1 RuleResolver via constructor injection"
  - "5-tier specificity sort: ProductOverride → trade_brand_category → trade_category → trade_brand → trade_default_tier → fall-through to v1 retail"
  - "Retail fast-path: null|0 customer_group_id delegates to v1 verbatim (Pitfall B1 4-quadrant NULL matrix held)"
  - "ProductOverride Layer 0 invariant preserved (Pitfall 3 — override beats trade rules with priority+100 bias)"
  - "Within-layer sort priority DESC, id ASC (Phase 3 convention preserved)"
  - "Singleton binding in AppServiceProvider — same instance per request via $app->make(TradeRuleResolver::class)"
  - "Purity contract: zero config/clock/random/cache/auth reads (locked by source-file scan test)"
  - "TradeRuleResolverTest — 13 it() blocks covering 4-quadrant NULL matrix + override layering + 5-tier sort + Layer 5 fall-through + tiebreak"
  - "TradeRuleResolverPurityTest — 4 it() blocks; Test 1 runs offline (DB-free source-file scan)"
affects: [09-03-golden-fixture, 09-04-sync-pipeline, 09-05-filament-ux, 09-06-verification, phase-11-quote-flow]

tech-stack:
  added: []  # zero composer changes — pure service-layer + test additions
  patterns:
    - "Decorator pattern (D-03 Pattern 1): constructor injection of v1 RuleResolver as private readonly base"
    - "Retail fast-path short-circuit when customer_group_id is null OR 0 (Pitfall B1)"
    - "ProductOverride Layer 0 query duplicated at top of decorator (mirrors v1 invariant — override beats EVERY rule including trade)"
    - "Per-test RefreshDatabase via ->uses(RefreshDatabase::class) instead of file-global — lets DB-free source-scan tests run offline"
    - "Source-file scan as purity guardrail: forbidden-token allow-list with comment-stripped source (mirrors v1 RuleResolverPurityTest pattern)"

key-files:
  created:
    - app/Domain/TradePricing/Services/TradeRuleResolver.php (176 LOC)
    - tests/Unit/TradePricing/Services/TradeRuleResolverTest.php (402 LOC)
    - tests/Unit/TradePricing/Services/TradeRuleResolverPurityTest.php (195 LOC)
  modified:
    - app/Providers/AppServiceProvider.php (singleton binding for TradeRuleResolver — Phase 3 PriceRecomputer pattern)

requirements: [TRDE-02]

commits:
  - 8935c01 feat(09-02): TradeRuleResolver decorator + singleton binding (TRDE-02 Task 1)
  - b8b6fd7 test(09-02): TradeRuleResolverTest — 4-quadrant NULL matrix + 5-tier sort (TRDE-02 Task 2)
  - d98d1c8 test(09-02): TradeRuleResolverPurityTest — determinism contract (TRDE-02 Task 3)

deferred-tests:
  - "TradeRuleResolverTest (13 it() blocks) — deferred: requires meetingstore_ops_testing MySQL online. Phase 6/7/8 + Plan 09-01 precedent inherited (skipIfMySqlOfflineTrade helper at top of beforeEach + per-test)"
  - "TradeRuleResolverPurityTest Tests 2/3/4 — deferred: require live MySQL via per-test RefreshDatabase. Test 1 (source-file scan) runs offline today (verified PASSING — 12 assertions, 1.73s)"
  - "MySQL-deferred test execution: blocked behind v1 cutover Gate 3 (feature-tier Pest suite run against online meetingstore_ops_testing per docs/ops/cutover-handover.md Appendix A)"
---

# Plan 09-02 — TradeRuleResolver decorator (the architectural keystone)

## What was built

The load-bearing decorator service for Phase 9. Three commits, three files created, one file modified additively.

### 1. `App\Domain\TradePricing\Services\TradeRuleResolver` (176 LOC, commit `8935c01`)

Decorator wrapping v1's `RuleResolver` via constructor injection (Pattern 1 — D-03). Resolution chain (terminal at first hit):

| Layer | What | When |
|------|------|------|
| 0 | ProductOverride | Always — beats EVERY rule including trade rules with `priority+100` (Pitfall 3 invariant) |
| Retail fast-path | `$base->resolve($product)` | When `$customerGroupId === null` OR `=== 0` (Pitfall B1 — Phase 3 byte-identical) |
| 1 | trade_brand_category | When group set + brand_id + category_id both non-null on product |
| 2 | trade_category | When Layer 1 missed + category_id non-null |
| 3 | trade_brand | When Layer 2 missed + brand_id non-null |
| 4 | trade_default_tier | When Layers 1-3 missed + tier rule covers buy_price |
| 5 | `$base->resolve($product)` (fall-through) | When all four trade layers missed |

Within each layer the sort is `priority DESC, id ASC` (Phase 3 convention preserved). The four `'trade_*'` source strings (`trade_brand_category`, `trade_category`, `trade_brand`, `trade_default_tier`) let RuleExplorer (Plan 09-04 Filament) badge resolution source for Phase 9 callers.

Singleton binding in AppServiceProvider (alongside Phase 3 PriceRecomputer / Phase 4 BitrixClient bindings) so `$app->make(TradeRuleResolver::class)` returns the same instance per request — matches v1's pattern and avoids repeat DI resolution cost on hot paths.

### 2. `TradeRuleResolverTest` (402 LOC, 13 it() blocks, commit `b8b6fd7`)

Locks every plan-acceptance behaviour:

- **4-quadrant NULL matrix (Pitfall B1)** — null / 0 / non-existent / valid group all covered
- **Override layering (Pitfall 3)** — ProductOverride beats trade rule with priority+100 bias
- **5-tier specificity** — Layers 1-4 each have their own win-condition test
- **Layer 5 fall-through** — different group with no matching rule falls to v1 retail
- **Tiebreak (×2)** — priority DESC + id ASC inside each trade layer
- **Singleton** — `$app->make()` returns same instance

### 3. `TradeRuleResolverPurityTest` (195 LOC, 4 it() blocks, commit `d98d1c8`)

Mirrors v1's `RuleResolverPurityTest` with one improvement:

- **Test 1 — source-file scan (DB-free, ALWAYS runs):** asserts zero matches for 12 forbidden tokens (`config(`, `now(`, `Carbon::now`, `auth(`, `auth()->`, `Cache::`, `cache(`, `Session::`, `session(`, `random_int(`, `mt_rand(`, `microtime(`). **Verified PASSING — 12 assertions, 1.73s, MySQL-offline.**
- **Test 2/3/4 — determinism (DB-required, deferred):** two calls on identical DB state return equal `PricingResolution` instances; loops over all four NULL quadrants; holds when ProductOverride wins Layer 0.

The improvement over v1: `RefreshDatabase` is applied **per-test** via `->uses(RefreshDatabase::class)` rather than file-globally, so the static source-file scan can run offline. Future TradeRuleResolver edits that introduce a clock/config/random/cache/auth read fail Test 1 on CI even when MySQL is offline.

### 4. AppServiceProvider singleton binding diff

Inserted immediately after Phase 3's `PriceRecomputer` registration (Pricing-domain bindings cluster):

```diff
+        // ── Phase 9 Plan 02: TradeRuleResolver decorator (TRDE-02) ───────
+        $this->app->singleton(\App\Domain\TradePricing\Services\TradeRuleResolver::class, function ($app) {
+            return new \App\Domain\TradePricing\Services\TradeRuleResolver(
+                $app->make(\App\Domain\Pricing\Services\RuleResolver::class),
+            );
+        });
```

DOES NOT alter the existing `RuleResolver` singleton registration. The decorator wraps without mutating the wrapee's container shape.

## Why this matters

- **v1 RuleResolver remains BYTE-IDENTICAL.** Verified: `git diff HEAD~10 app/Domain/Pricing/Services/RuleResolver.php` returns empty. The file hasn't been touched since `4603de4` (Phase 3 03-02 ship). Six v1 callers (`PriceRecomputer`, `SimulatedImpactCalculator`, `RuleExplorer`, `ComputeMarginSuggestionJob`, `CreateWooProductJob`, plus `RuleResolver` self-tests) keep resolving via v1 directly — they don't know customer groups exist. Phase 3 golden-fixture parity preserved by definition.
- **Phase 9 + Phase 11 callers reach for TradeRuleResolver.** New trade-aware paths (Plan 09-04 Filament, Plan 09-03 golden fixture extension, Phase 11 E2 Quote flow) construct via the singleton.
- **Pitfall 3 invariant locked.** `it('ProductOverride beats trade rule even with priority+100')` fails on CI if anyone reorders the layers.
- **Purity contract auto-enforced.** Future edits introducing a clock/config/random read fail Test 1 even with MySQL offline.

## Notable deviations

- **Per-test RefreshDatabase (improvement, not deviation).** Plan said "skip with markTestSkipped at top if MySQL offline (Phase 6/7/8 precedent)" — i.e. per-test skip helpers. I went one better in the purity test by applying `RefreshDatabase` per-test via `->uses()` so Test 1's DB-free source scan runs PASSING offline rather than failing-skipped. Tests 2/3/4 still follow the deferred-tests posture. This is consistent with the plan's success criterion ("exits 0 OR markTestSkipped on MySQL-offline") and strictly better than the v1 RuleResolverPurityTest pattern.

- **No documented deviations beyond that.** The TradeRuleResolver service shape, singleton binding placement, test coverage matrix, and acceptance criteria are all as specified in the plan.

## What this enables

- **Plan 09-03** can extend the golden fixture with 30 trade triples and assert end-to-end resolution at penny precision. The decorator service is the seam those triples flow through.
- **Plan 09-04** Filament `PricingRuleResource` extension + `CustomerGroupResource` can wire the trade-rule Select field; pricing manager creates a group rule and `app(TradeRuleResolver::class)->resolve($product, $user->customer_group_id)` returns the right margin.
- **Plan 09-05** retail-parity guardrail tests can assert that v1 callers (PriceRecomputer etc.) keep resolving via v1's RuleResolver directly — no leakage of TradeRuleResolver into retail paths.
- **Phase 11 E2 Quote flow** — every line in a quote has its margin computed via `TradeRuleResolver::resolve($product, $quote->customer_group_id)`. Without this service, the quote builder has nothing to call.

## Verification snapshot

| Check | Status |
|---|---|
| `php -l app/Domain/TradePricing/Services/TradeRuleResolver.php` | PASS (no syntax errors) |
| `php -l app/Providers/AppServiceProvider.php` | PASS (no syntax errors) |
| `php -l tests/Unit/TradePricing/Services/TradeRuleResolverTest.php` | PASS |
| `php -l tests/Unit/TradePricing/Services/TradeRuleResolverPurityTest.php` | PASS |
| `php artisan tinker` resolves `TradeRuleResolver::class` | PASS — returns `App\Domain\TradePricing\Services\TradeRuleResolver` |
| Singleton: same instance across two `app()` calls | PASS (verified via tinker) |
| `grep -c "TradeRuleResolver::class" app/Providers/AppServiceProvider.php` | 2 (binding + comment) — exactly one singleton registration |
| Forbidden tokens in TradeRuleResolver source | 0 matches (Test 1 passing — 12 assertions, MySQL-offline) |
| `git diff HEAD~10 app/Domain/Pricing/Services/RuleResolver.php` | EMPTY — v1 BYTE-IDENTICAL |
| `it()` blocks in TradeRuleResolverTest | 13 (≥10 minimum per plan acceptance) |
| `expect($a->...)` assertions in TradeRuleResolverPurityTest | 14 (≥5 minimum per plan acceptance) |
| TradeRuleResolverPurityTest Test 1 (DB-free source scan) | PASS — 12 assertions, 1.73s |
| TradeRuleResolverTest 13 tests | DEFERRED to MySQL-online run (Phase 6/7/8 + Plan 09-01 precedent) |

## Threat surface scan

Reviewed all files created/modified against Plan 09-02 `<threat_model>` STRIDE register. No new attack surface beyond what's documented:

- **T-09-02-01 (SQL injection on customer_group_id)** — mitigated as planned via Eloquent `->where('customer_group_id', $customerGroupId)` parameter binding + PHP `?int` typehint coercion.
- **T-09-02-03 (override bypassed by trade priority+100)** — mitigated as planned: ProductOverride Layer 0 query at the top of `resolve()` BEFORE the retail fast-path check; locked on CI by `TradeRuleResolverTest::it('ProductOverride beats trade rule even with priority+100')`.
- **T-09-02-04 (wrong rule ID logged)** — mitigated as planned: `matchedRuleId` + `chain[]` are deterministic per purity contract; locked on CI by `TradeRuleResolverPurityTest::it('two calls on identical DB state return equal PricingResolution')`.

No threat-flag types introduced.

## Self-Check: PASSED

**Files:**
- FOUND: `app/Domain/TradePricing/Services/TradeRuleResolver.php` (176 LOC)
- FOUND: `tests/Unit/TradePricing/Services/TradeRuleResolverTest.php` (402 LOC)
- FOUND: `tests/Unit/TradePricing/Services/TradeRuleResolverPurityTest.php` (195 LOC)
- FOUND: `app/Providers/AppServiceProvider.php` modified (singleton binding present)

**Commits:**
- FOUND: `8935c01` (Task 1)
- FOUND: `b8b6fd7` (Task 2)
- FOUND: `d98d1c8` (Task 3)

**Invariants:**
- FOUND: `git diff HEAD~10 app/Domain/Pricing/Services/RuleResolver.php` → EMPTY (v1 byte-identical)
- FOUND: TradeRuleResolverPurityTest Test 1 PASSES with MySQL offline (purity contract enforceable on CI)
