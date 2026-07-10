---
phase: 260710-pdw-admin-nav-polish-supplierpolicy-register
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Domain/Sync/Policies/SupplierPolicy.php
  - app/Providers/AppServiceProvider.php
must_haves:
  truths:
    - "The admin navigation is deterministic + tidy: (1) the orphan 'Woo Maintenance' group is registered in AdminPanelProvider navigationGroups() so it renders in a controlled position (after 'Catalogue') instead of dangling last; (2) all navigationSort collisions are removed (unique increasing sort within each group) so item order is stable across deploys; (3) the 'Crm'/'Csv' auto-humanized labels get explicit correct-casing navigationLabels; (4) the stale Quotes 'Sales group' docblock comment is corrected to reflect the actual 'Catalogue' group."
    - "Basic product identity: AdminPanelProvider sets ->brandName('MeetingStore Ops') + ->favicon(asset('favicon.ico')) (public/favicon.ico exists)."
    - "Security consistency: a new SupplierPolicy (app/Domain/Sync/Policies/SupplierPolicy.php) mirrors SyncRunPolicy — viewAny/view = all 4 roles (shared-workspace read of non-secret supplier metadata), create/update/delete = false (Supplier rows are sync-owned), plus a `sync`/action gate = hasAnyRole(['admin','pricing_manager']) matching the existing inline SupplierResource gating. Registered via Gate::policy(Supplier::class, SupplierPolicy::class) in AppServiceProvider. Conforms to PolicyTemplateIntegrityTest (no Shield placeholders; references hasRole/hasAnyRole)."
    - "NO behaviour/authorization REGRESSION: nav-config + additive policy only. The SupplierResource inline hasAnyRole gating stays; the new policy is additive RBAC/Shield consistency. Every existing Filament resource/page still registers + the panel boots. No test regressions."
  artifacts:
    - path: "app/Providers/Filament/AdminPanelProvider.php"
      provides: "Woo Maintenance group registered + brandName + favicon"
      contains: "Woo Maintenance"
    - path: "app/Domain/Sync/Policies/SupplierPolicy.php"
      provides: "SupplierPolicy (RBAC consistency)"
      contains: "viewAny"
  key_links:
    - from: "Supplier model"
      to: "SupplierPolicy via Gate::policy in AppServiceProvider"
      via: "Gate::policy(Supplier::class, SupplierPolicy::class)"
      pattern: "SupplierPolicy"
---

<objective>
Action the audit's menu + polish + security-consistency fixes: register the orphan Woo Maintenance nav group,
de-collide navigationSort across the panel (esp. Settings), fix CRM/CSV label casing, add brandName+favicon,
correct a stale comment, and add the missing SupplierPolicy. Config + additive policy only — no behaviour change.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260710-pdw-admin-nav-polish-supplierpolicy-register/
@CLAUDE.md
@app/Providers/Filament/AdminPanelProvider.php
@app/Providers/AppServiceProvider.php
@app/Domain/Sync/Policies/SyncRunPolicy.php
@app/Domain/Sync/Models/Supplier.php
@app/Domain/Sync/Filament/Resources/SupplierResource.php
@tests/Architecture/PolicyTemplateIntegrityTest.php
---
Audit findings (verified). navigationGroups() at AdminPanelProvider ~line 91 = [Operations, Catalogue, Review,
Competitors, 'Sync & CRM', Settings]. `->colors(['primary'=>Color::hex('#5B21B6')])`; NO brandName/favicon.
public/favicon.ico EXISTS. Woo Maintenance pages: WooMaintenanceOverviewPage + CatalogueGapsPage set
`$navigationGroup='Woo Maintenance'` (unregistered → orphan). navigationSort collisions (each is a per-Resource/Page
`$navigationSort`): SETTINGS 10 = IntegrationCredential + Competitor(Feeds) + CrmFieldMapping; 20 = AlertRecipient +
CrmStatusMapping; 30 = AutoCreateSettings + PricingRule; 40 = ProductOverride + CrmPipeline + AutoCreateSkipRule;
COMPETITOR-FEEDS children 50 = CsvIngestIssues + FtpCredentials. Auto-humanized labels needing explicit casing:
CrmFieldMappingResource→'CRM Field Mappings', CrmStatusMappingResource→'CRM Status Mappings',
CsvParseErrorResource→'CSV Parse Errors', CompetitorIngestRunResource→'Competitor Ingest Runs'. Nesting pattern:
children set `protected static ?string $navigationParentItem = '<parent label>';` (see Competitor Feeds / Horizon).
SyncRunPolicy is the exact template to mirror. SupplierResource inline gating: writes hasAnyRole(['admin','pricing_manager']).
PolicyTemplateIntegrityTest: no `{{ Placeholder }}` leaks + each policy references hasRole/hasPermissionTo.
</context>

