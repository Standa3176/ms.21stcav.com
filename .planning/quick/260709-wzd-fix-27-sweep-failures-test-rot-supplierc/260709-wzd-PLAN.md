---
phase: 260709-wzd-fix-27-sweep-failures-test-rot-supplierc
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - tests/Feature/SyncResumeTest.php
  - tests/Feature/SyncReportMailTest.php
  - tests/Feature/SyncSupplierCommandFlagsTest.php
  - tests/Feature/SupplierEventDispatchTest.php
  - tests/Feature/WooClientGetTest.php
  - tests/Feature/WooRateLimitTest.php
  - tests/Feature/TradePricing/BackfillCustomerGroupsCommandTest.php
  - database/seeders/RolePermissionSeeder.php
must_haves:
  truths:
    - "27 of the 30 sweep failures go green: 23 test-rot fixes + 1 REAL RBAC bug fix (the RolePermissionSeeder clobber, which fixes 4 CustomerGroup tests). The remaining 3 (AnonymousDisplayPostureTest — a real trade-pricing-leak bug entangled with the v1 byte-identity lock) are handled SEPARATELY and are NOT touched here."
    - "Bucket 1 (8, test-rot): SyncResumeTest / SyncReportMailTest / SyncSupplierCommandFlagsTest bound a bare `new class {}` anon stub into the container for SupplierClient, which fails the command's typed `SupplierClient $supplier` ctor arg during Artisan command discovery. Replace each with `Mockery::mock(SupplierClient::class)->shouldReceive('fetchAllProducts')->andReturn(...)` (mirror the PASSING tests/Feature/Sync/WooImportProductsCommandTest.php `fakeSupplier`)."
    - "Bucket 2 (6, test-rot): SupplierEventDispatchTest.php has `use Illuminate\\Support\\Facades\\Str;` — that class does not exist (Str is `Illuminate\\Support\\Str`). Fix the import. Confirmed TEST-ONLY (no app/ occurrence) — not a prod bug."
    - "Bucket 3 (8, test-rot): WooClientGetTest + WooRateLimitTest construct WooClient with the OLD 2-arg signature (inner client passed into the resolver slot). WooClient ctor is now (IntegrationLogger, IntegrationCredentialResolver, ?AutomatticClient $inner). Pass `app(IntegrationCredentialResolver::class)` as arg 2 + the inner mock as arg 3; the G4 reflection test's param-count assertion goes 2→3 with the AutomatticClient at index 2; G3's TypeError-vs-HttpClientException resolves downstream."
    - "Bucket 4c (1, test-rot): BackfillCustomerGroupsCommandTest asserts `list --namespace=b2b` output contains an OPTION description ('Commit writes') — `list` never prints option help. Assert against `help b2b:backfill-customer-groups` output (or drop that line); the command's `--live` option is correct."
    - "Bucket 4b (4, REAL BUG): RolePermissionSeeder grants pricing_manager (5) + sales (view_any+view) the *_customer_group perms via givePermissionTo (~lines 231-235), then a LATER `syncPermissions()` (~491 pricing_manager, ~551 sales) REPLACES each role's whole set WITHOUT those perms → wiped in prod (D-10 matrix not enforced). Fix by adding the customer_group perms to the pricing_manager + sales syncPermissions source lists (the `whereIn` at ~366 / ~511). Apply the SAME fix to the identically-clobbered Quote grants (~lines 247-263) so pricing_manager/sales keep their quote perms too."
  artifacts:
    - path: "database/seeders/RolePermissionSeeder.php"
      provides: "customer_group + quote perms survive syncPermissions (D-10 RBAC)"
      contains: "customer_group"
  key_links: []
---

