---
quick_id: 260822-rmo
slug: woo-throttle-defer-sell-price-reconcile
date: 2026-08-22
status: complete
commits:
  - d18954f feat(260822-rmo) T1 throttle retry-after + defer trait
  - 9602ff9 fix(260822-rmo) T2 Woo write jobs defer on throttle
  - 53e868e feat(260822-rmo) T3 sell_price becomes a reconcilable field
  - (T4) tests
branch: quick/260822-rmo-woo-throttle-sell-price
---

# Summary — Woo throttle defers + permanent sell_price reconciliation

## 1. Confirmed root cause

`WooClient::throttlePace()` signalled back-pressure by THROWING
`WooWriteThrottleException`. A thrown exception consumes a queue attempt, so
`PushPriceChangeToWoo` (`tries = 3`, `backoff = [30, 120, 300]`) spent its
entire retry budget inside the burst window and the job died. The class
docblock's claim that "queued callers requeue automatically (tries/backoff)"
was the incorrect assumption — tries/backoff terminate a job, they do not
wait out a rate limit.

Prod evidence: 5,319 `failed_jobs` rows 2026-08-18 → 2026-08-22 (2046 / 1388 /
998 / 611 / 276), newest exception `Woo live-write rate ceiling (60/min)
reached — requeueing; window resets in 19s`, thrown from
`PushPriceChangeToWoo.php:145`.

The compounding defect: `PushPriceChangeToWoo` had no `failed()` hook, and
`cutover:auto-sync` reconciled only `stock_quantity,buy_price`. So a dead
price push was permanently lost and undetectable.

## 2. Failure timing — ~2.5 minutes, not ~7.5

Verified against the installed laravel/framework 12.56.0 rather than assumed.
`Worker::markJobAsFailedIfWillExceedMaxAttempts()` (Worker.php:587-598) fails
the job when `! retryUntil && maxTries > 0 && attempts() >= maxTries`:

| attempt | at | check | outcome |
|---|---|---|---|
| 1 | T+0 | `1 >= 3` false | release +30s |
| 2 | T+30 | `2 >= 3` false | release +120s |
| 3 | T+150 | `3 >= 3` **true** | **failed** |

Death at **T+150s ≈ 2.5 min**. `backoff[2] = 300` is never reached — the third
value in a 3-try backoff array is unreachable by construction. The operator's
estimate was right; the earlier "~7.5 min" wrongly summed all three backoffs.

## 3. Files changed

| file | change |
|---|---|
| `app/Domain/Sync/Exceptions/WooWriteThrottleException.php` | carries `retryAfterSeconds` (min 1, default 60) |
| `app/Domain/Sync/Services/WooClient.php` | passes the real `RateLimiter::availableIn()` / lock-wait; `WRITE_RATE_LIMITER_KEY` made public |
| `app/Domain/Sync/Support/WooWriteWindow.php` | **new** — read-only "is the write window open?" probe |
| `app/Domain/Sync/Concerns/HandlesWooWriteThrottle.php` | **new** — `releaseForWooThrottle()`, `releaseIfWooWriteWindowClosed()`, `retryUntil()` |
| `app/Domain/Sync/Support/WooWriteMetrics.php` | **new** — daily deferred/failed counters |
| `app/Domain/Sync/Services/WooProductWriter.php` | re-throws the throttle instead of masking it as `status='error'`; new `sell_price` branch + sale guard; `fields_skipped` in the return shape |
| `app/Domain/Sync/Contracts/SellPriceFormatter.php` | **new** — Sync-owned contract for the sell_price → regular_price mapping |
| `app/Domain/Pricing/Services/WooRegularPriceFormatter.php` | **new** — the single implementation (VAT basis lives in Pricing) |
| `app/Providers/AppServiceProvider.php` | binds the contract to the implementation |
| `app/Domain/Pricing/Listeners/PushPriceChangeToWoo.php` | trait + `maxExceptions` + throttle catch + `failed()` |
| `app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php` | trait + `maxExceptions` + catch on both write paths |
| `app/Domain/ProductAutoCreate/Jobs/CreateWooProductJob.php` | trait + `maxExceptions` + **preflight only** (see risks) |
| `app/Domain/ProductAutoCreate/Jobs/ProcessAutoCreateImageJob.php` | trait + `maxExceptions` + catch |
| `app/Domain/ProductAutoCreate/Listeners/PushProductFieldsToWoo.php` | trait + `maxExceptions` + catch |
| `app/Console/Commands/PushDivergenceToWooCommand.php` | `sell_price` in `SUPPORTED_FIELDS`; throttle wait-and-retry; skip/throttle tallies; dry-run price detail table |
| `app/Console/Commands/Cutover/AutoSyncDivergenceCommand.php` | default `--field` → `stock_quantity,buy_price,sell_price` |
| `routes/console.php` | 23:00 schedule entry carries `sell_price` |
| `config/services.php` | `services.woo.write_retry_until_minutes` (env `WOO_WRITE_RETRY_UNTIL_MINUTES`, default 180) |

