# Phase 9: E1 Trade Customer Pricing - Context

**Gathered:** 2026-04-25 (auto-mode — recommended options selected without interactive input)
**Status:** Ready for planning
**Phase position:** Second v2.0 phase (parallel-shippable to Phase 8 per research; running sequential here because P8 already shipped)

<domain>
## Phase Boundary

Phase 9 opens the B2B revenue motion by extending v1's `RuleResolver` (Phase 3) with a **decorator-pattern** `TradeRuleResolver` that consults a new `customer_group_id` column on `pricing_rules` plus a new `customer_groups` lookup table (Trade / Reseller / Education / NHS). Six requirements (TRDE-01..06) lock the schema, decorator approach, golden-fixture extension (50 → 80 triples with v1's original 50 byte-identical), and the trade-pricing display strategy.

**Critical invariant: v1's 50-triple golden fixture stays byte-identical.** Phase 9 ADDS 30 customer-group triples; never modifies originals. New regression test `GoldenFixtureV1UnchangedTest` locks this. The penny-exact parity gate that v1 shipped under remains the bar v2 measures against.

Decorator pattern means: when `TradeRuleResolver::resolve()` is called WITH a `customer_group_id`, it includes group-scoped rules in specificity sort (`customer_group + brand + category` most specific); when called WITHOUT (null), it falls through to v1's untouched `RuleResolver`. v1 retail behaviour is byte-identical for null callers.

Scope is fixed by ROADMAP.md Phase 9 + REQUIREMENTS.md TRDE-01..06. Discussion auto-resolved 6 implementation decisions (D-01..D-06) covering customer-group seed list, anonymous display posture, customer→group mapping source, and the Filament UX shape.

</domain>

<decisions>
## Implementation Decisions

### Customer-group seed list (TRDE-01)

- **D-01:** **4 seeded customer groups** (per research recommendation):
  - `trade` — slug `trade`, name "Trade Customer"
  - `reseller` — slug `reseller`, name "Reseller / Distributor"
  - `education` — slug `education`, name "Education Sector"
  - `nhs` — slug `nhs`, name "NHS / Healthcare"
  All marked `is_active=true` at seed. Admin can deactivate via Filament Resource if a group becomes unused. New groups added by ops via Filament; no migration required for new groups.
- **D-02:** **`customer_groups` table is admin-managed (NOT auto-discovered).** Unlike Phase 5's competitor table (which auto-creates on first CSV ingest), customer groups are sales-defined business entities. Filament `CustomerGroupResource` (admin + pricing_manager CRUD) lets sales add new groups. Slug + name + is_active + display_order columns; created_at/updated_at; LogsActivity for audit trail.

### TradeRuleResolver decorator pattern (TRDE-02)

- **D-03:** **Decorator wraps v1 RuleResolver — does NOT replace.** New service `App\Domain\TradePricing\Services\TradeRuleResolver` with method `resolve(Product $product, ?int $customerGroupId = null): ?PricingRule`. When `$customerGroupId` is null OR `0`, immediately delegates to v1's `RuleResolver::resolve()` and returns its result unchanged (v1 byte-identical fallback). When `$customerGroupId` is set, queries `pricing_rules` for matching customer-group-scoped rules in priority order:
  1. `customer_group_id = X AND brand_id = Y AND category_id = Z` (most specific)
  2. `customer_group_id = X AND brand_id = Y`
  3. `customer_group_id = X AND category_id = Z`
  4. `customer_group_id = X` (least specific group rule)
  5. Falls through to v1's retail rules in same hierarchy if no group match found

  Group-scoped rules default to `priority = base_priority + 100` so customer-group wins against same-scope retail rule. Tied rules sorted by `priority DESC, id ASC` (Phase 3 convention preserved).

- **D-04:** **Schema is single column add: `pricing_rules.customer_group_id BIGINT NULL`.** No new pricing_rules table. Existing rules remain customer_group_id=null = retail default. Foreign key `customer_groups(id) ON DELETE RESTRICT` (deleting a customer group with active rules requires manual cleanup). Index added on `(customer_group_id, brand_id, category_id)` for resolver query path.

