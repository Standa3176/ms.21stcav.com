---
phase: 09-e1-trade-customer-pricing
plan: 05
subsystem: trade-pricing
tags: [trade-pricing, filament, customer-group-resource, pricing-rule-resource-additive, customer-group-policy, role-permission-seeder, w-05-v1-parity, i-01-nav-sort, d-09-additive, d-10-rbac, pest, architecture-tests]

requires:
  - phase: 09-01
    provides: customer_groups table + CustomerGroup model + factory + Deptrac TradePricing layer (with Http allow-list extension for Plan 09-05)
  - phase: 09-02
    provides: TradeRuleResolver (downstream consumer of customer_group_id Select results)
  - phase: 09-04
    provides: users.customer_group_id FK + CustomerGroupPolicy permission targets + 4 seeded groups
  - phase: 03-pricing-engine
    provides: PricingRuleResource Filament code (additive edit target — UNTOUCHED at form/filter shape, only INSERTS allowed)
  - phase: 08-c4-agent-framework
    provides: shield:safe-regenerate wrapper (intent — pre-existing --force bug logged as deferred-item)

provides:
  - "App\\Domain\\TradePricing\\Filament\\Resources\\CustomerGroupResource — admin/pricing_manager CRUD; sales/read_only gated by Policy; Pricing nav group with $navigationSort=11 (PricingRuleResource is at 10 — I-01 distinct)"
  - "3 Page classes (ListCustomerGroups, CreateCustomerGroup, EditCustomerGroup) with ->authorize() guarded header actions (Warning 9 defence-in-depth)"
  - "App\\Domain\\TradePricing\\Policies\\CustomerGroupPolicy — 5 methods (viewAny/view/create/update/delete) gated on $user->can('*_customer_group') strings"
  - "AppServiceProvider Gate::policy(CustomerGroup::class, CustomerGroupPolicy::class) registration alongside Phase 8 AgentRunPolicy"
  - "PricingRuleResource additive edit (D-09): customer_group_id Select inserted as the FIRST form field (before scope) + customer_group_id SelectFilter appended alongside existing TernaryFilter('active') and SelectFilter('scope') — NO existing field removed/renamed; Phase 3 reactive() scope behaviour intact"
  - "RolePermissionSeeder extension: 5 customer_group_* perms scaffolded via Permission::firstOrCreate (mirrors Phase 8 AgentRunResource pattern); admin/pricing_manager get all 5 (admin via Permission::all() sync; pricing_manager via givePermissionTo); sales gets view_any+view only; read_only locked out via step 4b revoke (D-10 matrix)"
  - "tests/Architecture/CustomerGroupResourceNavigationSortTest — 2 tests (3 assertions, runs offline) — I-01 reflection invariant"
  - "tests/Architecture/PricingRuleResourceAdditiveInvariantTest — 4 tests (29 assertions total with the nav-sort file, runs offline) — D-09 source-grep invariant + W-05 documentation lock"
  - "tests/Feature/TradePricing/CustomerGroupResourceTest — 8 it() blocks (CRUD + RBAC + slug uniqueness + alphaDash + FK ON DELETE RESTRICT) — execution deferred to MySQL-online per Phase 6/7/8 + Plans 09-01..04 precedent"
  - "tests/Feature/TradePricing/PricingRuleResourceCustomerGroupFieldTest — 6 it() blocks (retail+trade rule create + SelectFilter + RBAC matrix + Policy gates) — execution deferred"

affects: [09-06-verification, phase-11-quote-flow]

