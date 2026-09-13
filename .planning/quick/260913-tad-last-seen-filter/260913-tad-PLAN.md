# 260913-tad — 7 / 14 / 30 day windows on the Still listed filter

**Branch:** `quick/260913-last-seen-filter`
**Trigger:** operator: *"can suggestions have a filter to show 7 / 14 days / all"*.

## Change

`SuggestionResource` — the `Still listed` filter added in 260913-qoi offered
7 / 30 / stale. Added **14 days**, and gave the blank option an explicit **All**
placeholder so the default reads as a deliberate choice rather than an empty
box.

The query needed no change: it already resolves any numeric option via
`now()->subDays((int) $value)`.

## Why the windows matter

Verified live on 2026-09-13, pending `new_product_opportunity`:

    seen today   2,434
    seen  <=7d   3,448   <- the working sourcing list
    30d+ stale   4,663

Before 260913-qoi the freshness date was meaningless (it only moved when a NEW
competitor appeared), so all 9,073 pending looked alike. The windows are what
make the queue workable.

## Tests

`tests/Feature/Suggestions`: **56 passed, 230 assertions**. Pint clean.
