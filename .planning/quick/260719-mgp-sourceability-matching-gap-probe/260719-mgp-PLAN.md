# 260719-mgp — Sourceability matching-gap probe (why are ~1,830 "not sourceable"?)

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Why:** ~1,830 on-Woo products aren't matched to `supplier_sku_cache` (exact lowercased-SKU match).
Before any cull or matcher rewrite, we need the REAL split: how many are (a) carried by a supplier
**under a different SKU format** (matching gap — fixable), (b) the **manufacturer is in the feed but this
exact item isn't** (likely discontinued/lead-time), (c) **not in the feed at all** (genuinely absent).
This decides whether the cleanup is a mechanical matcher fix or a business cull.

## Safety (post-incident — read carefully)
- READ-ONLY. No writes anywhere. No Woo calls.
- Queries the **remote supplier feed DB** (the `supplier_db` connection / VPS) — a SEPARATE box from the
  shop+app server, so it does NOT load the incident box. Still: **SAMPLE + bound** every remote query.
- Default sample size modest (150); dedupe remote lookups; cap rows fetched per manufacturer. The prod
  RUN is the instrument that produces the split — tests cover the classification logic, not live data.

## Task 1 — Investigate the remote feed access
- Find how the app reads the supplier feed: `SupplierSkuRegistry::refresh()` + `supplier:db-sync`
  (the connection name for `supplier_db`, and the `feeds_products` columns — expect `mpn`,
  `suppliersku`, `manufacturer`/brand, `stock`, `product_excluded`; confirm exact names). Note the SKU
  normalisation used for the cache (`LOWER(TRIM(...))`) and any padded-CHAR handling (memory:
  space-padded feed keys — trim matters).
- Note the local product fields available for matching: `sku`, `brand_id`/brand name, manufacturer.
- Record findings in the SUMMARY.

## Task 2 — Build `supplier:probe-sourceability-gap` (TDD)
A read-only diagnostic command:
- **Sample:** select up to `--limit` (default 150) on-Woo products that are NOT in `supplier_sku_cache`
  (the "not sourceable" set), `--status=publish|pending|all` (default `all`). Random sample
  (`inRandomOrder()`) for representativeness; `--limit` raisable for tighter confidence.
- **Classify each** against the remote feed (bounded queries):
  1. Compute `norm(x)` = lowercase + strip non-alphanumerics (e.g. `MR.JQU11.002` → `mrjqu11002`).
  2. Fetch the feed rows for the product's **manufacturer/brand** (one bounded query per distinct
     manufacturer, cached within the run; cap rows). Compare `norm(product.sku)` to
     `norm(mpn)` / `norm(suppliersku)` of those rows.
  3. Bucket: **(a) matching_gap** — a normalised match exists → supplier carries it under a different
     format; **(b) brand_in_feed_item_absent** — manufacturer has rows in the feed but no SKU match;
     **(c) not_in_feed** — manufacturer absent from the feed entirely; **(d) no_manufacturer** — product
     has no brand/manufacturer to key on (report separately; optionally a bounded global norm-SKU search).
- **Output:** a summary table — count + % per bucket over the sample — plus ~5 example SKUs per bucket
  (sku | name | manufacturer | matched-feed-key if any). Make the interpretation explicit in the output
  ("(a) is fixable via matcher; (c) is genuinely absent").
- Efficiency: dedupe per-manufacturer feed fetches; hard cap per-manufacturer rows (e.g. 5,000) with a
  logged note if capped; total remote queries ≈ number of distinct manufacturers in the sample.

## Verify
- `pest`: unit-test the **classification + normalisation** as pure logic against a FAKE in-memory feed
  dataset (given known feed rows, assert a/b/c/d bucketing incl. the different-format case like
  `MR.JQU11.002` vs feed `MRJQU11002`/`MR-JQU11-002`); assert the command runs with a stubbed feed
  reader and prints the summary; NO real remote/network in tests.
- `route:list --path=admin` exit 0 (boot ok); `pint`; `deptrac 0`.

## Guardrails / out of scope
- READ-ONLY diagnostic only. No matcher CHANGES yet (that's the follow-up once we see the split), no
  status changes, no Woo writes, no migration. Does NOT touch WOO_WRITE_ENABLED or the demotion logic.
- Structure so the remote-feed read is a thin injectable seam (so the classification is unit-testable
  without the VPS). Driver-portable.
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP/composer via Herd (~/.config/herd/bin/php84/php.exe). No push, no deploy. Atomic commits. Write
  `260719-mgp-SUMMARY.md` (remote-feed access map, the classification method + normalisation, tests, and
  the exact prod command the operator runs to get the split — e.g. `php artisan supplier:probe-
  sourceability-gap --limit=150`).
