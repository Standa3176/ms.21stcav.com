---
id: 260911-czm
slug: trade-sync-throttle
date: 2026-09-11
status: complete
---

# trade:sync dropped most of the catalogue on its first live run

## What happened

The first full `trade:sync --live` produced a wall of:

```
write failed for 55BDL4050Q/00 — Woo live-write rate ceiling (60/min)
reached — deferring; window resets in 16s.
```

Roughly 60 products landed per minute and **every other product in that
window was silently skipped**. The run was killed part-way.

## Cause — mine, and a repeat of a known incident

`WooClient` raises `WooWriteThrottleException` when the 60/min live-write
ceiling is hit. Its own docblock is explicit:

> This is a RETRYABLE condition: the correct response is to requeue the job so
> the write is attempted again later, NEVER to write un-serialised / un-paced.

260910-lwv caught it as a generic `\Throwable`, logged `write_failed`, counted
it in `failed` and moved to the next product. The message even said
"deferring" — nothing deferred.

This is the same shape as **260822-rmo**, where a thrown throttle consumed
queue attempts and killed **5,319 price pushes** between 2026-08-18 and 08-22.
That fix taught every queued Woo writer to `release($e->retryAfterSeconds())`.
`trade:sync` is synchronous, so it never learned the lesson — I wrote a new
Woo writer and did not carry the existing pattern into it.

## Fix

`writeWithThrottleWait()` catches `WooWriteThrottleException` separately,
sleeps for the interval **the exception itself reports** (it carries
`retryAfterSeconds()` precisely so a caller need not guess), and re-attempts
the SAME product. Bounded at `MAX_THROTTLE_WAITS = 10` so a stuck write lock
ends that product rather than hanging the run. Genuine errors still fail the
product and continue.

The write ends up paced rather than lossy: a full catalogue run takes about
the same wall-clock time it always would have, but now every product lands.

## Verification

Two new cases in `TradeSyncCommandTest`:

- a product whose first write throttles and whose second succeeds is
  **written**, not counted failed — asserted on the call count, so a
  regression that silently drops the retry fails the test
- a product whose throttle never clears is abandoned after the bound, the run
  continues, and the command exits non-zero

74 tests in `tests/Feature/TradePricing`. Deptrac 0 violations.

## Note for the next Woo writer

There are now six callers that write to Woo. Five use
`HandlesWooWriteThrottle`; this one cannot (it is not a queued job) and needed
its own synchronous equivalent. Anything new that calls `WooClient::put()` on
the live path must handle the throttle deliberately — catching `\Throwable`
around a Woo write is always wrong.
