---
phase: 260710-pdw-admin-nav-polish-supplierpolicy-register
plan: 01
subsystem: admin-nav + rbac
tags: [filament, navigation, rbac, policy, polish]
requires:
  - SyncRunPolicy (template mirrored)
  - Supplier model (App\Domain\Sync\Models\Supplier)
provides:
  - Woo Maintenance nav group (registered)
  - deterministic Settings nav order (unique navigationSort)
  - SupplierPolicy (additive RBAC/Shield consistency)
  - brandName + favicon (product identity)
affects:
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Providers/AppServiceProvider.php
tech-stack:
  added: []
  patterns:
    - "hand-written Domain policy mirroring SyncRunPolicy (Pitfall P2-H — no shield:generate)"
    - "unique increasing navigationSort per nav group/parent (gaps of 10)"
key-files:
  created:
    - app/Domain/Sync/Policies/SupplierPolicy.php
  modified:
    - app/Providers/Filament/AdminPanelProvider.php
    - app/Providers/AppServiceProvider.php
    - app/Domain/Competitor/Filament/Resources/CompetitorResource.php
    - app/Domain/CRM/Filament/Resources/CrmFieldMappingResource.php
    - app/Domain/CRM/Filament/Resources/CrmStatusMappingResource.php
    - app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php
    - app/Domain/ProductAutoCreate/Filament/Pages/AutoCreateSettingsPage.php
    - app/Domain/Pricing/Filament/Resources/PricingRuleResource.php
    - app/Domain/Pricing/Filament/Resources/ProductOverrideResource.php
    - app/Domain/CRM/Filament/Pages/CrmPipelineSettingsPage.php
    - app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateSkipRuleResource.php
    - app/Domain/TradePricing/Filament/Resources/CustomerGroupResource.php
    - app/Filament/Pages/Admin/StockUpdaterAdminPage.php
    - app/Domain/Competitor/Filament/Pages/CsvIngestIssuesPage.php
    - app/Domain/Competitor/Filament/Resources/CsvParseErrorResource.php
    - app/Domain/Competitor/Filament/Resources/CompetitorIngestRunResource.php
decisions:
  - "CRM nesting SKIPPED — conflicts with the required explicit 'CRM Field Mappings' label + adds panel-boot risk for zero deterministic-order gain. Unique sorts + explicit labels already deliver a tidy CRM cluster."
  - "SupplierResource left UNTOUCHED — inline hasAnyRole write-gating is the source of truth; the new policy is purely additive (no authz regression)."
metrics:
  duration: ~15m
  completed: 2026-07-10
---

# Phase 260710-pdw Plan 01: Admin Nav Polish + SupplierPolicy Register Summary

Config + additive-policy pass over the Filament admin panel: registered the orphan Woo Maintenance nav group, de-collided every `navigationSort` collision in the Settings group (and its Competitor Feeds children), added explicit CRM/CSV nav labels, set `brandName` + `favicon`, corrected a stale Quotes docblock, and added a hand-written `SupplierPolicy` (mirroring `SyncRunPolicy`) registered via `Gate::policy`. Zero behaviour/authorization change.

## Nav changes made

### Group registration + product identity (AdminPanelProvider)
- Inserted `'Woo Maintenance'` into `navigationGroups()` right after `'Catalogue'` (was orphaned — `WooMaintenanceOverviewPage` + `CatalogueGapsPage` set the group but it was never listed, so it dangled last).
- Added `->brandName('MeetingStore Ops')` + `->favicon(asset('favicon.ico'))` to the panel chain (public/favicon.ico ships in the repo).
- Corrected the stale ~L209 docblock that claimed `QuoteResource` adds a `'Sales'` nav group — it actually lives in `'Catalogue'` (sort 20). Comment fixed; resource NOT moved.

### navigationSort de-collision map (Settings group — top level)
| Resource / Page | Old | New |
|---|---|---|
| IntegrationCredentialResource | 10 | 10 (unchanged) |
| CompetitorResource ("Competitor Feeds") | 10 | 20 |
| CrmFieldMappingResource | 10 | 30 |
| CrmStatusMappingResource | 20 | 40 |
| AlertRecipientResource | 20 | 50 |
| AutoCreateSettingsPage | 30 | 60 |
| PricingRuleResource | 30 | 70 |
| ProductOverrideResource | 40 | 80 |
| CrmPipelineSettingsPage | 40 | 90 |
| AutoCreateSkipRuleResource | 40 | 100 |
| CustomerGroupResource | 25 | 110 |
| StockUpdaterAdminPage | 50 | 120 |

Result: 10/20/30/40/50/60/70/80/90/100/110/120 — all unique within Settings.

### navigationSort de-collision (Competitor Feeds children)
| Child | Old | New |
|---|---|---|
| CompetitorFtpCredentialResource ("FTP Credentials") | 50 | 50 (unchanged) |
| CsvIngestIssuesPage ("CSV Ingest Issues") | 50 | 55 |
| CompetitorFtpFeedResource ("FTP Feeds") | 60 | 60 (unchanged) |
| CompetitorIngestRunResource | 70 | 70 (unchanged) |
| CsvParseErrorResource | 80 | 80 (unchanged) |

