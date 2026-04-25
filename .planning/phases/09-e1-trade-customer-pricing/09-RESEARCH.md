# Phase 9: E1 Trade Customer Pricing — Research

**Researched:** 2026-04-25
**Domain:** Greenfield `app/Domain/TradePricing/` decorator layer over v1 Phase 3 Pricing — adds nullable `customer_group_id` column to `pricing_rules`, new `customer_groups` lookup table, `users.customer_group_id` denormalised FK, listener-based sync from Phase 4 customer webhooks
**Confidence:** HIGH on v1 reuse seams (verified by direct file reads of every named v1 service). HIGH on the decorator pattern shape (RuleResolver signature is `resolve(Product): PricingResolution` with no other args — the wrapper is a clean superset). HIGH on Phase 4/8 precedents (Filament Resource pattern, dual-YAML Deptrac, `shield:safe-regenerate`). MEDIUM on the WooCommerce role → customer_group mapping (no v1 customer-role data exists in this codebase yet — see §Open Questions).

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

#### Customer-group seed list (TRDE-01)

- **D-01:** **4 seeded customer groups** (per research recommendation):
  - `trade` — slug `trade`, name "Trade Customer"
  - `reseller` — slug `reseller`, name "Reseller / Distributor"
  - `education` — slug `education`, name "Education Sector"
  - `nhs` — slug `nhs`, name "NHS / Healthcare"
  All marked `is_active=true` at seed. Admin can deactivate via Filament Resource if a group becomes unused. New groups added by ops via Filament; no migration required for new groups.
- **D-02:** **`customer_groups` table is admin-managed (NOT auto-discovered).** Filament `CustomerGroupResource` (admin + pricing_manager CRUD) lets sales add new groups. Slug + name + is_active + display_order columns; created_at/updated_at; `LogsActivity` for audit trail.

#### TradeRuleResolver decorator pattern (TRDE-02)

- **D-03:** **Decorator wraps v1 RuleResolver — does NOT replace.** New service `App\Domain\TradePricing\Services\TradeRuleResolver` with method `resolve(Product $product, ?int $customerGroupId = null): PricingResolution`. When `$customerGroupId` is null OR `0`, immediately delegates to v1's `RuleResolver::resolve($product)` and returns its result unchanged (v1 byte-identical fallback). When `$customerGroupId` is set, queries `pricing_rules` for matching customer-group-scoped rules in priority order:
  1. `customer_group_id = X AND brand_id = Y AND category_id = Z` (most specific)
  2. `customer_group_id = X AND brand_id = Y`
  3. `customer_group_id = X AND category_id = Z`
  4. `customer_group_id = X` (least specific group rule)
  5. Falls through to v1's retail rules in same hierarchy if no group match found

  Group-scoped rules default to `priority = base_priority + 100` so customer-group wins against same-scope retail rule. Tied rules sorted by `priority DESC, id ASC` (Phase 3 convention preserved).

- **D-04:** **Schema is single column add: `pricing_rules.customer_group_id BIGINT NULL`.** No new pricing_rules table. Existing rules remain `customer_group_id=null` = retail default. Foreign key `customer_groups(id) ON DELETE RESTRICT`. Index added on `(customer_group_id, brand_id, category_id)` for resolver query path.

#### Golden fixture extension (TRDE-03)

- **D-05:** **80-triple golden fixture: 50 v1 + 30 v2.** Original 50 retail triples remain byte-identical in `tests/Fixtures/Pricing/golden-fixtures.json`. New 30 customer-group triples in same JSON file with `customer_group_id` field set. Coverage:
  - 5 triples per group × 4 groups = 20 (basic group price calculation per group)
  - 5 triples for brand+group precedence ("Trade gets 22% margin on Logitech, retail gets 25%")
  - 3 triples for NULL handling (rule with brand AND null group should match retail-only customer; rule with brand AND non-null group should NOT match retail-only customer)
  - 2 triples for override-equipped SKUs at trade pricing

  New regression test `GoldenFixtureV1UnchangedTest` (tests/Architecture/) asserts the v1 portion is byte-identical: snapshots commit `a4d075f` (Phase 3 03-01 fixture file blob hash), fails build if any of original 50 triples drift. Phase 3 ship gate (PRCE-06) extended; build fails if any of 80 triples drift.

#### Anonymous display posture (TRDE-05 — operator decision per research B8)

- **D-06:** **`config('b2b.anonymous_display') === 'retail'` (default).** Anonymous users see retail prices; after authenticated B2B login, the Cart + product-detail-page (PDP) re-resolves prices using their `customer_group_id`. Login state determines which prices the user sees. Operator can flip to `'hidden'` (showing "Login to see trade pricing") via env override (`B2B_ANONYMOUS_DISPLAY=hidden`); recompiles config; no code change.

#### Customer → group mapping source