tech-stack:
  added: []  # zero composer changes — Plan 9-05 is pure Filament UX + Policy + seeder additions
  patterns:
    - "Additive Filament Resource extension (D-09 pattern): one Select inserted at TOP of form schema; one SelectFilter appended to filters list; existing fields/columns/filters/actions preserved unchanged. T-09-05-04 mitigation locked on CI by Architecture source-grep."
    - "I-01 navigationSort distinct invariant (Plan 05 vs Phase 3): reflection-based architecture test asserts CustomerGroupResource::$navigationSort != PricingRuleResource::$navigationSort. Bumping either to the same value fails CI offline."
    - "W-05 v1-parity documented: RolePermissionSeeder findByName brittleness is accepted as v1-parity — silent role-permission drift is worse than fail-fast Throwable on seed. Architecture test asserts the W-05 comment + findByName usage are both present in the seeder."
    - "Spatie permission-string-based Policy (vs Phase 3 hasRole-based): CustomerGroupPolicy gates on $user->can('*_customer_group') strings instead of hasAnyRole(). Tighter coupling to RolePermissionSeeder than Phase 3 PricingRulePolicy — D-10 matrix is permission-driven, not role-driven."
    - "Filament policy auto-resolution + explicit Gate::policy: CustomerGroupResource is detected by Filament via the namespace convention (Models\\X => Policies\\XPolicy). The explicit Gate::policy registration in AppServiceProvider closes any namespace-resolution edge case (matches Phase 4/5/6/7/8 precedent for every domain policy)."
    - "Feature-test/Architecture-test split: tests/Architecture for the always-on (DB-free) D-09 + I-01 + W-05 invariants; tests/Feature for the MySQL-required CRUD/RBAC reach checks (Phase 9 Plan 02 TradeRuleResolverPurityTest precedent)."

key-files:
  created:
    - app/Domain/TradePricing/Filament/Resources/CustomerGroupResource.php
    - app/Domain/TradePricing/Filament/Resources/CustomerGroupResource/Pages/ListCustomerGroups.php
    - app/Domain/TradePricing/Filament/Resources/CustomerGroupResource/Pages/CreateCustomerGroup.php
    - app/Domain/TradePricing/Filament/Resources/CustomerGroupResource/Pages/EditCustomerGroup.php
    - app/Domain/TradePricing/Policies/CustomerGroupPolicy.php
    - tests/Feature/TradePricing/CustomerGroupResourceTest.php
    - tests/Feature/TradePricing/PricingRuleResourceCustomerGroupFieldTest.php
    - tests/Architecture/CustomerGroupResourceNavigationSortTest.php
    - tests/Architecture/PricingRuleResourceAdditiveInvariantTest.php
    - .planning/phases/09-e1-trade-customer-pricing/deferred-items.md
  modified:
    - app/Domain/Pricing/Filament/Resources/PricingRuleResource.php (additive: customer_group_id Select FIRST + customer_group_id SelectFilter appended)
    - app/Providers/AppServiceProvider.php (Gate::policy registration for CustomerGroup → CustomerGroupPolicy)
    - database/seeders/RolePermissionSeeder.php (5 perms scaffolded + role assignments + step 4b read_only revoke + W-05 doc)

requirements: [TRDE-04]

commits:
  - 2b41adb feat(09-05): CustomerGroupResource Filament + Policy + I-01 nav-sort guard (TRDE-04 Task 1)
  - 29c1b07 feat(09-05): PricingRuleResource additive customer_group_id Select+Filter + RolePermissionSeeder + tests (TRDE-04 Task 2)
  - 471285b docs(09-05): log shield:safe-regenerate --force flag mismatch as deferred Phase 8 wrapper bug

deferred-tests:
  - "CustomerGroupResourceTest (8 it() blocks) — Pest discovery clean (8 cases enumerated). Execution deferred: tests/Pest.php applies RefreshDatabase file-globally to Feature/, fires before per-test skipIfMySqlOfflineCustomerGroupResource helper (inherited Plans 09-01..04 + Phase 6/7/8 limitation). Unblocks once meetingstore_ops_testing MySQL is online (cutover Gate 3)."
  - "PricingRuleResourceCustomerGroupFieldTest (6 it() blocks) — same posture; Pest discovery clean (6 cases enumerated). Same MySQL-offline limitation."
  - "Phase 3 PricingRuleResourceAccessTest (20 it() blocks) — Pest discovery clean; same MySQL-offline limitation. The additive edit did not break test discovery — 20 cases enumerated identically before and after Plan 05."
  - "MySQL-deferred test execution: blocked behind v1 cutover Gate 3 (feature-tier Pest suite run against online meetingstore_ops_testing per docs/ops/cutover-handover.md Appendix A)."

