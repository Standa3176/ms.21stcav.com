---
quick_id: 260823-gua
slug: empty-competitor-feed-guard
date: 2026-08-23
status: complete
---

# Summary — an empty competitor feed now fails loudly

## The silence it fixes

Found on prod 2026-08-23. `screenmoove` had been publishing a **23-byte CSV**
(BOM + header + one blank `,,` row) since **2026-07-22**:

```
2026-07-01   1,263,610 bytes   13,993 lines
2026-07-12     108,536 bytes    1,177 lines   <- collapse begins
2026-07-19     512,108 bytes    5,688 lines
2026-07-22          23 bytes        1 line    <- empty
... 10 consecutive empty pulls to 2026-08-23
```

Ten pulls, every one `last_pull_status=success`, `consecutive_failures=0`. Ten
ingests, every one `status=completed`, `rows_total=1`, `rows_written=0`.
**Nothing alerted**, because:

- the FTP pull genuinely succeeded — the file arrived, it was just empty
- `competitor:check-stale` watches ingest RECENCY, and the ingests were on time
- an empty file is legitimate for a NEW competitor, so header-only was treated
  as an ordinary completion

screenmoove held **176,132 of ~268,000 competitor price rows — 66% of all
competitor data**. So for five weeks roughly **3,400 published products**
silently priced against stale data or fell through to the margin rules. Measured
impact: 92.2% of published products are matched to a competitor, but only
**19.0%** had data under 14 days old.

## What changed

`FlagEmptyFeedRegression`, a listener on the `CompetitorCsvIngested` event.

It was FIRST written inside `IngestCompetitorCsvJob` — and
`Phase5IngestUntouchedTest` rejected it. Phase 11.2 D-07 freezes that file so
format normalisation stays upstream of it, and the test enforces it bluntly:
any commit touching it since 2026-04-25 fails. The guard is not format
normalisation, but it does not belong there either — the job already emits
`CompetitorCsvIngested` carrying `rowsTotal` and `rowsWritten`, which is
exactly the data required. Moving it to a listener respects the freeze AND is
the better design.

The listener, When a run writes zero rows **and the
competitor already holds price rows**, it:

- flips the run to `failed` with an error naming the row counts and the size of
  the existing history
- logs `competitor.empty_feed_regression`
- notifies `AlertRecipient` where `receives_competitor_alerts=true` via the new
  `EmptyFeedNotification` (mirrors `StaleFeedNotification`)

**Regression-only by design.** A brand-new source whose first file is empty
stays silent — that condition is what makes the guard quiet enough to leave on.

Best-effort throughout: a notification failure can never turn an otherwise
successful ingest into a failed one.

## Tests

5 cases (11 assertions), all green:
empty-after-populated fails + alerts · new competitor stays quiet · healthy
ingest untouched · still fails with no subscribers (the run status is the
durable signal, the email is a courtesy) · the error message records the
existing row count as evidence to take to the supplier.

Wider sweep: `tests/Feature/Competitor` 215 passed. The single failure,
`ShieldRestorationProtocolTest`, is PRE-EXISTING — `IntegrationEventPolicy.php`
was committed 2026-05-09 in `408ab94`. deptrac 0 violations.

## Not fixed by this

screenmoove's file is empty at the SOURCE. This guard makes that visible on the
next pull; it cannot repopulate the feed. Ops needs to raise it with them —
"empty on every pull since 22 July, after dropping from 13,993 to 1,177 rows on
12 July".
