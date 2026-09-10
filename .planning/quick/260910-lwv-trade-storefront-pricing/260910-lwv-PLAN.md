---
id: 260910-lwv
slug: trade-storefront-pricing
date: 2026-09-10
status: complete
---

# Trade pricing on the storefront, published to B2BKing

Operator wants logged-in trade customers to see their own price on
meetingstore.co.uk. B2BKing is already installed and configured with two groups.

## What was already there

Phase 9 built a trade pricing engine — `customer_groups`, group-scoped
`pricing_rules`, `TradeRuleResolver`, `RoleToGroupMapper` — and none of it is
configured: `customer_groups` is **empty** and `PricingRule` holds exactly three
`default_tier` rows.

More importantly, `TradeRuleResolver` is the **wrong tool for the storefront**.
It resolves a MARGIN over cost, which is right for the ops-side quote flow where
a human reads the number. But retail here is not rule-priced —
`pricing:undercut-competitors` sets it from competitor data. Measured over the
188 newest products on 2026-09-06: `121 undercut, 42 floored, 18 margin`. 163 of
188 were competitor-driven, and for 159 the rule price sat **10-27% ABOVE** the
live price. A lower trade margin would have quoted trade customers MORE than the
public pays across most of the catalogue.

So the retail price had to become a hard ceiling, and a new pricer was needed.

## The policy (operator, 2026-09-09/10)

```
cost    = buy_price x (1 - trade_cost_adjustment)    trade-only, see below
target  = cost + 15%     max trade margin
floor   = cost + 6%      min trade margin
ceiling = retail - 1p    never at or above the public price

price   = min(target, ceiling), SUPPRESSED when that falls below floor
```

Modelled across 4,340 published products before writing any code:

| outcome | n | |
|---|---|---|
| at target (15%) | 3,398 | 78% |
| capped by retail | 407 | 9% |
| suppressed | 535 | 12% |

Median trade discount **10.2%** off retail. The scheme works catalogue-wide on
feed cost alone — the manufacturer arrangements below are an enhancement, not a
prerequisite. That reversed my earlier advice that cost accuracy had to land
first.

**Suppression is a real outcome, not a failure.** On a product retail has already
floored at 6% there is nothing to give away; B2BKing falls back to the retail
price. The live Logitech `960-001311` is exactly this case — retail GBP 3258.58
sits precisely on the 6% floor over its GBP 2561.78 cost.

## Trade-only cost adjustments

The daily feed does not carry the cost actually paid: it quotes SILVER-level
Yealink while the business buys at platinum, and a 2-month Logitech deal is
absent entirely. There was **no cost-override concept in the app at all** —
`buy_price` is written straight from the feed.

`trade_cost_adjustments` expresses "our real trade cost is N% below the feed",
scoped by brand or SKU, with `valid_from`/`valid_until` so a manufacturer deal
lapses on its own date instead of relying on somebody remembering.

**It is consumed ONLY by the trade pricer.** `products.buy_price` keeps the feed
value untouched, so retail pricing, health-check, divergence scan and supplier
comparison all keep reading one unchanged column — and the better cost is spent
on trade customers rather than given away at retail, which is what the operator
asked for. Nothing in the byte-locked v1 pricing path changes.

Resolution: a SKU row beats a brand row even when it is the smaller discount (a
per-SKU entry is a deliberate override); within a scope the largest wins.

## Publishing

Proven live on 2026-09-09 before any of this was written: the app CAN write
B2BKing's per-group price meta through the existing `WooClient`. Wrote `4000` to
product 15716 and read it back; `regular_price` was untouched.

- key shape `b2bking_regular_product_price_group_{GROUP_ID}`, group **167507**
  ("B2B Users"; 167509 exists and is empty)
- one key per group, NOT a serialised blob, so writing one cannot damage another
- protected meta is not an obstacle — the app already writes
  `_yoast_wpseo_metadesc` the same way

`trade:sync` writes that one key. **Dry-run is the default**; `--live` writes.
A suppressed product has its meta CLEARED rather than left stale — a trade price
surviving a cost rise would quietly keep selling at a loss.

## Files

- `trade_cost_adjustments` migration + `TradeCostAdjustment` model + factory
- `TradeStorefrontPricer` — the policy, pure, VAT delegated entirely to
  `PriceCalculator` (no new VAT arithmetic; the one near-miss on this codebase
  was a double strip introduced by a second place doing its own maths)
- `TradeCostAdjustmentResolver` — memoised, most-specific-wins
- `TradePrice` — result carrying `source` and `reason`, because "what price" is
  never the whole question
- `trade:preview` — read-only, no Woo calls; `--adjust=12` models a hypothetical
  brand discount so its effect is a number rather than an argument
- `trade:sync` — dry-run by default

## Deptrac

The commands live in `app/Domain/Pricing/Console/Commands/`, not TradePricing.
TradePricing's allow-list is `[Foundation, Pricing, Products, Webhooks]` — it
must never reach into Sync, and `DeptracTradePricingLayerTest` guards that.
Pricing may depend on BOTH Sync and TradePricing, so a command reading a trade
price and writing it to Woo belongs there. Widening TradePricing's allow-list
would have been the easy fix and the wrong one. **0 violations.**

## Verification

65 tests pass in `tests/Feature/TradePricing`. The pricer cases use real numbers
off the live catalogue so a later reader can check them against the storefront.
Notably pinned: suppression on the live Logitech, meta CLEARED for suppressed
products, `regular_price` never present in the payload, dry-run writing nothing,
and refusal to run when no group is configured.

One test failure was mine, not the code's: a cost-100/retail-100 fixture is
already loss-making once VAT is applied, so suppression was correct.

## NOT done — deliberately

- **Not wired into the daily push.** `PushPriceChangeToWoo` is untouched. Trade
  prices are published by running `trade:sync --live` explicitly. Wiring it into
  the daily path is slice 2, once the numbers have been seen on real products.
- **No Filament UI** for adjustments yet — rows go in by hand for now.
- **No nightly sweep.** Only retail-price changes trigger the daily push, but a
  trade price also moves when COST moves and when an adjustment expires, so a
  reconcile pass is needed before this can be left alone.
- The retail tier cap (35%/28% → 25%) discussed alongside this is a SEPARATE
  proposal with its own dry-run — it reprices ~2,166 live products and should
  not ride along with a new feature.