## 4. Throttle behaviour, before vs after

| | before | after |
|---|---|---|
| ceiling hit | `throw` → consumes an attempt | caught → `release($availableIn)` |
| delay | fixed `backoff` (30/120/300) | the real window reset the limiter reports |
| survives a burst | no — dead at T+150s | yes — until `retryUntil` |
| genuine Woo 500 | fails after 3 attempts | fails after 3 **uncaught exceptions** (`maxExceptions`) |
| operator signal | none (silent `failed_jobs` row) | `woo.write_throttled_deferred` info log + daily counter |
| lost price | permanent | re-driven by the nightly reconciler |

Unchanged on purpose: `WOO_WRITE_MAX_PER_MINUTE=60`, the 250ms spacing and the
`woo:write` lock. The ceiling was never the bug — the reaction to it was.

## 5. retryUntil policy

`now()->addMinutes(config('services.woo.write_retry_until_minutes', 180))`.

- **Long enough**: 3h at the 60/min ceiling covers ~10,800 writes, well past a
  full-catalogue repricing burst.
- **Bounded**: expires long before the next daily run, so a job can never
  pile onto its own successor. Not an infinite retry loop.
- **Paired with `maxExceptions`, always.** `retryUntil` makes the queue skip
  the attempts check entirely (Worker.php:559-576), so without
  `maxExceptions` a genuinely broken job would retry for the full 3h. An
  audit test enforces that every trait adopter declares it.

## 5a. Layer violation found and fixed mid-task

The first cut injected `PriceCalculator` (Pricing) into `WooProductWriter`
(Sync) for the VAT basis. Deptrac rejected it —
`Sync: [Foundation, Products, Alerting, Integrations, -WpDirectDb]`; the
permitted arrow is Pricing → Sync, never the reverse. Two architecture tests
(`deptrac analyse exits 0 against depfile.yaml` / `deptrac.yaml`) failed on it.

Fixed by dependency inversion, NOT by widening the allow-list: Sync declares
`SellPriceFormatter`, Pricing implements it as `WooRegularPriceFormatter`, and
`AppServiceProvider` binds them. `PushPriceChangeToWoo` was moved onto the
same implementation, so the event-driven push and the nightly reconciler
cannot drift apart on the VAT basis. Duplicating the ex-VAT maths inside Sync
to dodge the boundary would have been the tempting shortcut and a silent 20%
error waiting to happen.

`deptrac analyse` → **0 violations**.

## 6. sell_price → Woo mapping

`Product.sell_price` (VAT-INCLUSIVE) → Woo **`regular_price`**, 2dp string.
Ex-VAT only when `services.woo.push_prices_ex_vat=true` (via
`PriceCalculator::stripVat`). Identical basis to `PushPriceChangeToWoo` and
`PublishProductJob::buildCreatePayload` — a divergent basis here would be a
silent 20% error.

Detection compares local `sell_price` to Woo **`price`** with a 0.005
tolerance (pre-existing `WooFieldComparator` behaviour, unchanged).

**Sale safety.** This app has no `sale_price` concept, so a Woo sale is
operator-owned. Woo's `price` IS the sale price while a sale runs, which makes
an on-sale product look permanently diverged. The writer therefore refuses to
push `sell_price` when `on_sale === true` or `sale_price` is a non-empty
positive value, and reports `skipped:sell_price:on_sale`. An empty
`sale_price` (the overwhelming majority) is not a sale.

Also skipped: `no_local_price` (null/zero local price — pushing "0.00" over a
live price would be worse than doing nothing) and `woo_dict_unreadable`.

## 7. Dry-run command

```bash
cd /home/stcav/ms.21stcav.com
sudo -u stcav php artisan cutover:auto-sync --field=sell_price --dry-run
```

Phase 1 runs a live scan (read-only), phase 2 runs the push in dry-run, phases
3-4 are skipped. Output gives scanned / would_push / errors / throttled /
no_woo_product_id / woo_not_found, a per-field tally, `skipped:*` reasons,
today's deferred/failed Woo-write counters, and a
`SKU | Internal | Woo | Difference` table capped at 25 rows with an
"… and N more" line.

