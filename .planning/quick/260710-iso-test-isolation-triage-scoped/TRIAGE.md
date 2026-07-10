# Test-Isolation Fragility — Triage & Scoped Proposal (2026-07-10)

**Status:** SCOPED, NOT YET ACTIONED (operator chose "deterministic test-rot now, isolation separately").
**Nature:** dev-experience / robustness issue — NOT a CI blocker. These tests PASS in the full `pest` run (an
earlier Feature test migrates the shared in-memory SQLite connection + seeds reference rows first); they only
FAIL when their file/dir is run in isolation or in an unlucky order. So `pest` (full) is unaffected by these;
`pest tests/Unit/...` (a dev running a subset) is.

## Affected files (8)
| File | Root cause |
|---|---|
| tests/Unit/Domain/Quotes/Observers/QuoteLineImmutabilityObserverTest | A — schema not migrated alone |
| tests/Unit/Domain/Quotes/Observers/QuoteTotalRecomputeObserverTest | A |
| tests/Unit/Domain/Quotes/Services/PriceSnapshotterTest | A |
| tests/Unit/Domain/Quotes/Services/QuotePdfRendererTest | A |
| tests/Unit/TradePricing/Services/TradeRuleResolverPurityTest | A |
| tests/Unit/Pricing/GoldenFixtureV2TradeTest | B — missing seeded reference rows (customer_groups FK) |
| tests/Feature/Domain/Quotes/ImportQuoteActionTest | B — missing seeded default_tier PricingRule |
| tests/Feature/Dashboard/HomeDashboardPageTest | C — unseeded Shield roles/permissions |

(Also: the ~24 in-isolation TradePricing/Pricing failures the 260710-efw run saw are the SAME A/B causes; and
PinnedQuotePricesSurviveRuleEditTest was an earlier one-off instance already fixed in 260709-hl0.)

## Root causes
- **A — Unit suite has no suite-level RefreshDatabase.** `tests/Pest.php` does
  `uses(TestCase::class, RefreshDatabase::class)->in('Feature')` but only `uses(TestCase::class)->in('Unit')`.
  The affected Unit files attach RefreshDatabase PER-TEST via the chained `})->uses(RefreshDatabase::class)` form,
  which does NOT run migrations before the body when the file runs alone → `no such table: customer_groups`/`quotes`/`products`.
  In the full suite an earlier Feature test already migrated the shared `:memory:` connection, so the tables exist.
- **B — Tests depend on reference rows created by other tests/seeders.** GoldenFixtureV2Trade inserts pricing_rules
  with `customer_group_id` FKs but no `customer_groups` rows exist alone (`FOREIGN KEY constraint failed`);
  ImportQuoteAction cases that don't build their own rule need a seeded `default_tier` PricingRule
  (`NoPricingRuleMatchedException`).
- **C — Permission-gated UI needs seeded Shield roles/perms.** HomeDashboardPage `assignRole('admin')` grants nothing
  without RolePermissionSeeder having run, so a permission-gated widget doesn't render + its label is absent.

## Proposed systemic fix (the scoped project)
1. **Attach RefreshDatabase at the Unit suite level** in `tests/Pest.php` (or via a shared base TestCase) —
   `uses(TestCase::class, RefreshDatabase::class)->in('Unit')` — so every DB-touching file migrates its own schema
   regardless of order. Prefer this over per-test `->uses()`.
2. **Add an idempotent test-baseline seeder** run from `RefreshDatabase::afterRefreshingDatabase()` (or a base
   `setUp`): a `default_tier` PricingRule, a couple of `customer_groups`, the admin role + Shield permissions. Then
   tests stop depending on cross-test ordering for reference data.
3. **Reset the spatie permission cache in setUp** (`app(PermissionRegistrar::class)->forgetCachedPermissions()`).
4. (Optional, strongest) consider per-test-file DB / explicit `migrate:fresh` to eliminate shared `:memory:` bleed.

## Risk / effort
- Medium. Changing `tests/Pest.php` Unit binding is global — must run the FULL suite after to confirm no NEW breakage
  (some Unit tests may not expect a migrated DB / may rely on the absence of RefreshDatabase). Roll out + full-sweep
  verify. The baseline seeder must be idempotent + minimal (not mask real "missing rule" assertions).
- Verify each of the 8 passes BOTH alone AND in-full after the change; then run a couple of random-ordered full
  sweeps to catch remaining order-dependence.

## Not included here (separate real bugs, decision-pending)
- #11 IntegrationEventPolicy (registered in AppServiceProvider — delete-vs-keep decision + ShieldRestorationProtocolTest).
- #22 QuotePdfRouteSnapshotTest — potential quote-PDF snapshot-bypass money bug; needs a no-mutation control to confirm.