### Golden fixture extension (TRDE-03)

- **D-05:** **80-triple golden fixture: 50 v1 + 30 v2.** Original 50 retail triples remain byte-identical in `tests/Fixtures/Pricing/golden-fixtures.json`. New 30 customer-group triples in same JSON file with `customer_group_id` field set. Coverage:
  - 5 triples per group × 4 groups = 20 (basic group price calculation per group)
  - 5 triples for brand+group precedence ("Trade gets 22% margin on Logitech, retail gets 25%")
  - 3 triples for NULL handling (rule with brand AND null group should match retail-only customer; rule with brand AND non-null group should NOT match retail-only customer)
  - 2 triples for override-equipped SKUs at trade pricing
  
  New regression test `GoldenFixtureV1UnchangedTest` (tests/Architecture/) asserts the v1 portion is byte-identical: snapshots commit `a4d075f` (Phase 3 03-01 fixture file blob hash), fails build if any of original 50 triples drift. Phase 3 ship gate (PRCE-06) extended; build fails if any of 80 triples drift.

### Anonymous display posture (TRDE-05 — operator decision per research B8)

- **D-06:** **`config('b2b.anonymous_display') === 'retail'` (default).** Anonymous users see retail prices; after authenticated B2B login, the Cart + product-detail-page (PDP) re-resolves prices using their `customer_group_id`. Login state determines which prices the user sees. Operator can flip to `'hidden'` (showing "Login to see trade pricing") via env override (`B2B_ANONYMOUS_DISPLAY=hidden`); recompiles config; no code change.

  Rationale: 'retail' default is the SEO-friendly + conversion-friendly default (anonymous Google traffic sees real prices and converts; trade customers log in for their bespoke pricing). 'hidden' is the friction-friendly option for businesses where retail-vs-trade differential is large enough that exposing retail pricing damages B2B negotiation leverage. MeetingStore's AV-installation niche sits between (some products commodity-priced, some configurable), so 'retail' default is safest.

### Customer → group mapping source