## 8. Current mismatch count

MEASURED 2026-08-23 — see 8a. **828 total, 281 published.** Run from the VPS;
this machine cannot reach it (ssh port 22 times out to
both `ms.21stcav.com` and `46.202.141.242`; 443 is open). The operator runs
the dry-run above to establish it.

## 8a. What the prod dry-run actually found (2026-08-23)

**828 sell_price divergences — but two-thirds are not live products.**

| product status | divergences |
|---|---|
| pending | 546 |
| **publish** | **281** |
| draft | 1 |

`DivergenceScanner::scan()` walks `Product::query()->cursor()` with NO status
filter, so the raw 828 is dominated by rows the storefront does not sell.
Every alarming outlier is in the non-published set — TE9804MIS-B1AG (-99.2%),
15486IMPACTLUX (-87.8%), the entire Sapphire MPCT/MPC/RAPT block at exactly
-38.7%. `pending` products have never had a price pushed (PushPriceChangeToWoo
has skipped non-published rows since 260701-n4y), so their local price has
drifted freely since cutover. Real, but not urgent, and NOT a lost write.

The reconciler was missing that same rule and would have pushed all 828 —
fixed by the publish-only guard (`skipped:sell_price:not_published`).

**The published 281 splits in two:**

- **178 rises, tightly clustered +11% to +17%** (V11HA64940 / V11HA68840 /
  GX186G-V4-5A / SBID-GX165 / LH75BEFHLGKXXU all at exactly +15.1%). THIS is
  the lost-writes signature: the repricer raised prices, the pushes died in
  the throttle, Woo stayed low. Reconciling these is the repair this task was
  built for, and it raises prices — low commercial risk.
- **103 drops, 20 of which move >25%.** The Epson lamp family is the standout
  (V13H010L93/94/91/97 all -65% to -75%). A whole family at a consistent ratio
  is the Sapphire fingerprint again: a cost-basis problem, not a lost write.
  These need individual review before any push.

**Revised conclusion.** The nightly gate stays OFF, but the reason is narrower
than first stated: it is the ~20 published outliers that must not be pushed
unattended, not the whole set. Once those are resolved, the remaining ~261 are
a legitimate, largely upward reconciliation.

## 9. Repair process

Reconciliation against CURRENT local prices, never a replay of the historic
failed payloads — a 2026-08-18 price may well have moved since.

1. Report: `cutover:auto-sync --field=sell_price --dry-run`
2. Controlled batch: `cutover:auto-sync --field=sell_price --max-products=100`
3. Re-report to confirm the count fell; repeat.

The nightly 23:00 run now carries `sell_price` at `--max-products=500`, so the
backlog drains on its own over several nights if left alone.

The 5,319 historic `failed_jobs` rows are NOT retried. They can be cleared
once reconciliation reports zero: `php artisan queue:flush`.

## 10. Tests

`tests/Feature/WooWriteThrottleReleaseTest.php` (16 cases) — release on
throttle, exact/floor delay, deferral counted as deferred not failed, info-not-
error logging, genuine exception still propagates, `failed()` records
SKU/Woo id/intended price to the audit log, `retryUntil` bounded + config-
driven, a parameterised audit asserting every trait adopter declares
`maxExceptions`, and the preflight probe (open / exhausted / shadow mode).

Results:

- new suites + directly-affected existing suites (`PushDivergenceToWooCommandTest`,
  `AutoSyncDivergenceCommandTest`, `PushProductFieldsToWooTest`):
  **53 passed, 221 assertions**
- other suites touching the changed classes (`PushPriceChangeToWooStaleIdTest`,
  `WooWriteQueueRoutingTest`, `ShadowModeTest`, `WooWriteThrottleTest`):
  **24 passed, 75 assertions**
- `tests/Architecture`: **121 passed** (was 14 failed / 107 passed while the
  Sync → Pricing violation stood — every one of those failures was mine, and
  all cleared once the inversion landed)
- `deptrac analyse`: **0 violations**
- `tests/Unit`: 41 failed / 695 passed — **pre-existing**, proven by checking out
  `main` and re-running the representative file (`GoldenFixtureV2TradeTest`):
  21 failed / 60 passed on BOTH main and this branch, byte-identical. Every
  failure is Quotes / QuoteLine / TradePricing / PDF fixture work; none touch
  Woo writes, the throttle or `WooProductWriter`.

