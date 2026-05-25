# Quick Task 260525-szo — Summary

**Description:** Supplier cost accuracy (cheapest-in-stock + obsolescence) + Pricing Operations dashboard UX.
**Date:** 2026-05-25 · **Status:** ✅ Shipped + pushed (retroactive GSD record).

## Commits
- `4eda8b5` — fix(sync): buy_price = cheapest IN-STOCK supplier, not latest-updated row
- `9b21671` — feat(sync): `supplier:explain-cost {sku}` cost-traceability diagnostic
- `1da47cd` — feat(sync): `supplier:db-sync --flag-obsolete` demotes no-supplier products to pending
- `645030d` — feat(pricing): clickable Pricing Ops tiles → modal + CSV export
- `d028ad4` — feat(admin): active-nav highlight + filterable dashboard modals + XLS export

## What shipped
- **🔴 Cost bug fixed (operator-found):** `supplier:db-sync` was costing each SKU at the latest-*updated* supplier row (ignoring price + stock). Now pulls every supplier from `feeds_products` and `buildBestOfferMap` picks **cheapest in-stock** (fallback cheapest); stock = sum across in-stock suppliers. Ran live: **1,772 of 3,863 matched SKUs re-costed** (~46% were wrong). floor-report after: below-cost 517→415, winnable 90.5%, median achievable margin 15.59%.
- **`supplier:explain-cost {sku}`:** reads the live feed for one SKU, prints every supplier's price/stock/updated/EXCL + which sets buy_price. Reusable for the below-cost review.
- **Obsolescence:** `--flag-obsolete` demotes published / non-custom / non-excluded products with NO supplier offer → `status=pending` for review (mirrors MarkMissingSkusJob on the unused supplier_api path). Ran live: **160 demoted**. Local-only pre-cutover (Woo push deferred to cutover step C-NEW). Operator chose immediate trigger.
- **Dashboard UX:** the 4 summary tiles are clickable → modal with full rows + **filter box** (Alpine) + **Export CSV & XLS** (real .xlsx via spatie/simple-excel); panels export too. Active-nav "you are here" highlight (operator couldn't tell the selected page); Home ordered above Pricing Ops.

## Tests
Scanner 4 · report/export 5 (incl. XLSX) · sync 9 (incl. cheapest-in-stock + isObsoleteCandidate + MUYHSMFFADW case) · page render 3 — all green.

## Open follow-ups (offered, not built)
- Scope dashboard/pricing analysis to `status=publish` only (so the 160 pending drop off below-cost panels).
- Add `--flag-obsolete` to the scheduled `supplier:db-sync`.
- Build `products:push-status-to-woo` for the flip-time obsolete unpublish (runbook C-NEW).
