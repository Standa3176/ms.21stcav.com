# 260722-shc — `products:shopping-candidates` (Google Shopping shortlist)

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Goal:** produce a ranked, Google-Merchant-eligible shortlist (default top 200) of **competitive,
high-margin, in-demand** products to trial on Google Shopping — exportable as CSV.

## Context / why this shape
The operator asked for "high volume/margin" where **volume = UK market demand, not own-store sales**.
The app has **no UK search-volume data** (own `last_sales_count_90d` and GA4 are both own-site signals).
So this command ranks on the best available internal **demand proxies** and leaves true search volume to
Google Keyword Planner (the operator runs the exported shortlist through it, location=UK, and we rank the
final pick by real demand):
- **competitor breadth** — how many competitors currently list the product (proven-demand proxy; already
  a first-class signal in the Suggestions inbox as the 3+/2/1 "Competitor count" filter),
- **margin** — absolute + %,
- gated on **saleable + Google-eligible**.

## Task 1 — Investigate & reuse (don't duplicate)
Read and reuse rather than re-implement:
- `app/Domain/Pricing/Services/AdCandidateScanner.php` — existing margin ≥ `minMarginPence` (default
  £199/19900p) + "lowest current competitor within a 30-day window exists" + "supplier_offer_snapshot
  stock > 0 within 7 days" (+ stale-supplier exclusion), chunked windowed SQL. **Do NOT modify it** — it
  powers the existing Ad Candidates page; build alongside it and reuse its patterns/queries.
- `CompetitorPositionScanner` — lowest-competitor resolution. **Deptrac note:** Pricing ↛ Competitor, so
  competitor data is read via parameter-bound raw `DB::select`, never Competitor model imports. Respect
  that; keep deptrac at 0.
- `LiveSupplierStockResolver::isListedByFreshSupplier()` (260713-rsp) — the churn-safe "current fresh
  in-stock supplier offer" signal.
- `products.ean` — the GTIN. Google Merchant **disapproves** items lacking a GTIN (or brand+MPN), so this
  is a hard eligibility gate by default.

## Task 2 — Build the command (TDD)
`products:shopping-candidates` — **READ-ONLY** (no writes, no Woo calls, no external APIs).

**Eligibility gates** (each one counted + reported so the operator can see *why* the list is what it is):
- `status = 'publish'` and `type = 'simple'` (variable products complicate a Merchant feed),
- `buy_price` and `sell_price` present and > 0,
- margin ≥ `--min-margin-pence` (default **19900** = £199, matching AdCandidateScanner),
- **current fresh in-stock supplier offer** (reuse the rsp signal),
- **competitor count ≥ `--min-competitors`** (default **2**) — distinct competitors with a current price
  inside `--competitor-window-days` (default 30),
- **has a GTIN** (`ean` non-empty) unless `--allow-missing-gtin` is passed (when passed, flag those rows).

**Per-product fields computed:** margin pence + margin %, competitor_count, lowest competitor gross,
our position vs lowest (beat / above, and by how much), stock, ean present, sku, name, brand,
woo_product_id.

**Ranking:** `--sort=score|margin|competitors` (default `score`), where **score = competitor_count ×
margin_pence** (demand proxy × value). Ties broken by margin desc. `--limit` default **200**.

**Output:** a gate-by-gate funnel summary (how many products dropped at each gate — including **how many
were excluded for missing GTIN**, which quantifies the EAN-backfill opportunity), then a table of the top
N, plus `--csv=<path>` to write the full shortlist (header row + one row per product) for Merchant Center
prep / Keyword Planner lookup.

**Bounded/efficient:** one windowed pass over competitor prices (mirror AdCandidateScanner's approach);
chunk the product scan; no N+1 per-product competitor queries.

## Verify
- `pest`: each gate excludes correctly (margin floor, min-competitors, missing GTIN with and without
  `--allow-missing-gtin`, non-publish, non-simple, no fresh stock); ranking order correct for all three
  `--sort` modes; `--csv` writes the expected header + rows; funnel counts add up; **no Woo call and no
  writes** (assert). Driver-portable (SQLite tests / MariaDB prod).
- `php artisan route:list --path=admin` exit 0; `pint`; `vendor/bin/deptrac analyse` → **0 violations**
  (respect Pricing ↛ Competitor — raw parameter-bound DB reads only).

## Guardrails / out of scope
- READ-ONLY reporting command. No writes, no Woo/Google API calls, no migration, no changes to
  `AdCandidateScanner` or the Ad Candidates page, no feed generation/upload (this only produces the
  shortlist; actually pushing to Merchant Center is a separate decision).
- Do NOT claim UK search volume — the command's output must state plainly that ranking uses
  competitor-breadth as a demand **proxy** and that true UK volume should be validated in Keyword Planner.
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP/composer via Herd (~/.config/herd/bin/php84/php.exe). No push, no deploy. Atomic commits. Write
  `260722-shc-SUMMARY.md` with the gates, the ranking formula, the exact prod command + a suggested
  Keyword-Planner workflow for the exported CSV.
