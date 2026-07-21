# 260719-mgp — Sourceability matching-gap probe — SUMMARY

**Status:** COMPLETE · **Commits:** `6321d92` (RED tests) → `1434e12` (implementation) · **Not pushed at time of writing / not deployed**
**Note:** the original executor session was interrupted mid-build (implementation written but uncommitted/unverified); completed directly in the main session.

## What it does
`supplier:probe-sourceability-gap` — a READ-ONLY diagnostic that answers *why* ~1,830 on-Woo products
aren't matched to `supplier_sku_cache`, so we can decide between a **matcher fix** and a **business cull**
before touching the catalogue. It samples the "not sourceable" set and classifies each product into:

| Bucket | Meaning | Action implied |
|---|---|---|
| `matching_gap` | Supplier carries it under a **different SKU format** | **Fixable** — a smarter matcher recovers these |
| `brand_in_feed_item_absent` | Manufacturer is in the feed, this exact part isn't | Likely discontinued / lead-time — review, don't blind-cull |
| `not_in_feed` | Manufacturer absent from the feed entirely | Genuine cull candidate |
| `no_manufacturer` | No brand/manufacturer to key on | Resolve brand first (excluded from feed comparison) |

## Remote-feed access map
- **Seam:** `SupplierFeedReader` (interface) — `rowsForManufacturer(string $manufacturer, int $cap = 5000): array`.
  Bound in `AppServiceProvider` (~L337) to `MysqlSupplierFeedReader`; swapped for a fake in tests.
- **Live reader:** `MysqlSupplierFeedReader` resolves the `supplier_db` credential via
  `IntegrationCredentialResolver` and issues **one bounded, prepared, READ-ONLY** query per manufacturer:
  `SELECT mpn, suppliersku FROM feeds_products WHERE product_excluded = 0 AND LOWER(TRIM(manufacturer)) LIKE ? LIMIT <cap>`
  (prefix match; LIKE metacharacters escaped). Uses **mysqli directly** rather than a registered Laravel
  connection, so it adds no app-wide connection and stays out of the Deptrac WpDirectDb lane.
- **Manufacturer resolution (no Woo call):** `brand_id` → name via the **cached** Woo taxonomy
  (`taxonomy.brands`, `Cache::get` only — never fetches), falling back to the leading token of the product
  name; unresolvable ⇒ bucket `no_manufacturer` (and the feed is **never** queried for those).

## Safety (post-incident)
READ-ONLY throughout: no writes, no Woo calls, no status changes, no matcher changes, no migration.
Queries only the **remote `supplier_db` VPS** — a separate box from the shop+app server that went down —
and every remote read is bounded: `--limit` sample (default 150), per-manufacturer fetches **deduped**
within the run, hard row cap per manufacturer (5,000, with a warning when hit so buckets read as lower
bounds). Total remote queries ≈ distinct manufacturers in the sample.

## Classification / normalisation
`SourceabilityClassifier` is **pure** (no DB/network): `normalize()` = lowercase + strip every
non-alphanumeric, so `MR.JQU11.002`, `MR-JQU11-002` and `MRJQU11002` all collapse to `mrjqu11002`.
This normalisation is **diagnostic only** — the live matcher stays exact `LOWER(TRIM())` until the split
tells us widening it is worth it.

## Verify (all green)
- `pest tests/Feature/Sync/ProbeSourceabilityGapCommandTest.php` — **4 passed**: bucketed summary vs a
  stubbed feed; feed never queried for `no_manufacturer`; cached SKUs excluded from the sample;
  `--status` filter honoured. No network in tests.
- `pint --test` on all touched files — **pass** (import ordering auto-fixed).
- `php artisan route:list --path=admin` — **exit 0**.
- `vendor/bin/deptrac analyse` — **0 violations, 0 errors** (the `AbstractString` deprecation is
  pre-existing vendor-phar noise).

### Test-harness gotcha worth remembering
`expectsOutputToContain()` failed on `fixable` even though the command genuinely printed it (verified by
running the command via `Artisan::call` + `Artisan::output()`). Cause: Laravel matches each `doWrite`
against the **first** registered expectation whose text matches — and `fixable` only appeared on a line
that also contained `matching_gap` (registered earlier), so that write was attributed to the earlier
expectation and `fixable` never fired. Fix: emit a standalone ASCII "Takeaway:" line containing no bucket
key. **Lesson: when asserting multiple output substrings, make each one appear on at least one line that
contains none of the other asserted substrings.**

## Run it on prod
```bash
cd /home/stcav/ms.21stcav.com && php artisan supplier:probe-sourceability-gap --limit=150
```
Options: `--limit=400` (tighter confidence), `--status=publish|pending|all` (default `all`).
Output = per-bucket counts + % of sample, ~5 examples per bucket (sku | name | manufacturer |
matched-feed-key), and an interpretation block.

## What the result decides
- **High `matching_gap`** ⇒ mostly a mechanical **matcher fix** — widen SKU normalisation and hundreds
  re-match automatically; no cull, and the cutover picture improves sharply.
- **High `not_in_feed`** ⇒ a genuine **catalogue cull** decision (export the list, review, retire).
- **High `brand_in_feed_item_absent`** ⇒ lead-time/discontinued judgement + fix the demotion model so
  lead-time products stay `publish` rather than being unpublished.
