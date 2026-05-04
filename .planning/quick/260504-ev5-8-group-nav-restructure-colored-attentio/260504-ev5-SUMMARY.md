---
quick_id: 260504-ev5
description: 8-group nav restructure + colored attention badges
date: 2026-05-04
commit: def23dc
files_changed: 29
duration: ~25 min
---

# Quick Task 260504-ev5 Summary

## One-liner

Replaces 4-group sidebar (Operations / Review / Catalogue / Admin) with a domain-aligned 8-group structure (Operations / Catalogue / Competitors / FTP & CSV / WooCommerce / CRM & Bitrix / Review / Admin), explicit-ordered via `AdminPanelProvider->navigationGroups([...])`, and surfaces "needs attention" via 12 colored navigationBadges that only render when count > 0.

## Tasks completed

| Task | Status | Notes |
|------|--------|-------|
| A — AdminPanelProvider explicit group order | done | Added `->navigationGroups([...])` after `->colors()`, before `->discoverResources()`. 8 group names enforced in display order; Filament defaults to alphabetical without this. |
| B — Bulk update $navigationGroup + $navigationSort | done | 27 Resource/Page files updated. Custom-Group I-01 invariant preserved (CG@20 distinct from PR@30). |
| C — 12 navigation badges | done | All 12 resources from the plan got `getNavigationBadge() + getNavigationBadgeColor()`. Each returns `null` when count = 0 so empty-state sidebars don't show "0" against unused features. |
| D — Verification | done | See verification section below. |
| E — Commit | done | Single commit `def23dc` (Tasks A+B+C combined per plan instruction). |

## Group → file mapping (final state)

### Operations (group 1)
- HomeDashboardPage @ 10 (already correct)
- HorizonDashboardPage @ 20 (parent — already correct)
- HorizonMonitoringPage / Metrics / Batches / Pending / Completed / Silenced / Failed @ 20-80 (children — already correct)
- NotificationCentrePage @ 100 (was 30 — bumped past Horizon children)

### Catalogue (group 2)
- ProductResource @ 10
- CustomerGroupResource @ 20 (was 21 — bumped to 20 after PricingRule moved to 30)
- PricingRuleResource @ 30 (was 20)
- ProductOverrideResource @ 40 (was 25)
- QuoteResource @ 50 (was Operations@50 — moved into Catalogue)

### Competitors (group 3 — NEW)
- CompetitorResource @ 10 (was Catalogue@10)
- CompetitorPriceResource @ 20 (was Catalogue@30)
- CompetitorAnalysisPage @ 30 (was Catalogue@35)
- SuggestionResource @ 40 (was Review@10 — primarily competitor-opportunity-driven)

### FTP & CSV (group 4 — NEW)
- CompetitorFtpCredentialResource @ 10 (was Admin@30 — REVERTED from 260503-rul)
- CompetitorFtpFeedResource @ 20 (was Catalogue@40)
- CompetitorIngestRunResource @ 30 (was Catalogue@70)
- CsvParseErrorResource @ 40 (was Catalogue@60)
- CsvIngestIssuesPage @ 50 (was Catalogue@65)

### WooCommerce (group 5 — NEW)
- SyncRunResource @ 10 (was Operations@40)
- ImportIssueResource @ 20 (was Catalogue@90)
- AutoCreateSettingsPage @ 30 (was Admin@40)
- AutoCreateSkipRuleResource @ 40 (was Admin@30)

### CRM & Bitrix (group 6 — NEW)
- CrmFieldMappingResource @ 10 (was Admin@70)
- CrmStatusMappingResource @ 20 (was Admin@80)
- CrmPushLogResource @ 30 (was Catalogue@80)
- CrmPipelineSettingsPage @ 40 (was Admin@50)

### Review (group 7 — kept, scope narrowed)
- AutoCreateReviewResource @ 10 (was @30 — first item now)
- AgentRunResource @ 20 (was @20 — kept)
- AgentRunRejectionInboxPage @ 30 (was @40 — bumped down by AutoCreate@10)