<interfaces>
=== AdminPanelProvider ===
- navigationGroups(): insert `'Woo Maintenance',` after `'Catalogue',` (keeps daily flow; groups the 2 admin maintenance pages in a controlled slot).
- Add `->brandName('MeetingStore Ops')` and `->favicon(asset('favicon.ico'))` to the panel chain.
- Fix the stale docblock (~lines 209-214) that says QuoteResource adds a 'Sales' group — QuoteResource is in 'Catalogue'; correct the comment (do NOT move the resource).

=== navigationSort de-collision (edit each resource/page's $navigationSort) ===
Assign UNIQUE increasing sorts within each group, preserving current relative intent. Suggested Settings sequence
(gap of 10 for future insertion): IntegrationCredential 10, Competitor Feeds 20, CRM (mappings) 30, CrmStatusMapping 40,
AlertRecipient 50, AutoCreateSettings 60, PricingRule 70, ProductOverride 80, CrmPipeline 90, AutoCreateSkipRule 100,
CustomerGroup 110, StockUpdaterActions 120. (Adjust to keep any deliberate adjacency; the ONLY requirement is
uniqueness within the group.) Competitor-Feeds children: give CsvIngestIssues + FtpCredentials distinct sorts
(e.g. 50 / 55). Verify NO two items in the same (group or parent) share a sort after the change.

=== Explicit labels ===
Add `protected static ?string $navigationLabel = '...';` to: CrmFieldMappingResource ('CRM Field Mappings'),
CrmStatusMappingResource ('CRM Status Mappings'), CsvParseErrorResource ('CSV Parse Errors'),
CompetitorIngestRunResource ('Competitor Ingest Runs').

=== OPTIONAL (best-effort) CRM nesting ===
If clean, nest CrmStatusMappingResource + the CRM Pipeline Settings page under CrmFieldMappingResource by giving
CrmFieldMappingResource `$navigationLabel='CRM'` (parent) and the other two `$navigationParentItem='CRM'` (mirror
Competitor Feeds). If this risks the panel not booting or is awkward, SKIP nesting — just keep the unique sorts +
explicit labels — and note it in the SUMMARY. Do not force it.

=== NEW app/Domain/Sync/Policies/SupplierPolicy.php (mirror SyncRunPolicy) ===
```php
<?php
declare(strict_types=1);
namespace App\Domain\Sync\Policies;
use App\Domain\Sync\Models\Supplier;
use App\Models\User;

final class SupplierPolicy
{
    public function viewAny(User $user): bool  // shared-workspace read (non-secret supplier metadata)
    {
        return $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']);
    }
    public function view(User $user, Supplier $supplier): bool { return $this->viewAny($user); }
    public function create(User $user): bool { return false; } // supplier rows are sync-owned
    public function update(User $user, Supplier $supplier): bool { return false; }
    public function delete(User $user, Supplier $supplier): bool { return $user->hasRole('admin'); }
    public function sync(User $user): bool { return $user->hasAnyRole(['admin', 'pricing_manager']); } // matches SupplierResource inline gating
}
```
Register in AppServiceProvider (next to the other Gate::policy lines ~450-611): `Gate::policy(\App\Domain\Sync\Models\Supplier::class, \App\Domain\Sync\Policies\SupplierPolicy::class);`. If PolicyTemplateIntegrityTest enumerates policy dirs, confirm Sync is covered (it already checks SyncRunPolicy's dir).
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: nav de-collision + labels + brand + SupplierPolicy</name>
  <files>
    app/Providers/Filament/AdminPanelProvider.php,
    app/Domain/Sync/Policies/SupplierPolicy.php,
    app/Providers/AppServiceProvider.php,
    app/Domain/Sync/Filament/Resources/SupplierResource.php
  </files>
  <behavior>
    Apply the <interfaces> changes. The navigationSort + navigationLabel edits touch several Resource/Page files
    (CrmFieldMapping, CrmStatusMapping, CsvParseError, CompetitorIngestRun, IntegrationCredential, AlertRecipient,
    AutoCreateSettings, PricingRule, ProductOverride, CrmPipeline settings page, AutoCreateSkipRule, CustomerGroup,
    StockUpdaterActions, CompetitorFtpCredential, CsvIngestIssues page — edit whichever hold the colliding sorts) —
    add those to files_modified as you touch them. Create + register SupplierPolicy. If SupplierResource needs a
    tiny tweak to call `authorize('sync')` for consistency, fine, but the inline hasAnyRole gating must remain
    functionally identical (no access regression).
  </behavior>
  <action>
    Edit the panel provider (group + brand + favicon + comment); de-collide every colliding navigationSort (unique
    within group/parent); add the 4 explicit labels; (best-effort) nest CRM config; create + register SupplierPolicy.
    Verify the panel boots + policy conforms + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Architecture/PolicyTemplateIntegrityTest.php 2>&1 | tail -6</automated>
    Expected: GREEN (SupplierPolicy has no placeholder leaks + references hasRole/hasAnyRole).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Dashboard/HomeDashboardPageTest.php tests/Feature/ProductAutoCreate/ProductResourcePinTabTest.php 2>&1 | tail -6</automated>
    Expected: GREEN — the admin panel + a couple of resources still boot/render (no nav discovery error). (If HomeDashboardPageTest is the isolation-only one, substitute another currently-green Filament resource/page test that boots the panel.)
    <automated>~/.config/herd/bin/php84/php.exe artisan about --only=environment 2>&1 | tail -3; ~/.config/herd/bin/php84/php.exe artisan route:list --path=admin 2>&1 | tail -3</automated>
    Expected: no exception (panel + routes resolve — proves no duplicate-nav / bad navigationParentItem error).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint app/Providers/Filament/AdminPanelProvider.php app/Domain/Sync/Policies/SupplierPolicy.php app/Providers/AppServiceProvider.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - Woo Maintenance group registered; all navigationSort collisions resolved (unique per group/parent); CRM/CSV labels explicit; brandName+favicon set; stale Quotes comment fixed; SupplierPolicy created + registered + integrity-test green; panel/routes boot; no access regression; pint clean.
  </done>
</task>

</tasks>

<verification>
1. PolicyTemplateIntegrityTest → GREEN
2. A Filament panel/resource test boots → GREEN
3. `artisan route:list --path=admin` → no exception (nav sane)
4. pint → PASS

Operator notes (for SUMMARY.md):
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration). Nav re-orders +
  brandName/favicon appear after deploy (asset cache clear runs in deploy). SupplierPolicy is additive RBAC.
- Records: Woo Maintenance now a proper group; Settings order deterministic; CRM/CSV labels consistent; product
  identity (brand + favicon) set; Supplier read gated via policy (Shield-manageable) while inline write gating unchanged.
- Deferred/best-effort: CRM-config nesting under a 'CRM' parent (done only if clean; else noted).
</verification>

<success_criteria>
- Woo Maintenance group registered; navigationSort unique per group/parent (deterministic order); CRM/CSV labels explicit; brandName+favicon added; stale comment fixed; SupplierPolicy created+registered (integrity-test green); panel + admin routes boot; no authorization regression; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260710-pdw-admin-nav-polish-supplierpolicy-register/260710-pdw-SUMMARY.md` documenting the nav group registration, the sort de-collision map, the label + brand/favicon changes, the SupplierPolicy, and whether CRM nesting was done or skipped (with reason).
</output>