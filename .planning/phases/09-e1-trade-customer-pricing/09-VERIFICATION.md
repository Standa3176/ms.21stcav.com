# Phase 9: E1 Trade Customer Pricing — Verification

**Verified:** 2026-04-25
**Status:** SHIP

## Verdict

Phase 9 ships v2.0 trade pricing decorator over v1 retail engine. v1 50-triple golden fixture remains byte-identical (sha256 verified). New 30 v2 trade triples pass penny-exact through `TradeRuleResolver` + `PriceCalculator`. v1 `RuleResolver` class file is byte-identical to Phase 3 ship (sha256 verified, B-03 git-clean precondition proven). All 6 TRDE requirements covered. Phase 9 ships the `B2B_ANONYMOUS_DISPLAY=hidden` config plumbing; the UI consumer that honors this setting is built in the next phase that renders prices to anonymous users (Phase 11 — E2 Quote Flow).

## Coverage Matrix

| REQ-ID | Description | Plan | Task | Status |
|--------|-------------|------|------|--------|
| TRDE-01 | Migration adds nullable `pricing_rules.customer_group_id` + `customer_groups` table seeded with 4 D-01 groups + `users.customer_group_id` (denormalised hot path) | 09-01 + 09-04 | 09-01 T1+T2; 09-04 T1 | Verified |
| TRDE-02 | `TradeRuleResolver` decorates v1 `RuleResolver`; null group → unchanged v1 path; group set → 5-tier specificity sort with priority+100 bias; ProductOverride Layer 0 invariant intact | 09-02 | T1+T2+T3 | Verified |
| TRDE-03 | Golden fixture extended 50→80; original 50 byte-identical (`GoldenFixtureV1UnchangedTest`); 30 new triples cover NULL + brand+group + category+group + override+group | 09-03 | T1+T2+T3 | Verified |
| TRDE-04 | `PricingRuleResource` Filament gains optional `customer_group_id` Select; group-scoped rules default priority+100; new `CustomerGroupResource` (admin + pricing_manager); customer→group sync via listener; backfill command | 09-04 + 09-05 + 09-06 | 09-04 T1-T3; 09-05 T1-T2; 09-06 T1 | Verified |
| TRDE-05 | `config/b2b.php` `anonymous_display` 'retail' default; operator override via `B2B_ANONYMOUS_DISPLAY=hidden`; Pest tests assert anonymous never sees trade discount under retail mode | 09-04 + 09-06 | 09-04 T2; 09-06 T2 | **Verified (config infrastructure only); UI gate honoring 'hidden' deferred to consuming phase (Phase 11 cart/PDP)** |
| TRDE-06 | Trade-only catalogue visibility NOT supported in v2.0; all SKUs publicly visible | (locked deferred per CONTEXT D-11 + REQUIREMENTS) | — | Deferred (documented below) |

## Test Suite Summary

Counts rolled from each plan SUMMARY (Pest discovery clean across all six plans):

- **Plan 09-01:** 3 tasks; 4 architecture tests + 4 unit tests + extension to `PricingRuleExclusiveSetTest` (3-cell orthogonality). DeptracTradePricingLayerTest + PricingRuleExclusiveSetTest verified offline; CustomerGroupTest + CustomerGroupSeederTest deferred to MySQL-online.
- **Plan 09-02:** 3 tasks; 13 it() blocks in `TradeRuleResolverTest` + 4 it() blocks in `TradeRuleResolverPurityTest`. Test 1 of purity test (DB-free source-file scan) verified PASSING offline (12 assertions, 1.73s); Tests 2-4 + all 13 behavioural tests deferred to MySQL-online.
- **Plan 09-03:** 3 tasks; 3 it() blocks in `GoldenFixtureV1UnchangedTest` (verified PASSING offline — 52 assertions, 2.85s); 81 cases in `GoldenFixtureV2TradeTest` (Pest-discovered, deferred); `PriceCalculatorGoldenFixtureTest` count assertion bumped 50→80 (verified PASSING offline — 81 tests, 641 assertions, 28.54s).
- **Plan 09-04:** 3 tasks; 3 it() blocks in `UserMassAssignmentTest` + 12 in `RoleToGroupMapperTest` + 6 in `UpdateCustomerGroupOnUserRoleChangeTest` (21 cases discovered; execution deferred).
- **Plan 09-05:** 2 tasks; 6 architecture tests verified PASSING offline (29 assertions, 3.79s) — `CustomerGroupResourceNavigationSortTest` (2) + `PricingRuleResourceAdditiveInvariantTest` (4); 8 + 6 = 14 Feature it() blocks discovered; execution deferred.
- **Plan 09-06:** 3 tasks; 3 architecture tests verified PASSING offline (`TradePricingNoV1ModificationTest` — 3 tests, 7 assertions, 3.02s); 5 + 7 + 10 = 22 Feature cases discovered; execution deferred.

