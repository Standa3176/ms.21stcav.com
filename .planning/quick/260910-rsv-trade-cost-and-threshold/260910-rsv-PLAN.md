---
id: 260910-rsv
slug: trade-cost-and-threshold
date: 2026-09-10
status: complete
---

# Absolute trade cost, and a minimum discount worth publishing

Two gaps the first live run of 260910-lwv exposed. Both block loading real
manufacturer terms, so they come before any brand rules or price-list data.

## 1. The 458 penny discounts

The first preview over 4,337 published products returned:

| outcome | n | share |
|---|---|---|
| at target | 3,386 | 78.1% |
| retail_capped | **458** | 10.6% |
| suppressed | 493 | 11.4% |

Every one of those 458 got **exactly 1p off**, by construction:
`price = min(target, retail - 1p)`, so wherever `cost + 15%` exceeds retail the
price IS retail minus a penny. A trade customer shown GBP 2,787.60 against a
public GBP 2,787.61 is being insulted, not served — and it is worse than
publishing nothing, because B2BKing falls back to the standard price, which is
the honest outcome.

The ceiling now also enforces `b2b.storefront.min_discount_pct` (default 2.0,
env-overridable). Below that threshold the product suppresses. Setting it to 0
restores the old behaviour exactly, and a test pins that.

**Expect the suppressed count to rise sharply** — most of those 458 have no
room above the 6% floor once a real discount is demanded. That is the correct
answer, not a regression.

## 2. Percentages decay; a price list does not

260910-lwv stored `adjustment_pct` only, because the operator described the
arrangements as "% extra off the price being fed". Measuring the August Yealink
platinum list against the live feed showed why that is insufficient:

- the gap is **bimodal** — 41 of 70 matched products cluster at 11.1-11.2%,
  but 22 sit in a distinct 5-9% tier, so no single percentage is right
- a manufacturer list is a **fixed price for the month**, while a percentage
  silently drifts every time the feed moves

`absolute_cost` (ex-VAT, matching `products.buy_price`) can now be stored
instead. The percentage stays for genuine "N% off whatever they quote" deals
where no per-line list exists.

## Resolution

`TradeCostAdjustmentResolver` now returns a `TradeCost` — pennies plus the
SOURCE (`feed` / `percentage` / `price_list`) and the effective fraction.
Source is carried because when a trade price looks wrong the first question is
always *which cost did this use*, and that has to be answerable from the
preview rather than by re-deriving it.

Precedence is unchanged in spirit: a SKU row beats a brand row even when it is
the less generous (a per-SKU entry is a deliberate override); within a scope
the cheapest resulting cost wins. A price-list row and a percentage row compete
on the same footing once both are reduced to pennies.

**New guard: a row that makes trade cost MORE than the feed is ignored.** Three
Yealink lines came back with the platinum list *above* feed cost —
`RCH80` -22.9%, `CM50` -23.1%, `CS10-D` -23.2%, three "independent" results
within 0.3% of each other, which is a systematic cause rather than coincidence
(prime suspect: SKU homonyms, per the `CP4` precedent). Whatever the reason,
honouring such a row would price trade above retail. Pinned by test.

## Verification

72 tests in `tests/Feature/TradePricing`, 204 across TradePricing +
Architecture + Security. Deptrac 0 violations. Pint clean.

The pricer tests use real catalogue numbers — the ViewSonic `CDE7531-1C` at
GBP 698 cost, the Logitech `960-001311` sitting exactly on its 6% floor, and
Yealink `A24` at its real platinum price of GBP 773 against a GBP 870 feed
cost — so a later reader can check them against the storefront.

Two test failures during the build were mine, not the code's: both were
fixtures where the floor bound before the case I meant to exercise. Cost 100.00
gives a 127.20 floor inc-VAT, which is easy to trip over.

## NOT done — needs operator numbers, not code

- **Brand pricing rules** (Neat, Yealink, Poly, Logitech). The engine has
  supported a brand layer since Phase 3 and it has never been populated; these
  are commercial figures, not a build.
- **The Yealink platinum rows themselves** — 70 matched SKUs are ready to load
  once the three suspect homonyms are checked.
- **Logitech deal** unquantified.
- Trade sync still is NOT wired into the daily push, and there is still no
  nightly reconcile for cost movement or expiring arrangements.
