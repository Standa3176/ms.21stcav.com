---
phase: quick-260527-sgp
plan: 01
title: Sourcing gaps view + exclude no-supplier products from cost counts
recorded: retroactive (shipped then recorded)
completed: 2026-05-27
---

# Quick Task 260527-sgp — Plan

## Request

> build a 'Sourcing gaps' view and remove these products from to add list and
> dont count in any of the dash counter as if we have no supplier, they are very
> likely obsolete or we need to find a supplier.

## Interpretation

A "sourcing gap" = a part a **competitor** currently lists that **no supplier**
carries and we **don't sell**. Two obligations:

1. Surface them in their own dashboard view (tile + popup + export).
2. Keep them out of the actionable counters: not in the cost tiles, not in
   "Products to add".

## Tasks

- **Task 1 — Cost counters exclude no-supplier products (Part A).**
  Add `status='publish'` to `CompetitorPositionScanner`'s product query so
  pending/obsolete (no-supplier) products fall out of below_cost / at_floor /
  winnable / matched. Regression test: a `status='pending'` product with a
  below-cost competitor must NOT count.

- **Task 2 — Sourcing gaps view (Part B).**
  - `SourcingGapScanner` (Pricing): local aggregation of `competitor_prices`
    (windowed, latest-per-competitor, per-sku) − parts we sell − parts any
    supplier carries. Competitor names via raw `DB::select` (deptrac-safe).
  - `SupplierFeedSourceabilityChecker` (Sync): remote `feeds_products` membership
    check for candidate keys (Sync owns the Integrations credential).
  - `pricing:scan-sourcing-gaps` command (cached) + AppServiceProvider
    registration + weekly schedule (Sun 05:30, offset from add-candidates).
  - `PricingOpsReport`: cache key + bucket + `sourcingGaps()` + `csv()` branch.
  - Dashboard tile + filterable modal + CSV/XLS export.

## Deptrac contract

Pricing → Sync ✓, Pricing → WpDirectDb ✓ (competitor_prices + competitors),
Pricing → Products ✓; Sync → Integrations ✓. Pricing must NOT reach Integrations
directly — hence the local/remote split across two services.

## Out of scope

- No removal logic for "Products to add" (≥4 suppliers ⇒ sourceable ⇒ disjoint).
- No Woo writes, no schema change, no change to the OrphanDetector suggestion flow.