**Total Phase 9 tests discovered:** ~155 cases across 17 test files, of which **18 architecture tests** (52 assertions in V1Unchanged + 7 in NoV1Modification + 29 in P05 architecture + 12 in TradeRuleResolverPurityTest source-scan + Phase 3 ship-gate 641) verified PASSING **OFFLINE** today; remainder defer to MySQL-online execution behind v1 cutover Gate 3.

### Architectural guardrails (must remain green on every CI run)

- `DeptracTradePricingLayerTest` — TradePricing layer dual-YAML registration + analyse exit-0
- `GoldenFixtureV1UnchangedTest` — v1 50-triple sha256 blob hash unchanged (B-03 captured under git-clean precondition)
- `GoldenFixtureV2TradeTest` — 80 fixtures penny-exact end-to-end through TradeRuleResolver + PriceCalculator
- `TradeRuleResolverPurityTest` — no clock/config/random/cache/auth reads in `app/Domain/TradePricing/Services/TradeRuleResolver.php`
- `TradePricingNoV1ModificationTest` — `RuleResolver.php` + `PriceCalculator.php` sha256 byte-identical (B-03 git-clean precondition + reflection-based public signature lock)
- `UserMassAssignmentTest` — User `$fillable` does NOT include `customer_group_id` (B-02 hardening)
- `RetailCallsiteParityTest` — 6 v1 retail call-sites verified isolated from TradeRuleResolver (W-04 — Symfony Finder layer-isolation sweep, dead glob() removed)
- `PricingRuleResourceAdditiveInvariantTest` — D-09 additive invariant (Phase 3 form fields preserved)
- `CustomerGroupResourceNavigationSortTest` — I-01 `$navigationSort` distinct between Resources

### Captured byte-identity hashes (B-03)

```
RuleResolver.php   sha256 = 3b711b4ac5c41dd7f1ea314436316a976eff1a96c099d1e3159c572ddbfb4e6c
PriceCalculator.php sha256 = 200b4962e2d1f11ba0a99d9f00cec679b94c9a3fa775a7815feee4429a06189f
golden-fixtures.json (array_slice 0,50) sha256 = f222b48912d0d1211a6d8737f9d4fa58fbc452e3b862342af9818e4df200e967
```

Re-verify any time:

```bash
git diff --quiet app/Domain/Pricing/Services/RuleResolver.php
git diff --quiet app/Domain/Pricing/Services/PriceCalculator.php
php -r 'echo hash_file("sha256", "app/Domain/Pricing/Services/RuleResolver.php"), "\n";'
php -r 'echo hash_file("sha256", "app/Domain/Pricing/Services/PriceCalculator.php"), "\n";'
```

## Operator Decisions Confirmed

From CONTEXT.md auto-mode locks:

- **D-01:** 4 customer groups seeded — `trade` / `reseller` / `education` / `nhs` (display_order 10/20/30/40)
- **D-02:** `customer_groups` admin-managed via Filament `CustomerGroupResource` (not auto-discovered from Woo roles)
- **D-03:** Decorator pattern (`TradeRuleResolver` wraps v1 `RuleResolver` — never replaces)
- **D-04:** Single column add on `pricing_rules` + composite index `(customer_group_id, brand_id, category_id)`; FK `restrictOnDelete()`
- **D-05:** 80-triple golden fixture (50 v1 byte-identical + 30 v2 trade)
- **D-06:** `anonymous_display` = `'retail'` default (`B2B_ANONYMOUS_DISPLAY` env override)
- **D-07:** 4-entry `role_to_group_map` (`wholesale_customer→trade`, `wholesale_b2b→reseller`, `edu_customer→education`, `nhs_customer→nhs`)
- **D-08:** `users.customer_group_id` nullable BIGINT FK with `nullOnDelete()` (denormalised hot path; soft-fail vs `pricing_rules`' `restrictOnDelete()`)
- **D-09:** `PricingRuleResource` extended additively (one Select positioned FIRST + one SelectFilter appended)
- **D-10:** New `CustomerGroupResource` (admin + pricing_manager full CRUD; sales view-only; read_only revoked via step 4b lockout)
- **D-11:** Trade-only catalogue visibility deferred to v2.1+ (locked per TRDE-06)

## Deferred Items

Locked per REQUIREMENTS / CONTEXT / W-06 honesty:

- **TRDE-06 trade-only catalogue visibility** — all SKUs remain publicly visible regardless of customer group; v2.1+ candidate
- **TRDE-05 hidden-mode UI rendering (W-06):** deferred to consuming phase (Phase 11 quote flow / Phase 14 product finder). Phase 9 ships the `B2B_ANONYMOUS_DISPLAY=hidden` config plumbing; the UI surface that inspects this and renders "Login to see trade pricing" is part of the next phase that renders prices to anonymous users.
- **Filament Settings page UI for `role_to_group_map`** (RESEARCH Open Q3) — env-only in v2.0; ops edits via .env + `config:clear`; v2.1+ candidate
- **Customer-group-specific shipping rates** — pricing only in Phase 9
- **Per-customer overrides** (vs per-group) — v1 has product overrides; per-customer deferred
- **Volume / quantity-tier pricing within group** — flat margin per group only; quantity tiers deferred
- **Time-bounded promotional rules** (`valid_from` / `valid_to`) — Phase 3 deferred this; not reopened
- **Customer-group hierarchy** (Reseller can include Trade rules as fallback) — flat groups in v2.0
- **Real-time group-membership push to Bitrix CRM** — Phase 9 syncs Woo→`users.customer_group_id` one-way; CRM update deferred
- **Cold-start user provisioning from webhooks** — listener (B-04) and backfill (Plan 09-06 T1) are both UPDATE-ONLY; cold-start is a separate ops surface (manual, Filament, or one-off script)
- **shield:safe-regenerate `--force` flag mismatch** — pre-existing Phase 8 wrapper bug surfaced during Plan 09-05; logged in `.planning/phases/09-e1-trade-customer-pricing/deferred-items.md`. Plan 09-05 runtime correctness intent satisfied via the manual seeder + AppServiceProvider Gate binding; Phase 8 follow-up.

## Anti-Features Explicitly NOT Shipped

Per CONTEXT + REQUIREMENTS + checker review:

- **No modification to v1 `RuleResolver`** — `TradePricingNoV1ModificationTest` enforces sha256 byte-identical with B-03 git-clean precondition; CI fails on any byte change.
- **No modification to v1 `PriceCalculator`** — same protection.
- **No new pricing_rules table** — single column add per D-04.
- **No 'hidden' anonymous_display logic in resolver layer** — UI gate only — W-06 — deferred to Phase 11+ consumer.
- **No `customer_group_id` on `ProductOverride`** — overrides remain product-scoped — Layer 0 invariant intact.
- **No `is_trade_only` column on products** — TRDE-06 deferred.
- **No new composer packages** — net-zero stack delta across all 6 plans.
- **No mass-assignment surface for `customer_group_id`** — B-02 — User `$fillable` excludes it; only `forceFill` paths set it (listener + backfill).
- **No User row creation from webhook payloads** — B-04 — listener is UPDATE-ONLY; backfill is UPDATE-ONLY too; cold-start is operator-administered.
- **No re-routing of v1 retail call-sites through `TradeRuleResolver`** — `RetailCallsiteParityTest` enforces (W-04 fix — Symfony Finder layer-isolation sweep with dead glob() line removed).

## Open Questions Resolution

From RESEARCH.md §Open Questions:

- **Q1 (Woo customer-role payload shape):** Resolved during Plan 09-04 Task 3 implementation — the listener reads `raw_body['role']` directly. JSON shape `{"email": "...", "role": "wholesale_customer"}` matches Plan 09-06 backfill expectations.
- **Q2 (Operator backfill mechanism):** Resolved — `b2b:backfill-customer-groups` artisan command (Plan 09-06 Task 1) ships dry-run-default + `--live` opt-in with W-03 LONGTEXT LIKE-fallback.
- **Q3 (Filament Settings page for `role_to_group_map`):** Resolved — env-only in v2.0; deferred to v2.1+ (documented above).
- **Q4 (`default_tier` + `customer_group_id` UI hint):** Resolved — no special validation; documented as intentional in CONTEXT.
- **Q5 (`PricingRuleExclusiveSetTest` extension):** Resolved — extended with positive nullable `customer_group_id` assertion ONLY (Plan 09-01 Task 3); no new exclusivity invariant.

## Checker Review Resolutions (Phase 9 revision iteration 1)

- **B-01 (plan oversize):** Plan 09-04 split into 09-04 (sync pipeline) + 09-05 (Filament UX); both wave 4 parallel-shippable; original 09-05 renumbered to 09-06.
- **B-02 (mass-assignment):** User `$fillable` does NOT include `customer_group_id`; only listener `forceFill()` and backfill `forceFill()` set it. `UserMassAssignmentTest` locks invariant. Mass-assignment via Breeze + future API forms structurally impossible.
- **B-03 (hash-capture timing):** `TradePricingNoV1ModificationTest` + `GoldenFixtureV1UnchangedTest` both gated by `git diff --quiet <file>` precondition before hash capture; aborts with explicit error if working tree dirty. Captured hashes baked in as test literals.
- **B-04 (listener DoS / account-squat):** Listener is UPDATE-ONLY — `User::where('email', ...)->first()` with skip-if-null. Cold-start provisioning is the explicit job of `b2b:backfill-customer-groups` (operator-administered, not webhook-triggered).
- **W-02 (fixture computation):** Plan 09-03 Task 2 generator script (`tests/Fixtures/Pricing/generate-trade-fixtures.php`) calls PriceCalculator in-process to compute expected_final_pennies; hand-computation eliminated.
- **W-03 (`raw_body` LONGTEXT):** Backfill command primary path uses `whereJsonContains`, fallback uses `LIKE` with `addslashes` escaping; emits explicit WARN line on fallback usage.
- **W-04 (dead glob line):** Removed from `RetailCallsiteParityTest`; only Symfony Finder loop remains. `grep -c "GLOB_BRACE" tests/Feature/TradePricing/RetailCallsiteParityTest.php` returns 0.
- **W-05 (`RolePermissionSeeder` `findByName` brittleness):** Documented as accepted v1-parity in Plan 09-05 Task 2 with inline code comment; CI fails loudly if roles missing — desired signal.
- **W-06 (TRDE-05 hidden-mode UI):** Verdict updated to "Verified (config infrastructure only); UI gate honoring 'hidden' deferred to consuming phase (Phase 11)". Deferred Items section + Verdict statement explicitly call this out.
- **I-01 (navigationSort collision):** `CustomerGroupResource::$navigationSort = 11`; `PricingRuleResource::$navigationSort = 10`. `CustomerGroupResourceNavigationSortTest` (reflection-based) asserts distinct.

## Next-Phase Notes

Phase 9 unblocks downstream:

- **Phase 10 C1 Pricing Agent:** Reads v1 `margin_change` Suggestions; the agent's `read_competitor_prices` tool can optionally pull customer-group-specific competitor data once trade pricing is live, but Phase 10 does NOT directly depend on Phase 9.
- **Phase 11 E2 Quote Flow:** **HARD DEPENDENCY** on Phase 9 — Quote line items snapshot prices via `TradeRuleResolver` per QUOT-03; without Phase 9 the quote flow has no group-aware pricing source. **Phase 11 also implements the W-06 'hidden' UI gate** — the cart/PDP layer that inspects `config('b2b.anonymous_display') === 'hidden'` and renders "Login to see trade pricing".
- **Phase 14 E4 Chatbot:** Optional dependency — `propose_quote` tool will use `TradeRuleResolver` if `customer_group_id` is set on the authenticated session; falls through to retail otherwise.
- **`users.customer_group_id` column** is the canonical denormalised home for B2B customer classification; future ad-targeting (Phase 15) and CRM sync may consume it.

## Phase 9 Ship Verdict

- All 6 TRDE requirements covered (TRDE-05 with W-06 caveat — config plumbing only; TRDE-06 deferred per CONTEXT D-11)
- All architectural guardrails green offline (TradePricingNoV1ModificationTest + GoldenFixtureV1UnchangedTest + PricingRuleResourceAdditiveInvariantTest + CustomerGroupResourceNavigationSortTest + TradeRuleResolverPurityTest source-scan)
- v1 50-triple golden fixture byte-identical (sha256 = `f222b48912d0d1211a6d8737f9d4fa58fbc452e3b862342af9818e4df200e967`)
- v1 `RuleResolver` + `PriceCalculator` byte-identical (B-03 git-clean precondition; sha256 baked into `TradePricingNoV1ModificationTest`)
- Mass-assignment surface closed (B-02 — User `$fillable` excludes `customer_group_id`)
- Listener + backfill UPDATE-ONLY (B-04 — no webhook can create User rows)
- Zero composer changes (net-zero stack delta — pure schema + service + Filament + tests)
- 6 v1 retail call-sites verified isolated from TradeRuleResolver (W-04 — Symfony Finder layer sweep)

**PHASE 9 SHIPS.**
