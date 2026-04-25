---
phase: 09-e1-trade-customer-pricing
plan: 01
subsystem: trade-pricing
tags: [trade-pricing, schema, migrations, customer-groups, pricing-rules, deptrac, eloquent, pest, architecture-tests, seeders]

requires:
  - phase: 01-foundation
    provides: LogsActivity convention, Auditor, BaseCommand, factory pattern
  - phase: 03-pricing-engine
    provides: PricingRule model + scope exclusive-set invariant, RuleResolver service (decorator target — UNTOUCHED)
  - phase: 05-competitor-analysis
    provides: Deptrac dual-YAML sync lesson (P05-05)
  - phase: 07-dashboard-cutover
    provides: Architecture-test exit-code assertion pattern (Symfony Process, NOT stdout grep — Phase 7 Windows lesson)
provides:
  - customer_groups table (id BIGINT PK, slug unique, name, is_active default true, display_order, timestamps)
  - pricing_rules.customer_group_id BIGINT NULL FK + composite index (customer_group_id, brand_id, category_id) for 5-tier resolver query path
  - CustomerGroup Eloquent model (LogsActivity + factory + display_order auto-sort)
  - CustomerGroupFactory for fixture-driven Plan 09-02..09-06 testing
  - Phase9\CustomerGroupSeeder seeding 4 D-01 groups (trade=10 / reseller=20 / education=30 / nhs=40) idempotently
  - PricingRule additive edit: $fillable + $casts + customerGroup() BelongsTo + LogsActivity column extension
  - Deptrac TradePricing layer in BOTH depfile.yaml AND deptrac.yaml with allow-list [Foundation, Pricing, Products]
  - Pricing allow-list extended with TradePricing for one-way model-relation BelongsTo arrow (PricingRule->customerGroup())
  - Http allow-list extended with TradePricing for Plan 09-05 Filament Resource access
  - DeptracTradePricingLayerTest (4 tests: dual-YAML structural, dual-YAML denial, deptrac analyse exit-0 against both yamls)
  - PricingRuleExclusiveSetTest extended: customer_group_id orthogonal to scope (RESEARCH §Open Q5)
affects: [09-02-trade-rule-resolver, 09-03-golden-fixture, 09-04-sync-pipeline, 09-05-filament-ux, 09-06-verification, phase-11-quote-flow]

tech-stack:
  added: []  # zero composer changes — Phase 9 is pure schema + service-layer + Filament additions
  patterns:
    - "Customer-group lookup table seeded by Phase9\\CustomerGroupSeeder (idempotent firstOrCreate)"
    - "Foreign key with restrictOnDelete() — deleting a group with active rules requires manual cleanup"
    - "Composite index (customer_group_id, brand_id, category_id) optimised for 5-tier specificity sort query path"
    - "Deptrac TradePricing layer mirrored byte-equivalent across both yaml configs (Phase 5 dual-YAML lesson)"
    - "Architecture-test guardrails for layer denial + exit-0 against both yamls"
    - "Orthogonal column extension: customer_group_id is nullable on every existing scope; PricingRuleExclusiveSetTest preserves original 8 assertions, adds 3-cell orthogonality test"

key-files:
  created:
    - database/migrations/2026_04_26_010000_create_customer_groups_table.php
    - database/migrations/2026_04_26_010100_add_customer_group_id_to_pricing_rules_table.php
    - app/Domain/TradePricing/Models/CustomerGroup.php
    - database/factories/Domain/TradePricing/CustomerGroupFactory.php
    - database/seeders/Phase9/CustomerGroupSeeder.php
    - tests/Architecture/DeptracTradePricingLayerTest.php
    - tests/Unit/TradePricing/Models/CustomerGroupTest.php
  modified:
    - app/Domain/Pricing/Models/PricingRule.php (additive: customer_group_id $fillable + $cast + BelongsTo relation)
    - database/seeders/DatabaseSeeder.php (CustomerGroupSeeder registered)
    - depfile.yaml (TradePricing layer + Pricing/Http allow-list extension)
    - deptrac.yaml (mirrored — dual-config-sync)
    - tests/Architecture/PricingRuleExclusiveSetTest.php (3-cell orthogonality test added)

requirements: [TRDE-01]

commits:
  - 0bcebaf feat(09-01): add customer_groups table + CustomerGroup model + PricingRule.customer_group_id (TRDE-01 Task 1)
  - 0c5066e feat(09-01): seed 4 customer groups idempotently + Feature test (TRDE-01 Task 2)
  - d0d96ce feat(09-01): TradePricing Deptrac dual-YAML layer + architecture tests (TRDE-01 Task 3)

deferred-tests:
  - Feature/Unit tests requiring live MySQL: deferred until meetingstore_ops_testing DB online (Phase 1 P03 lesson; Phase 6/7/8 precedent)
  - DeptracTradePricingLayerTest exit-0 assertions: require local deptrac-shim install (run via composer install --dev)
---

# Plan 09-01 — Foundation: schema + Deptrac + tests

## What was built

The bedrock of Phase 9. Everything downstream (Plan 09-02 TradeRuleResolver decorator, Plan 09-03 golden fixture extension, Plan 09-04 sync pipeline, Plan 09-05 Filament UX) reads from these:

1. **`customer_groups` table** — admin-managed lookup table (D-02). 5 columns: id BIGINT PK, slug VARCHAR(64) UNIQUE, name VARCHAR(128), is_active BOOLEAN DEFAULT true, display_order SMALLINT DEFAULT 0; plus timestamps. The `slug` UNIQUE index is the FK target for D-04 / D-08 customer_group_id columns.

2. **`pricing_rules.customer_group_id` BIGINT NULL FK** — single-column additive edit to v1's PricingRule schema (D-04). Foreign key with `restrictOnDelete()` so a group with active rules cannot be deleted silently. Composite index `(customer_group_id, brand_id, category_id)` covers the 5-tier specificity sort query path that Plan 09-02 walks.

3. **CustomerGroup model** — single Eloquent model in `App\Domain\TradePricing\Models`. LogsActivity captures slug + name + is_active + display_order changes for admin audit trail (D-10 ops-driven groups). Factory + 4-test unit suite locks the model contract.

4. **Phase9\CustomerGroupSeeder** — idempotent `firstOrCreate` seeding of D-01's 4 groups (trade=10 / reseller=20 / education=30 / nhs=40). Registered in DatabaseSeeder so `php artisan db:seed` includes them. Fresh installs and re-runs both leave the seed list intact.

5. **PricingRule additive edit** — non-breaking extension to v1's model. Adds `customer_group_id` to `$fillable` (controllers can mass-assign it; the value is bound by Filament Resource form and does not reach user input via Breeze) + cast + `customerGroup()` BelongsTo relation. LogsActivity column list extended so existing v1 audit semantics carry forward.

6. **Deptrac TradePricing layer** — registered in BOTH depfile.yaml AND deptrac.yaml (Phase 5 P05-05 dual-YAML lesson). Allow-list `[Foundation, Pricing, Products]` matches CONTEXT D-04 and RESEARCH §Specifics. Pricing's allow-list gains TradePricing for the one-way BelongsTo arrow (PricingRule->customerGroup()); architectural intent preserved because the dependency lives entirely on the model-relation surface, not service logic. Http allow-list gains TradePricing so Plan 09-05's Filament Resource can resolve.

7. **DeptracTradePricingLayerTest** — 4 architecture tests: dual-YAML structural assertion (TradePricing layer + ruleset present in both); dual-YAML denial assertion (Sync/CRM/Webhooks/Cutover/Marketing/Agents/Channels/Quotes NOT in TradePricing's allow-list); deptrac analyse exit-0 against depfile.yaml; deptrac analyse exit-0 against deptrac.yaml. Symfony Process pattern (NOT stdout grep) — Phase 7 Windows reliability lesson preserved.

8. **PricingRuleExclusiveSetTest extended** — RESEARCH §Open Q5 ratification. customer_group_id is orthogonal to the scope/brand/category/default_tier exclusive-set invariant. Original 8 assertions stay green; new 3-cell test exercises (brand_category + NULL), (brand_category + group), (default_tier + group) to lock the orthogonality.

## Why this matters

- **v1 RuleResolver remains BYTE-IDENTICAL.** Plan 09-01 only TOUCHES PricingRule (additive cast/relation) — never RuleResolver itself. The decorator pattern (Plan 09-02) reads PricingRule via the existing v1 query layer and doesn't require any service-class changes.
- **Schema delta is minimal.** Single column on pricing_rules; the customer_groups lookup table is new but slim. Net stack delta: zero composer packages, three migrations, one Eloquent model.
- **Architectural invariants are CI-locked.** DeptracTradePricingLayerTest catches accidental allow-list drift (e.g. someone copy-pastes Dashboard's broad allow-list onto TradePricing). PricingRuleExclusiveSetTest extension catches accidental customer_group_id semantics drift.

## Notable deviations

- **Pricing allow-list extension** — RESEARCH had not pre-emptively flagged that PricingRule->customerGroup() BelongsTo would create a new arrow from Pricing → TradePricing. The deviation is documented inline in both depfile.yaml + deptrac.yaml comments: one-way arrow, model-relation only, no service-class import. PriceCalculator + RuleResolver + PriceRecomputer + SimulatedImpactCalculator stay completely ignorant of customer groups — Plan 09-02's TradeRuleResolver is the only TradePricing service that knows.

- **Http allow-list extension** — anticipates Plan 09-05's Filament CustomerGroupResource. Adding it now (rather than in Plan 09-05) keeps all Deptrac changes atomically committed in Plan 09-01.

## What this enables

- **Plan 09-02** can construct TradeRuleResolver against PricingRule with customer_group_id queries; CustomerGroup is available for fixture creation.
- **Plan 09-03** can extend the golden fixture with 30 trade triples that reference seeded customer_group_ids (trade / reseller / education / nhs).
- **Plan 09-04** users.customer_group_id migration + listener can FK into customer_groups.id.
- **Plan 09-05** Filament CustomerGroupResource has its model + Deptrac layer ready.
- **Plan 09-06** retail-parity guardrail tests reference the seeded groups to verify TradePricing doesn't bleed into v1 retail callsites.
