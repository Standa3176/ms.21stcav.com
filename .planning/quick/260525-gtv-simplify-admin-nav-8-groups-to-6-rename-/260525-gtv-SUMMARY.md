# Quick Task 260525-gtv — Summary

**Description:** Simplify the Filament admin navigation (operator feedback: menu "over complex for what we are doing").
**Date:** 2026-05-25
**Status:** ✅ Done — shipped + pushed to origin/main (recorded retroactively).

## Commits
- `7c9578d` — refactor(admin): simplify nav from 8 groups to 6 (quarantine config)
- `46086a4` — refactor(admin): rename Configuration -> Settings + nest competitor feeds

## What changed

**8 groups → 6.** Removed `WooCommerce`, `CRM & Bitrix`, `Admin`, and the orphan one-page `FTP & CSV` group. All ~18 rarely-touched, set-once screens were quarantined into a single collapsible **"Settings"** group; operational logs were merged into **"Sync & CRM"**.

Final groups: **Operations · Catalogue · Review · Competitors · Sync & CRM · Settings**. Daily-use groups now hold 2–4 items each.

**Competitor feeds nested.** The 5 feed-plumbing screens (FTP Credentials, FTP Feeds, Ingest Runs, CSV Parse Errors, CSV Ingest Issues) now collapse under a **"Competitor Feeds"** parent inside Settings — `CompetitorResource` relabelled from "Competitors" acts as the parent; the others use `navigationParentItem = 'Competitor Feeds'` (same Filament 3.3 parent/child pattern as the Horizon sub-menu). The daily "Competitors" group keeps only Competitor Prices + Analysis.

## Files touched (~22, all metadata)
- **→ Settings** (`$navigationGroup`): ProductOverrideResource, PricingRuleResource, IntegrationCredentialResource, CompetitorResource, CompetitorFtpCredentialResource, CompetitorFtpFeedResource, CompetitorIngestRunResource, CsvParseErrorResource, CsvIngestIssuesPage, CrmStatusMappingResource, CrmFieldMappingResource, CrmPipelineSettingsPage, AutoCreateSkipRuleResource, AutoCreateSettingsPage, CustomerGroupResource, AlertRecipientResource, StockUpdaterAdminPage.
- **→ Sync & CRM**: SyncRunResource, ImportIssueResource, CrmPushLogResource.
- **Nesting**: CompetitorResource (`navigationLabel = 'Competitor Feeds'`) + `navigationParentItem` added to the 5 children.
- **Shield Roles**: `lang/vendor/filament-shield/en/filament-shield.php` `nav.group` → 'Settings'.
- **Panel**: `app/Providers/Filament/AdminPanelProvider.php` `navigationGroups()` order + comment (Pint also normalised its imports + added `declare(strict_types=1)`).

## Verification
- `php -l` clean on all touched files.
- `vendor/bin/pint` clean (style-normalised).
- Boot check: `php artisan route:list` resolves **81 admin routes** — panel + every resource load without error.
- Final group distribution confirmed: Settings 17, Operations 10, Review 4, Sync & CRM 3, Catalogue 3, Competitors 2 (+ Shield Roles → Settings). No stale group names remain.

## Notes / non-goals
- **Pure navigation metadata** — no logic, routes, queries, policies, or behaviour changed. RBAC visibility untouched.
- Deploy via `deploy/deploy.sh` (clears/caches config so the new grouping shows on next page load).
- Possible future follow-up (not done): split "Settings" further or rename "Sync & CRM" if it still feels long once in use.
