# Quick Task 260525-gtv — Plan

**Description:** Simplify the Filament admin navigation — operator feedback that the menu was "over complex for what we are doing."
**Date:** 2026-05-25
**Mode:** quick (retroactive record — work shipped before logging, per the same pattern as 260524-qqn)

## Problem

The admin panel had **8 navigation groups** and ~40 leaf items. The complexity wasn't the number of screens (a sync/pricing/CRM tool needs them) but that **set-once configuration was scattered through the daily-use groups**, making every group look busy when the real daily surface is small: Home → Review → Competitor Prices → Pricing → Products. Specific defects:
- One-page **orphan group "FTP & CSV"** (a single page, not even in the panel's `navigationGroups()` order list, so it rendered unsorted at the bottom).
- Stale panel-provider comments referencing "Sales"/"Pricing" groups that no longer existed in code.
- 7-item "Competitors" group mixing daily intel (Prices, Analysis) with 5 feed-plumbing screens.

## Tasks

### Task 1 — Consolidate 8 groups → 6
- Move all rarely-touched, set-once screens into a single group.
- Merge operational logs (Sync Runs, Import Issues, CRM Push Log) into one "Sync & CRM" group.
- Remove the `WooCommerce`, `CRM & Bitrix`, `Admin`, and orphan `FTP & CSV` groups.
- Rewrite `AdminPanelProvider::navigationGroups()` order + refresh the stale comment.
- **Files:** ~20 Filament Resource/Page `$navigationGroup` strings + `app/Providers/Filament/AdminPanelProvider.php` + `lang/vendor/filament-shield/en/filament-shield.php` (Shield Roles group).
- **Verify:** `php -l` clean; `php artisan route:list` resolves all admin routes; no stale group names remain.

### Task 2 — Rename "Configuration" → "Settings" + nest competitor feeds
- Rename the set-once group to the clearer "Settings".
- Nest the 5 feed-plumbing screens (FTP Credentials, FTP Feeds, Ingest Runs, CSV Parse Errors, CSV Ingest Issues) under a **"Competitor Feeds"** parent — `CompetitorResource` relabelled from "Competitors", others set `navigationParentItem = 'Competitor Feeds'` (the same pattern as the existing Horizon sub-menu).
- **Verify:** `php -l` + Pint clean; panel boots; "Settings" shows one expandable "Competitor Feeds" item instead of 6 flat rows.

## Final groups (6)

| Group | Items |
|---|---|
| Operations | Home, Notifications, Horizon ▸ |
| Catalogue | Products, Quotes, Price History |
| Review | Auto-Create Review, Suggestions, Agent Runs |
| Competitors | Competitor Prices, Analysis |
| Sync & CRM | Sync Runs, Import Issues, CRM Push Log |
| Settings | Integration Credentials, Competitor Feeds ▸ (registry + FTP creds/feeds + ingest runs + CSV errors/issues), CRM Status/Field Mapping, Pipeline Settings, Pricing Rules, Product Overrides, Skip Rules, Auto-Create Settings, Customer Groups, Alert Recipients, Roles & Permissions |

## Constraints / non-goals
- **Pure navigation metadata** — no logic, routes, queries, policies, or behaviour changed.
- No new dependencies. RBAC visibility (`canViewAny`) untouched.