Result: 50/55/60/70/80 — all unique among the Competitor Feeds children (only the FtpCredentials/CsvIngestIssues 50=50 collision needed fixing).

### Explicit navigationLabels (correct casing)
- `CrmFieldMappingResource` → `'CRM Field Mappings'`
- `CrmStatusMappingResource` → `'CRM Status Mappings'`
- `CsvParseErrorResource` → `'CSV Parse Errors'`
- `CompetitorIngestRunResource` → `'Competitor Ingest Runs'`

(CsvParseError/CompetitorIngestRun already carried matching `pluralModelLabel`; explicit `navigationLabel` added for determinism per the plan.)

### I-01 invariant preserved
`CustomerGroupResource` (now 110) stays distinct from `PricingRuleResource` (now 70); the reflection-based `CustomerGroupResourceNavigationSortTest` still passes. Its inline comment was updated to reference the new PricingRule sort.

## CRM nesting — SKIPPED (best-effort, not forced)
The optional nesting of CrmStatusMapping + CRM Pipeline Settings under a `'CRM'` parent was **skipped**, for two reasons:
1. **Direct conflict with a required change** — the plan's item 3 mandates the explicit label `CrmFieldMappingResource = 'CRM Field Mappings'`, whereas nesting requires that same resource's label to become the parent `'CRM'`. Can't satisfy both.
2. **Boot risk for no order gain** — `navigationParentItem` must string-match the parent's registered label exactly; a mismatch is a silent nav break. Unique sorts (30/40/90) + explicit labels already cluster the CRM config surface deterministically without that risk.

Per the plan ("Do not force it"), nesting was left out. The three CRM config items remain flat in Settings with correct casing and stable order.

## SupplierPolicy + registration
- **New** `app/Domain/Sync/Policies/SupplierPolicy.php` mirroring `SyncRunPolicy`:
  - `viewAny` / `view` → all 4 roles (`admin`, `pricing_manager`, `sales`, `read_only`) — shared-workspace read of non-secret supplier metadata.
  - `create` / `update` → `false` (Supplier rows are auto-discovered / sync-owned).
  - `delete` → `admin` only.
  - `sync` → `hasAnyRole(['admin','pricing_manager'])` — matches the existing inline `SupplierResource` write-gating.
  - No Shield curly-brace placeholders; references `hasRole`/`hasAnyRole` → conforms to `PolicyTemplateIntegrityTest`.
- Registered in `AppServiceProvider::boot()` next to the other Sync bindings: `Gate::policy(Supplier::class, SupplierPolicy::class);` (+ the two `use` imports).

## No authorization regression
`SupplierResource` was left **untouched** — its inline `hasAnyRole(['admin','pricing_manager'])` gating on the `is_active` toggle column, the form `->disabled` states, and `canCreate()=false` are all functionally identical. The new policy is purely additive RBAC/Shield consistency. No existing `->authorize`/`->visible`/`->disabled` gating was removed anywhere.

## Deviations from Plan
**1. [Rule 1 - Bug] SupplierPolicy docblock tripped the placeholder grep**
- **Found during:** Task 1 verification (first PolicyTemplateIntegrityTest run went RED).
- **Issue:** The policy docblock literally wrote "Shield `{{ }}` placeholder leaks", and the integrity test greps for the two-open-brace-plus-space sequence — the doc text itself was flagged as a leak.
- **Fix:** Reworded to "Shield curly-brace placeholder leaks" (no literal brace sequence).
- **Files modified:** app/Domain/Sync/Policies/SupplierPolicy.php
- Otherwise: plan executed as written; CRM nesting intentionally skipped (documented above).

## Verification results
- `pest tests/Architecture/PolicyTemplateIntegrityTest.php` → **3 passed** (33 assertions) GREEN.
- `pest tests/Architecture/CustomerGroupResourceNavigationSortTest.php` → **2 passed** GREEN (I-01 invariant intact).
- `pest tests/Feature/ProductAutoCreate/ProductResourcePinTabTest.php` (panel/resource boot) → **6 passed** GREEN (used in place of the isolation-flaky HomeDashboardPageTest).
- `artisan route:list --path=admin` → exit 0, **95 routes**, no exception (nav sane — no duplicate/bad navigationParentItem; Woo Maintenance page resolves).
- `pint --test` on the key changed files → **pass**.

## Operator notes
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration). Nav re-orders + brandName/favicon appear after deploy (asset cache clear runs in deploy). SupplierPolicy is additive RBAC.
- Records: Woo Maintenance is now a proper group pinned after Catalogue; Settings order deterministic; CRM/CSV labels consistent; product identity (brand + favicon) set; Supplier read gated via policy (Shield-manageable) while inline write gating unchanged.
- Deferred/best-effort: CRM-config nesting under a 'CRM' parent — skipped (rationale above).