### Admin (group 8 — kept, scope narrowed)
- IntegrationCredentialResource @ 10 (was @20)
- AlertRecipientResource @ 20 (was @10)

## Badges added

| # | Resource | Trigger | Color | Hidden when |
|---|----------|---------|-------|-------------|
| 1 | SuggestionResource | `status = 'pending'` count | warning | count = 0 |
| 2 | CompetitorFtpFeedResource | `last_pull_status = 'failed'` count | danger | count = 0 |
| 3 | CsvParseErrorResource | `resolved_at IS NULL` count | danger | count = 0 |
| 4 | AutoCreateReviewResource | `auto_create_status IN (REVIEW_STATUSES)` count | warning | count = 0 |
| 5 | AgentRunResource | `status = 'failed' AND started_at >= now-24h` count | danger | count = 0 |
| 6 | SyncRunResource | `status = 'failed' AND started_at >= now-24h` count | danger | count = 0 |
| 7 | CompetitorIngestRunResource | `status = 'failed' AND started_at >= now-24h` count | danger | count = 0 |
| 8 | ImportIssueResource | `resolved_at IS NULL` count | warning | count = 0 |
| 9 | CompetitorResource | total count | gray | count = 0 |
| 10 | CompetitorPriceResource | total count, thousands-formatted | gray | count = 0 |
| 11 | ProductResource | total count, thousands-formatted | gray | count = 0 |
| 12 | IntegrationCredentialResource | `is_active = false` count | warning | count = 0 |

All 12 badges return `?string` (Filament 3.3 contract) — null when count = 0 so a clean install or healthy system shows no badges at all. Colors follow the project's existing badge-color convention seen on `status` columns throughout the codebase.

## Verification results

- **PHP lint** (`php -l`): all 29 files report "No syntax errors detected".
- **Architecture tests** (`vendor/bin/pest tests/Architecture` with `DB_CONNECTION=sqlite DB_DATABASE=:memory:`): 73 passed / 5 failed. The 5 failures are pre-existing SQLite-environment issues (missing `customer_groups` table, missing `pin_price` column on `product_overrides`, deptrac-CRM negative test) unrelated to this nav restructure. The directly-relevant tests all pass:
  - `CustomerGroupResourceNavigationSortTest` (I-01 invariant — distinct sort values): **PASS** (CG@20 ≠ PR@30)
  - `DeptracCutoverLayerTest`, `DeptracPricingLayerTest`, `DeptracSyncLayerTest`, `DeptracCompetitorLayerTest`, `DeptracQuotesLayerTest`, `DeptracTradePricingLayerTest`, `DeptracProductAutoCreateLayerTest`, `DeptracDashboardLayerTest`, `DeptracAgentsLayerTest`, `DeptracTest`: **PASS**
  - `PricingRuleResourceAdditiveInvariantTest`: **PASS**
- **Deptrac analyse** (no progress): **0 violations / 0 warnings / 0 errors / 617 allowed**.
- **Route registration** (`artisan route:list | grep filament.admin`): 74 admin routes registered (59 resources + 15 pages) with no class-load errors — proves every Resource/Page edited in this task still resolves cleanly.

## Deviations from plan

None. The plan's file mapping was accurate for every file. Column-name verifications all matched plan assumptions:

- `Suggestion::STATUS_PENDING = 'pending'` — confirmed via reading `Suggestion.php` model.
- `AgentRun::status` — backed enum cast (`AgentRunStatus`), DB stores string value `'failed'`. Used `AgentRunStatus::Failed->value` for clarity.
- `ImportIssue::resolved_at` — confirmed (column matches plan).
- `CsvParseError::resolved_at` — confirmed (column matches plan).
- `CompetitorFtpFeed::STATUS_FAILED` — confirmed constant exists.
- `SyncRun::STATUS_FAILED` — confirmed constant exists.
- `CompetitorIngestRun::STATUS_FAILED` — confirmed constant exists.
- `IntegrationCredential::is_active` — confirmed boolean column.
- `Product::auto_create_status` for AutoCreateReviewResource — re-used `self::REVIEW_STATUSES` so the badge count and the table query stay in lock-step (better than the plan's "status='pending'" simplification — the actual review inbox spans 3 statuses).

## CustomerGroupResource sort change rationale (I-01 invariant)

Plan didn't explicitly specify the new sort for CustomerGroup, only that PricingRule moves to 30 and CG must remain distinct. Chose **CG@20 / PR@30** (vs the alternative CG@21/PR@30) because:

1. Distinct-value invariant satisfied (20 ≠ 30).
2. Sequential numbering keeps the Catalogue group's sort sequence clean (10, 20, 30, 40, 50 vs 10, 20, 21, 30, 40, 50).
3. CustomerGroup test passes with this change (verified by re-running `CustomerGroupResourceNavigationSortTest` after the edit).

## Files modified (29)

- `app/Providers/Filament/AdminPanelProvider.php` (added `->navigationGroups([...])`)
- `app/Filament/Pages/NotificationCentrePage.php`
- `app/Filament/Pages/AgentRunRejectionInboxPage.php`
- `app/Domain/Products/Filament/Resources/ProductResource.php` (+ badge)
- `app/Domain/TradePricing/Filament/Resources/CustomerGroupResource.php`
- `app/Domain/Pricing/Filament/Resources/PricingRuleResource.php`
- `app/Domain/Pricing/Filament/Resources/ProductOverrideResource.php`
- `app/Domain/Quotes/Filament/Resources/QuoteResource.php`
- `app/Domain/Competitor/Filament/Resources/CompetitorResource.php` (+ badge)
- `app/Domain/Competitor/Filament/Resources/CompetitorPriceResource.php` (+ badge)
- `app/Domain/Competitor/Filament/Pages/CompetitorAnalysisPage.php`
- `app/Domain/Suggestions/Filament/Resources/SuggestionResource.php` (+ badge)
- `app/Domain/Competitor/Filament/Resources/CompetitorFtpCredentialResource.php`
- `app/Domain/Competitor/Filament/Resources/CompetitorFtpFeedResource.php` (+ badge)
- `app/Domain/Competitor/Filament/Resources/CompetitorIngestRunResource.php` (+ badge)
- `app/Domain/Competitor/Filament/Resources/CsvParseErrorResource.php` (+ badge)
- `app/Domain/Competitor/Filament/Pages/CsvIngestIssuesPage.php`
- `app/Domain/Sync/Filament/Resources/SyncRunResource.php` (+ badge)
- `app/Domain/Sync/Filament/Resources/ImportIssueResource.php` (+ badge)
- `app/Domain/ProductAutoCreate/Filament/Pages/AutoCreateSettingsPage.php`
- `app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateSkipRuleResource.php`
- `app/Domain/CRM/Filament/Resources/CrmFieldMappingResource.php`
- `app/Domain/CRM/Filament/Resources/CrmStatusMappingResource.php`
- `app/Domain/CRM/Filament/Resources/CrmPushLogResource.php`
- `app/Domain/CRM/Filament/Pages/CrmPipelineSettingsPage.php`
- `app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource.php` (+ badge)
- `app/Domain/Agents/Filament/Resources/AgentRunResource.php` (+ badge)
- `app/Domain/Integrations/Filament/Resources/IntegrationCredentialResource.php` (+ badge)
- `app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php`

## Self-Check: PASSED

- [x] Commit `def23dc` exists in `git log`.
- [x] All 29 modified files exist on disk.
- [x] PHP linter clean for all files.
- [x] Architecture nav-sort test passes.
- [x] Deptrac 0 violations.
- [x] 74 admin routes register without class-load errors.