- **D-07 (Claude's Discretion):** **WooCommerce user role → customer_group_id mapping.** v1 cutover uses Woo as the auth + customer database. Phase 9 reads Woo user role on order webhook arrival (`order.created` event) and maps to a `customer_group_id` via a config map: `config('b2b.role_to_group_map')`:
  ```php
  'wholesale_customer' => 'trade',
  'wholesale_b2b' => 'reseller',
  'edu_customer' => 'education',
  'nhs_customer' => 'nhs',
  // any other role → null = retail
  ```
  Unrecognised roles default to null (retail). Mapping editable via Filament Settings page (admin-only) with `key:value` form. Column `users.customer_group_id` added in v2 (denormalised from Woo role at sync time so subsequent operations don't need to refetch). Mismatched/changed roles trigger re-sync.

- **D-08 (Claude's Discretion):** **Explicit `users.customer_group_id` migration ships in Phase 9.** New nullable BIGINT FK column on the `users` table (Phase 1's auth table). Backfill existing users to null (retail). New `UpdateCustomerGroupOnUserRoleChange` listener subscribed to a v1 event firing when Woo customer role changes (or to a new event Phase 9 introduces if v1 doesn't already cover this). Persistent denormalisation rather than join-on-every-resolve query — perf wins outweigh staleness risk because rule resolution runs in hot order paths.

### Filament UX (TRDE-04 + B7 + B13)

- **D-09 (Claude's Discretion):** **Filament `PricingRuleResource` extended (NOT replaced) with optional `customer_group_id` Select field.** Empty select = retail (null). Non-empty = customer-group rule. Same Resource handles retail + trade rules. List view filterable by `customer_group_id`; per-group view available via filter preset. Phase 3's existing `PricingRuleResource` Filament code edited additively — preserves form/table structure; adds one Select field + one filter.

- **D-10 (Claude's Discretion):** **NEW `CustomerGroupResource` Filament Resource (admin + pricing_manager).** CRUD for customer_groups table. Slug uniqueness enforced. Cannot delete a group with active rules (FK ON DELETE RESTRICT — Filament shows actionable error). Order by `display_order ASC` for predictable Select dropdown ordering across the app.

### Trade-only catalogue visibility (TRDE-06)

- **D-11 (locked per REQUIREMENTS):** **NOT supported in v2.0.** All SKUs remain publicly visible regardless of customer group. v2.1+ candidate if ops requires. No `is_trade_only` column added to products in v2.

### Claude's Discretion (defaults documented above)

- D-07/D-08: WooCommerce role → customer_group_id mapping via config + denormalised `users.customer_group_id` column
- D-09/D-10: Single PricingRuleResource for both rule kinds + new CustomerGroupResource

Additional implementation defaults:
- **Migration timestamps:** start `2026_04_26_*` (Phase 8 ended `2026_04_25_*`)
- **Deptrac:** new `TradePricing` layer added to BOTH `depfile.yaml` AND `deptrac.yaml`. Allow-list: `[Foundation, Products, Pricing]` (read-only access to v1 Pricing for delegation; depends-on Products for product lookups). DENIES Sync/CRM/Webhooks/Cutover/Marketing/Agents.
- **`DeptracTradePricingLayerTest`** (positive + negative path).
- **`shield:safe-regenerate`** wraps any new `shield:generate` invocation; new policies (CustomerGroupPolicy + extension to PricingRulePolicy if needed) restored automatically per Phase 8 P5-F protocol.
- **Testing DB:** `meetingstore_ops_testing` MySQL (Phase 1 P03 lesson; deferred Feature tests if MySQL offline matches Phase 6/7/8 precedent).
- **`->authorize()` on Filament actions** mandatory.
- **Listener-based extension** of v1: zero modifications to v1's `RuleResolver` class. TradeRuleResolver wraps via constructor injection. Phase 3 tests stay green by definition because Phase 3 code is untouched.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 3 Pricing Engine (decorator target — preserve byte-identical)

- `.planning/milestones/v1.50.1-ROADMAP.md` §Phase 3 — locked decisions on RuleResolver design, golden fixture, integer-pennies math
- `app/Domain/Pricing/Services/RuleResolver.php` — the v1 service Phase 9 wraps (DO NOT MODIFY)
- `app/Domain/Pricing/Services/PriceCalculator.php` — VAT-inclusive integer-pennies calculator (REUSE; v2 trade pricing uses same calculator)
- `tests/Fixtures/Pricing/golden-fixtures.json` — v1's 50-triple ship gate; Phase 9 extends to 80 (original 50 byte-identical)
- `tests/Architecture/PricingRuleExclusiveSetTest.php` — v1 architectural test for rule shape; Phase 9 extends to assert customer_group_id NULL/non-null exclusive set

### Phase 1 Foundation

- `app/Models/User.php` — Phase 9 adds `customer_group_id` column FK
- `app/Domain/Suggestions/...` — not directly used by Phase 9; trade pricing rules are admin-CRUD'd, not suggestion-driven
- v1 `Auditor` + `LogsActivity` — Phase 9's CustomerGroup model uses LogsActivity trait

### Phase 4 CRM Sync

- v1 customer.created/updated webhook listeners — Phase 9 listener `UpdateCustomerGroupOnUserRoleChange` subscribes to the same events to sync customer_group_id from Woo role

### Phase 5 Competitor + Phase 6 AutoCreate

- Pattern reference for Phase 9 Deptrac layer setup (dual-YAML Deptrac per Phase 5 lesson)

### Phase 8 C4 Agent Framework

- Phase 8 doesn't directly affect Phase 9 (parallel tracks per research); Phase 9 may run in parallel with Phase 8 in a hypothetical re-roadmap, but here Phase 8 already shipped first
- Phase 8's `shield:safe-regenerate` command available for Phase 9's policy regen

### Project + milestone artefacts

- `.planning/PROJECT.md` §"Current Milestone: v2.0 Intelligence + B2B" + Active scope listing E1 trade pricing
- `.planning/REQUIREMENTS.md` TRDE-01..06 (6 contract REQ-IDs)
- `.planning/ROADMAP.md` §Phase 9 (5 success criteria; depends-on: nothing in v2 — parallel to Phase 8)
- `.planning/STATE.md` — current milestone status

### v2 research artefacts

- `.planning/research/STACK.md` — no new deps for Phase 9 (existing pricing stack handles trade pricing)
- `.planning/research/FEATURES.md` §E1 — trade-pricing table-stakes / differentiators
- `.planning/research/ARCHITECTURE.md` §TradePricing — decorator pattern + 5-tier specificity sort
- `.planning/research/PITFALLS.md` §B1-B5 — golden-fixture preservation, NULL handling, anonymous-display leak, idempotency
- `.planning/research/SUMMARY.md` — synthesized v2 overview

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets (v1 + Phase 8 delivered)

- **`RuleResolver` + `PriceCalculator`** (Phase 3) — Phase 9 wraps via decorator; v1 calculator handles VAT-inclusive integer-pennies math unchanged
- **`PricingRule` Eloquent model** (Phase 3) — Phase 9 ADDS `customer_group_id` column via migration; existing relationships preserved
- **`PricingRuleResource` Filament** (Phase 3) — Phase 9 EXTENDS additively (one Select + one filter)
- **`User` model** (Phase 1 + Breeze) — Phase 9 ADDS `customer_group_id` FK column
- **`Auditor` + `LogsActivity` trait** (Phase 1) — Phase 9 CustomerGroup model + PricingRule customer_group_id changes capture audit
- **`shield:safe-regenerate`** (Phase 8) — Phase 9 uses this for any policy regen
- **Phase 3 golden fixture infrastructure** (`tests/Fixtures/Pricing/golden-fixtures.json` + Pest data provider) — Phase 9 extends with 30 new triples; same data provider syntax
- **Customer.created/updated webhook listeners** (Phase 4) — Phase 9 subscribes to sync customer_group_id from Woo role
- **`spatie/laravel-permission ^6.0`** (Phase 1) — Phase 9 doesn't add new permissions for the rule extension; uses existing `view_any_pricing_rule`/`update_pricing_rule`. NEW permissions only for CustomerGroupResource (`view_any_customer_group` etc.)

### Established Patterns (from v1 + Phase 8)

- **Migration timestamps:** start `2026_04_26_*` (Phase 8 ended `2026_04_25_*`)
- **Domain layout:** `app/Domain/TradePricing/` (currently absent) gets populated:
  - `Models/CustomerGroup.php`
  - `Services/TradeRuleResolver.php`
  - `Services/RoleToGroupMapper.php`
  - `Listeners/UpdateCustomerGroupOnUserRoleChange.php`
  - `Filament/Resources/CustomerGroupResource.php`
  - `Policies/CustomerGroupPolicy.php`
- **Deptrac dual-YAML lesson (v1 Phase 5 P05-05):** `TradePricing` layer added to BOTH `depfile.yaml` AND `deptrac.yaml`. Allow-list: `[Foundation, Products, Pricing]`. Layer DENIES Sync/CRM/Webhooks/Cutover/Marketing/Agents.
- **`DeptracTradePricingLayerTest`** at `tests/Architecture/` (exit-code assertion pattern, NOT stdout grep — Phase 5 lesson).
- **`shield:safe-regenerate`** (Phase 8) — Phase 9 uses this when adding CustomerGroupResource permissions; old PricingRulePolicy preserved automatically.
- **Listener-based extension:** Phase 9 does NOT modify Phase 3 RuleResolver. TradeRuleResolver wraps via constructor injection. Phase 3 tests stay green.
- **Golden fixture as ship gate:** Phase 9 doesn't ship until 80/80 triples pass. CI-blocking.

### Integration Points

- **Inbound (existing v1 events):**
  - `customer.created` / `customer.updated` (Phase 4 Webhooks domain) → `UpdateCustomerGroupOnUserRoleChange` listener resolves Woo role → maps to customer_group_id → updates User row
  - `order.created` (Phase 4 Webhooks) → existing listener uses `User->customer_group_id` for any order-time price recalc (rare; orders typically lock prices at checkout)
- **Outbound (Phase 9 → v1 retail path):**
  - `TradeRuleResolver` delegates to `RuleResolver` when customer_group_id null. Zero behaviour change for retail customers
  - `PricingRuleChanged` event (Phase 5 A1 backport) fires when group-scoped rule changes; Phase 5's listener triggers price recompute for affected SKUs in the customer group
- **New migrations (Phase 9):**
  - `2026_04_26_010000_create_customer_groups_table.php` (id BIGINT, slug unique, name, is_active, display_order, timestamps)
  - `2026_04_26_010100_add_customer_group_id_to_pricing_rules_table.php` (nullable BIGINT FK + composite index)
  - `2026_04_26_010200_add_customer_group_id_to_users_table.php` (nullable BIGINT FK)
- **New Filament Resources:** `CustomerGroupResource` (new) + `PricingRuleResource` (extended additively)

### Net stack delta

- ZERO new composer packages. All Phase 9 functionality uses existing v1 + Phase 8 stack.

</code_context>

<specifics>
## Specific Ideas

- **The decorator pattern is load-bearing.** TradeRuleResolver wrapping (not replacing) v1's RuleResolver is the architectural choice that preserves v1 retail behaviour byte-identical. Any deviation from this pattern risks regressing Phase 3's golden fixture.
- **80-triple golden fixture stays the ship gate.** New `GoldenFixtureV1UnchangedTest` snapshots the v1 portion; build fails if anyone touches the original 50. Belt-and-braces protection.
- **`config('b2b.anonymous_display') === 'retail'` default is SEO-friendly.** Anonymous Google traffic sees real prices; trade customers log in for bespoke pricing. Operator can flip to 'hidden' via env override if needed.
- **Customer→group mapping persists denormalised on `users.customer_group_id`.** Performance over staleness; rule resolution runs in hot order paths and can't afford a join-on-every-call.
- **TradePricing Deptrac layer is tight.** Reads from `[Foundation, Products, Pricing]` only; cannot import any other v2 layer (Agents, Channels, etc.). Forces clean separation.
- **No new composer deps.** Phase 9 is pure schema + service layer + Filament UX additions. Smallest stack delta of any v2 phase.
- **Phase 5 `PricingRuleChanged` event auto-rewires for trade rules** because Phase 5 listens for ANY pricing rule update including the new customer_group_id column. No new event needed.

</specifics>

<deferred>
## Deferred Ideas

These came up during analysis but are explicitly scoped out of Phase 9:

- **Trade-only catalogue visibility** (TRDE-06) — locked deferred per REQUIREMENTS
- **Customer-group-specific shipping rates** — Phase 9 is pricing only; shipping deferred to v2.1+
- **Per-customer (not just per-group) pricing overrides** — v1 has product overrides; per-customer overrides deferred
- **Volume / quantity-break pricing within a group** — flat margin per group only in v2.0; quantity-tier pricing deferred
- **Time-bounded promotional rules** (rule valid_from / valid_to columns) — Phase 3 deferred this; Phase 9 doesn't reopen
- **Trade-customer-group portal** (self-service group membership management) — deferred
- **Per-group catalogue visibility** (some SKUs hidden per group) — deferred to v2.1
- **Customer-group hierarchy** (Reseller can include Trade pricing rules as fallback) — flat groups in v2.0
- **Real-time group-membership change push to Bitrix CRM** — Phase 9 syncs Woo role → user.customer_group_id one-way; CRM update deferred (CRM Deal already captures via UTM-style fields; not load-bearing)

### Reviewed Todos (not folded)

No pending todos matched Phase 9 scope at discussion time.

</deferred>

---

*Phase: 09-e1-trade-customer-pricing*
*Context gathered: 2026-04-25 via auto-mode (recommended defaults selected inline)*