<objective>
Take 27 of the 30 full-suite sweep failures to green: 23 test-rot (SupplierClient Mockery stubs, a Facades\Str
import typo, WooClient 3-arg ctor + a reflection assertion, a list-vs-help assertion) + 1 REAL RBAC bug (the
RolePermissionSeeder syncPermissions clobber that wipes pricing_manager/sales customer_group + quote grants). The
remaining 3 (AnonymousDisplayPostureTest trade-pricing leak) are a separate, byte-lock-entangled task — do NOT touch
RuleResolver here.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260709-wzd-fix-27-sweep-failures-test-rot-supplierc/
@CLAUDE.md
@tests/Feature/Sync/WooImportProductsCommandTest.php
@tests/Feature/SyncResumeTest.php
@tests/Feature/SyncReportMailTest.php
@tests/Feature/SyncSupplierCommandFlagsTest.php
@tests/Feature/SupplierEventDispatchTest.php
@tests/Feature/WooClientGetTest.php
@tests/Feature/WooRateLimitTest.php
@tests/Feature/TradePricing/BackfillCustomerGroupsCommandTest.php
@database/seeders/RolePermissionSeeder.php
@app/Domain/Sync/Services/SupplierClient.php
@app/Domain/Sync/Services/WooClient.php
@app/Domain/TradePricing/Policies/CustomerGroupPolicy.php
---
Triage verdicts (evidence-backed) are baked into the truths above. WooImportProductsCommand ctor =
(WooClient $woo, SupplierClient $supplier) — command discovery eagerly resolves it, so a container-bound stub MUST
be a real SupplierClient subtype (Mockery::mock(SupplierClient::class) is). WooClient ctor =
(IntegrationLogger $logger, IntegrationCredentialResolver $resolver, ?AutomatticClient $inner = null).
RolePermissionSeeder: givePermissionTo blocks at ~231-235 (customer_group) + ~247-263 (quote) are undone by
$pricingManager->syncPermissions($pricingManagerPermissions) (~491) and $sales->syncPermissions($salesPermissions)
(~551); the source lists are the `whereIn([...])` at ~366 (pricing_manager) / ~511 (sales). admin syncs
Permission::all() so it's unaffected.
</context>

<interfaces>
=== Bucket 1 — SupplierClient stubs (3 test files) ===
Replace each bare `new class {...}` bound as SupplierClient with a Mockery mock (mirror
WooImportProductsCommandTest `fakeSupplier`):
- SyncResumeTest resumeStub(): `$m = Mockery::mock(SupplierClient::class); $m->shouldReceive('fetchAllProducts')->andReturn($feed); app()->instance(SupplierClient::class, $m);`
- SyncReportMailTest stubSupplierClientEmpty(): mock → fetchAllProducts andReturn([]); stubSupplierClientThrowingJwt(): mock → fetchAllProducts andThrow(new JwtRefreshFailedException('bad creds')).
- SyncSupplierCommandFlagsTest beforeEach: mock → fetchAllProducts andReturn([]).
Match each stub's ORIGINAL returned shape (whatever the anon class returned) so downstream assertions hold.

=== Bucket 2 — Str import ===
SupplierEventDispatchTest.php: `use Illuminate\Support\Facades\Str;` → `use Illuminate\Support\Str;`.

=== Bucket 3 — WooClient 3-arg ctor ===
- WooClientGetTest.php (lines ~37, ~55, ~83): `new WooClient(app(IntegrationLogger::class), $mockInner)` →
  `new WooClient(app(IntegrationLogger::class), app(IntegrationCredentialResolver::class), $mockInner)`; add
  `use App\Domain\Integrations\Services\IntegrationCredentialResolver;`.
- WooClientGetTest.php G4 reflection (~99-107): `toHaveCount(2)` → `toHaveCount(3)`; the `?AutomatticClient` param
  is now index 2; assert index 1 is `IntegrationCredentialResolver`.
- WooRateLimitTest.php (~28, `rateLimitTestClient` anon subclass): add `app(IntegrationCredentialResolver::class)`
  as the middle ctor arg (3 args total); add the import.

=== Bucket 4c — backfill command help assertion ===
BackfillCustomerGroupsCommandTest.php (~line 72): replace the `list`-output 'Commit writes' assertion with
`Artisan::call('help', ['command_name' => 'b2b:backfill-customer-groups']); $help = Artisan::output();`
then `expect($help)->toContain('--live')->toContain('Commit writes');` (or drop the 'Commit writes' clause).

=== Bucket 4b — RolePermissionSeeder (REAL RBAC fix) ===
Add the missing perms to the syncPermissions SOURCE lists so they survive:
- pricing_manager `whereIn([...])` (~366): add `view_any_customer_group, view_customer_group, create_customer_group,
  update_customer_group, delete_customer_group` AND the quote perms the ~247-263 block grants it.
