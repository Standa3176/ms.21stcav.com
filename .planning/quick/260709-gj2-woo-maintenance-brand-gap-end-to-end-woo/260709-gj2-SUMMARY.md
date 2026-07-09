---
phase: 260709-gj2-woo-maintenance-brand-gap-end-to-end-woo
plan: 01
subsystem: api
tags: [woocommerce, product_brand, catalogue-gaps, woo-maintenance, artisan-command, filament]

# Dependency graph
requires:
  - phase: 260708-pw3 (publish-sourced-eans)
    provides: the WooGtinPublisher + PublishSourcedEansCommand publish-backlog pattern this mirrors
  - phase: 260708-jou/kg4 (RunCatalogueGapFixJob + CatalogueGapsPage fixes)
    provides: the allow-listed background bulk-fix chunker + fix-action shape
  - phase: 260708-fyh (Catalogue Gaps missing_brand gap)
    provides: the woo_brand_count 'Brand' column + missing_brand gap this closes
provides:
  - WooBrandPublisher — brand-only push of the product_brand taxonomy term to a live Woo product + woo_brand_count bump
  - products:publish-sourced-brands — cheap brand-only backlog command (mirrors publish-sourced-eans)
  - Catalogue Gaps 'Publish brand' row + bulk fix (allow-listed via RunCatalogueGapFixJob)
affects: [woo-maintenance, catalogue-gaps, product_brand]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "publish-sourced-* backlog command: build id→name map ONCE from the live Woo list, auto-select the missing-on-Woo backlog (or --skus), --dry-run/--limit, tally results"
    - "Brand-only Woo push via WooBrandPublisher — no price/tag/attribute side-effects (unlike Resync)"

key-files:
  created:
    - app/Domain/Products/Services/WooBrandPublisher.php
    - app/Console/Commands/PublishSourcedBrandsCommand.php
    - tests/Feature/Products/WooBrandPublisherTest.php
    - tests/Feature/Products/PublishSourcedBrandsCommandTest.php
  modified:
    - app/Domain/Products/Jobs/RunCatalogueGapFixJob.php
    - app/Filament/Pages/CatalogueGapsPage.php
    - tests/Feature/Products/CatalogueGapsPageTest.php

key-decisions:
  - "Reused ProductBrandTermResolver (getTermIdForName + assignToProduct) rather than creating brands — WooBrandPublisher reports 'no_term' when the term is absent."
  - "publish-sourced-brands injects WooClient directly and fetches products/brands (mirroring ResyncProductsToWooCommand's brand-name map) rather than routing through TaxonomyResolver — the plan + tests bind a WooClient products/brands stub."
  - "Products with NO local brand_id are out of scope (never selected) — documented follow-up needing products:backfill-merchant-feed --field=brand first."

patterns-established:
  - "Pattern: brand-only Woo publisher mirroring WooGtinPublisher/WooGalleryPublisher (skipped/no_term/published + woo_* mirror bump)."

requirements-completed: []

# Metrics
duration: ~35min
completed: 2026-07-09
---

# Phase 260709-gj2: Woo Maintenance Brand-Gap End-to-End Summary

**WooBrandPublisher + products:publish-sourced-brands close the Woo Maintenance missing-brand gap by assigning the product_brand taxonomy term to live Woo products that already carry a local brand_id, bumping woo_brand_count — surfaced as a one-click Catalogue Gaps 'Publish brand' row + bulk fix.**

## Performance

- **Duration:** ~35 min
- **Tasks:** 1 (TDD)
- **Files modified:** 7 (4 created, 3 modified)

## Accomplishments
- `WooBrandPublisher::publish(Product, ?brandName): 'published'|'no_term'|'skipped'` — resolves the brand NAME → product_brand term (ProductBrandTermResolver::getTermIdForName), assigns it to the live Woo product (assignToProduct), bumps local `woo_brand_count=1`. Skips when no `woo_product_id`/blank brand/assign fails; reports `no_term` when the term doesn't exist (brand not created here). Brand-only — no price/tag/attribute side-effects.
- `products:publish-sourced-brands` command — builds `brand_id → name` ONCE from the live Woo brand list (`products/brands`, paginated, mirroring ResyncProductsToWooCommand), auto-selects live products (`status=publish`, `woo_product_id NOT NULL`, `brand_id NOT NULL`) with `woo_brand_count=0`, or honours `--skus`; `--dry-run`/`--limit`; reports `published / no brand term / skipped`.
- `products:publish-sourced-brands` added to `RunCatalogueGapFixJob::ALLOWED_COMMANDS`.
- Catalogue Gaps 'Publish brand' row + bulk fix (admin-only, no `--push-to-woo` — this command IS the push).