deferred-items:
  - "shield:safe-regenerate --force flag mismatch — Phase 8 wrapper bug (logged in deferred-items.md). Plan 09-05 runtime correctness satisfied by the manual seeder + AppServiceProvider Gate binding; PricingRulePolicy unchanged (git diff = 0 lines)."

metrics:
  duration: ~37min
  completed_date: 2026-04-25
  tasks: 2
  files_created: 9
  files_modified: 3
  commits: 3
---

# Plan 09-05 — Filament UX + Policy + RolePermissionSeeder (the operator surface)

## What was built

The operator surface for Phase 9 trade pricing. Two tasks, three commits, nine new files, three files modified additively.

### 1. CustomerGroupResource + 3 Pages + CustomerGroupPolicy + I-01 architecture test (commit `2b41adb`)

**`app/Domain/TradePricing/Filament/Resources/CustomerGroupResource.php`** — standard Filament Resource. Form: slug (required + unique + alphaDash + maxLength 64) + name (required + maxLength 128) + display_order (numeric, default 100) + is_active Toggle (default true). Table: display_order/slug/name/is_active/updated_at columns; `defaultSort('display_order')` per Pitfall 7 (predictable dropdown ordering across the app).

**I-01 invariant** — PricingRuleResource has `$navigationSort = 10`; CustomerGroupResource is set to `11` so they sit consecutively in the Pricing nav group. Reflection-based test in tests/Architecture/CustomerGroupResourceNavigationSortTest fails CI on collision — bumping either Resource to the same value trips the `expect($cg)->not->toBe($pr)` assertion.

**3 Page classes** — `ListCustomerGroups` (header CreateAction with `->authorize()` guard), `CreateCustomerGroup` (vanilla CreateRecord), `EditCustomerGroup` (DeleteAction with `->authorize()` guard for FK ON DELETE RESTRICT bubble-up). Mirrors PricingRuleResource Pages shape.

**`app/Domain/TradePricing/Policies/CustomerGroupPolicy.php`** — thin policy. 5 methods (viewAny/view/create/update/delete) each gating on `$user->can('*_customer_group')` strings. The 5 perms are seeded by RolePermissionSeeder (Plan 05 Task 2). DO NOT regenerate via shield:generate — see policy docblock; PolicyTemplateIntegrityTest catches Shield placeholder leaks on every CI run.

**`AppServiceProvider::boot()`** — Gate::policy(CustomerGroup::class, CustomerGroupPolicy::class) registration alongside Phase 8 AgentRunPolicy. Filament auto-resolution by namespace convention also works, but the explicit binding closes any namespace-resolution edge case (matches Phase 4/5/6/7/8 pattern).

**`tests/Architecture/CustomerGroupResourceNavigationSortTest.php`** (2 it() blocks):
- I-01 navigationSort distinct (reflection — runs offline)
- both Resources have integer navigationSort values

**`tests/Feature/TradePricing/CustomerGroupResourceTest.php`** (8 it() blocks; MySQL-online deferred):
- I-01 (duplicates the architecture test for Feature parity)
- Test 1: admin creates CustomerGroup via Filament
- Test 2: pricing_manager updates CustomerGroup via Filament
- Test 3: sales reaches list but cannot create/update/delete (policy gate)
- Test 4: read_only locked out entirely
- Test 5: slug uniqueness enforced
- Test 6: alphaDash rejects 'Trade Customer'
- Test 7: deleting a group with active pricing_rules raises QueryException (FK RESTRICT)

### 2. PricingRuleResource additive edit + RolePermissionSeeder + Tests (commit `29c1b07`)

**`app/Domain/Pricing/Filament/Resources/PricingRuleResource.php`** — additive only:

