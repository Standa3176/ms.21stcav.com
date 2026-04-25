# Phase 9: E1 Trade Customer Pricing - Discussion Log

> **Audit trail only.** Decisions captured in CONTEXT.md.

**Date:** 2026-04-25
**Phase:** 09-e1-trade-customer-pricing
**Mode:** `--auto` (recommended defaults selected without interactive input)
**Areas auto-resolved:** All 11 gray areas (D-01..D-11)

---

## Auto-mode rationale

Phase 9 entered with low-ambiguity scope:
- 6 contract REQ-IDs (TRDE-01..06) already lock customer-group concept, decorator pattern, golden-fixture extension, and trade-only-catalogue deferral
- v2 research (`.planning/research/ARCHITECTURE.md` §TradePricing + `.planning/research/PITFALLS.md` §B1-B5) prescribed the decorator approach + 5-tier specificity sort
- Phase 3 ship gate (PRCE-06) already proven; Phase 9 only needs to extend, not rewrite
- Phase 4 webhook listener pattern + Phase 8 `shield:safe-regenerate` already established
- Zero new composer deps (smallest stack delta of any v2 phase)

User invoked `--auto` to compress agent calls under monthly usage cap. All 11 decisions land on the recommended option per research; no novel architecture choices needed.

---

## Customer-group seed list (TRDE-01)

### D-01 — Seed group count + names

| Option | Description | Selected |
|--------|-------------|----------|
| 4 seeded groups: Trade / Reseller / Education / NHS (Recommended — research B6) | Sales-defined business entities; covers MeetingStore's known B2B verticals | ✓ |
| 2 seeded groups: Trade / Reseller only | Smaller seed; ops adds Education/NHS later via Filament | |
| 1 seeded group: Trade only | Minimal seed; everything else added by ops | |
| Auto-discover from Woo roles | Auto-create on first Woo role sync; risks accidental drift | |

### D-02 — Customer-group provisioning model

| Option | Selected |
|--------|----------|
| Admin-managed via Filament `CustomerGroupResource` (Recommended) | ✓ |
| Auto-discovered like Phase 5 competitor table | |
| Hardcoded enum (no DB table) | |

---

## TradeRuleResolver decorator pattern (TRDE-02)

### D-03 — Resolver design

| Option | Selected |
|--------|----------|
| Decorator wraps v1 `RuleResolver` — does NOT replace (Recommended — research §TradePricing) | ✓ |
| Replace v1 `RuleResolver` with combined retail + trade resolver | |
| Strategy pattern (separate retail vs trade resolvers, dispatched by caller) | |

### D-04 — Pricing-rules schema delta

| Option | Selected |
|--------|----------|
| Single column add: `pricing_rules.customer_group_id BIGINT NULL` + composite index (Recommended) | ✓ |
| Separate `trade_pricing_rules` table | |
| Polymorphic `pricing_rule_targets` join table | |

---

## Golden fixture extension (TRDE-03)

### D-05 — Triple count + structure

| Option | Selected |
|--------|----------|
| 80 triples: 50 v1 byte-identical + 30 new (Recommended — preserves v1 ship gate) | ✓ |
| 100 triples: 50 v1 + 50 new | |
| 60 triples: 50 v1 + 10 new | |
| Replace v1 fixture entirely with v2-aware version | |

Includes new `GoldenFixtureV1UnchangedTest` regression to lock v1 portion byte-identical.

---

## Anonymous display posture (TRDE-05 — operator decision per research B8)

### D-06 — Anonymous user pricing visibility

| Option | Selected |
|--------|----------|
| `config('b2b.anonymous_display') === 'retail'` default; env-overridable to `'hidden'` (Recommended — SEO + conversion friendly) | ✓ |
| `'hidden'` default | |
| Hardcoded `'retail'` (no operator override) | |

---

## Customer → group mapping source

### D-07 — Mapping data source (Claude's Discretion)

| Option | Selected |
|--------|----------|
| WooCommerce user role → customer_group_id via `config('b2b.role_to_group_map')` (Recommended) | ✓ |
| Manual per-user assignment in Filament Users page | |
| Bitrix24 contact custom field | |

### D-08 — Storage on user record (Claude's Discretion)

| Option | Selected |
|--------|----------|
| Denormalised `users.customer_group_id` BIGINT FK column (Recommended — perf wins over staleness) | ✓ |
| Join-on-every-call from a separate `user_customer_group` pivot | |
| Resolve on-the-fly from Woo API | |

---

## Filament UX (TRDE-04 + B7 + B13)

### D-09 — PricingRuleResource shape (Claude's Discretion)

| Option | Selected |
|--------|----------|
| Extend existing PricingRuleResource additively (Recommended) | ✓ |
| Replace with new RuleResource handling both types | |
| Separate TradePricingRuleResource alongside retail | |

### D-10 — CustomerGroupResource (Claude's Discretion)

| Option | Selected |
|--------|----------|
| New Filament `CustomerGroupResource` with admin + pricing_manager CRUD (Recommended) | ✓ |
| Reuse Settings page for inline group editing | |
| No UI; seeder + migrations only | |

---

## Trade-only catalogue visibility (TRDE-06)

### D-11 — Catalogue visibility per group

| Option | Selected |
|--------|----------|
| NOT supported in v2.0; deferred to v2.1+ (locked per REQUIREMENTS) | ✓ |
| Add `is_trade_only` column on products | |
| Per-group catalogue visibility table | |

---

## Claude's Discretion (defaults documented in CONTEXT.md)

- Migration timestamps: start `2026_04_26_*` (Phase 8 ended `2026_04_25_*`)
- Deptrac dual-YAML: new `TradePricing` layer added to BOTH `depfile.yaml` AND `deptrac.yaml`; allow-list `[Foundation, Products, Pricing]`; DENIES Sync/CRM/Webhooks/Cutover/Marketing/Agents
- `DeptracTradePricingLayerTest` (positive + negative path)
- `shield:safe-regenerate` wraps any new `shield:generate` invocation; new policies (CustomerGroupPolicy + extension to PricingRulePolicy if needed) restored automatically
- Testing DB: `meetingstore_ops_testing` MySQL (Phase 1 P03 lesson; deferred Feature tests if MySQL offline matches Phase 6/7/8 precedent)
- `->authorize()` on Filament actions mandatory
- Listener-based extension of v1: zero modifications to v1's `RuleResolver` class
- `users.customer_group_id` column: nullable BIGINT FK; backfill existing users to null (retail)
- Phase 5 `PricingRuleChanged` event auto-rewires for trade rules; no new event needed

## Deferred Ideas

- Trade-only catalogue visibility (TRDE-06 — locked deferred per REQUIREMENTS)
- Customer-group-specific shipping rates (v2.1+)
- Per-customer (not just per-group) pricing overrides (v2.1+)
- Volume / quantity-break pricing within a group (v2.1+)
- Time-bounded promotional rules (rule valid_from/valid_to columns) — Phase 3 deferred; not reopened
- Trade-customer-group portal (self-service group membership management)
- Per-group catalogue visibility (some SKUs hidden per group) — v2.1+
- Customer-group hierarchy (Reseller can include Trade pricing rules as fallback) — flat groups in v2.0
- Real-time group-membership change push to Bitrix CRM (one-way Woo → user only in v2.0)
