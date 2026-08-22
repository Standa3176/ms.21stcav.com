---
quick_id: 260822-rmo
slug: woo-throttle-defer-sell-price-reconcile
date: 2026-08-22
status: in-progress
---

# Quick Task 260822-rmo — Woo write throttle defers instead of dying + permanent sell_price reconciliation

## Problem (confirmed on prod 2026-08-22)

`failed_jobs` on `ms.21stcav.com`:

| date | failures |
|---|---|
| 2026-08-22 | 276 |
| 2026-08-21 | 611 |
| 2026-08-20 | 998 |
| 2026-08-19 | 1388 |
| 2026-08-18 | 2046 |

Newest exception:

```
App\Domain\Sync\Exceptions\WooWriteThrottleException:
Woo live-write rate ceiling (60/min) reached — requeueing; window resets in 19s.
  WooClient.php:297 throttlePace()
  WooClient.php:270 throttledWriteLive()
  WooClient.php:222 writeOrShadow()
  PushPriceChangeToWoo.php:145 put()
```

**Root cause.** `throttlePace()` signals "too fast" by THROWING. A thrown
exception consumes a queue attempt. `PushPriceChangeToWoo` has `tries = 3`,
`backoff = [30, 120, 300]`, so the whole retry budget is spent inside the
burst window and the job dies — the docblock's promise that "the job
requeues" is not what the queue actually does.

**Timing (verified against laravel/framework 12.56.0, not assumed).**
`Worker::markJobAsFailedIfWillExceedMaxAttempts()` fails the job when
`! retryUntil && maxTries > 0 && attempts() >= maxTries`. With `tries = 3`:

- attempt 1 at T+0 → throw → `1 >= 3` false → release +30s
- attempt 2 at T+30 → throw → `2 >= 3` false → release +120s
- attempt 3 at T+150 → throw → `3 >= 3` TRUE → **failed**

Death at **T+150s ≈ 2.5 minutes**. The third backoff value (300) is never
used. (The earlier "~7.5 min" estimate was wrong — it summed all three
backoffs including the unreachable one.)

**Consequence.** `PushPriceChangeToWoo` has no `failed()` hook, and
`cutover:auto-sync` reconciles only `stock_quantity,buy_price`. A dead price
push is therefore permanently lost: MS believes it repriced, Woo never heard,
and nothing detects it afterwards.

## Approach

### 1. Throttle defers (does not consume the failure budget)

Laravel 12's documented rate-limited-job shape, confirmed in vendor source:

- `Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts()` — when `retryUntil`
  is set and in the future, the attempts check is **skipped entirely**
  (`Worker.php:563-571`). So `retryUntil` is what lets a throttled job outlive
  `tries`.
- `Worker::markJobAsFailedIfWillExceedMaxExceptions()` — an independent,
  cache-counted budget of *uncaught* exceptions (`Worker.php:608-624`). This
  is what keeps genuine failures terminating.
- `Events\Dispatcher::propagateListenerOptions()` (`Dispatcher.php:705-733`)
  copies `retryUntil` and `maxExceptions` from a queued **listener** to
  `CallQueuedListener`, and `CallQueuedListener::setJobInstanceIfNecessary()`
  injects the job so `$this->release()` works. So the same pattern is valid
  for `PushPriceChangeToWoo`.

Therefore, per affected class:

- catch `WooWriteThrottleException` → `$this->release($retryAfter)` and return
  (no exception escapes ⇒ `maxExceptions` untouched)
- `retryUntil()` → `now()->addMinutes(config('services.woo.write_retry_until_minutes', 180))`
- `maxExceptions = 3` so genuine Woo/API errors still fail after 3, on the
  existing `backoff` schedule

**Both are required together.** `retryUntil` alone would make a genuinely
broken job retry for the full window; `maxExceptions` alone cannot stop the
attempts counter from killing a throttled job.

`WooWriteThrottleException` gains `retryAfterSeconds` so the release delay is
the real `RateLimiter::availableIn()` value rather than a guess.

### 2. Failure visibility

`PushPriceChangeToWoo::failed()` writes a `SyncError` row (the project's
existing append-only DLQ, already pruned by `sync-errors:prune`) plus a
structured `Log::error`. Throttle releases are NOT errors — they log at
`info` and increment a counter only.

### 3. sell_price reconciliation

`WooFieldComparator` **already** emits `sell_price` diffs (local `sell_price`
vs Woo `price`, 0.005 tolerance) — the scan half exists. The missing half is
the push: `PushDivergenceToWooCommand::SUPPORTED_FIELDS` is
`['stock_quantity','buy_price','category_id']` and `WooProductWriter` has no
`sell_price` branch. So the fix is to extend the existing chain, not to build
a new reconciler.

Price mapping follows the app's authoritative behaviour
(`PushPriceChangeToWoo`, `PublishProductJob::buildCreatePayload`):
`sell_price` (VAT-inclusive) → Woo **`regular_price`**, 2dp string,
ex-VAT only when `services.woo.push_prices_ex_vat=true`.

**Sale-price safety.** The app has no `sale_price` concept — a Woo sale is
operator-owned. The comparator compares against Woo `price`, which IS the
sale price while a sale runs, so an on-sale product looks permanently
diverged. The writer therefore **skips** any product with `on_sale=true` or a
non-empty `sale_price` and reports it, rather than overwriting the sale's
reference price.

### 4. Dry-run report

`cutover:auto-sync --field=sell_price --dry-run` already routes phase 1 scan
live + phase 2 push dry-run. The push command's dry-run gains a
`SKU | internal | Woo | difference` detail table (capped at 25 rows + a
"… and N more" line) sourced from the sync_diff payload's `laravel`/`live`
values.

## Tasks

- **T1** — Throttle plumbing: `retryAfterSeconds` on the exception, `WooClient`
  passes the real values, `WooProductWriter` re-throws the throttle instead of
  swallowing it into `status='error'`, new
  `App\Domain\Sync\Concerns\HandlesWooWriteThrottle` trait, new config knob.
- **T2** — Apply to every affected Woo-write job/listener + `failed()` DLQ on
  `PushPriceChangeToWoo`.
- **T3** — `sell_price` push support: writer branch (with sale guard),
  `SUPPORTED_FIELDS`, auto-sync default `--field`, the 23:00 schedule entry,
  dry-run detail table, throttle counters in the summary.
- **T4** — Tests + full suite regression run.

## Out of scope (explicit)

- Approving the 387 auto-create drafts.
- Raising `WOO_WRITE_MAX_PER_MINUTE` or removing the `woo:write` lock — the
  60/min ceiling and the 250ms spacing protect the shared WP box and stay
  exactly as they are.
- Replaying historic `failed_jobs` price payloads (stale prices).
- Woo product **variations** — `DivergenceScanner` scans parent products only,
  so variation prices remain outside the reconciler.

## must_haves

- truths:
  - a throttled Woo write is released back onto the queue, not failed
  - a genuine Woo error still fails after 3 uncaught exceptions
  - sell_price divergence is detectable AND correctable through the existing
    scan → push chain
  - an on-sale Woo product is never silently repriced
- artifacts:
  - `app/Domain/Sync/Concerns/HandlesWooWriteThrottle.php`
  - `sell_price` in `PushDivergenceToWooCommand::SUPPORTED_FIELDS`
  - `tests/Feature/WooWriteThrottleReleaseTest.php`
  - `tests/Feature/WooSellPriceReconcileTest.php`