The full `php vendor/bin/pest` run cannot complete on this repo for reasons
that predate this task: `tests/Feature/Agents/Marketing/ReadMarketingToolsTest.php`
and `tests/Feature/Integrations/MarketingOverviewStatsTest.php` both declare
`seedGaRow()`, and `tests/Unit/ProductAutoCreate/SpecTaxonomyResolverTest.php`
and `tests/Feature/ProductAutoCreate/Services/ProductBrandTermResolverTest.php`
both declare `makeResolver()` — PHP fatals on the redeclare. Both pairs were
last touched on 2026-07-12 and 2026-07-03 respectively, well before this work.
Suites therefore run per-directory.

`tests/Feature/WooSellPriceReconcileTest.php` (13 cases) — string-vs-float and
sub-penny tolerance produce no false mismatch, genuine difference is detected,
push writes `regular_price` 2dp, ex-VAT stripping, no-local-price skip, on-sale
skip (both `on_sale` and bare `sale_price`), empty `sale_price` still pushes,
idempotent second pass, meta_data untouched on a price-only push, the command
accepts `--field=sell_price`, and the dry-run detail table.

## 11. Remaining risks

- **`SyncChunkJob` + `MarkMissingSkusJob` carry the same defect and were NOT
  converted.** Both write to Woo inside a per-row loop with non-idempotent
  side-effects (SyncRun counters, `ImportIssue` / `SyncRunItem` inserts), so a
  job-level release would double-count on re-run. Both are dormant — the
  `sync:supplier --live` schedule entry is commented out — so the exposure is
  manual invocation only. Converting them needs per-row resumability, which is
  a bigger change than this task should carry.
- **Variations are not reconciled.** `DivergenceScanner` scans parent products
  only, so a variation price lost to the throttle stays lost.
  `PushPriceChangeToWoo` does push variation prices, and it now defers rather
  than dying, but there is no backstop for that path.
- **`CreateWooProductJob` gets preflight only** (`WooWriteWindow::retryAfterSeconds()`). Its `handle()` creates the
  local `Product` row before the Woo POST, and the AUTO-08 duplicate gate
  would reject that row on a re-run. The preflight closes the common case; a
  ceiling hit in the race window still lands in its existing
  `auto_create_failed` Suggestion DLQ, as before.
- **Counters are cache-backed.** A Redis flush zeroes them. They are a health
  signal, not an audit trail.
- **The dry-run's phase-1 scan is a full-catalogue Woo read** and takes real
  time on prod.

## 11a. A design correction the tests forced

The first cut put the preflight probe on `WooClient` as an instance method and
called it from `PublishProductJob` too. That took `PublishProductJobTest` from
green to 13 red: a dozen suites mock `WooClient` strictly, so a new instance
method means `BadMethodCallException: Received writeThrottleRetryAfter(), but
no expectations were specified` — for a call those tests do not care about.

Two corrections rather than editing the mocks:

1. The probe reads only config + the RateLimiter, so it does not belong on the
   client at all → `App\Domain\Sync\Support\WooWriteWindow::retryAfterSeconds()`.
2. `PublishProductJob` does not need a preflight — its `handle()` is
   re-entrant, so the write-site catches were always the real guarantee.

Only `CreateWooProductJob` keeps the preflight, where it is load-bearing.

## 12. Other jobs with the same throttle bug

Audited every Woo writer. Seven classes had it:

| class | queue | tries/backoff | status |
|---|---|---|---|
| `PushPriceChangeToWoo` | woo-writes | 3 / [30,120,300] | **fixed** — the incident path |
| `PublishProductJob` | woo-writes | 3 / [30,120,300] | **fixed** (write-site catches) — died at T+150s too |
| `CreateWooProductJob` | woo-writes | 3 / [30,300,1800] | **fixed** (preflight) — died at T+330s |
| `ProcessAutoCreateImageJob` | sync-bulk | 3 / [30,300,1800] | **fixed** |
| `PushProductFieldsToWoo` | woo-writes | 4 / default | **fixed** — throttle was masked as `status='error'` by `WooProductWriter`, then re-thrown as a generic RuntimeException |
| `SyncChunkJob` | woo-writes | 3 / [10,30,90] | identified, deferred (dormant; would die at T+40s) |
| `MarkMissingSkusJob` | sync-bulk | 3 / no backoff | identified, deferred (dormant; **no backoff** = 3 near-instant attempts) |

`BackfillOrdersChunkJob` and `RecacheSalesCountsJob` use `WooClient` for reads
only — unaffected.

`PushDivergenceToWooCommand` runs synchronously (no queue): it now waits out
the window once, retries, and leaves rows `pending` rather than counting the
throttle as an error.