## Task Commits

1. **Task 1: WooBrandPublisher + publish-sourced-brands + Catalogue Gaps fix (TDD)** - see commit below (feat)

**Plan metadata:** SUMMARY committed separately (docs).

## Files Created/Modified
- `app/Domain/Products/Services/WooBrandPublisher.php` - Brand-only Woo push (product_brand term assign + woo_brand_count bump).
- `app/Console/Commands/PublishSourcedBrandsCommand.php` - products:publish-sourced-brands backlog command.
- `app/Domain/Products/Jobs/RunCatalogueGapFixJob.php` - Allow-list the new command.
- `app/Filament/Pages/CatalogueGapsPage.php` - 'Publish brand' row + bulk fix (kept resync + all existing fixes).
- `tests/Feature/Products/WooBrandPublisherTest.php` - published/no_term/skipped/assign-false + woo_brand_count bump (Mockery resolver).
- `tests/Feature/Products/PublishSourcedBrandsCommandTest.php` - selection / auto-exclude-already-on-Woo / --dry-run / --skus / registration (WooClient products/brands stub + resolver stub).
- `tests/Feature/Products/CatalogueGapsPageTest.php` - Publish-brand bulk dispatches RunCatalogueGapFixJob with 'products:publish-sourced-brands' (existing image/EAN/resync cases kept green).

## Decisions Made
- Mirrored the 260708-pw3 EAN pattern byte-for-byte in shape (publisher + command + allow-list + Catalogue Gaps fix).
- Command fetches `products/brands` via injected WooClient (not TaxonomyResolver) so tests can bind a deterministic stub, matching the plan's interfaces.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Preserve the existing `resync` row action**
- **Found during:** Task 1 (Catalogue Gaps fix)
- **Issue:** The initial edit that added the `publish_brand` row action accidentally REPLACED the existing `resync` row action instead of inserting after it, breaking the pre-existing "resync row action dispatches products:resync-to-woo" test.
- **Fix:** Restored the `resync` fixAction and inserted `publish_brand` after it (both row + bulk).
- **Files modified:** app/Filament/Pages/CatalogueGapsPage.php
- **Verification:** All 28 CatalogueGapsPageTest cases green (including the resync row + bulk cases).
- **Committed in:** Task 1 commit.

---

**Total deviations:** 1 auto-fixed (1 bug).
**Impact on plan:** Kept the existing fix actions intact per the plan's "existing fixes unchanged" requirement. No scope creep.

## Issues Encountered
- None beyond the resync-row regression above (found + fixed during TDD before commit).

## Observations / Follow-ups (not built here)
- **`getTermIdForName` creates the term in production.** The plan describes `ProductBrandTermResolver::getTermIdForName` as "resolve only (null if the term doesn't exist)", but the live implementation CREATES the product_brand term (with slug-collision strategy) when absent. So in prod the `no_term` path is rare — the resolver will usually create the brand and return an id. The `no_term` branch is fully exercised via the bound Mockery stub in tests and remains correct if a future resolver change makes it resolve-only. Flagged so operators know `publish-sourced-brands` may create product_brand terms as a side-effect of resolution (consistent with today's Resync behaviour).
- **Products with NO local brand_id** are out of scope (never selected). They need brand derivation first: `products:backfill-merchant-feed --field=brand` (supplier manufacturer → brand_id), then `publish-sourced-brands`.
- **Products reported 'no brand term'** (if the resolver is ever made resolve-only) need the brand CREATED in the product_brand taxonomy first.

## Operator Run Steps
- **Deploy:** push `main` → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration).
- **Clear the missing-brand gap for products that already have a brand:**
  - `sudo -u stcav php artisan products:publish-sourced-brands --dry-run`   (preview)
  - `sudo -u stcav php artisan products:publish-sourced-brands`             (assign product_brand on Woo)
  - then reconcile + reload — 'missing brand' drops. Or use the Catalogue Gaps 'Publish brand' fix (row/bulk).

## Verification
- `pest` (3 files) → **28 passed, 203 assertions**.
- `artisan list | grep publish-sourced-brands` → present.
- `pint` (7 files) → **pass**.

## Self-Check: PASSED

---
*Phase: 260709-gj2-woo-maintenance-brand-gap-end-to-end-woo*
*Completed: 2026-07-09*