- **D-07 (Claude's Discretion):** **WooCommerce user role → customer_group_id mapping.** Phase 9 reads Woo user role on customer webhook arrival and maps to a `customer_group_id` via a config map: `config('b2b.role_to_group_map')`:
  ```php
  'wholesale_customer' => 'trade',
  'wholesale_b2b' => 'reseller',
  'edu_customer' => 'education',
  'nhs_customer' => 'nhs',
  // any other role → null = retail
  ```
  Unrecognised roles default to null (retail). Mapping editable via Filament Settings page (admin-only). Column `users.customer_group_id` added in v2 (denormalised from Woo role at sync time). Mismatched/changed roles trigger re-sync.

- **D-08 (Claude's Discretion):** **Explicit `users.customer_group_id` migration ships in Phase 9.** New nullable BIGINT FK column on the `users` table. Backfill existing users to null (retail). New `UpdateCustomerGroupOnUserRoleChange` listener subscribed to v1's `CustomerRegistered` event (or to a new event Phase 9 introduces if v1 doesn't already cover this).

#### Filament UX (TRDE-04 + B7 + B13)

- **D-09 (Claude's Discretion):** **Filament `PricingRuleResource` extended (NOT replaced) with optional `customer_group_id` Select field.** Empty select = retail (null). Non-empty = customer-group rule. Same Resource handles retail + trade rules. List view filterable by `customer_group_id`; per-group view available via filter preset. Phase 3's existing `PricingRuleResource` Filament code edited additively — preserves form/table structure; adds one Select field + one filter.

- **D-10 (Claude's Discretion):** **NEW `CustomerGroupResource` Filament Resource (admin + pricing_manager).** CRUD for `customer_groups` table. Slug uniqueness enforced. Cannot delete a group with active rules (FK ON DELETE RESTRICT — Filament shows actionable error). Order by `display_order ASC` for predictable Select dropdown ordering across the app.

#### Trade-only catalogue visibility (TRDE-06)

- **D-11 (locked per REQUIREMENTS):** **NOT supported in v2.0.** All SKUs remain publicly visible regardless of customer group. v2.1+ candidate if ops requires. No `is_trade_only` column added to products in v2.

### Claude's Discretion (defaults documented above)

- D-07/D-08: WooCommerce role → customer_group_id mapping via config + denormalised `users.customer_group_id` column
- D-09/D-10: Single PricingRuleResource for both rule kinds + new CustomerGroupResource

Additional implementation defaults:
- **Migration timestamps:** start `2026_04_26_*` (Phase 8 ended `2026_04_25_*`)
- **Deptrac:** new `TradePricing` layer added to BOTH `depfile.yaml` AND `deptrac.yaml`. Allow-list: `[Foundation, Products, Pricing]`. DENIES Sync/CRM/Webhooks/Cutover/Marketing/Agents.
- **`DeptracTradePricingLayerTest`** (positive + negative path).
- **`shield:safe-regenerate`** wraps any new `shield:generate` invocation; new policies (CustomerGroupPolicy + extension to PricingRulePolicy if needed) restored automatically per Phase 8 P5-F protocol.
- **Testing DB:** `meetingstore_ops_testing` MySQL (Phase 1 P03 lesson; deferred Feature tests if MySQL offline matches Phase 6/7/8 precedent).
- **`->authorize()` on Filament actions** mandatory.
- **Listener-based extension** of v1: zero modifications to v1's `RuleResolver` class. TradeRuleResolver wraps via constructor injection. Phase 3 tests stay green by definition because Phase 3 code is untouched.

### Deferred Ideas (OUT OF SCOPE)

- Trade-only catalogue visibility (TRDE-06) — locked deferred per REQUIREMENTS
- Customer-group-specific shipping rates — Phase 9 is pricing only; shipping deferred to v2.1+
- Per-customer (not just per-group) pricing overrides — v1 has product overrides; per-customer overrides deferred
- Volume / quantity-break pricing within a group — flat margin per group only in v2.0; quantity-tier pricing deferred
- Time-bounded promotional rules (rule valid_from / valid_to columns) — Phase 3 deferred this; Phase 9 doesn't reopen
- Trade-customer-group portal (self-service group membership management) — deferred
- Per-group catalogue visibility (some SKUs hidden per group) — deferred to v2.1
- Customer-group hierarchy (Reseller can include Trade pricing rules as fallback) — flat groups in v2.0
- Real-time group-membership change push to Bitrix CRM — Phase 9 syncs Woo role → user.customer_group_id one-way; CRM update deferred
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| TRDE-01 | Migration adds nullable `pricing_rules.customer_group_id BIGINT` + new `customer_groups` table seeded with Trade / Reseller / Education / NHS | §Schema Changes — 3 migration shape; §Standard Stack — Eloquent + LogsActivity; §Code Examples — `customer_groups` migration template |
| TRDE-02 | `TradeRuleResolver` decorates v1 `RuleResolver`; null group → unchanged v1 path; group set → 5-tier specificity sort with `priority + 100` bias | §Architecture Patterns — Pattern 1 (decorator); §Code Examples — `TradeRuleResolver` skeleton; §Common Pitfalls — Pitfall B1 (NULL handling) |
| TRDE-03 | Golden fixture extended 50 → 80 triples; original 50 byte-identical (`GoldenFixtureV1UnchangedTest`); new 30 cover NULL + brand+group + category+group + override+group | §Code Examples — Pest dataset extension; §Common Pitfalls — Pitfall I3 (extend, never replace) |
| TRDE-04 | `PricingRuleResource` Filament gains optional `customer_group_id` Select; group-scoped rules default `priority + 100`; new `CustomerGroupResource` (admin + pricing_manager) | §Architecture Patterns — Pattern 4 (additive Filament edit); §Code Examples — Filament Select + Filter shape |
| TRDE-05 | `config/b2b.php => 'anonymous_display' => 'retail'` default; operator override via `B2B_ANONYMOUS_DISPLAY=hidden`; documented in CONTEXT.md | §Code Examples — `config/b2b.php` shape; §Common Pitfalls — Pitfall B2 (anonymous display leak) |
| TRDE-06 | Trade-only catalogue visibility NOT supported in v2.0; all SKUs publicly visible | §Deferred Ideas — locked per REQUIREMENTS; documented in 09-VERIFICATION.md deferred section |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

CLAUDE.md in this working directory is RAMS-project-specific (a different Laravel app — `rams.21stcav.com`) and was injected by the parent agent's project context. It does NOT govern `meetingstore-ops-app` work directly. The relevant project-wide constraints for THIS phase come from `.planning/PROJECT.md` + the CONTEXT.md `<decisions>` block above. The GSD enforcement directive ("only edit through `/gsd-execute-phase` or `/gsd-quick`") DOES apply universally and is honoured by the planner workflow.

## Summary

Phase 9 is the smallest stack delta of any v2 phase: zero new composer packages, zero v1 file modifications, three migrations, one new domain (`TradePricing`), one new Filament Resource (`CustomerGroupResource`), one decorator service (`TradeRuleResolver`), one config file (`config/b2b.php`), one listener (`UpdateCustomerGroupOnUserRoleChange`), and one extension to the existing `PricingRuleResource`. Every other v2 invariant rides on prior-phase rails (dual-YAML Deptrac, `shield:safe-regenerate`, golden-fixture-as-ship-gate, `LogsActivity`, listener-based extension).

The decorator-over-RuleResolver design is **the load-bearing architectural choice**. v1's `RuleResolver::resolve(Product): PricingResolution` signature has zero customer-group awareness (verified by direct read of `app/Domain/Pricing/Services/RuleResolver.php`); the wrapper is a clean superset because v1's signature is a subset of the wrapper's signature. Six existing call-sites (`PriceRecomputer`, `SimulatedImpactCalculator`, `RuleExplorer`, `ComputeMarginSuggestionJob`, `CreateWooProductJob`, and `RuleResolver` itself) keep using v1 directly — only NEW Phase 9 / Phase 11 callers (E2 quote flow) reach for `TradeRuleResolver`. v1 retail behaviour stays byte-identical because v1 callers never pay the trade tax.

The schema is one nullable column on `pricing_rules` + one new `customer_groups` table + one nullable column on `users` (denormalised from Woo role for hot-path performance). The 50-triple v1 golden fixture stays byte-identical (new `GoldenFixtureV1UnchangedTest` snapshots its blob hash). Phase 9 ADDS 30 new triples covering 5 NULL-handling and brand/category/override × group permutations.

**Primary recommendation:** Five-plan structure mirroring Phase 3/4 cadence:
1. **Plan 09-01** — Migrations + `CustomerGroup` model + Phase 9 seeder + `TradePricing` Deptrac layer (dual-YAML) + `DeptracTradePricingLayerTest` + `PricingRuleExclusiveSetTest` extended for new column
2. **Plan 09-02** — `TradeRuleResolver` service + 5-tier specificity sort + Pest unit tests (4-quadrant NULL matrix per Pitfall B1) + `RuleResolverPurityTest` extended to cover Trade path purity
3. **Plan 09-03** — Golden fixture extension 50→80 + `GoldenFixtureV1UnchangedTest` + `GoldenFixtureV2TradeTest` (Phase 3 ship gate v2)
4. **Plan 09-04** — `CustomerGroupResource` Filament + extension to `PricingRuleResource` (Select + Filter, additive edit) + `users.customer_group_id` migration + `UpdateCustomerGroupOnUserRoleChange` listener subscribed to `CustomerRegistered` + `config/b2b.php` + `RolePermissionSeeder` LIKE-pattern extension + `shield:safe-regenerate --allow-new=CustomerGroupPolicy`
5. **Plan 09-05** — `DeptracTradePricingLayerTest` + retail-parity guardrails (`B2B_ANONYMOUS_DISPLAY=retail` test) + 09-VERIFICATION.md ship verdict

Granularity matches Phase 3 (5 plans, 7 tasks/plan average) and Phase 8 (5 plans).

## Standard Stack

### Core (zero new composer packages)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Laravel | ^12.0 (already installed) | Migration + Eloquent + Service Container | v1 baseline, no bump |
| Filament | ^3.3 (already installed) | `CustomerGroupResource` CRUD + `PricingRuleResource` extension | Phase 3 + 8 precedent |
| `spatie/laravel-activitylog` | ^4 (already installed) | `LogsActivity` trait on `CustomerGroup` model | Mirrors v1 `PricingRule` audit |
| `spatie/laravel-permission` | ^6 (already installed) | New `CustomerGroupPolicy` permissions via `shield:safe-regenerate` | Phase 1 + 8 precedent |
| `qossmic/deptrac-shim` | (already installed) | `TradePricing` layer + `DeptracTradePricingLayerTest` | Phase 5 dual-YAML lesson |
| `pestphp/pest` | (already installed) | All test classes (golden fixture extension, decorator unit tests, Filament feature tests) | v1 baseline |

### Supporting (re-used unchanged from v1)

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `App\Domain\Pricing\Services\RuleResolver` | v1 (Phase 3) | DELEGATED to by `TradeRuleResolver` when `$customerGroupId === null` | Every retail price resolve path |
| `App\Domain\Pricing\Services\PriceCalculator` | v1 (Phase 3) | UNCHANGED — receives `marginBasisPoints` from whichever resolver wins | Every price compute (retail or trade) |
| `App\Domain\Pricing\Models\PricingRule` | v1 (Phase 3) — gains nullable `customer_group_id` column + cast + observed-by + `LogsActivity` extension | Stores BOTH retail and trade rules | Every rule read/write |
| `App\Domain\Webhooks\Events\CustomerRegistered` | v1 (Phase 4) | LISTENED TO by Phase 9's `UpdateCustomerGroupOnUserRoleChange` | Customer create/update from Woo |
| `App\Foundation\Events\DomainEvent` | v1 (Phase 1) | Phase 9's listener uses `correlation_id` threading | Customer-role sync events |
| `App\Domain\Agents\Console\Commands\ShieldSafeRegenerateCommand` | v1 (Phase 8) | Wraps `shield:generate --all` for new `CustomerGroupPolicy` | Plan 09-04 RBAC step |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Decorator wrap | Modify `RuleResolver::resolve(Product, ?int $groupId)` directly | Locked OUT (D-03) — would break v1's Phase 3 ship gate (golden fixture is signed against the unchanged signature). |
| Single column add | Parallel `trade_pricing_rules` table | Locked OUT (D-04) — would force two query plans, two Filament Resources, two test suites. v1's `RuleResolver` remains the single retail path. |
| Customer-group on `users` | Denormalise per-request via Bitrix lookup | Locked OUT (D-08) — performance concern: rule resolution runs in hot order paths and can't afford a join-on-every-call. |
| Auto-discovered groups | Phase 5 competitor-table pattern | Locked OUT (D-02) — customer groups are sales-defined business entities, not data-discoverable; admin Filament CRUD is the right shape. |

**Installation:**

```bash
# ZERO new composer packages
# (verified by direct read of CONTEXT.md `<specifics>` + research/STACK.md scope-limit clause)
```

**Version verification:** N/A — no new packages. v1 packages already pinned in `composer.lock` and verified shipped by `.planning/STATE.md` (v1.50.1 shipped 2026-04-24, all 7 phases complete).

## Architecture Patterns

### Recommended Project Structure

```
app/Domain/TradePricing/                 # NEW Phase 9 domain (greenfield)
├── Models/
│   └── CustomerGroup.php                # id, slug, name, is_active, display_order, timestamps
├── Services/
│   ├── TradeRuleResolver.php            # Decorator over v1 RuleResolver
│   └── RoleToGroupMapper.php            # config('b2b.role_to_group_map') reader → customer_group_id
├── Listeners/
│   └── UpdateCustomerGroupOnUserRoleChange.php  # subscribed to v1 CustomerRegistered
├── Filament/
│   └── Resources/
│       ├── CustomerGroupResource.php
│       └── CustomerGroupResource/
│           └── Pages/
│               ├── ListCustomerGroups.php
│               ├── CreateCustomerGroup.php
│               └── EditCustomerGroup.php
└── Policies/
    └── CustomerGroupPolicy.php          # admin + pricing_manager CRUD; sales view-only

app/Domain/Pricing/                       # EXTENDED additively
├── Models/PricingRule.php               # +customer_group_id fillable + cast + LogsActivity column
├── Filament/Resources/PricingRuleResource.php  # +Select field + filter (additive edit only)
└── (everything else UNTOUCHED — RuleResolver / PriceCalculator / PriceRecomputer / SimulatedImpactCalculator)

app/Models/User.php                       # +customer_group_id fillable (cast bigint, nullable)

config/b2b.php                            # NEW — anonymous_display + role_to_group_map

database/migrations/                      # 3 new migrations, timestamps 2026_04_26_*
├── 2026_04_26_010000_create_customer_groups_table.php
├── 2026_04_26_010100_add_customer_group_id_to_pricing_rules_table.php
└── 2026_04_26_010200_add_customer_group_id_to_users_table.php

database/seeders/Phase9/                  # NEW seeder dir mirroring Phase3/, Phase4/
└── CustomerGroupSeeder.php               # Trade / Reseller / Education / NHS

tests/
├── Architecture/
│   ├── DeptracTradePricingLayerTest.php  # NEW — dual-YAML grep + 2 deptrac analyse exit-0 tests
│   ├── GoldenFixtureV1UnchangedTest.php  # NEW — blob-hash snapshot of v1 50 triples
│   └── PricingRuleExclusiveSetTest.php   # EXTENDED — adds customer_group_id NULL/non-null exclusivity
├── Unit/
│   ├── TradePricing/
│   │   ├── TradeRuleResolverTest.php     # NEW — 4-quadrant NULL matrix + 5-tier specificity
│   │   └── TradeRuleResolverPurityTest.php  # NEW — mirrors v1 RuleResolverPurityTest
│   └── Pricing/
│       └── GoldenFixtureV2TradeTest.php  # NEW — 30 new trade triples (extending Phase 3 ship gate shape)
└── Feature/
    └── TradePricing/
        └── CustomerGroupResourceTest.php # NEW — admin/pricing_manager CRUD + slug uniqueness

depfile.yaml                              # +TradePricing layer + ruleset entry (dual-YAML)
deptrac.yaml                              # +TradePricing layer + ruleset entry (dual-YAML)
```

### Pattern 1: Decorator over v1 RuleResolver (THE load-bearing pattern)

**What:** `TradeRuleResolver` wraps the v1 `RuleResolver` via constructor injection. When `$customerGroupId === null`, delegates verbatim to v1; otherwise queries customer-group-scoped rules first, falls through to v1 retail path on miss.

**When to use:** EVERY new caller introduced by Phase 9 + Phase 11 (Quote flow). v1 callers (`PriceRecomputer`, `SimulatedImpactCalculator`, `RuleExplorer`, `ComputeMarginSuggestionJob`, `CreateWooProductJob`) stay on the v1 `RuleResolver` directly — they don't know the customer group. This is intentional: those paths are the "retail" code path and must remain byte-identical.

**Example:**

```php
// Source: research/ARCHITECTURE.md §Seam 7 + CONTEXT.md D-03
namespace App\Domain\TradePricing\Services;

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Services\PricingResolution;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;

final class TradeRuleResolver
{
    public function __construct(
        private readonly RuleResolver $base,
    ) {}

    public function resolve(Product $product, ?int $customerGroupId = null): PricingResolution
    {
        // ── Retail fast-path: byte-identical to v1 ──────────────────────────
        if ($customerGroupId === null || $customerGroupId === 0) {
            return $this->base->resolve($product);
        }

        $brandId = $product->getPricingBrandId();
        $categoryId = $product->getPricingCategoryId();

        // ── Layer 1 — customer_group + brand + category (most specific) ─────
        if ($brandId !== null && $categoryId !== null) {
            $rule = PricingRule::query()
                ->where('customer_group_id', $customerGroupId)
                ->where('scope', PricingRule::SCOPE_BRAND_CATEGORY)
                ->where('active', true)
                ->where('brand_id', $brandId)
                ->where('category_id', $categoryId)
                ->orderByDesc('priority')->orderBy('id')
                ->first();
            if ($rule !== null) {
                return new PricingResolution(
                    marginBasisPoints: (int) $rule->margin_basis_points,
                    source: 'trade_brand_category',
                    matchedRuleId: (int) $rule->id,
                    overrideId: null,
                    chain: ['trade_brand_category'],
                );
            }
        }

        // ── Layer 2 — customer_group + brand ────────────────────────────────
        if ($brandId !== null) {
            // … (same shape, scope=BRAND, customer_group_id, brand_id) …
        }

        // ── Layer 3 — customer_group + category ─────────────────────────────
        // … (same shape, scope=CATEGORY) …

        // ── Layer 4 — customer_group only ───────────────────────────────────
        // … (default_tier rule scoped to group; tier_min/tier_max bound) …

        // ── Layer 5 — fall through to v1 retail (NO trade rule matched) ─────
        return $this->base->resolve($product);
    }
}
```

**Critical invariants** (every plan must enforce):
- `TradeRuleResolver` is the ONLY new resolver class; v1's `RuleResolver` is untouched.
- The 5-tier specificity walk parallels v1's 4-tier walk, with each layer constrained additionally by `customer_group_id = X`. The v1 4-tier order (override → brand_category → category → brand → default_tier) is preserved on the retail-fall-through path.
- ProductOverride layer (v1 Layer 0) stays the v1 winner: an override beats EVEN a customer-group rule. Rationale: overrides are admin-set per-product; if admin pinned a price, neither retail nor trade rule should override the override.
- Group-scoped rules' `priority` defaults to `base_priority + 100` so when a tied retail rule and trade rule could apply, the trade rule wins. (D-03; locked.)
- Chain string includes `trade_` prefix for any layer that matched on customer_group; this lets the `RuleExplorer` UI (Phase 3 page) badge the resolution source as trade vs retail without modification.

### Pattern 2: Listener-based extension of v1 customer events

**What:** v1 already ships `App\Domain\Webhooks\Events\CustomerRegistered` (fired from `WooWebhookController` when a Woo customer.created/customer.updated webhook arrives) and `App\Domain\CRM\Listeners\HandleCustomerRegistered` (subscribes, dispatches `PushCustomerToBitrixJob`). Phase 9 registers a NEW listener `App\Domain\TradePricing\Listeners\UpdateCustomerGroupOnUserRoleChange` on the same event, runs alongside the CRM listener (separate concern, no coupling).

**When to use:** Whenever a v1 event already exists and a v2 phase needs to react. Never modify v1 listeners.

**Example:**

```php
// Source: app/Domain/CRM/Listeners/HandleCustomerRegistered.php (v1 precedent)
// Phase 9 mirrors the shape one-to-one — different queue, different work.
namespace App\Domain\TradePricing\Listeners;

use App\Domain\TradePricing\Services\RoleToGroupMapper;
use App\Domain\Webhooks\Events\CustomerRegistered;
use App\Domain\Webhooks\Models\WebhookReceipt;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class UpdateCustomerGroupOnUserRoleChange implements ShouldQueue
{
    public string $queue = 'default';  // light DB write — not crm-bitrix

    public function __construct(
        private readonly RoleToGroupMapper $mapper,
    ) {}

    public function handle(CustomerRegistered $event): void
    {
        $receipt = WebhookReceipt::findOrFail($event->webhookReceiptId);
        $body = (array) ($receipt->raw_body ?? []);
        $email = (string) ($body['email'] ?? '');
        $role = (string) ($body['role'] ?? 'customer');

        if ($email === '') {
            Log::warning('UpdateCustomerGroupOnUserRoleChange: no email — skipping', [
                'webhook_receipt_id' => $receipt->id,
                'correlation_id' => $event->correlationId,
            ]);
            return;
        }

        $groupId = $this->mapper->resolve($role);  // null = retail (no row in customer_groups)
        $user = User::firstOrCreate(['email' => $email], ['name' => $body['display_name'] ?? $email]);

        if ($user->customer_group_id !== $groupId) {
            $user->forceFill(['customer_group_id' => $groupId])->save();
            Log::info('TradePricing: user customer_group updated', [
                'user_id' => $user->id,
                'old_group' => $user->getOriginal('customer_group_id'),
                'new_group' => $groupId,
                'role' => $role,
                'correlation_id' => $event->correlationId,
            ]);
        }
    }
}
```

### Pattern 3: Additive Filament Resource edit

**What:** Phase 3's `PricingRuleResource` is edited to ADD ONE Select field + ONE table filter. No restructure, no rename, no removal of existing fields.

**When to use:** Whenever an existing v1 Filament Resource needs a single new column surfaced. Never refactor Phase 3 / Phase 4 / Phase 5 / etc. UI shapes — Phase 9 is purely additive.

**Example:**

```php
// Insert as the FIRST form field in PricingRuleResource::form() (NEW field — keep at top so admin sees it before scope)
Select::make('customer_group_id')
    ->label('Customer Group')
    ->relationship('customerGroup', 'name')  // requires customerGroup() relation on PricingRule
    ->searchable()
    ->preload()
    ->placeholder('— Retail (default) —')
    ->nullable()
    ->helperText('Empty = retail rule (matches all customers without a group). Choose a group to make this a trade rule.'),

// Insert as a NEW filter alongside the existing TernaryFilter('active') and SelectFilter('scope')
SelectFilter::make('customer_group_id')
    ->label('Customer Group')
    ->relationship('customerGroup', 'name')
    ->placeholder('All groups + retail'),
```

The PricingRule model's new `customerGroup()` relation:

```php
// In PricingRule.php — added by Phase 9 migration plan
public function customerGroup(): BelongsTo
{
    return $this->belongsTo(\App\Domain\TradePricing\Models\CustomerGroup::class);
}
```

### Pattern 4: New Filament Resource (CustomerGroupResource)

**What:** Standard Filament Resource for the new `customer_groups` table — mirrors Phase 4's `CrmFieldMappingResource` and Phase 5's `CompetitorResource` shape (admin + pricing_manager CRUD; sales + read_only view-only).

**When to use:** Any new lookup table that ops needs to manage via UI.

```php
// Source: mirrors app/Domain/CRM/Filament/Resources/CrmFieldMappingResource.php pattern
namespace App\Domain\TradePricing\Filament\Resources;

use App\Domain\TradePricing\Filament\Resources\CustomerGroupResource\Pages;
use App\Domain\TradePricing\Models\CustomerGroup;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomerGroupResource extends Resource
{
    protected static ?string $model = CustomerGroup::class;
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Pricing';
    protected static ?int $navigationSort = 20;
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('slug')
                ->required()
                ->unique(ignoreRecord: true)
                ->alphaDash()
                ->helperText('lowercase-with-hyphens — used in role_to_group_map config and URL slugs'),
            TextInput::make('name')
                ->required()
                ->helperText('Display name shown in PricingRule Select + customer profile.'),
            TextInput::make('display_order')
                ->numeric()->default(100)
                ->helperText('Lower = higher in dropdowns.'),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('display_order')->label('#')->sortable(),
            TextColumn::make('slug')->fontFamily('mono')->sortable(),
            TextColumn::make('name')->searchable(),
            IconColumn::make('is_active')->boolean(),
            TextColumn::make('updated_at')->dateTime(),
        ])->defaultSort('display_order');
    }

    public static function getPages(): array { /* index/create/edit pages */ }
}
```

### Anti-Patterns to Avoid

- **Modifying `RuleResolver::resolve()` signature.** Adding `?int $customerGroupId = null` to v1's class would technically be backward-compatible at the PHP level, but breaks Phase 3's purity contract (`tests/Unit/Pricing/RuleResolverPurityTest.php`) which fingerprints the class shape. Locked OUT by D-03 + listener-based-extension invariant.
- **Adding `customer_group_id` to `pricing_rules` exclusive-set test as another exclusive column.** The exclusivity invariant (`PricingRuleExclusiveSetTest`) is about scope-vs-tier columns; `customer_group_id` is **orthogonal** to scope (every scope can have NULL or non-NULL group). The test must be EXTENDED to assert `customer_group_id` allows NULL on every row (no constraint), not that it's mutually exclusive with anything.
- **Re-baselining the 50-triple golden fixture.** Forbidden by D-05 + Pitfall I3. Phase 9 ADDS 30 new triples in the same file; never overwrites the v1 50.
- **Resolver path queries that use `==` instead of `<=>` for nullable column.** Pitfall B1 risk. The retail-fall-through MUST use the v1 `RuleResolver` directly (not a re-implementation that filters by `whereNull('customer_group_id')`) — that way NULL-vs-non-NULL semantics inherit unchanged.
- **Pushing trade prices into Woo directly.** Pitfall B2 risk. Maintain retail price as the canonical Woo product price; trade pricing applied via Cart/PDP filter that resolves at render time using `User->customer_group_id`. (v2.0 Woo integration is out of scope for Phase 9 — but Phase 9's `users.customer_group_id` column makes this future Woo-side filter trivial.)
- **Sync-on-every-resolve from Bitrix.** Pitfall — would explode N×M latency on bulk `pricing:recompute`. Locked OUT by D-08: denormalised on `users.customer_group_id`.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| LogsActivity for CustomerGroup | Custom audit table | `spatie/laravel-activitylog` `LogsActivity` trait | v1 already uses; PricingRule precedent shows exact pattern (logOnly + logOnlyDirty) |
| Slug generation | Custom slugify | `Str::slug($name)` only as form helper; admin sets slug manually with alphaDash validation | D-01 list is curated; ops will not auto-generate |
| Customer-role discovery | Custom Bitrix poll | Subscribe to v1's `CustomerRegistered` event (Phase 4) | Pattern 2 — listener-based extension is the v1 invariant |
| Role-to-group mapping config | Hardcoded match expression in listener | `config('b2b.role_to_group_map')` array, editable via Filament Settings page | D-07 — operator config-flippable without code change |
| Anonymous-display gating | Per-controller `if (auth()->guest())` checks | `config('b2b.anonymous_display')` central reader | D-06 — single env-flip is the operator-affordance |
| Deptrac layer tests | Stdout-grep style | Exit-code Symfony Process pattern from `DeptracAgentsLayerTest` | Phase 5/8 lesson — stdout grep is unreliable on Windows PHP |
| Filament policy regen | Manual `shield:generate` + restoration | `php artisan shield:safe-regenerate --allow-new=CustomerGroupPolicy` | Phase 8 ships this; mandatory per CONTEXT |
| Golden fixture v1 lock | New custom assert against current file | `GoldenFixtureV1UnchangedTest` snapshots blob hash from commit `a4d075f` | Pitfall I3 — belt-and-braces against drift |
| ProductOverride extension | Adding customer_group_id to overrides | DON'T — overrides remain product-scoped (D-08 anti-feature) | v1 invariant: override is admin-set per-product, beats every rule including trade |

**Key insight:** Phase 9 is the smallest stack delta of any v2 phase precisely because it builds on rails: every concern except the decorator service has a v1 pattern to copy. The discipline is "edit additively, never refactor v1, fall through to v1 on every miss path."

## Common Pitfalls

### Pitfall 1: Customer-group scope leaking into non-B2B price calculations (B1)

**What goes wrong:** The Phase 9 `customer_group_id` column on `pricing_rules` accidentally short-circuits a v1 retail price calculation. Storefront prices flip to 0 / fallback because a NULL group request fails to match a NULL group rule.

**Why it happens:** Resolver matching predicate uses `==` instead of `<=>` for nullable column; OR the wrapper's retail fall-through is implemented as `where('customer_group_id', null)` (which produces SQL `= NULL` — always false in MySQL) instead of delegating to v1's untouched RuleResolver.

**How to avoid:**
- The retail fall-through MUST be `return $this->base->resolve($product)` — NEVER a re-implementation that filters by `whereNull('customer_group_id')`. v1's RuleResolver doesn't reference `customer_group_id` at all (verified by direct read), so retail callers reach the unchanged Phase 3 path.
- Pest dataset test `it('all v1 callers reach base resolver bit-for-bit')` parameterised over 4 quadrants (`null` / `0` / non-existent group / explicit retail group).
- `GoldenFixtureV1UnchangedTest` snapshots commit `a4d075f` blob hash and fails build if any v1 triple drifts.
- `PriceRecomputer` and `SimulatedImpactCalculator` and `ComputeMarginSuggestionJob` and `CreateWooProductJob` and `RuleExplorer` keep using `RuleResolver` directly (NOT `TradeRuleResolver`). Six call-sites are unchanged.

**Warning signs:**
- `pricing:recompute --dry-run` produces non-zero diff count for retail (NULL customer-group) products
- Storefront price flips to 0 / supplier list price (fallback ladder firing unexpectedly)
- `GoldenFixtureV1UnchangedTest` reports v1 triple drift

### Pitfall 2: Trade customer sees retail price before login (B2)

**What goes wrong:** Trade customer browses anonymously, sees retail price; logs in, cart re-prices; confusion + abandonment.

**Why it happens:** Page-cache key doesn't include `customer_group_id`. Server-side render uses retail price; client-side JS doesn't know about the user's group.

**How to avoid:**
- `config('b2b.anonymous_display')` enum: `retail` (default) or `hidden`. Operator-configurable via env. Ship default = `retail` per D-06.
- Visible badge on PDP for trade customers: "Your Trade Gold price · was £4,999 retail" — trust-building.
- Cart cache key MUST include `customer_group_id` hash. (Future Woo-side filter; out of v2.0 Phase 9 scope but the `users.customer_group_id` column unlocks this.)
- Pest test: anonymous viewer never sees `priority+100`-discounted price; logged-in trade viewer sees their tier's price.

**Warning signs:**
- Cart abandonment climbs after Phase 9 deploy
- Customer service tickets "I logged in and the price changed"

### Pitfall 3: ProductOverride bypassed by Trade rule (B3 — invariant)

**What goes wrong:** A trade rule with `priority+100` outranks a `ProductOverride` because the resolver checks rules before override.

**Why it happens:** Wrong layer order in `TradeRuleResolver::resolve()`. v1's invariant is: ProductOverride is Layer 0, beats every rule.

**How to avoid:**
- The retail fall-through MUST be `return $this->base->resolve($product)` — and `RuleResolver::resolve()` ALREADY checks ProductOverride first. As long as `TradeRuleResolver`'s 5 trade-layer queries don't return on a positive ProductOverride match, the override layer is reached.
- BUT: Phase 9 must add an explicit Layer 0 check at the top of `TradeRuleResolver::resolve()` BEFORE the trade layers run. Otherwise a customer with both a ProductOverride AND a trade-group rule gets the trade rule (wrong).

**Implementation:**

```php
public function resolve(Product $product, ?int $customerGroupId = null): PricingResolution
{
    // ── Layer 0 — ProductOverride still beats EVERYTHING (v1 invariant preserved) ──
    $override = ProductOverride::query()->where('product_id', $product->id)->first();
    if ($override !== null) {
        return new PricingResolution(
            marginBasisPoints: (int) $override->margin_basis_points,
            source: 'override',
            matchedRuleId: null,
            overrideId: (int) $override->id,
            chain: ['override'],
        );
    }

    // ── Retail fast-path ───────────────────────────────────────────────────
    if ($customerGroupId === null || $customerGroupId === 0) {
        return $this->base->resolve($product);  // v1 will re-check override (idempotent) then walk rules
    }

    // ── Trade layers 1..4 (group-scoped) ──────────────────────────────────
    // … (queries above) …

    // ── Layer 5 fall-through to v1 retail ─────────────────────────────────
    return $this->base->resolve($product);
}
```

**Pest assertion:**
```php
it('ProductOverride beats Trade rule even with priority+100', function () {
    $product = Product::factory()->create();
    ProductOverride::factory()->create(['product_id' => $product->id, 'margin_basis_points' => 1500]);
    PricingRule::factory()->create([
        'customer_group_id' => $this->tradeGroup->id,
        'scope' => 'brand',
        'brand_id' => $product->brand_id,
        'margin_basis_points' => 5000,
        'priority' => 200,  // priority+100 over default
    ]);
    $resolution = app(TradeRuleResolver::class)->resolve($product, $this->tradeGroup->id);
    expect($resolution->source)->toBe('override');
    expect($resolution->marginBasisPoints)->toBe(1500);
});
```

### Pitfall 4: Idempotency on UpdateCustomerGroupOnUserRoleChange listener

**What goes wrong:** Woo fires customer.updated webhook 3 times for the same edit (network retry); listener fires 3 times; user.customer_group_id flaps if Bitrix-side update lands between two Woo events.

**Why it happens:** The listener writes `User->customer_group_id` unconditionally, no compare-and-swap.

**How to avoid:**
- Compare current group vs new group; only `save()` on diff (Pattern 2 example shows `if ($user->customer_group_id !== $groupId)`).
- Never fire `PricingRuleChanged` from this listener — it's a customer-group denormalisation, not a rule change. (Already guaranteed by separation; documenting as invariant.)
- Pest test: dispatch the listener twice in a row with identical input; assert only one DB write.

### Pitfall 5: Dual-YAML Deptrac drift (D1 — recurring)

**What goes wrong:** TradePricing layer added to `deptrac.yaml` only; `depfile.yaml` silently stale. Tests pass, CI green. A cross-domain leak slips through later because dual-YAML is desynced.

**Why it happens:** Phase 5 lesson — both files MUST be updated in lockstep.

**How to avoid:**
- Plan 09-01 ships dual-YAML for the new layer.
- `DeptracTradePricingLayerTest` (mirrors `DeptracAgentsLayerTest` shape exactly) parses BOTH YAML files and asserts:
  - Both have `TradePricing` layer with `directory regex: app/Domain/TradePricing/.*`
  - Both have `TradePricing: [Foundation, Pricing, Products]` ruleset
  - Both EXCLUDE Sync, CRM, Webhooks, Cutover, Marketing, Agents, Channels, Quotes from TradePricing's allow-list
  - Both deptrac analyse runs exit-code 0 against the new layer

### Pitfall 6: Shield regen overwrites CustomerGroupPolicy (D2 — recurring)

**What goes wrong:** Plan 09-04 adds `CustomerGroupResource` to Filament. Dev runs `php artisan shield:generate --all` to scaffold the new permissions. Shield 3.9.10 writes a policy stub for `CustomerGroupPolicy` AND clobbers the hand-written `PricingRulePolicy` next to it. Production deploy breaks pricing-manager auth.

**Why it happens:** Shield's `generate --all` regenerates ALL policies, not just the new one.

**How to avoid:**
- Plan 09-04 uses `php artisan shield:safe-regenerate --allow-new=CustomerGroupPolicy` (Phase 8 ships this command — direct read of `app/Domain/Agents/Console/Commands/ShieldSafeRegenerateCommand.php`).
- The `--allow-new` flag lets `CustomerGroupPolicy` be a fresh Shield-generated stub on first run; subsequent runs (after committing the hand-written body) drop the flag.
- `PolicyTemplateIntegrityTest` (Phase 1 + Phase 5 + Phase 8 floor bumps) catches `{{ Placeholder }}` literal regressions on every CI run.

### Pitfall 7: Filament Select dropdown ordering drift

**What goes wrong:** New customer groups added by ops appear at the top of the Select, pushing established groups (Trade, Reseller) below the fold.

**How to avoid:**
- `customer_groups.display_order` column with default 100; smaller values sort to top.
- `CustomerGroupResource` defaults sort by `display_order ASC`.
- Seeder pre-assigns: trade=10, reseller=20, education=30, nhs=40 (ops can renumber).

### Pitfall 8: Migration timestamp collision

**What goes wrong:** Plan 09-01 tries to use `2026_04_25_*` (matches Phase 8 final timestamp); Laravel migration runner picks up Phase 9 migrations BEFORE Phase 8 final migration if they sort earlier.

**How to avoid:**
- All Phase 9 migrations start `2026_04_26_*` (CONTEXT.md locked timestamp; verified by Phase 8's last migration `2026_04_25_010200_add_receives_agent_alerts_to_alert_recipients_table.php`).
- Three migrations: `010000` (customer_groups), `010100` (add column to pricing_rules), `010200` (add column to users).

## Code Examples

Verified patterns from this codebase:

### `customer_groups` migration

```php
// Source: pattern mirrors app/database/migrations/2026_04_19_090000_create_pricing_rules_table.php
// File: database/migrations/2026_04_26_010000_create_customer_groups_table.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 64)->unique();
            $t->string('name', 128);
            $t->boolean('is_active')->default(true)->index();
            $t->unsignedSmallInteger('display_order')->default(100)->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_groups');
    }
};
```

### Add `customer_group_id` to `pricing_rules`

```php
// File: database/migrations/2026_04_26_010100_add_customer_group_id_to_pricing_rules_table.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_rules', function (Blueprint $t) {
            $t->foreignId('customer_group_id')
                ->nullable()
                ->after('scope')  // visually adjacent to other rule-shape columns
                ->constrained('customer_groups')
                ->restrictOnDelete();  // FK ON DELETE RESTRICT — D-04
            $t->index(
                ['customer_group_id', 'brand_id', 'category_id'],
                'pricing_rules_group_brand_category_idx',  // resolver query path
            );
        });
    }

    public function down(): void
    {
        Schema::table('pricing_rules', function (Blueprint $t) {
            $t->dropIndex('pricing_rules_group_brand_category_idx');
            $t->dropConstrainedForeignId('customer_group_id');
        });
    }
};
```

### Add `customer_group_id` to `users`

```php
// File: database/migrations/2026_04_26_010200_add_customer_group_id_to_users_table.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('customer_group_id')
                ->nullable()
                ->after('email')
                ->constrained('customer_groups')
                ->nullOnDelete();  // soft-fail on group deletion (vs RESTRICT on pricing_rules)
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('customer_group_id');
        });
    }
};
```

### `CustomerGroup` Eloquent model

```php
// Source: mirrors app/Domain/Pricing/Models/PricingRule.php LogsActivity shape
namespace App\Domain\TradePricing\Models;

use Database\Factories\Domain\TradePricing\CustomerGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class CustomerGroup extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = ['slug', 'name', 'is_active', 'display_order'];

    protected $casts = [
        'is_active' => 'bool',
        'display_order' => 'integer',
    ];

    public function pricingRules(): HasMany
    {
        return $this->hasMany(\App\Domain\Pricing\Models\PricingRule::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['slug', 'name', 'is_active', 'display_order'])
            ->logOnlyDirty();
    }

    protected static function newFactory(): CustomerGroupFactory
    {
        return CustomerGroupFactory::new();
    }
}
```

### `config/b2b.php`

```php
// Source: pattern mirrors config/pricing.php
declare(strict_types=1);

return [

    // D-06 — anonymous-user display posture. 'retail' (default) shows retail
    // prices to logged-out browsers; trade customers see their group price
    // after authenticated login. Operator can flip to 'hidden' via env to
    // show "Login to see trade pricing" instead.
    'anonymous_display' => env('B2B_ANONYMOUS_DISPLAY', 'retail'),

    // D-07 — Woo user role → customer_groups.slug mapping. Listener
    // UpdateCustomerGroupOnUserRoleChange reads this on every customer
    // webhook to denormalise users.customer_group_id. Unrecognised roles
    // (or 'customer' default) → null = retail. Editable via Filament
    // Settings page (admin-only).
    'role_to_group_map' => [
        'wholesale_customer' => 'trade',
        'wholesale_b2b'      => 'reseller',
        'edu_customer'       => 'education',
        'nhs_customer'       => 'nhs',
    ],

];
```

### `CustomerGroupSeeder`

```php
// Source: pattern mirrors database/seeders/Phase3/DefaultPricingTierSeeder.php
namespace Database\Seeders\Phase9;

use App\Domain\TradePricing\Models\CustomerGroup;
use Illuminate\Database\Seeder;

class CustomerGroupSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ['slug' => 'trade',     'name' => 'Trade Customer',          'display_order' => 10],
            ['slug' => 'reseller',  'name' => 'Reseller / Distributor',  'display_order' => 20],
            ['slug' => 'education', 'name' => 'Education Sector',        'display_order' => 30],
            ['slug' => 'nhs',       'name' => 'NHS / Healthcare',        'display_order' => 40],
        ];

        foreach ($groups as $row) {
            CustomerGroup::firstOrCreate(
                ['slug' => $row['slug']],  // idempotency key
                $row + ['is_active' => true],
            );
        }

        $this->command?->info(sprintf(
            'CustomerGroupSeeder: %d groups present (4 expected)',
            CustomerGroup::count(),
        ));
    }
}
```

### `GoldenFixtureV1UnchangedTest` (regression guard)

```php
// Source: NEW — Pattern from research/PITFALLS.md §Pitfall B1 + I3
// File: tests/Architecture/GoldenFixtureV1UnchangedTest.php
declare(strict_types=1);

it('v1 50-triple golden fixture is byte-identical to commit a4d075f', function (): void {
    $path = base_path('tests/Fixtures/Pricing/golden-fixtures.json');
    $raw = file_get_contents($path);
    $fixtures = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    // Phase 9 extends 50 → 80; v1 portion is the FIRST 50 entries (fx-001..fx-050).
    $v1 = array_slice($fixtures, 0, 50);

    // Snapshot: the v1 portion serialised back to JSON must hash to the
    // pre-Phase-9 blob hash. If anyone edits a v1 triple, this test trips.
    $v1Json = json_encode($v1, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $hash = hash('sha256', $v1Json);

    expect($hash)->toBe(
        // Captured by `git rev-parse HEAD:tests/Fixtures/Pricing/golden-fixtures.json`
        // BEFORE Phase 9 first edit lands. Plan 09-03 captures this as the first task.
        '<computed-by-plan-09-03-task-1>',
        'v1 50-triple golden fixture has drifted — Phase 3 ship gate broken. '.
        'Original triples MUST remain byte-identical (CONTEXT.md D-05).'
    );
});

it('total fixture count is 80 (50 v1 retail + 30 v2 trade)', function (): void {
    $path = base_path('tests/Fixtures/Pricing/golden-fixtures.json');
    $fixtures = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect($fixtures)->toHaveCount(80);
});
```

### `DeptracTradePricingLayerTest`

```php
// Source: mirrors tests/Architecture/DeptracAgentsLayerTest.php exactly
declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('TradePricing layer registered in BOTH depfile.yaml and deptrac.yaml', function (): void {
    foreach (['depfile.yaml', 'deptrac.yaml'] as $yamlPath) {
        $config = Yaml::parseFile(base_path($yamlPath));
        $params = $config['parameters'] ?? $config['deptrac'] ?? $config;
        $layerNames = array_column($params['layers'] ?? [], 'name');

        expect($layerNames)->toContain('TradePricing');

        $ruleset = $params['ruleset'] ?? [];
        expect($ruleset)->toHaveKey('TradePricing');

        $allowed = $ruleset['TradePricing'];
        expect($allowed)->toContain('Foundation');
        expect($allowed)->toContain('Pricing');
        expect($allowed)->toContain('Products');

        // Explicit denials per CONTEXT — TradePricing reads ONLY from Foundation+Pricing+Products.
        expect($allowed)->not->toContain('Sync');
        expect($allowed)->not->toContain('CRM');
        expect($allowed)->not->toContain('Webhooks');
        expect($allowed)->not->toContain('Cutover');
        expect($allowed)->not->toContain('Marketing');
        expect($allowed)->not->toContain('Agents');
        expect($allowed)->not->toContain('Channels');
        expect($allowed)->not->toContain('Quotes');
    }
});

it('deptrac analyse exits 0 against depfile.yaml (TradePricing clean)', function (): void {
    $entry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($entry)) test()->markTestSkipped('deptrac-shim not installed');
    $process = new Process(
        [PHP_BINARY, $entry, 'analyse', '--no-progress', '--config-file='.base_path('depfile.yaml')],
        base_path(),
    );
    $process->setTimeout(120);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});

// (mirror the same assertion against deptrac.yaml)
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| v1 Phase 3 RuleResolver: Product-only signature | Phase 9 wraps with TradeRuleResolver(Product, ?int) | This phase | Decorator preserves byte-identical retail path; trade adds 5-tier specificity |
| 50-triple golden fixture (Phase 3 ship gate) | 80-triple (50 v1 + 30 v2 trade) — original byte-identical | This phase | New `GoldenFixtureV1UnchangedTest` blob-hash snapshots v1 portion |
| Anonymous viewers see retail prices implicitly | `config('b2b.anonymous_display')` enum (retail/hidden) | This phase | Operator-flip via env; SEO-friendly default |
| Customer group is implicit | Denormalised `users.customer_group_id` FK column | This phase | Hot-path performance (no Bitrix join per resolve) |
| WP B2BKing/Wholesale Suite plugin (deferred v2.1) | NOT this phase — Woo storefront filter is out of scope | v2.1+ | `users.customer_group_id` unblocks the future Woo filter |

**Deprecated/outdated:**
- Hand-written shield restoration protocol (Phase 5 P5-F): replaced by `php artisan shield:safe-regenerate` (Phase 8 P8-05). Phase 9 uses the wrapper.
- Stdout-grep style Deptrac assertions (early Phase 5): replaced by exit-code Symfony Process pattern (Phase 7 + Phase 8). Phase 9 follows.
- Phase 3 fixture as "the" ship gate: replaced by the union of v1 50 + v2 30 = 80 triples; v1 portion still locked byte-identical.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Woo customer.created/updated webhook payload includes a `role` field — `[ASSUMED]` based on WooCommerce REST API standard. NOT verified by direct read of v1 webhook receipt sample data in this codebase. | Pattern 2 example | If `role` is at a different path (e.g. `meta_data[].role`), Phase 9 listener fails silently. Plan 09-04 first task: dump a sample `WebhookReceipt.raw_body` from the test DB and confirm payload shape. |
| A2 | The blob hash for `tests/Fixtures/Pricing/golden-fixtures.json` at HEAD (commit `a4d075f` per CONTEXT) can be captured before Phase 9's first fixture edit lands — `[ASSUMED]` | `GoldenFixtureV1UnchangedTest` | Must be computed in Plan 09-03 task 1 BEFORE the new 30 triples are appended. Otherwise the snapshot already includes drift. |
| A3 | v1 has no Filament Settings page yet for free-form key:value mappings — `[ASSUMED]` based on `app/Filament/` directory listing. The `b2b.role_to_group_map` editable-via-Filament-Settings line in CONTEXT D-07 may need a new ad-hoc Filament page rather than reusing existing infra. | Pattern 2 + D-07 | Plan 09-04 could need a small Settings page (Filament Page, not Resource) for editing the map. Alternative: leave as env-only for v2.0; Filament UI deferred to v2.1. RECOMMENDATION: env-only for v2.0; document deferral in CONTEXT. |
| A4 | v1's `User` model has no auth_provider column suggesting Woo SSO is wired — `[VERIFIED]` by direct read of `app/Models/User.php` (vanilla Breeze) and `0001_01_01_000000_create_users_table.php`. v1 `users` table has only `id, name, email, email_verified_at, password, remember_token, timestamps`. No backfill from Woo data exists today. | §Open Questions | Phase 9 ships the migration; v1 cutover ops must populate `users.customer_group_id` for any pre-existing customers. RECOMMENDATION: Plan 09-04 ships an idempotent backfill artisan command `b2b:backfill-customer-groups --dry-run` that walks Bitrix Contacts (or Woo customers via API) and seeds `users.customer_group_id` from role mappings. |
| A5 | Phase 5's `PricingRuleChanged` event fires automatically when `customer_group_id` column changes — `[ASSUMED]` based on observer code reading `margin_basis_points` dirty state ONLY. Direct read of `PricingRuleObserver.php` confirms: observer fires `PricingRuleChanged` ONLY when `margin_basis_points` is dirty. Phase 5's event signature is `(ruleId, oldMarginBps, newMarginBps)` — no customer_group_id payload. | §Integration Points | Adding `customer_group_id` to a rule does NOT fire the v5 recompute pipeline. INTENTIONAL — adding a trade rule shouldn't recompute retail prices. But Phase 9 plan must DOCUMENT this as a deliberate design choice (changing a trade rule's margin DOES still fire PricingRuleChanged because margin_basis_points is the dirty trigger, regardless of which group the rule belongs to). |
| A6 | `customer_groups` table seeded by `CustomerGroupSeeder` will be picked up by `DatabaseSeeder` automatically — `[ASSUMED]`. Phase 3 / 4 / 5 / 6 / 7 / 8 patterns each register their seeders in `DatabaseSeeder`. Plan 09-01 must register Phase9 in DatabaseSeeder. | Standard Stack | Plan 09-01 must include `DatabaseSeeder` edit; otherwise tests using `migrate:fresh --seed` won't have customer_groups rows. |
| A7 | Phase 9's `TradePricing` Deptrac layer can read from `Pricing` (which itself depends on `Sync` per current allow-list) without transitive violation — `[ASSUMED]`. Direct read of depfile.yaml: `Pricing: [Foundation, Products, Sync, WpDirectDb]`. Deptrac allow-list rules are non-transitive (TradePricing reading Pricing does NOT mean TradePricing can reach Sync directly). Verified safe. | §Updated Deptrac Allow-Lists | None — confirmed safe by Deptrac semantics. |
| A8 | The `Suggestion` table has columns Phase 9 does NOT need — `[VERIFIED]`. Phase 9 produces zero new Suggestion kinds. Trade rule changes flow through `PricingRuleChanged` (existing), customer-group sync flows through listener (existing CustomerRegistered event), and admin CRUD on customer_groups flows through normal Filament policies. No new suggestion kind needed. | §Integration Points | None. |

## Open Questions

1. **Where does Woo customer-role data come from in this repo?**
   - What we know: v1 `WooWebhookController` dispatches `CustomerRegistered` event when a customer.created/customer.updated webhook arrives. v1 stores raw_body on `WebhookReceipt`. Phase 4's `HandleCustomerRegistered` extracts topic from `x-wc-webhook-topic` header.
   - What's unclear: The exact payload shape of Woo's customer webhook — specifically whether `role` is a top-level field or under `meta_data[]`, and whether there's a single `role` value or an array (`roles[]` like WP-side).
   - Recommendation: Plan 09-04 task 1 dumps a sample `WebhookReceipt.raw_body` from the test DB or staging, confirms shape, and updates the listener accordingly. If staging has no real customer.created webhooks yet (likely — v1 cutover is parallel), use a fixture from Woo REST API docs or the Phase 4 test data.

2. **How does v1 cutover ops backfill `users.customer_group_id` for existing customers?**
   - What we know: v1 cutover is ops-executed in parallel with v2 dev (per `.planning/STATE.md`). v1 `users` table has zero customer-group data today (verified by reading the migration).
   - What's unclear: Whether ops will seed `users` from Woo customer export, OR Phase 9 ships a backfill command, OR users sync into the table only as new customer.created webhooks arrive.
   - Recommendation: Phase 9 ships an idempotent `b2b:backfill-customer-groups --dry-run` artisan command (Phase 4 `bitrix:backfill-orders` precedent — dry-run-default per v1 convention). Operator runs once on cutover day after seeding customer_groups. Add to Plan 09-04.

3. **Should the `b2b.role_to_group_map` be editable via Filament UI in v2.0, or env-only?**
   - What we know: CONTEXT D-07 says "Mapping editable via Filament Settings page (admin-only) with `key:value` form."
   - What's unclear: v1 has no existing Filament Settings page abstraction in this codebase (verified — `app/Filament/` has no Pages/Settings). Adding one for a single config map is overkill.
   - Recommendation: Defer Filament Settings UI to v2.1. v2.0 ships env-only with the map in `config/b2b.php`. Document the deferral in 09-CONTEXT.md (already documented as Claude's discretion D-07; need to clarify scope in 09-VERIFICATION.md). RECOMMENDATION: env-only for v2.0; Filament Settings page deferred.

4. **How does the `PricingRuleResource` form's `customer_group_id` field interact with the existing scope toggle?**
   - What we know: Phase 3's form uses `reactive()` on the `scope` Select to show/hide `brand_id` / `category_id` / `is_default_tier` / tier columns. The new `customer_group_id` Select is orthogonal (every scope can have a customer_group_id or not).
   - What's unclear: Whether Phase 9 should add a UI hint when admin pairs a default_tier scope with a customer_group_id (rare — usually trade rules are brand or category scoped; default_tier-per-group means "the entire trade group gets X% margin on everything"). Possibly desired (NHS gets 30% off everything), possibly a footgun (admin meant to pick brand_category but forgot).
   - Recommendation: No special validation; document as intentional. NHS/Education customers absolutely could have a flat-rate group rule. If ops finds it confusing, add a helper text in v2.1.

5. **Does `PricingRuleExclusiveSetTest` need a new branch for `customer_group_id`?**
   - What we know: The test asserts the exclusive-set invariant on (is_default_tier, brand_id, category_id, tier_min/tier_max).
   - What's unclear: Whether `customer_group_id` is part of any exclusive set. PER ANALYSIS: It is NOT — every existing scope shape (brand, category, brand_category, default_tier) can be combined with any value of customer_group_id. The exclusivity is about scope-vs-tier, not about who.
   - Recommendation: Plan 09-01 EXTENDS the test ONLY to assert that `customer_group_id` is nullable for every scope (positive test: a brand_category rule with `customer_group_id = trade.id` is valid; a brand_category rule with `customer_group_id = null` is also valid). The test should NOT assert any new exclusivity.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| MySQL 8.0+ | All migrations + tests requiring `meetingstore_ops_testing` DB | ✓ (per Phase 1 P03 lesson; assumed by all v2 phases) | Operator-managed | Defer Feature tests to manual smoke (Phase 6/7/8 precedent) |
| Composer (PHP) | No new packages — N/A | ✓ | 8.2+ | — |
| `vendor/qossmic/deptrac-shim` | DeptracTradePricingLayerTest | ✓ (already installed; Phase 5+) | shipped via composer.lock | `markTestSkipped` if not present (Phase 8 precedent) |
| `php artisan shield:safe-regenerate` | Plan 09-04 RBAC step | ✓ (Phase 8 ships at `app/Domain/Agents/Console/Commands/ShieldSafeRegenerateCommand.php`) | Phase 8 v1.0 | Manual P5-F restoration (Phase 5 protocol) |
| Existing v1 Eloquent / Filament / Spatie packages | Everything | ✓ (verified by `composer.lock` v1.50.1 ship) | — | — |

**Missing dependencies with no fallback:** None.

**Missing dependencies with fallback:** None — Phase 9 is the smallest stack delta of any v2 phase.

## Security Domain

> Note: `.planning/config.json` does not include `security_enforcement` flag; defaulting to enabled.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes (extends) | Spatie Permission v6 + Filament Shield — existing v1 |
| V3 Session Management | no (no new session-bearing surface) | — |
| V4 Access Control | yes | `CustomerGroupPolicy` (admin + pricing_manager CRUD; sales view-only); existing `PricingRulePolicy` reused unchanged |
| V5 Input Validation | yes | Filament's built-in (slug `alphaDash` + `unique` validation; numeric on customer_group_id Select); migration FK constraints |
| V6 Cryptography | no (no new secrets stored) | — |

### Known Threat Patterns for Laravel + Filament + Eloquent

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| SQL injection on customer_group_id resolver query | Tampering | Parameterised Eloquent (`->where('customer_group_id', $customerGroupId)` is bound) |
| Mass assignment on User customer_group_id | Tampering | `User::$fillable` controls — must EXPLICITLY add 'customer_group_id' to fillable; otherwise admin can't be set via web |
| Privilege escalation: sales role edits customer_groups | Elevation of Privilege | `CustomerGroupPolicy` denies non-admin/non-pricing_manager updates |
| FK violation on group deletion | DoS | `pricing_rules.customer_group_id` ON DELETE RESTRICT (D-04) — Filament shows actionable error rather than silent NULL revert |
| Trade-pricing leak to anonymous viewer | Information Disclosure | `config('b2b.anonymous_display')` (D-06) + cart cache key including customer_group_id (deferred Woo-side) |
| Mismatched Woo role → wrong customer group | Tampering | Listener compare-and-swap pattern (Pitfall 4); fall-through to retail (null) on unknown role |

## Validation Architecture

> `.planning/config.json` has `workflow.nyquist_validation: false` — Validation Architecture section SKIPPED per RESEARCH.md template instructions.

## Sources

### Primary (HIGH confidence — verified by direct file reads)

- `.planning/phases/09-e1-trade-customer-pricing/09-CONTEXT.md` — 11 D-01..D-11 locked decisions
- `.planning/REQUIREMENTS.md` — TRDE-01..06 contract definitions
- `.planning/STATE.md` — current milestone (v2.0 active; Phase 8 verifying; Phase 9 next)
- `.planning/ROADMAP.md` — Phase 9 5 success criteria
- `.planning/research/ARCHITECTURE.md` §TradePricing — decorator pattern + 5-tier specificity sort + Schema Decision
- `.planning/research/PITFALLS.md` §B1-B5 — 4 critical pricing pitfalls (NULL handling, anonymous display, idempotency, override layering)
- `.planning/research/SUMMARY.md` — Phase 9 placement in build order; cross-cutting invariants
- `.planning/research/STACK.md` — net-zero composer-package addition for Phase 9
- `.planning/research/FEATURES.md` §E1 — trade-pricing differentiators + anti-features + 9-tier resolution chain reasoning
- `.planning/milestones/v1.50.1-ROADMAP.md` §Phase 3 — RuleResolver + PriceCalculator design + 50-triple ship gate definition
- `app/Domain/Pricing/Services/RuleResolver.php` — v1 service Phase 9 wraps (signature confirmed: `resolve(Product): PricingResolution`, no customer-group awareness)
- `app/Domain/Pricing/Services/PriceCalculator.php` — v1 VAT-inclusive integer-pennies calculator (REUSE unchanged)
- `app/Domain/Pricing/Services/PricingResolution.php` — Phase 9 reuses this DTO; chain[] field accepts new `trade_*` source strings
- `app/Domain/Pricing/Services/PriceRecomputer.php` — confirmed: keeps using v1 RuleResolver directly (NOT TradeRuleResolver) — this is the retail recompute path
- `app/Domain/Pricing/Services/SimulatedImpactCalculator.php` — confirmed: keeps using v1 RuleResolver directly
- `app/Domain/Pricing/Models/PricingRule.php` — Phase 9 adds `customer_group_id` to `$fillable` and `$casts`; LogsActivity column list extended
- `app/Domain/Pricing/Models/ProductOverride.php` — confirmed unchanged in Phase 9; override layering preserved
- `app/Domain/Pricing/Filament/Resources/PricingRuleResource.php` — Phase 3 base for additive edit (1 Select + 1 Filter)
- `app/Domain/Pricing/Policies/PricingRulePolicy.php` — confirmed unchanged (existing role matrix admin/pricing_manager/sales/read_only sufficient)
- `app/Domain/Pricing/Events/PricingRuleChanged.php` — confirmed: Phase 5 backport; fires on `margin_basis_points` dirty only (NOT customer_group_id)
- `app/Domain/Pricing/Listeners/RecomputePriceListener.php` — confirmed: subscribes to SupplierPriceChanged (unrelated)
- `app/Domain/Pricing/Observers/PricingRuleObserver.php` — confirmed: fires PricingRuleChanged on margin dirty only
- `app/Domain/Webhooks/Events/CustomerRegistered.php` — confirmed: v1 event Phase 9 listener subscribes to
- `app/Domain/CRM/Listeners/HandleCustomerRegistered.php` — pattern for Phase 9 `UpdateCustomerGroupOnUserRoleChange` listener (queue, signature, error handling)
- `app/Models/User.php` — confirmed: vanilla Breeze; no `customer_group_id` column today
- `app/Domain/Agents/Console/Commands/ShieldSafeRegenerateCommand.php` — Phase 8 wrapper Phase 9 uses for `CustomerGroupPolicy` regen
- `app/Domain/Agents/Filament/Resources/AgentRunResource.php` — pattern for new `CustomerGroupResource`
- `app/Providers/AppServiceProvider.php` — singleton bindings (Phase 9 likely binds TradeRuleResolver as singleton mirroring PriceRecomputer pattern)
- `tests/Architecture/PricingRuleExclusiveSetTest.php` — Phase 9 extends with positive test for nullable customer_group_id on every scope
- `tests/Architecture/DeptracAgentsLayerTest.php` — pattern for `DeptracTradePricingLayerTest`
- `tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php` — Phase 9's `GoldenFixtureV2TradeTest` mirrors this test's dataset shape
- `tests/Fixtures/Pricing/golden-fixtures.json` — verified: 50 entries (line count 452 / fx-001..fx-050); Phase 9 appends 30 more
- `depfile.yaml` + `deptrac.yaml` — confirmed dual-YAML in lockstep through Phase 8; Phase 9 maintains
- `database/migrations/0001_01_01_000000_create_users_table.php` — confirmed: `users` table has no customer_group_id today
- `database/migrations/2026_04_19_090000_create_pricing_rules_table.php` — Phase 3 base; Phase 9 ALTERs additively
- `database/seeders/Phase3/DefaultPricingTierSeeder.php` — pattern for Phase 9 `CustomerGroupSeeder` (firstOrCreate idempotency)
- `config/pricing.php` — pattern for new `config/b2b.php`
- `app/Domain/Competitor/Jobs/ComputeMarginSuggestionJob.php` — confirmed: uses RuleResolver directly (retail-only resolve path; out of Phase 9 scope)
- `app/Domain/ProductAutoCreate/Jobs/CreateWooProductJob.php` — confirmed: uses RuleResolver directly (auto-create new SKU is retail by definition)
- `app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php` — confirmed: uses RuleResolver directly via `app(RuleResolver::class)` (UI is admin tool — no customer context)

### Secondary (MEDIUM confidence)

- WooCommerce REST API customer payload shape — assumed standard `role` field per WC docs; needs verification against real WebhookReceipt sample (Open Question 1)
- Filament Settings page abstraction — not present in v1 codebase; defer to v2.1 (Open Question 3)

### Tertiary (LOW confidence)

- None — Phase 9 builds entirely on verified v1 patterns.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — zero new packages, every reused dependency verified by `composer.lock` and direct file reads
- Architecture: HIGH — decorator pattern signature confirmed by reading `RuleResolver.php` (no customer-group args, clean superset for Phase 9 wrapper)
- Pitfalls: HIGH — all 8 pitfalls map to either Phase 5 lessons (D1, D2), Pitfalls research (B1-B5), or invariants verified by direct code reads
- Code examples: HIGH — every snippet derived from a verified v1 pattern (`PricingRule` model, Phase 3 migrations, `HandleCustomerRegistered` listener, `DeptracAgentsLayerTest`, etc.)
- Open questions: HIGH that the questions are correctly identified; resolution pending Plan 09-04 sample-payload dump (Q1) and operator decision on backfill mechanism (Q2)

**Research date:** 2026-04-25
**Valid until:** 2026-05-25 (30 days — stable; Phase 9 builds on shipped v1 patterns + locked Phase 8 framework, neither of which moves)