```diff
 public static function form(Form $form): Form
 {
     return $form->schema([
+        // ── Phase 9 Plan 05 (TRDE-04 D-09 additive) ──
+        Select::make('customer_group_id')
+            ->label('Customer Group')
+            ->relationship('customerGroup', 'name')
+            ->searchable()->preload()->nullable()
+            ->placeholder('— Retail (default) —')
+            ->helperText('Empty = retail rule (matches all customers without a group). Choose a group to make this a trade rule.'),
+
         Select::make('scope')
             ->label('Scope')
             ->required()
             ->reactive()
             ...
```

```diff
         ->filters([
             TernaryFilter::make('active')->label('Active only')->default(true),
             SelectFilter::make('scope')->multiple()->options([...]),
+            SelectFilter::make('customer_group_id')
+                ->label('Customer Group')
+                ->relationship('customerGroup', 'name')
+                ->placeholder('All groups + retail'),
         ])
```

NO existing form/column/filter/action removed or renamed. Reactive scope→brand/category visibility preserved. After edit, `grep -c` shows 5 Select::make + SelectFilter::make + TernaryFilter::make calls (was 3 before; 2 added).

**`database/seeders/RolePermissionSeeder.php`** — W-05 documented v1-parity edit:

- 5 perms scaffolded via Permission::firstOrCreate at step 2b (alongside the AgentRunResource pattern from Phase 8 Plan 04 — defensive scaffold so cold-start tests work without shield:generate)
- pricing_manager grant via `Role::findByName('pricing_manager')->givePermissionTo($tradePricingPermissions)` (5 perms)
- sales grant via `Role::findByName('sales')->givePermissionTo(['view_any_customer_group', 'view_customer_group'])`
- admin gets all 5 implicitly via the existing `$admin->syncPermissions(Permission::all())` at step 3
- read_only — step 4's view_% LIKE pattern would sweep in view_any_customer_group + view_customer_group; step 4b explicitly revokes them per D-10 lock-out

The `W-05` doc block above the new section explains: "findByName matches v1 RolePermissionSeeder pattern; brittleness is accepted v1-parity. CI fails loudly if roles are missing, which is the desired signal — silent role-permission drift is worse than a fail-fast Throwable on seed."

**`tests/Architecture/PricingRuleResourceAdditiveInvariantTest.php`** (4 it() blocks; runs offline):
- D-09 additive invariant — Phase 3 form fields preserved (scope/brand_id/category_id/margin/priority/is_default_tier/active + ->reactive())
- D-09 additive invariant — existing filters retained + new customer_group_id added; ≥5 Select/Filter make() calls
- D-09 first-in-form — strpos check that customer_group_id Select is positioned BEFORE the scope Select (the plan-mandated ordering)
- W-05 v1-parity — seeder contains the 5 perm strings AND the W-05 + findByName documentation comment

**`tests/Feature/TradePricing/PricingRuleResourceCustomerGroupFieldTest.php`** (6 it() blocks; MySQL-online deferred):
- D-09 form/filter source-grep invariants (mirror the Architecture tests for Feature parity)
- Test 1: admin creates retail (null) + trade (group) PricingRule via Filament
- Test 2: SelectFilter on customer_group_id filters list correctly
- Test 5: post-seed RBAC matrix per D-10 (admin/pm = all 5; sales = view_*; ro = none)
- Test 6: CustomerGroupPolicy gates each action correctly across all 4 roles

### 3. shield:safe-regenerate workflow (commit `471285b` deferred-items log)

`php artisan shield:safe-regenerate --allow-new=CustomerGroupPolicy` exited early at Step 2 because the Phase 8 wrapper calls `shield:generate --all --force`, but Filament Shield 3.x in this codebase doesn't accept `--force`. Pre-existing Phase 8 wrapper bug — logged in `deferred-items.md` as a Phase 8 follow-up. Plan 09-05 runtime correctness intent (5 perms seeded + CustomerGroupPolicy hand-written + Gate::policy registered + PricingRulePolicy unchanged) is satisfied via the manual seeder + AppServiceProvider binding. **`git diff app/Domain/Pricing/Policies/PricingRulePolicy.php` → 0 lines** (Pitfall 6 verification passes regardless).

