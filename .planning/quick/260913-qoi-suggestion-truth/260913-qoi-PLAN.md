# 260913-qoi — Make the Suggestions inbox tell the truth

**Branch:** `quick/260913-suggestion-truth`
**Trigger:** operator: *"are the suggestion SKUs based on the last feed... also
when I sent them for creation there is no status change on the app eg creating,
sending, sent/failed"*.

Two unrelated lies on the same screen.

---

## Lie 1 — a live listing looked a month dead

`OrphanDetector::record()` returned early when the sighting came from a
competitor already counted for that SKU:

```php
if ($alreadyCounted) {
    return $existing;          // D-09 idempotent no-op
}
```

Correct for its stated purpose — it stops `supporting_competitors` being
incremented twice for one competitor, and stops 5 competitors × 1,000 orphans
creating duplicate rows. But `updated_at` then only moved when a **new**
competitor appeared. A SKU Screenmoove has listed on every scrape since May
still carried its May timestamp.

**Measured 2026-09-13**, of 9,083 pending `new_product_opportunity`:

    last seen today      438
    last seen  <=7d      871
    last seen <=30d      809
    last seen older    6,965

…and of those 6,965 "stale" rows, **1,631 were in that morning's scrape** (748
more within 30 days; 4,586 genuinely absent). The operator could not tell a
dead listing from one a competitor is selling right now.

### Fix

- `last_seen_at` written into evidence on EVERY sighting, including the
  already-counted path. D-09 still holds: the counter and the sightings array
  are untouched there — only recency moves.
- `suggestions:backfill-last-seen` (dry-run default) repairs the existing
  23,567 rows from `competitor_prices` history, so a row reads "last seen 3
  months ago" rather than blank. Never moves a date backwards. Uses
  `saveQuietly()` — a data repair must not re-notify anyone about a months-old
  opportunity.
- Filament: a **Last seen** badge (green <7d, amber <30d, red beyond) and a
  **Still listed** filter.

The list is cumulative by design — nothing expires an orphan when a competitor
delists it — so the filter is the honest way to use it. Raw pending 9,083;
actually live ~2,800.

---

## Lie 2 — "applied" meant "queued"

```
Auto-create → ApplySuggestionJob → NewProductOpportunityApplier
           → dispatches CreateWooProductJob → ApplySuggestionJob marks APPLIED
```

The applier only **dispatches**. Marking the suggestion `applied` at that
moment told the operator the product existed when it was merely queued — and if
the job later failed, the row still read `applied` while the failure surfaced as
a **separate** `auto_create_failed` suggestion elsewhere. The row being watched
never changed. That is precisely the reported symptom.

### Fix

- New `Suggestion::STATUS_APPLYING`. `ApplySuggestionJob` sets it when the
  applier reports a `dispatched_job_class`; synchronous appliers are unchanged.
- `CreateWooProductJob` resolves the originating suggestion (it already
  receives the id): **APPLIED** after `AutoCreateSucceeded`, **FAILED** in
  `failed()`. Never throws — bookkeeping must not fail a job whose real work
  succeeded, nor mask the original error.
- The `auto_create_failed` DLQ row **stays**: it is what Replay acts on. This
  adds the visible half rather than replacing it.
- Filament: `applying` badge in **info**, deliberately not `success` — the
  product does not exist yet. Filter option "Creating…". The table already
  polls 30s, so rows now progress pending → Creating… → Applied/Failed on
  screen.

---

## Verification of the surrounding claim

The operator also asked whether the SKUs sent for creation had actually been
created. They had: **1,100 published, every one with a `woo_product_id`**; 243
drafts present in Woo awaiting publish; 23 drafts that never reached Woo; zero
`auto_create_failed` suggestions; zero failed jobs. Nothing was broken — only
untold.

## Tests

`tests/Feature/Suggestions/SuggestionTruthfulStateTest.php` — 9 tests covering
both halves, including that refreshing recency does NOT double-count the
competitor (D-09), and that the failure path is wired as well as the success one
(without it a failed create would sit on `applying` forever — a different lie).

Suites `tests/Feature/Suggestions tests/Feature/ProductAutoCreate
tests/Feature/Competitor`: **609 passed, 2,003 assertions**, 1 failure.

⚠️ That failure — `ShieldRestorationProtocolTest > no Shield-generated
IntegrationEventPolicy stub exists` — is **PRE-EXISTING**. Verified by checking
out `main` and running it there: fails identically. Untouched by this task; the
committed `app/Foundation/Integration/Policies/IntegrationEventPolicy.php`
predates it (added in 408ab94). Worth its own look.

Pint clean, deptrac 0 violations.

## Operator steps after deploy

    php artisan suggestions:backfill-last-seen            # dry-run, shows the age spread
    php artisan suggestions:backfill-last-seen --apply

Then in the inbox, set **Still listed → Seen in the last 7 days** to get the
sourcing list that reflects what competitors are actually selling.
