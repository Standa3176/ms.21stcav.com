# 260927-oje — SKU count on the Competitor Feeds list

**Branch:** `quick/260927-competitor-sku-count`
**Trigger:** *"to dash competitor feeds, can you add sku count"*

## What the page said before

Settings › Competitor Feeds carried **Feeds**, **Last Ingest** and **Feed file
date**. Between them they answer "is a file configured" and "did a file arrive
recently" — and both can read green while the file is nearly empty.

That gap is not hypothetical. The per-competitor distinct-SKU reading taken on
2026-09-26 was:

    screenmoove   9,158     (absent for the whole of August)
    onedirect     2,079     (-44% vs its own earlier coverage)
    ballicom      1,533
    avparts         375
    avitdirect      215     (-43%)

None of that was visible on the page. A feed can keep its ingest timestamp
fresh, keep its file date fresh, and deliver a fraction of its SKUs.

## The column

**SKUs** — distinct SKUs priced by that competitor inside a recency window,
right-aligned, **red at zero**.

Zero is coloured because a quiet `0` in a column of numbers is the state we
were already in. A configured feed delivering nothing is the specific failure
this column exists to surface.

## Two decisions that make the number honest

**DISTINCT, not rows.** `competitor_prices` is append-only with
`UNIQUE(competitor_id, sku, recorded_at)`, so one SKU seen on twenty scrapes is
twenty rows. A row count would read ~20× too high — and would *rise* as a feed
stalled and got re-ingested, which is precisely backwards.

**Windowed, not all-time.** Rows are never pruned (COMP-07), so an all-time
count answers "did this feed ever carry SKUs" — always yes. Only a window says
whether it carries them *now*. `competitor.sku_count_window_days` defaults to
**30**: wide enough to survive a skipped pull (the FTP pull runs Sun + Wed),
narrow enough that a feed that died last month reads as dead. The window also
keeps the scan on `competitor_prices_comp_recorded_idx` rather than reading the
full history.

## Why not the per-row pattern next to it

`ftp_feeds_count` resolves per row — `$record->ftpFeeds()->count()` — and the
comment there explains why that is fine: `competitor_ftp_feeds` is a ~5-row
settings table.

`COUNT(DISTINCT sku)` is not that. Screenmoove alone was 182,868 rows inside
the 30-day window, and a per-row closure would run one of those per competitor
on every render of the list. So this column uses **one grouped query for every
competitor, cached for 10 minutes**.

The TTL is the staleness bound and it is deliberate: a just-finished ingest can
read up to ten minutes old. This is a coverage gauge, not a live progress bar.
The cache key carries the window (`competitor:sku_counts:{$days}d`) so changing
the config does not serve the previous window's numbers.

The query itself is wrapped in a `try`/`catch` returning `[]`, matching
`getNavigationBadge()` — this runs on every render of the list and a failed
query must not 500 the admin.

## Changed

| File | Change |
|---|---|
| `app/Domain/Competitor/Filament/Resources/CompetitorResource.php` | `skuCountsByCompetitor()` + the `sku_count` column |
| `config/competitor.php` | `sku_count_window_days` (default 30) |
| `tests/Feature/Competitor/CompetitorSkuCountColumnTest.php` | new |

Read-only against `competitor_prices`. No write path, no Woo call, no schema
change — a column and a cached read.

## Pinned by the tests

- distinct SKUs, not price rows (3 rows / 2 SKUs → 2)
- a 60-day-old row does not count inside a 30-day window
- competitors do not borrow each other's SKUs, even on a shared SKU
- the table renders `2` and `0`, not blanks
- zero is `danger`, non-zero is `gray`
- the whole table costs **one** query, and the second call costs none
- the window comes from config, and the cache key carries it