## Why this matters

- **B2B revenue motion has its operator surface.** Admin and pricing_manager can administer customer_groups via Filament — slug + name + display_order + is_active. New groups appear in PricingRule Select dropdowns automatically (relationship-based — no migration on add).
- **Phase 3 retail UX byte-identical.** PricingRuleResource form/table behaviour preserved by D-09 additive invariant; T-09-05-04 mitigation locked on CI by source-grep tests. Future PRs that try to refactor the Resource non-additively trip the Architecture test.
- **D-10 RBAC matrix matches the spec exactly.** Admin + pricing_manager have full CRUD; sales has view-only; read_only is locked out. CustomerGroupPolicy + RolePermissionSeeder + step 4b revoke combine to enforce the matrix; Test 5 + Test 6 in the Feature test lock it on CI (deferred to MySQL-online).
- **W-05 v1-parity documented.** Future readers see the deliberate findByName brittleness rationale inline in the seeder; CI fails loudly on missing roles is the desired signal, not silently dropping perms.
- **I-01 nav-sort collision impossible silently.** Reflection-based architecture test fails CI when either Resource's $navigationSort drifts to the other's value.
- **B-01 closure verified.** Plan 09-05 ships 2 tasks; Plan 09-04 shipped 3 tasks separately. Total wave-4 task count = 5, distributed across two plans with zero file overlap. The original-09-04 (5-task superset) split into 09-04 (sync pipeline) + 09-05 (Filament UX) keeps both within the 2-3 task budget per plan.

## Notable deviations

### Rule 1 — Bug fixes

None — no live bugs discovered.

### Rule 2 — Auto-added critical functionality

