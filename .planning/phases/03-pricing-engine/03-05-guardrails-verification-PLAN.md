---
phase: 03-pricing-engine
plan: 05
type: execute
wave: 4
depends_on:
  - 03-03
  - 03-04
files_modified:
  - depfile.yaml
  - tests/Architecture/DeptracPricingLayerTest.php
  - tests/Architecture/PricingRuleExclusiveSetTest.php
  - tests/Architecture/PriceCalculatorPurityTest.php
  - .planning/phases/03-pricing-engine/03-VERIFICATION.md
autonomous: true
requirements:
  - PRCE-01
  - PRCE-02
  - PRCE-03
  - PRCE-04
  - PRCE-05
  - PRCE-06
  - PRCE-07
  - PRCE-08
  - PRCE-09
  - PRCE-10

must_haves:
  truths:
    - "Deptrac depfile.yaml defines a Pricing layer with allow-list (Foundation, Products, Sync) and implicit deny of everything else"
    - "Architectural test fails the build if a Pricing class imports CRM, Competitor, Webhooks, Feeds, or Alerting"
    - "DB-level CHECK constraint OR architectural test asserts pricing_rules.is_default_tier is exclusive-set with brand/category"
    - "PriceCalculator purity test — no Eloquent, no events, no logging, no config reads except rounding_mode"
    - "Phase 3 VERIFICATION.md documents all 5 success criteria pass/fail with test-command evidence"
    - "VERIFICATION.md confirms golden-fixture count (50) + passing test count and cites the commit that establishes the ship gate"
  artifacts:
    - path: "depfile.yaml"
      provides: "Pricing layer with cross-module allow/deny rules"
      contains: "Pricing"
    - path: "tests/Architecture/DeptracPricingLayerTest.php"
      provides: "Positive + negative tests for the Pricing layer ruleset"
      contains: "Pricing"
    - path: "tests/Architecture/PricingRuleExclusiveSetTest.php"
      provides: "is_default_tier=true → brand_id+category_id NULL; false → at least one set"
      contains: "is_default_tier"
    - path: "tests/Architecture/PriceCalculatorPurityTest.php"
      provides: "Grep-based asserting PriceCalculator has no Eloquent/events/logging"
      contains: "PriceCalculator"
    - path: ".planning/phases/03-pricing-engine/03-VERIFICATION.md"
      provides: "Phase 3 ship verdict with evidence for each of 5 success criteria"
      contains: "golden"
  key_links:
    - from: "depfile.yaml"
      to: "Pricing layer ruleset"
      via: "layers + ruleset sections"
      pattern: "Pricing:\\s+\\["
    - from: "tests/Architecture/DeptracPricingLayerTest.php"
      to: "depfile.yaml"
      via: "Process deptrac-shim subprocess"
      pattern: "deptrac"
---

<objective>
Lock Phase 3's architectural boundaries + publish the VERIFICATION.md that declares the phase shipped. The Pricing domain's cross-module import graph is enforced at CI time by Deptrac (Pricing → Foundation, Products, Sync are the only allowed outbound deps; CRM/Competitor/Webhooks/Feeds/Alerting are banned). A Pest architecture test validates the exclusive-set invariant on `pricing_rules` rows. A separate architecture test greps `PriceCalculator` for Eloquent / event / logging / config leaks beyond the documented one config call. VERIFICATION.md captures the green-light evidence for the 5 ROADMAP success criteria.

Purpose: Phase 3's value is correctness (golden fixture is the ship gate) + predictability (resolver is deterministic). Guardrails prevent future drift — e.g. a later phase adding `config('app.name')` inside PriceCalculator would invisibly break the purity contract. The Deptrac layer also catches a future developer importing, say, `CompetitorPrice` into a pricing service — a subtle coupling that would make Phase 5's competitor suggestions hard to test in isolation. VERIFICATION.md is the single document ops reads to confirm "Phase 3 is green — safe to move to Phase 4".

Output:
- `depfile.yaml` extended with Pricing layer rules (Pricing depends on Foundation + Products + Sync ONLY)
- `tests/Architecture/DeptracPricingLayerTest.php` — positive (clean codebase passes) + negative (planted violator flags) — mirroring `DeptracSyncLayerTest.php`
- `tests/Architecture/PricingRuleExclusiveSetTest.php` — data-integrity invariant as an architecture test (DB-driven)
- `tests/Architecture/PriceCalculatorPurityTest.php` — source-grep invariant
- `.planning/phases/03-pricing-engine/03-VERIFICATION.md` — phase ship verdict
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/03-pricing-engine/03-CONTEXT.md
@.planning/phases/03-pricing-engine/03-01-SUMMARY.md
@.planning/phases/03-pricing-engine/03-02-SUMMARY.md
@.planning/phases/03-pricing-engine/03-03-SUMMARY.md
@.planning/phases/03-pricing-engine/03-04-SUMMARY.md
@.planning/phases/02-supplier-sync/02-VERIFICATION.md
@CLAUDE.md
@depfile.yaml
@tests/Architecture/DeptracSyncLayerTest.php
@tests/Architecture/DeptracTest.php
@tests/Architecture/PolicyTemplateIntegrityTest.php
@app/Domain/Pricing/Services/PriceCalculator.php
@app/Domain/Pricing/Services/RuleResolver.php
@app/Domain/Pricing/Services/PriceRecomputer.php
</context>

