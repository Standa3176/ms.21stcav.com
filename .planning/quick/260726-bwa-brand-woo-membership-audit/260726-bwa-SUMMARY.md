---
task: 260726-bwa
title: Read-only Woo brand-membership audit
type: quick
subsystem: Sync / brand tooling
tags: [woo, product_brand, dedupe, audit, read-only, tdd]
requires: [BrandDuplicateFinder, WooClient]
provides: [brands:audit-woo-membership]
affects: []
tech-stack:
  added: []
  patterns: [woo-brand-term-read, read-only-audit-command, tdd-red-green]
key-files:
  created:
    - app/Console/Commands/AuditWooBrandMembershipCommand.php
    - tests/Feature/Console/AuditWooBrandMembershipCommandTest.php
  modified: []
decisions:
  - Suggest canonical by MOST live Woo products; flag when finder's slug pick disagrees.
  - HARD read-only — only WooClient::get; tests assert writeCalls === [].
  - Do NOT change brands:dedupe / BrandDuplicateFinder — that is the supervised follow-up.
metrics:
  tasks: 2
  files: 2
  commits: 3
  completed: 2026-07-26
---

# 260726-bwa: Read-only Woo brand-membership audit Summary

READ-ONLY `brands:audit-woo-membership` command that reports the TRUE live Woo
`product_brand` term membership per duplicate group — the safe data needed to pick
the right canonical before a brand merge, given that `brands:dedupe`'s slug-based
canonical pick would delete the POPULATED term for samsung / yealink / logitech and
is blind to the ~713 many-to-many Woo assignments.

## Woo brand-term read pattern (the deliverable ask)

Reuses the exact pattern from `RetagProductsOnWooCommand`:

```php
$this->woo->get('products', [
    'brand'    => $termId,        // WC REST filters products by product_brand term id
    'per_page' => 100,            // WC per-page cap
    'page'     => $n,             // INCREMENTS 1,2,3… (see note below)
    'status'   => 'any',          // capture pending/draft, not just publish
]);
```

- **WC REST param:** `brand=<termId>` (the `product_brand` term id from
  `BrandDuplicateFinder::discover()`). Duplicate-group discovery itself pages
  `products/brands?per_page=100&page=N` inside the finder — unchanged here.
- **Term slugs:** available. `BrandDuplicateFinder::discover()` already carries
  `slug` on every canonical/source row (added 260613-o33), so `canonical_slug` /
  `source_slug` come straight off the finder — no extra Woo call.
- **Pagination difference vs the WRITE command:** `RetagProductsOnWooCommand`
  always re-reads `page=1` because each PUT shrinks the `?brand=N` filter set.
  This audit is a **pure read** — the set never shrinks — so it increments the
  page normally and stops on a short page, an empty page, or the per-term cap.
  De-duped by product id defensively. Backstop: `MAX_PAGES_PER_TERM = 1000`.
- **Bounded:** only the duplicate groups' terms are read (each distinct term
  fetched once, cached across comparisons). `--per-term-cap` (default 10000)
  stops a runaway term and logs `brands.audit_term_cap_hit`. A 404 term (deleted
  between discovery and now) → counted as 0 products + `brands.audit_term_missing`.

## Audit output shape

Per duplicate group, for each (canonical, source) pair:
`canonical_slug`, `source_slug`, `canonical_woo_count`, `source_woo_count`,
`on_canonical_only`, `on_source_only`, `on_both`, `distinct_total`,
`suggested_canonical_id` (most-products winner across all terms in the group),
`finder_disagrees` (true when the finder's slug pick ≠ the most-populated term).

Surfaces as: a per-group table, a summary table
(`duplicate_groups` / `comparison_rows` / `groups_where_finder_disagrees`), one
`brands.audit_group` activity-log row per comparison (machine-readable), and an
optional `--csv` with per-product rows:
`group, canonical_id, source_id, product_id, sku, name, on_canonical, on_source`.

## Exact prod command

```bash
# On prod (read-only — safe to run any time):
php artisan brands:audit-woo-membership --csv=storage/app/brand-membership.csv
```

Then eyeball `storage/app/brand-membership.csv` (≈700 rows) and the on-screen
"Finder disagrees?" column. Any `YES` row = a group where
`brands:dedupe --delete-empty-woo-terms` would delete the populated term. Do NOT
run the dedupe delete until the canonical-fix + Woo-aware re-tag follow-up ships.

## Deviations from Plan

None functional. Two additive-but-in-spirit choices:
- **CSV superset columns** (Rule 2 — usability): the plan listed
  `product_id, sku, name, on_canonical, on_source`; added leading
  `group, canonical_id, source_id` so ~700 rows across 7 groups are unambiguous.
- **Machine-readable audit rows** (`brands.audit_group`) added so the Pest suite
  can assert the maths precisely rather than scraping table text — mirrors the
  existing DedupeBrands / Retag test style (assert on Spatie Activity).

## Guardrails honoured

- READ-ONLY: only `WooClient::get`. The stub throws on put/post/patch/delete and
  every test asserts `writeCalls === []`. No local `brand_id` write, no migration,
  no `WOO_WRITE_ENABLED` change. `brands:dedupe` / `BrandDuplicateFinder` /
  `RetagProductsOnWooCommand` untouched.
- Did NOT stage the pre-existing working-tree noise
  (`storage/app/research/supplier-probe.json` deletion,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked
  `.claude/`). No push, no deploy.

## Verify results

- `pest` (new file): 6 passed, 51 assertions.
- `pint` (new files): pass.
- `deptrac analyse`: 0 violations.
- `artisan route:list --path=admin`: exit 0. Command registered in `artisan list`.

## TDD Gate Compliance

- RED: `test(260726-bwa)` commit — 6 tests fail with CommandNotFoundException.
- GREEN: `feat(260726-bwa)` commit — all 6 pass.
- REFACTOR: none needed.

## Commits

- `b5b0e59` test(260726-bwa): failing tests (RED)
- `cedaf2f` feat(260726-bwa): command implementation (GREEN)
- (docs commit for this SUMMARY follows)

## Follow-up (separate supervised task — NOT this one)

Canonical fix (pick most-populated Woo term) + Woo-aware re-tag (union both terms'
products onto the chosen canonical, preserving BOTH-tagged products) + gated
delete of the now-empty term. This audit is the input to that task.

## Self-Check: PASSED

- FOUND: app/Console/Commands/AuditWooBrandMembershipCommand.php
- FOUND: tests/Feature/Console/AuditWooBrandMembershipCommandTest.php
- FOUND commit: b5b0e59 (test / RED)
- FOUND commit: cedaf2f (feat / GREEN)
