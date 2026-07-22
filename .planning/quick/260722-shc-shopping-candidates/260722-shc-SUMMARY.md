# 260722-shc — `products:shopping-candidates` SUMMARY

**Status:** COMPLETE (not pushed, not deployed — per plan guardrail)
**Commits:**

| SHA | Type | What |
|---|---|---|
| `7c07f26` | test (RED) | 29 failing tests — every gate, every sort mode, CSV shape, funnel arithmetic, READ-ONLY + no-HTTP asserts |
| `c99cd30` | feat (GREEN) | `ShoppingCandidateScanner` + `ShoppingCandidatesCommand` |

**Files:**
- `app/Domain/Pricing/Services/ShoppingCandidateScanner.php` (new)
- `app/Console/Commands/ShoppingCandidatesCommand.php` (new)
- `tests/Unit/Domain/Pricing/Services/ShoppingCandidateScannerTest.php` (new, 18 tests)
- `tests/Feature/Console/ShoppingCandidatesCommandTest.php` (new, 11 tests)

Nothing else was touched. `AdCandidateScanner`, the Ad Candidates page, `CompetitorPositionScanner`,
`LiveSupplierStockResolver` and `deptrac.yaml` are all byte-unchanged. No migration.

---

## Task 1 — Investigation findings (what was reused, what deliberately was not)

