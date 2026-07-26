# 260726-bwa — Read-only Woo brand-membership audit (safe basis for a brand merge)

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Why:** operator wants to merge duplicate `product_brand` terms on the live storefront + delete empties.
The existing `brands:dedupe --dry-run` (run on prod 2026-07-26) exposed TWO blockers that make a live run
UNSAFE, so we need real Woo data before any write:
1. **Canonical picker is backwards** — it keeps the emptier/older term and marks the POPULATED term as
   the "source to delete" (e.g. samsung canonical=0 vs source=163; yealink 0 vs 285; logitech 8 vs 176).
   `--delete-empty-woo-terms` would strip brands from ~700 live products.
2. **Local blind spot** — `product_brand` is many-to-many on Woo; local `products.brand_id` is single-
   valued, so the dedupe saw only 10 reassignable products vs ~713 on the Woo terms. Operator confirms
   some products are tagged with BOTH duplicate terms.

This task builds a READ-ONLY command that reports the TRUE Woo term membership per duplicate group — the
data needed to (a) choose the right canonical and (b) merge safely without orphaning anyone. **No writes,
no canonical-fix, no re-tag, no delete here** — purely the audit.

## Task 1 — Investigate & reuse
- `BrandDuplicateFinder::discover()` — the 7 duplicate groups (canonical + source term ids/names/counts).
  Reuse it to get the groups; do NOT change its canonical logic in this task (that's the follow-up fix).
- How the app lists Woo products by a `product_brand` term (READ-only): reuse the pattern in
  `RetagProductsOnWooCommand` / the finder / `WooClient` GET (WC REST `products?product_brand=<termId>`
  or however the app already does it). Note the exact param + pagination in the SUMMARY.
- Confirm each term's **slug** is available (needed to judge canonical: clean `logitech` vs `logitech-2`).

## Task 2 — `brands:audit-woo-membership` (READ-ONLY, TDD)
For each duplicate group, fetch (via WooClient GET, paginated, bounded) the set of product ids tagged
with the **canonical** term and with the **source** term, then compute:
- `canonical_slug`, `source_slug`, `canonical_woo_count`, `source_woo_count`,
- `on_canonical_only`, `on_source_only`, `on_both`, `distinct_total`,
- a suggested canonical by **most-products** AND a note if the current finder pick disagrees (flag the bug).
Output: a per-group table + summary, and `--csv=<path>` writing per-product rows
(product_id, sku, name, on_canonical, on_source) so the operator can eyeball the ~700.
- READ-ONLY: only `WooClient` GET (or the app's existing brand-term product reader). **Assert in tests
  there is no put/post/delete / no WooClient write call.** No local `brand_id` writes either.
- Bounded: only the duplicate groups' terms (~713 products), not the whole catalogue; paginate; per-term
  page cap with a logged note if hit. Reads the remote Woo API only — light, no shop-box write load.

## Verify
- `pest`: with a stubbed WooClient/brand-reader returning fake term memberships (incl. a both-tagged
  product and a source-only product), assert the on-only/on-both/ distinct maths, the most-products
  canonical suggestion, the "finder pick disagrees" flag, and `--csv` output. NO real network; assert no
  write call.
- `route:list --path=admin` exit 0; `pint`; `deptrac 0` (respect Pricing↛Competitor etc. — this is Sync/
  brand tooling; mirror where DedupeBrandsCommand/RetagProductsOnWooCommand live).

## Guardrails / out of scope
- READ-ONLY audit only. Do NOT fix the canonical selection, do NOT re-tag, do NOT delete, do NOT change
  `brands:dedupe` or `BrandDuplicateFinder`, no local writes, no migration, no WOO_WRITE_ENABLED change.
  Those are the SEPARATE supervised follow-up (canonical fix + Woo-aware re-tag + gated delete).
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP/composer via Herd (~/.config/herd/bin/php84/php.exe). No push, no deploy. Atomic commits. Write
  `260726-bwa-SUMMARY.md` with the Woo brand-term read pattern used, the audit output shape, and the exact
  prod command (e.g. `php artisan brands:audit-woo-membership --csv=storage/app/brand-membership.csv`).