- **read_only step 4b explicit revoke (D-10 lock-out enforcement).** The plan said "read_only gets none" and the existing seeder has a `view_%` LIKE pattern at step 4 that sweeps in `view_any_customer_group` + `view_customer_group`. Without an explicit revoke at step 4b, read_only would inadvertently see customer_group views. Added inline; documented as the D-10 lock-out enforcement step. Architecture test does NOT cover this (it's a runtime behaviour requiring DB); Feature Test 5 + Test 6 lock it on CI when MySQL is online.

### Rule 3 — Auto-fixed blocking issues

- **Pest file-global RefreshDatabase forced Architecture test split.** The plan envisioned both the I-01 reflection test and the D-09 source-grep invariants living in the Feature test file. But `tests/Pest.php` line 13 applies RefreshDatabase to every Feature test file-globally, and `RefreshDatabase` fires before any test body runs — so even the DB-free reflection test fails on MySQL-offline (Connector exception bubbles up through setUp()). Refactored: created `tests/Architecture/CustomerGroupResourceNavigationSortTest.php` (2 tests) + `tests/Architecture/PricingRuleResourceAdditiveInvariantTest.php` (4 tests) so the always-on invariants run offline. Feature tests retain MySQL-online execution paths (8 + 6 cases enumerated cleanly; deferred per documented Phase 9 precedent). This matches the Phase 9 Plan 02 TradeRuleResolverPurityTest precedent — DB-free invariants in a separate file from the DB-required behavioural tests.

### Rule 4 — Architectural decisions

None.

### Authentication gates

None.

### Out-of-scope deferred items

- **shield:safe-regenerate `--force` flag mismatch** — pre-existing Phase 8 wrapper bug (`shield:generate` in Filament Shield 3.x does NOT accept `--force`). Plan 09-05's runtime correctness intent satisfied via the manual seeder + AppServiceProvider binding. Logged in `deferred-items.md` as a Phase 8 follow-up. **Out-of-scope per GSD scope-boundary rule** (only auto-fix issues DIRECTLY caused by current task changes; this is a pre-existing wrapper issue, not introduced by Plan 09-05).

## What this enables

- **Phase 11 E2 Quote Flow** — quote builder can attach a customer_group_id and call `app(TradeRuleResolver::class)->resolve($product, $customerGroupId)` per quote line. The Filament UX for managing groups + group-scoped rules is now in place.
- **Plan 09-06 verification + backfill** — `b2b:backfill-customer-groups` command will walk existing users and call `RoleToGroupMapper::mapToGroupId($role)`. The Filament surface lets an admin verify the backfill result by visiting the CustomerGroupResource list and inspecting `pricingRules` count via the inverse relation (future enhancement; not in 09-05 scope).
- **Future per-group features** — anything new that needs ops to manage customer_groups (e.g. per-group catalogue visibility deferred to v2.1) gets a free Filament Resource to extend.

## Verification snapshot

| Check | Status |
|---|---|
| `php -l app/Domain/TradePricing/Filament/Resources/CustomerGroupResource.php` | PASS |
| `php -l app/Domain/TradePricing/Filament/Resources/CustomerGroupResource/Pages/{ListCustomerGroups,CreateCustomerGroup,EditCustomerGroup}.php` | PASS (3 files) |
| `php -l app/Domain/TradePricing/Policies/CustomerGroupPolicy.php` | PASS |
| `php -l app/Domain/Pricing/Filament/Resources/PricingRuleResource.php` | PASS |
| `php -l app/Providers/AppServiceProvider.php` | PASS |
| `php -l database/seeders/RolePermissionSeeder.php` | PASS |
| `php -l tests/Feature/TradePricing/CustomerGroupResourceTest.php` | PASS |
| `php -l tests/Feature/TradePricing/PricingRuleResourceCustomerGroupFieldTest.php` | PASS |
| `php -l tests/Architecture/CustomerGroupResourceNavigationSortTest.php` | PASS |
| `php -l tests/Architecture/PricingRuleResourceAdditiveInvariantTest.php` | PASS |
| Architecture tests (offline) | **PASS — 6 tests, 29 assertions, 3.79s** |
| `grep -c "Select::make\\|SelectFilter::make\\|TernaryFilter::make" app/Domain/Pricing/Filament/Resources/PricingRuleResource.php` | 5 (was 3; 2 added — D-09 additive verified) |
| Position of `Select::make('customer_group_id')` in PricingRuleResource | line 69 (BEFORE `Select::make('scope')` at line 78 — D-09 first-in-form) |
| New SelectFilter `customer_group_id` location in PricingRuleResource | line 208 (AFTER existing TernaryFilter('active') at 194 + SelectFilter('scope') at 195 — D-09 additive append) |
| CustomerGroupResource `$navigationSort` | 11 (PricingRuleResource is at 10 — I-01 distinct) |
| `git diff HEAD~3 app/Domain/Pricing/Policies/PricingRulePolicy.php` | EMPTY (Pitfall 6 — PricingRulePolicy unchanged) |
| 5 customer_group_* perm strings in RolePermissionSeeder | PRESENT (architecture test asserts) |
| W-05 documentation in RolePermissionSeeder | PRESENT (architecture test asserts) |
| Pest discovery — CustomerGroupResourceTest | 8 cases enumerated cleanly |
| Pest discovery — PricingRuleResourceCustomerGroupFieldTest | 6 cases enumerated cleanly |
| Pest discovery — Phase 3 PricingRuleResourceAccessTest | 20 cases enumerated cleanly (additive edit did not break discovery) |
| Test execution | DEFERRED for Feature tests (MySQL-offline) — same posture as Plans 09-01..04 + Phase 6/7/8 |

## Threat surface scan

Reviewed all files created/modified against Plan 09-05 `<threat_model>` STRIDE register. Every `mitigate` disposition is implemented and CI-enforced where the plan listed an invariant test:

- **T-09-05-01 (sales escalating to update customer_groups):** mitigated. CustomerGroupPolicy::update() gates on `$user->can('update_customer_group')`; sales role NOT granted that perm in RolePermissionSeeder. Feature Test 3 + Test 6 lock on CI.
- **T-09-05-02 (read_only escalating to view customer_groups):** mitigated. CustomerGroupPolicy::viewAny gates on `view_any_customer_group`; RolePermissionSeeder step 4b explicitly revokes the LIKE-pattern sweep so read_only has zero customer_group perms. Feature Test 4 + Test 6 lock on CI.
- **T-09-05-03 (Shield regen overwriting hand-written PricingRulePolicy):** mitigated. Plan 09-05 did NOT run shield:generate (the wrapper exited early due to the deferred --force bug). PricingRulePolicy unchanged: `git diff app/Domain/Pricing/Policies/PricingRulePolicy.php` → 0 lines. Future shield:generate runs are guarded by Phase 2 Plan 05's PolicyTemplateIntegrityTest.
- **T-09-05-04 (future PR removing PricingRuleResource form fields while extending):** mitigated. tests/Architecture/PricingRuleResourceAdditiveInvariantTest source-greps for SelectFilter('scope') + TernaryFilter('active') + the 7 Phase 3 form fields; bumping or removing any trips the test on CI.
- **T-09-05-05 (Filament navigation collision via I-01):** mitigated. tests/Architecture/CustomerGroupResourceNavigationSortTest reflection-asserts $navigationSort distinct between CustomerGroupResource and PricingRuleResource.

No new threat-flag types introduced. Trade-pricing surface is admin/pricing_manager-only; no new public endpoints, file paths, or schema changes outside the plan.

## Self-Check: PASSED

**Files:**
- FOUND: `app/Domain/TradePricing/Filament/Resources/CustomerGroupResource.php`
- FOUND: `app/Domain/TradePricing/Filament/Resources/CustomerGroupResource/Pages/ListCustomerGroups.php`
- FOUND: `app/Domain/TradePricing/Filament/Resources/CustomerGroupResource/Pages/CreateCustomerGroup.php`
- FOUND: `app/Domain/TradePricing/Filament/Resources/CustomerGroupResource/Pages/EditCustomerGroup.php`
- FOUND: `app/Domain/TradePricing/Policies/CustomerGroupPolicy.php`
- FOUND: `app/Domain/Pricing/Filament/Resources/PricingRuleResource.php` modified (additive — Select + SelectFilter)
- FOUND: `app/Providers/AppServiceProvider.php` modified (Gate::policy CustomerGroup binding)
- FOUND: `database/seeders/RolePermissionSeeder.php` modified (5 perms + role assignments + step 4b revoke + W-05 doc)
- FOUND: `tests/Feature/TradePricing/CustomerGroupResourceTest.php`
- FOUND: `tests/Feature/TradePricing/PricingRuleResourceCustomerGroupFieldTest.php`
- FOUND: `tests/Architecture/CustomerGroupResourceNavigationSortTest.php`
- FOUND: `tests/Architecture/PricingRuleResourceAdditiveInvariantTest.php`
- FOUND: `.planning/phases/09-e1-trade-customer-pricing/deferred-items.md`

**Commits:**
- FOUND: `2b41adb` (Task 1)
- FOUND: `29c1b07` (Task 2)
- FOUND: `471285b` (deferred-items log)

**Invariants:**
- FOUND: 6 architecture tests PASSING offline (29 assertions, 3.79s)
- FOUND: `git diff HEAD~3 app/Domain/Pricing/Policies/PricingRulePolicy.php` → EMPTY (Pitfall 6)
- FOUND: PricingRuleResource customer_group_id Select positioned at line 69 (BEFORE scope at line 78 — D-09 first-in-form)
- FOUND: PricingRuleResource SelectFilter('customer_group_id') positioned at line 208 (AFTER existing filters — D-09 additive append)
- FOUND: CustomerGroupResource::$navigationSort = 11; PricingRuleResource::$navigationSort = 10 (I-01 distinct)
- FOUND: 5 customer_group_* perm strings + W-05 doc + findByName usage in RolePermissionSeeder