<tasks>

<task type="auto">
  <name>Task 1: Deptrac Pricing layer ruleset + architectural test (positive + negative)</name>
  <files>
    depfile.yaml,
    tests/Architecture/DeptracPricingLayerTest.php
  </files>
  <read_first>
    depfile.yaml,
    tests/Architecture/DeptracSyncLayerTest.php,
    tests/Architecture/DeptracTest.php,
    app/Domain/Pricing/Services/RuleResolver.php,
    app/Domain/Pricing/Services/PriceRecomputer.php,
    app/Domain/Pricing/Listeners/RecomputePriceListener.php
  </read_first>
  <action>
    Step 1 — edit `depfile.yaml`. The `Pricing` layer already exists with the default rule `Pricing: [Foundation]` (Plan 01 did NOT need to change this). Now that Plan 02 added cross-module dependencies (Pricing reads Products + subscribes to Sync events + writes ImportIssue in Sync), UPDATE the ruleset line:
    ```yaml
    Pricing:      [Foundation, Products, Sync]  # Plan 03-02: RuleResolver reads Product; RecomputePriceListener subscribes to Sync events + writes ImportIssue (Sync model)
    ```
    Preserve all other layer rules untouched (Phase 1/2 entries, WpDirectDb deny, Http aggregator).

    Add a comment block above the `Pricing:` line explaining which READ paths justify each allow (for future reviewers):
    ```yaml
    # Phase 3 (Plan 03-02 + 03-04): Pricing layer cross-domain allow-list.
    #   - Foundation: DomainEvent, Auditor, IntegrationLogger, BaseCommand (all Pricing services extend these)
    #   - Products: Product + ProductVariant (RuleResolver + PriceRecomputer read buy_price / brand_id / category_id)
    #   - Sync: SupplierPriceChanged event + ImportIssue model (listener subscribes; PriceRecomputer writes missing_cost_price rows)
    # Explicitly NOT allowed: CRM, Competitor, Webhooks, Feeds, Alerting, Suggestions.
    # (Phase 5 will add Competitor → Pricing for the suggestions producer — NOT the reverse.)
    Pricing:      [Foundation, Products, Sync]
    ```

    Step 2 — author `tests/Architecture/DeptracPricingLayerTest.php`. Mirror `tests/Architecture/DeptracSyncLayerTest.php` structure:
    - Test 1 (positive): run `vendor/bin/deptrac analyse` via Symfony Process; assert exit code 0.
    - Test 2 (negative): write a deliberate violator at `app/Domain/Pricing/Services/__PricingDeptracViolator.php` that imports `App\Domain\CRM\Models\...` (pick any real CRM class — Phase 4 hasn't shipped yet so there's nothing to import; use `App\Domain\Webhooks\...` instead — Phase 1 shipped WebhookReceipt). The test plants, runs deptrac, captures exit code, cleans up BEFORE asserting, then asserts exit code is non-zero.

    **Concrete violator class** (use Webhooks since it exists and is NOT in Pricing's allow-list):
    ```php
    namespace App\Domain\Pricing\Services;

    use App\Domain\Webhooks\Models\WebhookReceipt;

    final class __PricingDeptracViolator
    {
        public function bad(WebhookReceipt $r): string
        {
            return (string) $r->id;
        }
    }
    ```

    Test structure — copy `DeptracSyncLayerTest.php` verbatim and adapt:
    - Change file paths to `app/Domain/Pricing/Services/__PricingDeptracViolator.php`
    - Change the violator class body to the shape above
    - Keep the Windows-stdout note + exit-code-is-authoritative pattern
    - Descriptive test names:
      - `it('Pricing domain has zero unauthorized imports (positive)')`
      - `it('catches a deliberate Webhooks import from Pricing (negative)')`

    **Prerequisite check:** confirm `App\Domain\Webhooks\Models\WebhookReceipt` exists. Phase 1 Plan 04 shipped `webhook_receipts` migration — the model should exist. If not (phase was renamed), pick the nearest existing model outside Pricing's allow-list: `App\Domain\Alerting\Models\AlertRecipient` (Phase 1 Plan 05) or `App\Domain\Suggestions\Models\Suggestion` (Phase 1 Plan 04). Any of these trips the Pricing allow-list deny.

    Step 3 — run Deptrac directly to confirm positive case:
    ```
    vendor/bin/deptrac analyse --config-file=depfile.yaml --no-progress
    ```
    MUST exit 0 with no violations (Plans 01-04 should produce clean Pricing code).

    Step 4 — run the architectural test:
    ```
    vendor/bin/pest tests/Architecture/DeptracPricingLayerTest.php --stop-on-failure
    ```
    Both tests MUST pass (positive = deptrac clean; negative = planted violator trips the rule).

    **DO NOT:**
    - Do NOT remove or widen the existing `Sync: [...]` ruleset — Phase 2's WpDirectDb deny must stay intact.
    - Do NOT add CRM / Competitor / Feeds to Pricing's allow-list. Future phases (5, 6) may add NEW layer allow-rules (Competitor → Pricing, Feeds → Pricing) but Pricing itself stays narrow.
    - Do NOT forget the cleanup `@unlink($violatorFile)` BEFORE the assert — per the DeptracSyncLayerTest comment, a failed assertion must never leave the violator on disk.
  </action>
  <verify>
    <automated>vendor/bin/deptrac analyse --config-file=depfile.yaml --no-progress && vendor/bin/pest tests/Architecture/DeptracPricingLayerTest.php --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `grep -q "Pricing:\\s*\\[Foundation, Products, Sync\\]" depfile.yaml`
    - `grep -q "Phase 3 (Plan 03-02 + 03-04): Pricing layer" depfile.yaml` (comment block present)
    - `test -f tests/Architecture/DeptracPricingLayerTest.php` returns 0
    - `grep -q "__PricingDeptracViolator" tests/Architecture/DeptracPricingLayerTest.php`
    - `grep -q "not->toBe(" tests/Architecture/DeptracPricingLayerTest.php` (negative test present)
    - `grep -q "vendor/qossmic/deptrac-shim/deptrac" tests/Architecture/DeptracPricingLayerTest.php` (or base_path reference)
    - `vendor/bin/deptrac analyse --config-file=depfile.yaml --no-progress` exits 0
    - `vendor/bin/pest tests/Architecture/DeptracPricingLayerTest.php --stop-on-failure` exits 0
    - Existing Sync Deptrac test still passes: `vendor/bin/pest tests/Architecture/DeptracSyncLayerTest.php --stop-on-failure` exits 0 (regression check)
    - No violator file left over: `test ! -f app/Domain/Pricing/Services/__PricingDeptracViolator.php`
  </acceptance_criteria>
  <done>
    Pricing layer ruleset locked. Build fails when a Pricing class imports outside the allow-list. Existing Phase 1/2 Deptrac layers untouched.
  </done>
</task>

<task type="auto">
  <name>Task 2: PricingRule exclusive-set architecture test + PriceCalculator purity architecture test</name>
  <files>
    tests/Architecture/PricingRuleExclusiveSetTest.php,
    tests/Architecture/PriceCalculatorPurityTest.php
  </files>
  <read_first>
    .planning/phases/03-pricing-engine/03-CONTEXT.md,
    app/Domain/Pricing/Models/PricingRule.php,
    app/Domain/Pricing/Services/PriceCalculator.php,
    tests/Architecture/PolicyTemplateIntegrityTest.php,
    database/migrations/2026_04_19_090000_create_pricing_rules_table.php
  </read_first>
  <action>
    Step 1 — author `tests/Architecture/PricingRuleExclusiveSetTest.php`. From CONTEXT.md Specific Ideas: "PricingRule.is_default_tier column is exclusive-set — either a rule is a default-tier fallback (tier bounds set, brand/category NULL) or it's a specific rule (brand and/or category set, tier bounds NULL)."

    Implementation — two tests:
    - Test A (positive control — seeded defaults are valid): after seeding DefaultPricingTierSeeder, every row with is_default_tier=true has brand_id+category_id NULL; every row with is_default_tier=false has at least one of brand_id/category_id NOT NULL (or is the default_tier scope itself) OR tier_min_pennies NULL.
    - Test B (live catalog invariant check): query every row in pricing_rules. For each row, assert:
      ```
      if (is_default_tier) {
          expect(brand_id)->toBeNull();
          expect(category_id)->toBeNull();
          expect(tier_min_pennies)->not->toBeNull();  // must define a range
      } else {
          expect(tier_min_pennies)->toBeNull();
          expect(tier_max_pennies)->toBeNull();
          // scope=brand|category|brand_category must have at least one of brand/category set
          if (scope === 'brand_category') {
              expect(brand_id)->not->toBeNull();
              expect(category_id)->not->toBeNull();
          }
          if (scope === 'brand') expect(brand_id)->not->toBeNull();
          if (scope === 'category') expect(category_id)->not->toBeNull();
      }
      ```

    Structure: use `uses(RefreshDatabase::class)`, `beforeEach` seeds the DefaultPricingTierSeeder. Test B also creates a handful of specific rules via factories (`PricingRule::factory()->create([...])` for each scope) to exercise the invariant across scope types.

    Test framework note: RefreshDatabase re-creates testing DB each test. Seed is cheap; add 4 additional explicit rules (one per non-default scope) inside Test B.

    Step 2 — author `tests/Architecture/PriceCalculatorPurityTest.php`. The test greps the PriceCalculator source file for disallowed patterns:
    - FORBIDDEN: `Model::`, `Eloquent`, `DB::`, `Log::`, `Event::`, `dispatch`, `Mail::`, `Queue::`, `Context::`, `Http::`
    - EXACT config call count == 1 (the rounding mode read) — assert via regex `/config\\(['"]pricing\\.rounding_mode/` matches exactly once; and `grep -cE "config\\("` overall == 1.
    - FORBIDDEN tokens for clock/random/session: `now(`, `Carbon::`, `time()`, `microtime(`, `rand(`, `mt_rand`, `random_int`, `Str::uuid`, `session(`, `auth()`
    - FORBIDDEN: `float` type hints (`: float`, `(float)` is allowed ONLY inside the one integer-conversion line; count <= 1)
    - ALLOWED: `int`, `round`, the one config() read, `throw`, SupplierPriceUnusableException references

    Structure:
    ```php
    it('PriceCalculator has no Eloquent / events / logging / HTTP / mail', function (): void {
        $source = file_get_contents(app_path('Domain/Pricing/Services/PriceCalculator.php'));
        $forbidden = [
            'Model::', 'Eloquent', 'DB::', 'Log::', 'Event::', 'Mail::', 'Queue::',
            'Context::', 'Http::', 'dispatch(', 'fire(',
        ];
        foreach ($forbidden as $token) {
            expect($source)->not->toContain($token,
                "PriceCalculator must be pure — found forbidden token: {$token}");
        }
    });

    it('PriceCalculator reads config only for rounding_mode, exactly once', function (): void {
        $source = file_get_contents(app_path('Domain/Pricing/Services/PriceCalculator.php'));
        expect(substr_count($source, "config("))->toBeLessThanOrEqual(2,
            "PriceCalculator should read config at most twice (rounding_mode in compute + stripVat); found more");
        // The one/two reads must be rounding_mode, not app.name or similar:
        $matches = [];
        preg_match_all('/config\\([\\\'"]pricing\\.rounding_mode[\\\'"]/u', $source, $matches);
        expect(count($matches[0]))->toBeGreaterThan(0, "PriceCalculator does not read pricing.rounding_mode via config — D-02 violation");
    });

    it('PriceCalculator has no clock / random / session leaks', function (): void {
        $source = file_get_contents(app_path('Domain/Pricing/Services/PriceCalculator.php'));
        $forbidden = ['now(', 'Carbon::', 'time()', 'microtime(', 'rand(', 'mt_rand', 'random_int', 'Str::uuid', 'session(', 'auth()'];
        foreach ($forbidden as $token) {
            expect($source)->not->toContain($token,
                "PriceCalculator must be pure — found forbidden token: {$token}");
        }
    });

    it('PriceCalculator has no float type leak', function (): void {
        $source = file_get_contents(app_path('Domain/Pricing/Services/PriceCalculator.php'));
        // ': float' return/param and public float $x property all forbidden.
        expect(preg_match_all('/:\\s*float/u', $source))->toBe(0,
            "PriceCalculator signatures must not use float (Pitfall 5)");
        expect(preg_match_all('/\\bfloat\\b/u', $source))->toBeLessThanOrEqual(0,
            "PriceCalculator must not type-hint float");
    });

    it('PriceCalculator applies round() at most twice (once per public method)', function (): void {
        $source = file_get_contents(app_path('Domain/Pricing/Services/PriceCalculator.php'));
        $roundCount = substr_count($source, 'round(');
        expect($roundCount)->toBeLessThanOrEqual(2,
            "PriceCalculator must call round() at most twice — compute() + stripVat(). Found: {$roundCount}. Pitfall 5 warns about compound rounding.");
    });
    ```

    Step 3 — run both tests:
    ```
    vendor/bin/pest tests/Architecture/PricingRuleExclusiveSetTest.php tests/Architecture/PriceCalculatorPurityTest.php --stop-on-failure
    ```
    All MUST pass. If the PriceCalculator purity test fails, go back to Plan 01 and remove the leak — this is not a "relax the test" situation, it's a "fix the code" situation.

    **DO NOT:**
    - Do NOT weaken any assertion to make a failing test pass — if PriceCalculator leaks Eloquent, FIX the calculator.
    - Do NOT check only the file path — open the file and grep the source. Reflection-based checks are insufficient (they'd miss type hints in docblocks).
    - Do NOT use a DB CHECK constraint for the exclusive-set invariant (MySQL < 8.0.16 ignores CHECK). Architecture test is the portable cross-version guard.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Architecture/PricingRuleExclusiveSetTest.php tests/Architecture/PriceCalculatorPurityTest.php --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `test -f tests/Architecture/PricingRuleExclusiveSetTest.php` returns 0
    - `grep -q "is_default_tier" tests/Architecture/PricingRuleExclusiveSetTest.php`
    - `grep -q "SCOPE_DEFAULT_TIER\\|default_tier" tests/Architecture/PricingRuleExclusiveSetTest.php`
    - `test -f tests/Architecture/PriceCalculatorPurityTest.php` returns 0
    - `grep -q "Model::" tests/Architecture/PriceCalculatorPurityTest.php`
    - `grep -q "pricing.rounding_mode" tests/Architecture/PriceCalculatorPurityTest.php`
    - `grep -q "Pitfall 5" tests/Architecture/PriceCalculatorPurityTest.php`
    - `vendor/bin/pest tests/Architecture/PricingRuleExclusiveSetTest.php --stop-on-failure` exits 0
    - `vendor/bin/pest tests/Architecture/PriceCalculatorPurityTest.php --stop-on-failure` exits 0
    - Test count >= 6 passing across the two files (2 exclusive-set + 4 purity + 1 round-count = 7)
  </acceptance_criteria>
  <done>
    Two architectural guards in place: pricing_rules data invariant (exclusive-set) + PriceCalculator source-level purity (no Eloquent/events/logging/floats/clock/random). Future accidental regressions caught in CI, not in production.
  </done>
</task>

<task type="auto">
  <name>Task 3: Phase 3 VERIFICATION.md — ship verdict with evidence for all 5 success criteria</name>
  <files>
    .planning/phases/03-pricing-engine/03-VERIFICATION.md
  </files>
  <read_first>
    .planning/ROADMAP.md,
    .planning/phases/03-pricing-engine/03-CONTEXT.md,
    .planning/phases/03-pricing-engine/03-01-SUMMARY.md,
    .planning/phases/03-pricing-engine/03-02-SUMMARY.md,
    .planning/phases/03-pricing-engine/03-03-SUMMARY.md,
    .planning/phases/03-pricing-engine/03-04-SUMMARY.md,
    .planning/phases/02-supplier-sync/02-VERIFICATION.md
  </read_first>
  <action>
    Step 1 — run the full Phase 3 regression suite and capture real output values:
    ```
    vendor/bin/pest tests/Unit/Pricing tests/Feature/Pricing tests/Architecture/DeptracPricingLayerTest.php tests/Architecture/PricingRuleExclusiveSetTest.php tests/Architecture/PriceCalculatorPurityTest.php tests/Architecture/PolicyTemplateIntegrityTest.php --stop-on-failure
    ```
    Record the total passing test count. Record which SUMMARY files exist.

    Step 2 — author `.planning/phases/03-pricing-engine/03-VERIFICATION.md` using this exact skeleton. Mirror `.planning/phases/02-supplier-sync/02-VERIFICATION.md` tone and structure:

    ```markdown
    # Phase 3: Pricing Engine — VERIFICATION

    **Verified:** <today's date, e.g. 2026-04-19>
    **Verdict:** PASS / PASS WITH NOTES / FAIL (fill in based on results)
    **Build gate:** All tests green OR list of known issues

    ## Success Criteria Evidence (from ROADMAP.md Phase 3)

    ### Criterion 1: Golden fixture parity — the SHIP GATE

    > The golden-fixture parity test covering 50 (supplier_price, margin, expected_final) triples from the legacy plugin passes to the penny — the build fails if any triple drifts, and this test is the Phase 3 ship gate.

    **Status:** PASS
    **Evidence:**
    - Fixture file: `tests/Fixtures/Pricing/golden-fixtures.json` — 50 entries
    - Test: `vendor/bin/pest tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php` — 50/50 passing
    - Calculator is integer-pennies pure; purity enforced by `tests/Architecture/PriceCalculatorPurityTest.php`
    - Config lock: `config/pricing.php` pins `rounding_mode` to `PHP_ROUND_HALF_UP` (D-02)
    - Re-baseline protocol documented in CONTEXT.md D-04 — re-sourcing requires a dedicated commit with justification

    ### Criterion 2: Rule Explorer with resolution chain

    > A pricing manager can open the Filament rule explorer, type any SKU, and see the effective price with the full resolution chain displayed (brand+category → brand → category → default tier), and a per-product override row takes precedence over all rules.

    **Status:** PASS
    **Evidence:**
    - Page: `app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php`
    - View: `resources/views/filament/pages/rule-explorer.blade.php`
    - Test: `vendor/bin/pest tests/Feature/Pricing/RuleExplorerPageTest.php` — 6/6 passing
    - Override precedence test: RuleResolverTest Test 1 asserts override beats brand_category rule
    - URL: `/admin/pricing-rules/rule-explorer`

    ### Criterion 3: Simulated Impact before save

    > Editing a PricingRule and previewing "simulated impact" lists the SKUs that would change before the rule is saved.

    **Status:** PASS
    **Evidence:**
    - Service: `app/Domain/Pricing/Services/SimulatedImpactCalculator.php`
    - Page: `app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/SimulatedImpact.php`
    - Test: `vendor/bin/pest tests/Feature/Pricing/SimulatedImpactCalculatorTest.php` — 7/7 passing
    - Transactional rollback guarantees no accidental persistence (Task 3 Test 1)

    ### Criterion 4: SupplierPriceChanged → recompute → ProductPriceChanged

    > A SupplierPriceChanged event fired by Phase 2's sync causes a listener to recompute the final price via PriceCalculator (integer-pennies / BCMath) and fire ProductPriceChanged only when the output differs.

    **Status:** PASS
    **Evidence:**
    - Listener: `app/Domain/Pricing/Listeners/RecomputePriceListener.php` (implements ShouldQueue, queue='default')
    - Shared core: `app/Domain/Pricing/Services/PriceRecomputer.php` (D-13 penny-diff gate)
    - Event: `app/Domain/Pricing/Events/ProductPriceChanged.php` extends DomainEvent
    - Tests: `tests/Feature/Pricing/RecomputePriceListenerTest.php` (8/8) + `RecomputePriceListenerZeroPriceTest.php` (5/5) + `PriceRecomputerTest.php` (12/12) = 25/25 passing
    - Zero-price handling: `ImportIssue(missing_cost_price)` written via updateOrCreate; sell_price untouched; no ProductPriceChanged fires
    - correlation_id threads from SupplierPriceChanged → ProductPriceChanged (covered by listener Test 7)

    ### Criterion 5: pricing:recompute --all dispatches queued batch

    > `php artisan pricing:recompute --all` dispatches a queued batch that recomputes every product's final price and surfaces progress in Horizon.

    **Status:** PASS
    **Evidence:**
    - Command: `app/Domain/Pricing/Console/Commands/PricingRecomputeCommand.php` (extends BaseCommand)
    - Job: `app/Domain/Pricing/Jobs/RecomputePriceJob.php` (implements ShouldQueue + ShouldBeUnique; queue='sync-bulk')
    - Tests: `tests/Feature/Pricing/PricingRecomputeCommandDryRunTest.php` (7/7) + `PricingRecomputeCommandLiveTest.php` (4/4) + `RecomputePriceJobTest.php` (6/6) = 17/17 passing
    - Dry-run is default (D-12); --live opts in; --live + --dry-run errors cleanly
    - `php artisan pricing:recompute --help` documents all flags
    - Queue: sync-bulk (Phase 1 D-09 + Pitfall 8 — segregated from sync-woo-push)

    ## Decision Coverage (CONTEXT.md D-01 through D-13)

    | Decision | Plan | Delivered |
    |---|---|---|
    | D-01 Plain 2dp rounding | 03-01 | Yes — PriceCalculator single round() at return boundary |
    | D-02 HALF_UP lock | 03-01 | Yes — config/pricing.php + purity test |
    | D-03 Integer-pennies + BCMath | 03-01 | Yes — integer-only compute(); no BCMath needed for £10k supplier headroom (2^63 documented in class docblock) |
    | D-04 Golden fixtures from live Woo | 03-01 | Yes — 50-triple JSON; re-baseline protocol documented |
    | D-05 stripVat() helper | 03-01 | Yes — PriceCalculator::stripVat() + dedicated test file |
    | D-06 Parity-only PricingRule v1 | 03-01 | Yes — migration columns match D-06 exactly; deferred items (floor, validity) NOT in plans |
    | D-07 Priority-integer tiebreak | 03-01 + 03-02 | Yes — priority column + resolver orderByDesc('priority')->orderBy('id') |
    | D-08 Margin-% override | 03-01 | Yes — ProductOverride table + D-08 semantics in PriceRecomputer |
    | D-09 Parent-only override | 03-01 | Yes — UNIQUE product_id; variant_id deferred (v2 forward-compat noted) |
    | D-10 Zero-price → ImportIssue | 03-02 | Yes — RecomputePriceListener + PriceRecomputer both guard |
    | D-11 Idempotent issue handling | 03-02 | Yes — updateOrCreate on (sku, product_id, variant_id, issue_type, resolved_at IS NULL) |
    | D-12 Bulk dry-run default | 03-04 | Yes — command defaults to dry-run; --live opts in |
    | D-13 ProductPriceChanged on penny-diff only | 03-02 | Yes — integer-pennies comparison in PriceRecomputer |

    ## Architectural Guards

    - **Deptrac Pricing layer:** `Pricing: [Foundation, Products, Sync]` — `tests/Architecture/DeptracPricingLayerTest.php` positive + negative pass
    - **PriceCalculator purity:** `tests/Architecture/PriceCalculatorPurityTest.php` — no Eloquent/events/logging/floats/clock/random
    - **RuleResolver purity:** `tests/Unit/Pricing/RuleResolverPurityTest.php` — no config/clock/random
    - **PricingRule exclusive-set invariant:** `tests/Architecture/PricingRuleExclusiveSetTest.php`
    - **Policy template integrity:** `tests/Architecture/PolicyTemplateIntegrityTest.php` extended to scan Domain/Pricing/Policies; positive control bumped 7 → 9

    ## Threat-Model Mitigations Covered

    | Threat | Plan/Task | Mitigation |
    |---|---|---|
    | T1 Unauthorised rule modification | 03-03/Task 1 | PricingRulePolicy hand-written; access test covers 4 roles |
    | T2 Override smuggling | 03-03/Task 1 | ProductOverridePolicy + DB UNIQUE + Filament unique form rule |
    | T3 Zero-price leak to Woo | 03-01 + 03-02 | Calculator guard + listener guard + PriceRecomputer guard (three layers) |
    | T4 Resolver non-determinism | 03-02/Task 1 | Priority DESC + id ASC tiebreak + purity test |
    | T5 Golden-fixture bypass | 03-01/Task 1 | 50-triple test + re-baseline protocol requires commit justification |
    | T6 BCMath/rounding drift | 03-01/Task 1 | HALF_UP locked in config; single round() at return; purity test forbids float |
    | T7 Bulk recompute DoS | 03-04/Task 2 | sync-bulk queue (segregated); ShouldBeUnique(uniqueFor=300) |

    ## Test Tally

    - Unit: PriceCalculator (53+) + RuleResolver (16)
    - Feature: Factories (2) + Seeder (5) + Listener (13) + PriceRecomputer (12) + Command (17) + Filament (~16) + SimulatedImpact (7) + RuleExplorer (6)
    - Architecture: Deptrac Pricing (2) + Exclusive-set (2) + Calculator purity (5) + Policy integrity (3 existing, extended scope)
    - **Total Phase 3 new tests: ~150+ passing**

    ## Deferred Items (CONTEXT.md Deferred Ideas — NOT in Phase 3)

    - Minimum-margin floor per rule
    - Rule validity windows (valid_from / valid_until)
    - Per-variation pricing overrides (ProductOverride.variant_id)
    - Psychological rounding (.99 / .95 endings)
    - Direct final-price override (enum style)
    - Rule audit dashboard UI (Phase 7)
    - Variable-product rule-level scoping

    These are all candidates for a dedicated Pricing Engine v2 post-cutover phase.

    ## Operator Handover Notes

    - **CLI:** `php artisan pricing:recompute --help` lists all flags. Default is DRY-RUN. --live is opt-in.
    - **Filament:** `/admin/pricing-rules` (CRUD), `/admin/pricing-rules/rule-explorer` (SKU lookup), `/admin/pricing-rules/{id}/simulated-impact` (preview rule change).
    - **Golden fixture re-baseline:** when ops supplies live Woo values, replace `tests/Fixtures/Pricing/golden-fixtures.json` AND `database/seeders/Phase3/DefaultPricingTierSeeder.php` margins in the SAME commit; commit message MUST cite the source (e.g. "re-baseline from Woo DB snapshot 2026-06-01").
    - **Import Issues triage:** Filament /admin/import-issues page (Phase 2) surfaces zero-price products; pricing_manager role fixes the supplier data OR creates a ProductOverride.
    - **Next phase:** Phase 4 (Bitrix24 CRM Sync) — does not depend on Phase 3; can start immediately.

    ## Known Non-Blockers

    (List any warnings / notes found during verification that do NOT block shipping. Examples: "default tier margins are deterministic placeholders pending ops confirmation per D-04 Claude's Discretion; re-baseline a single-commit change before cutover.")

    ---

    *Phase 3 verified: <date>*
    *Next phase: 04-bitrix-crm-sync*
    ```

    Fill in the real test counts, real dates, real status. If any test file did NOT end up with the exact count listed, adjust. If verdict is "PASS WITH NOTES" (e.g. tier margins pending live Woo re-baseline), list the notes explicitly.

    Step 3 — git-add the file but do NOT commit (the /gsd-plan-phase orchestrator handles the commit).

    **DO NOT:**
    - Do NOT fabricate test counts. Run the tests and copy real numbers.
    - Do NOT mark the verdict PASS if any of the 5 ROADMAP success criteria has failing test evidence.
    - Do NOT skip the Decision Coverage table — every D-01..D-13 must be accounted for.
    - Do NOT leave placeholders like `<today's date>` un-replaced.
  </action>
  <verify>
    <automated>test -f .planning/phases/03-pricing-engine/03-VERIFICATION.md && grep -q "Golden fixture parity" .planning/phases/03-pricing-engine/03-VERIFICATION.md && grep -q "Verdict:" .planning/phases/03-pricing-engine/03-VERIFICATION.md</automated>
  </verify>
  <acceptance_criteria>
    - `test -f .planning/phases/03-pricing-engine/03-VERIFICATION.md` returns 0
    - `grep -q "Verdict:" .planning/phases/03-pricing-engine/03-VERIFICATION.md`
    - `grep -q "Golden fixture parity" .planning/phases/03-pricing-engine/03-VERIFICATION.md`
    - `grep -q "Rule Explorer" .planning/phases/03-pricing-engine/03-VERIFICATION.md`
    - `grep -q "Simulated Impact" .planning/phases/03-pricing-engine/03-VERIFICATION.md`
    - `grep -q "ProductPriceChanged" .planning/phases/03-pricing-engine/03-VERIFICATION.md`
    - `grep -q "pricing:recompute" .planning/phases/03-pricing-engine/03-VERIFICATION.md`
    - `grep -cE "D-0[0-9]|D-1[0-3]" .planning/phases/03-pricing-engine/03-VERIFICATION.md` returns >= 13 (all 13 decisions cited)
    - `grep -q "Deferred Items" .planning/phases/03-pricing-engine/03-VERIFICATION.md`
    - `grep -q "Next phase:" .planning/phases/03-pricing-engine/03-VERIFICATION.md`
    - Document has all 5 ROADMAP success criteria sections
    - Full regression test suite passes (ran in Step 1 before authoring): `vendor/bin/pest tests/Unit/Pricing tests/Feature/Pricing tests/Architecture/DeptracPricingLayerTest.php tests/Architecture/PricingRuleExclusiveSetTest.php tests/Architecture/PriceCalculatorPurityTest.php tests/Architecture/PolicyTemplateIntegrityTest.php --stop-on-failure` exits 0
  </acceptance_criteria>
  <done>
    VERIFICATION.md committed with real test evidence. Every ROADMAP success criterion has Status + Evidence rows. Every D-01..D-13 decision accounted for in coverage table. Threat-model mitigations traceable to plans. Operator handover notes list the 3 main CLI/UI entry points and the re-baseline protocol.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| CI pipeline → Deptrac | depfile.yaml version-controlled; CI runs deptrac as a gate; exit-code authoritative on Windows PHP |
| Architecture tests → source files | Tests read source via file_get_contents; assertions run at test time, no runtime impact |
| VERIFICATION.md → ops handover | Human-read document; no code executes from it |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-03-05-01 (maps to T1 unauthorised import) | T (Tampering) | depfile.yaml ruleset weakened by future PR | mitigate | DeptracPricingLayerTest negative test planted on every CI run catches weakening. Deptrac ruleset + architecture test live in the same commit — hard to drop one without the other. |
| T-03-05-02 (maps to T6 rounding drift) | T (Tampering) | Someone adds float type hint to PriceCalculator | mitigate | PriceCalculatorPurityTest forbids `: float` and `\\bfloat\\b` in the source file. Test fails immediately on the PR. |
| T-03-05-03 | T (Tampering) | PricingRule data corrupted with is_default_tier=true + brand_id set | mitigate | PricingRuleExclusiveSetTest asserts the invariant on all rows; factories generate exclusive states only. Corrupt row trips the test on every CI run. |
| T-03-05-04 | R (Repudiation) | VERIFICATION.md fabricates test counts to ship | mitigate | Task 3 explicitly requires running the suite to capture real counts; acceptance criterion re-runs the suite; CI check before git-commit fails if any listed test doesn't exist or doesn't pass. |
| T-03-05-05 | I (Information Disclosure) | VERIFICATION.md leaks test structure | accept | Document is internal to the project. No secrets. |
| T-03-05-06 | S (Spoofing) | Someone renames a Policy file to dodge the integrity test | mitigate | PolicyTemplateIntegrityTest positive-control count (>= 9) fails if a Policy is deleted. Test 3 checks Gate::policy bindings for the 9 expected pairs — a rename that breaks the binding is caught there. |
</threat_model>

<verification>
- Task 1: `vendor/bin/deptrac analyse --config-file=depfile.yaml` exits 0 AND `vendor/bin/pest tests/Architecture/DeptracPricingLayerTest.php --stop-on-failure` exits 0
- Task 2: `vendor/bin/pest tests/Architecture/PricingRuleExclusiveSetTest.php tests/Architecture/PriceCalculatorPurityTest.php --stop-on-failure` exits 0
- Task 3: `test -f .planning/phases/03-pricing-engine/03-VERIFICATION.md` AND all grep acceptance criteria pass
- Full Phase 3 regression: `vendor/bin/pest tests/Unit/Pricing tests/Feature/Pricing tests/Architecture/Deptrac*Test.php tests/Architecture/Pricing*Test.php tests/Architecture/PriceCalculatorPurityTest.php tests/Architecture/PolicyTemplateIntegrityTest.php --stop-on-failure` exits 0
- No regression on Phase 1 + Phase 2 tests: `vendor/bin/pest tests/Feature --exclude-testsuite Pricing --stop-on-failure` exits 0 (or equivalent scope)
</verification>

<success_criteria>
- Pricing layer added to Deptrac with narrow allow-list (Foundation, Products, Sync only)
- DeptracPricingLayerTest positive + negative both pass
- PriceCalculatorPurityTest catches Eloquent / events / logging / float / clock / random leaks
- PricingRuleExclusiveSetTest catches invalid mixed-state rows
- PolicyTemplateIntegrityTest (Plan 03 extended it) remains green
- VERIFICATION.md published with Verdict + Evidence rows for all 5 ROADMAP criteria + decision coverage table + deferred items
- Full Phase 3 regression test suite passes (150+ tests)
- No Phase 1 or Phase 2 tests regressed
</success_criteria>

<output>
Create `.planning/phases/03-pricing-engine/03-05-SUMMARY.md` covering:
- Deptrac rule added + comment rationale
- 3 new architecture tests (Deptrac layer, exclusive-set, calculator purity)
- VERIFICATION.md status + real test counts + verdict
- Phase 3 ready for /gsd-verify-phase handoff
- Deferred items list propagated to STATE.md next-session notes (optional — /gsd handles the STATE update)
</output>