| Source | Verdict | Why |
|---|---|---|
| `AdCandidateScanner` | **Patterns reused, file untouched** | It answers a different question ("where are we already undercutting on a fat-margin SKU?") and drives the live Ad Candidates page + Merchant-feed backfill. Its `beatRequired` gate is wrong here (a Shopping listing is worth trialling even when we're currently *dearer* — price is a lever, presence is the question), it has no competitor-count floor and no GTIN gate. Forking its predicate to carry both shapes would have risked a live operator page, so the new scanner is built **alongside** it and copies its proven SQL: windowed `ROW_NUMBER() OVER (PARTITION BY competitor_id, sku ...)` reduction of `competitor_prices`; windowed latest-offer reduction of `supplier_offer_snapshots` with `SupplierFreshnessResolver` stale-supplier exclusion; `chunkById(500)` over products. |
| `CompetitorPositionScanner` | **Deptrac contract reused** | Confirmed the house rule: Pricing must not import Competitor models, so competitor rows are read with a **parameter-bound raw `DB::select`** on `competitor_prices` — never interpolated. New scanner follows this exactly. `vendor/bin/deptrac analyse` → **0 violations**. |
| `LiveSupplierStockResolver::isListedByFreshSupplier()` (260713-rsp) | **Reused, opt-in + bounded** | It is the churn-safe "listed by a fresh supplier right now" signal, but it issues **one external `supplier_db` mysqli query per SKU** — running it across the catalogue is precisely the N+1 the plan forbids. So the *bulk* gate stays snapshot-based (one windowed pass), and the live signal is available via `--live-stock`, applied only to the already-ranked, already-limited shortlist (≤ `--limit` calls). |
| `TaxonomyResolver::allBrands()` | **Rejected** | `AdCandidateScanner` uses it for brand names, but it resolves through the **Woo REST API** and this command is contractually no-Woo. Brand name is instead read from the product's own local `attributes_json` "Brand" spec row (the same row `PublishProductJob` writes), and `brand_id` is exported alongside for manual join-back. Pinned by a test that runs under `Http::preventStrayRequests()` + `Http::assertNothingSent()`. |
| `products.ean` | **Hard gate** | Google Merchant disapproves items with no GTIN (or brand+MPN), so a missing `ean` is a default exclusion — and the count of those exclusions is printed as the EAN-backfill opportunity. |

---

## Gates implemented (funnel order — every product lands in exactly one bucket)

| # | Gate | Default | Funnel key |
|---|---|---|---|
| 1 | `status = 'publish'` AND `type = 'simple'` | — | `dropped_not_publish_simple` |
| 2 | non-empty `sku`, `buy_price > 0`, `sell_price > 0` | — | `dropped_no_price_or_sku` |
| 3 | margin (`sell − buy`) ≥ `--min-margin-pence` | `19900` (£199, matches AdCandidateScanner) | `dropped_below_min_margin` |
| 4 | current fresh in-stock supplier offer — latest `supplier_offer_snapshots` row within **7 days** with `stock > 0`, stale suppliers excluded via `SupplierFreshnessResolver` | on | `dropped_no_fresh_stock` |
| 5 | **DISTINCT** competitors with a *current* price ≥ `--min-competitors`, within `--competitor-window-days` | `2` / `30` | `dropped_below_min_competitors` |
| 6 | has GTIN (`products.ean` non-empty, whitespace counts as missing) unless `--allow-missing-gtin` (then kept and flagged `GTIN: NO`) | required | `dropped_missing_gtin` |

`dropped_* + eligible == products_total` — asserted by a test.

Per-product fields computed: `margin_pence`, `margin_pct_bps` (margin as % **of sell price**),
`competitor_count`, `lowest_comp_pence` (competitor **gross** — what a Shopping buyer sees),
`position` (`beat` / `level` / `above`) + `delta_vs_lowest_pence`, `stock`, `supplier_name`,
`has_gtin`, `ean`, `sku`, `name`, `brand`, `brand_id`, `woo_product_id`, `score`.

## Ranking formula

```
score = competitor_count × margin_pence          (demand proxy × value)
```

`--sort=score` (default) | `margin` (`margin_pence`) | `competitors` (`competitor_count`).
**Every** mode tie-breaks `margin_pence` DESC → `competitor_count` DESC → `sku` ASC.
Sorting happens in PHP, not SQL, so ordering is identical on SQLite (tests) and MariaDB (prod).
`--limit` default **200** caps the shortlist; the funnel still reports the full `eligible` count.

## Funnel output shape

```
── products:shopping-candidates — Google Shopping shortlist (READ-ONLY) ──
  min-margin 19900p (£199.00) · min-competitors 2 · competitor window 30d · GTIN required
  sort=score · limit=200

── Eligibility funnel ───────────────────────────────────────
    Products scanned                                 4812
  − not publish/simple                               1180  →   3632
  − no SKU / buy+sell price                            94  →   3538
  − margin < 19900p (£199.00)                        2907  →    631
  − no fresh in-stock supplier offer (7d)             288  →    343
  − competitors < 2 (30d window)                      171  →    172
  − missing GTIN (products.ean)                       119  →     53   ← EAN-backfill opportunity
    = ELIGIBLE                                              →     53
      shortlisted (--limit)                                 →     53

── Top candidates ───────────────────────────────────────────
  # | SKU | Name | Brand | Margin | Margin% | Comps | Lowest | Vs lowest | Stock | GTIN | Score
  (first 25 rows; --preview to change)

CSV written: …/shopping-candidates.csv  (53 rows)

── How to read this ─────────────────────────────────────────
  Ranking uses COMPETITOR BREADTH as a DEMAND PROXY — not UK search volume.
  This app holds no UK market volume data (last_sales_count_90d and GA4 are
  own-site signals). "N competitors currently list this SKU" is evidence that
  the product sells somewhere, not evidence of how much it is searched for.
  Validate true demand in Google Keyword Planner (location: United Kingdom)
  on the exported SKU/name list before committing Shopping spend.
```

(Numbers above are illustrative shape, not measured prod values — the local dev DB has 2 products.)

CSV columns (19): `rank, sku, name, brand, brand_id, woo_product_id, ean, has_gtin,
buy_price_pence, sell_price_pence, margin_pence, margin_pct, competitor_count,
lowest_competitor_gross_pence, position, delta_vs_lowest_pence, stock, supplier_name, score`.

---

## The exact prod command

```bash
cd /home/stcav/ms.21stcav.com

# 1. The shortlist you actually act on — top 200 by score, exported for Keyword Planner.
php artisan products:shopping-candidates \
  --limit=200 \
  --sort=score \
  --csv=storage/app/research/shopping-candidates.csv

# 2. Size the EAN-backfill prize (how many perfect candidates are blocked only on a missing GTIN):
#    compare the "missing GTIN" drop above with this run's ELIGIBLE count.
php artisan products:shopping-candidates --allow-missing-gtin --limit=500 \
  --csv=storage/app/research/shopping-candidates-incl-no-gtin.csv

# 3. Optional: confirm the shortlist against the LIVE supplier feed (≤200 supplier_db queries).
php artisan products:shopping-candidates --limit=200 --live-stock \
  --csv=storage/app/research/shopping-candidates-live.csv
```

Read-only — safe to run on prod at any time. Check `uptime` / `ps` first only because prod has
form for CPU saturation under concurrent heavy ops; this command itself is three SELECT passes.

## Suggested Keyword-Planner workflow for the exported CSV

1. Open `shopping-candidates.csv`; copy the `name` column (and, for ambiguous SKUs, `brand + sku`).
2. Google Ads → Tools → **Keyword Planner** → *Discover new keywords* → paste up to 10 product
   names per batch. Set **Location: United Kingdom**, **Language: English**, **Search networks:
   Google**, date range **last 12 months**.
3. Export each batch (Avg. monthly searches, Competition, Top-of-page bid low/high).
4. Join back to the CSV on `name`/`sku` and add two columns: `uk_monthly_searches` and
   `top_bid_high_pence`.
5. Re-rank on **real** demand:
   `true_score = uk_monthly_searches × margin_pence`, then sanity-filter
   `margin_pence > top_bid_high_pence × 20` (i.e. one sale must pay for ~20 clicks; tighten as
   real conversion data arrives).
6. Take the top 20–30 of that re-ranked list as the Shopping trial set. Products in the CSV with
   `has_gtin = no` (only present when `--allow-missing-gtin` was used) must go through
   `products:backfill-merchant-feed` before they can be listed at all.
7. `position = above` rows are still worth trialling — they just need a price decision first;
   `delta_vs_lowest_pence` is exactly how far we'd have to move to match the cheapest competitor.

---

## Verification results

| Check | Result |
|---|---|
| `pest` (2 new files, 29 tests) | **29 passed, 103 assertions** |
| `pest` + adjacent regression (`AdCandidateScannerTest`, `AdCandidateScannerStaleSupplierTest`, `tests/Architecture`) | **157 passed, 674 assertions** |
| `php artisan route:list --path=admin` | **exit 0** |
| `vendor/bin/pint` (all 4 new files) | **`{"result":"pass"}`** |
| `vendor/bin/deptrac analyse` | **Violations 0**, Skipped 0, Errors 0, Warnings 0 |
| Live smoke run on the dev DB | funnel + caveat render correctly, exit 0 |

Test coverage of the specific plan requirements:
- each gate excludes correctly — margin floor, min-competitors (incl. **distinct** competitors, not
  price rows), competitor window recency, missing GTIN with **and** without `--allow-missing-gtin`
  (incl. whitespace-only EAN), non-publish, non-simple, no price, and all three no-fresh-stock
  shapes (zero stock / stale snapshot / no snapshot at all);
- ranking order asserted for all three `--sort` modes plus the score-tie → margin-desc tie-break;
- `--csv` header asserted column-for-column, rows asserted in ranked order with score values;
- funnel counts asserted individually **and** asserted to sum to `products_total`;
- **no writes**: `DB::listen` asserts zero `insert|update|delete|replace|truncate|drop|alter`
  statements, plus before/after row-set equality on `products` and `competitor_prices`;
- **no Woo / no outbound HTTP**: `Http::preventStrayRequests()` + `Http::assertNothingSent()`,
  with the brand name still resolving (proving the local `attributes_json` path, not the Woo path);
- driver-portable: `ROW_NUMBER()` (MySQL 8 + SQLite ≥ 3.25), all ordering done in PHP, all dates
  bound as parameters.

## Deviations from plan

1. **Brand name source changed from `TaxonomyResolver` to local `attributes_json`** (Rule 2 —
   correctness vs. the plan's own no-Woo constraint). `AdCandidateScanner`'s brand decoration
   calls `TaxonomyResolver::allBrands()`, which hits the Woo REST API; reusing it verbatim would
   have violated the command's READ-ONLY / no-Woo contract. `brand_id` is exported alongside the
   name so nothing is lost.
2. **`--live-stock` is opt-in and shortlist-scoped** rather than the bulk stock gate (Rule 3 —
   the literal reading conflicts with the plan's own "no N+1" requirement). Documented in the
   command docblock and in the table above.
3. **Two extra options not in the plan:** `--preview=25` (console table length — a 200-row table
   is unusable) and `--live-stock`. Both default to safe behaviour.
4. **Funnel gate 2 folds "empty SKU" in with "no price"** (`dropped_no_price_or_sku`) so the
   drop buckets remain mutually exclusive and provably sum to the catalogue total.

## Known stubs

None.

## Out of scope (unchanged, as instructed)

No migration; `AdCandidateScanner` and the Ad Candidates page untouched; no feed generation or
Merchant Center upload; not pushed, not deployed. Pre-existing working-tree noise
(`storage/app/research/supplier-probe.json` deletion, `CompetitorIngestFreshnessColorTest.php`
modification, untracked `.claude/`) was left unstaged and unmodified.

## Self-Check: PASSED

All created files verified on disk; both task commits (`7c07f26`, `c99cd30`) verified in `git log`.