- sales `whereIn([...])` (~511): add `view_any_customer_group, view_customer_group` AND the quote perms the
  ~247-263 block grants sales.
(Confirm the exact perm slugs from the givePermissionTo blocks + the CustomerGroupPolicy. Keep admin's
Permission::all() as-is.) This makes CustomerGroupPolicy actually enforce the D-10 matrix after seeding.
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: 23 test-rot fixes + RBAC seeder fix</name>
  <files>
    tests/Feature/SyncResumeTest.php,
    tests/Feature/SyncReportMailTest.php,
    tests/Feature/SyncSupplierCommandFlagsTest.php,
    tests/Feature/SupplierEventDispatchTest.php,
    tests/Feature/WooClientGetTest.php,
    tests/Feature/WooRateLimitTest.php,
    tests/Feature/TradePricing/BackfillCustomerGroupsCommandTest.php,
    database/seeders/RolePermissionSeeder.php
  </files>
  <behavior>
    Apply buckets 1, 2, 3, 4c (test-rot) + 4b (RBAC seeder). For 4b, after editing the seeder, the CustomerGroupResourceTest
    + PricingRuleResourceCustomerGroupFieldTest (which re-seed via RolePermissionSeeder in setup) must pass — run them to
    confirm. Do NOT touch RuleResolver.php, TradeRuleResolver.php, PriceCalculator.php, or AnonymousDisplayPostureTest
    (the separate byte-lock-entangled trade-leak task owns those). tdd: the failing tests ARE the oracle for the test-rot;
    for 4b add/confirm the seeder-grant assertion.
  </behavior>
  <action>
    Fix the 7 test files + the seeder per <interfaces>. Run the 10 sweep files (minus AnonymousDisplayPostureTest) + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/SyncResumeTest.php tests/Feature/SyncReportMailTest.php tests/Feature/SyncSupplierCommandFlagsTest.php tests/Feature/SupplierEventDispatchTest.php tests/Feature/WooClientGetTest.php tests/Feature/WooRateLimitTest.php tests/Feature/TradePricing/BackfillCustomerGroupsCommandTest.php tests/Feature/TradePricing/CustomerGroupResourceTest.php tests/Feature/TradePricing/PricingRuleResourceCustomerGroupFieldTest.php 2>&1 | tail -12</automated>
    Expected: ALL GREEN (27 previously-failing cases now pass; CustomerGroup tests pass via the seeder fix).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test database/seeders/RolePermissionSeeder.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - 23 test-rot green (SupplierClient mocks, Str import, WooClient 3-arg + reflection, help assertion); RBAC seeder grants customer_group + quote perms that survive syncPermissions (4 CustomerGroup tests green); RuleResolver/trade-leak untouched; pint clean.
  </done>
</task>

</tasks>

<verification>
1. The 9 files above (all 10 sweep files except AnonymousDisplayPostureTest) → GREEN
2. `pint --test` → PASS

Operator notes (for SUMMARY.md):
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration). **Re-run the
  RolePermissionSeeder on deploy** (or `php artisan db:seed --class=RolePermissionSeeder`) so pricing_manager/sales
  regain their customer_group + quote permissions (the D-10 RBAC matrix — was silently wiped by a syncPermissions clobber).
- 23 of the fixes are pure test-rot (no prod impact). The seeder fix is a REAL RBAC correction.
- SEPARATE, still-owed: AnonymousDisplayPostureTest (3) — a real trade-pricing-LEAK bug (RuleResolver returns trade
  margin to anonymous/retail); fix is entangled with the v1 byte-identity lock — handled as its own task after an
  approach decision.
</verification>

<success_criteria>
- 27 sweep failures green (23 test-rot + the RBAC seeder fix clearing 4 CustomerGroup tests); RuleResolver/TradeRuleResolver/PriceCalculator + AnonymousDisplayPostureTest untouched; pint clean; SUMMARY records the seeder re-run deploy note + the still-owed trade-leak task.
</success_criteria>

<output>
Create `.planning/quick/260709-wzd-fix-27-sweep-failures-test-rot-supplierc/260709-wzd-SUMMARY.md` documenting each bucket (test-rot vs the real RBAC fix), the seeder clobber root cause + fix (incl. the quote-grants same bug), before/after, the deploy seeder-re-run note, and the deferred trade-leak task.
</output>