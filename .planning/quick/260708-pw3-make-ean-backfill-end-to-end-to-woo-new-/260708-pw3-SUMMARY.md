---
phase: 260708-pw3-make-ean-backfill-end-to-end-to-woo-new-
plan: 01
subsystem: products / woo-maintenance
tags: [woo, gtin, ean, backfill, catalogue-gaps, wc9]
requires:
  - WooClient::put (shadow-mode-aware Woo write)
  - Product.woo_gtin / woo_product_id / ean columns (existing)
  - RunCatalogueGapFixJob options arg (260708-jou/kg4)
  - CatalogueGapsPage fixAction/bulkFixAction $extraOptions (260708-kg4)
provides:
  - WooGtinPublisher::publish(product, ean) → 'published'|'collision'|'skipped'
  - products:publish-sourced-eans (publish-only, zero re-lookup backlog command)
  - products:backfill-merchant-feed --push-to-woo flag
  - Catalogue Gaps Backfill-EAN row + bulk fixes now end-to-end
affects:
  - app/Filament/Pages/CatalogueGapsPage.php
  - app/Console/Commands/BackfillMerchantFeedCommand.php
tech-stack:
  added: []
  patterns:
    - "Mirror the WooGalleryPublisher / publish-sourced-images shape for GTIN"
    - "WC 9.x duplicate-GTIN collision handling (PublishProductJob Path B)"
    - "driver-portable whereNull('woo_gtin') auto-select (SQLite tests / MariaDB prod)"
key-files:
  created:
    - app/Domain/Products/Services/WooGtinPublisher.php
    - app/Console/Commands/PublishSourcedEansCommand.php
    - tests/Feature/Products/WooGtinPublisherTest.php
    - tests/Feature/Products/PublishSourcedEansCommandTest.php
  modified:
    - app/Console/Commands/BackfillMerchantFeedCommand.php
    - app/Filament/Pages/CatalogueGapsPage.php
    - tests/Feature/Products/CatalogueGapsPageTest.php
decisions:
  - "Only the GTIN is pushed by --push-to-woo; brand/category keep their existing paths."
  - "BackfillMerchantFeedCommand is NOT E2E-tested (raw mysqli supplier_db + EAN-search, no harness) — its push is a loop over the unit-tested WooGtinPublisher."
metrics:
  duration: ~1 task
  completed: 2026-07-08
---

# Phase 260708-pw3 Plan 01: EAN backfill end-to-end to Woo GTIN — Summary

Backfilled EANs can now actually reach Woo's GTIN field (`global_unique_id`) via a
unit-tested, WC-9-collision-safe `WooGtinPublisher` — closing the EAN equivalent of the
image gap. Previously NO path pushed a backfilled EAN to a live product (backfill was
local-only; resync doesn't carry the GTIN; `global_unique_id` was only written on the
initial auto-create POST). Mirrors the recently-shipped image solution
(`WooGalleryPublisher` / `products:publish-sourced-images`), with added duplicate-GTIN handling.

## What was built

### 1. `WooGtinPublisher` (NEW service)
`publish(Product, ?string $ean): 'published'|'collision'|'skipped'`
- PUTs `['global_unique_id' => $ean]` to `products/{woo_product_id}`, then bumps local
  `woo_gtin` so the Woo Maintenance "missing EAN" gap clears without waiting for the nightly reconcile.
- **WC 9.x duplicate-GTIN collision handling** (mirrors `PublishProductJob` Path B, lines 115-142):
  on a `product_invalid_global_unique_id` error it does **NOT** rethrow — it clears the local
  `ean` (via `forceFill(['ean'=>null])->saveQuietly()`) so it stops colliding, and returns
  `'collision'`; `woo_gtin` stays null. Suppliers share one EAN across SKU variants, so this is
  expected, not an error.
- **Non-collision errors DO rethrow** — a real failure must reach the caller/queue.
- Returns `'skipped'` (no Woo call) when `woo_product_id` is empty or `$ean` is blank.

### 2. `products:publish-sourced-eans` (NEW command)
Mirrors `PublishSourcedImagesCommand` — pushes ALREADY-backfilled local EANs to Woo with
**zero re-lookup cost** (no supplier_db / EAN-search / Icecat calls; it just reads `Product.ean`).
- Auto-selects live products (`status=publish` + `woo_product_id NOT NULL` + non-blank `ean`)
  with `woo_gtin` NULL (missing on Woo). `--skus` force-publishes specific SKUs even if they
  already carry a GTIN. `--dry-run` + `--limit` supported.
- Auto-select uses `whereNull('woo_gtin')` (driver-portable — SQLite tests / MariaDB prod).
- Reports `published / collision / skipped`.

### 3. `products:backfill-merchant-feed --push-to-woo` (NEW flag, default OFF)
- After the local EAN backfill, loops the successfully-updated SKUs (`$updatedSkus`) and
  publishes each GTIN via `WooGtinPublisher`, reporting `published / collision(s) / skipped`.
- **Flag-absent behaviour is byte-identical** — the whole block is guarded by
  `if ($pushToWoo && $updatedSkus !== [] && ! $dryRun)`. The `--resync` block and all existing
  behaviour are untouched. Only the GTIN is pushed — brand/category still go via their existing paths.

### 4. Catalogue Gaps wiring
- The Backfill-EAN **row** (`backfill_ean`) and **bulk** (`backfill_ean_bulk`) fixes now pass
  `['--push-to-woo' => true]` (via the existing `fixAction`/`bulkFixAction` `$extraOptions` arg
  from kg4; `RunCatalogueGapFixJob` already merges options + allow-lists `backfill-merchant-feed`).
- Tooltips updated to note the fix now also publishes the EAN to the Woo GTIN with duplicate-GTIN handling.
- Source-images already passed the flag; Resync still passes nothing.

## Tests
`~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Products/WooGtinPublisherTest.php tests/Feature/Products/PublishSourcedEansCommandTest.php tests/Feature/Products/CatalogueGapsPageTest.php`

**Tests: 27 passed (186 assertions).**

- `WooGtinPublisherTest` (core proof): live product → `put('products/201', ['global_unique_id'=>ean])`
  + `woo_gtin` set + returns `'published'`; collision → returns `'collision'`, local `ean` cleared,
  no exception escapes; non-collision error rethrows; null `woo_product_id` / blank `ean` → `'skipped'`, no `put`.
- `PublishSourcedEansCommandTest`: auto-selects only the live+ean+missing-on-Woo backlog; excludes
  already-on-Woo (`woo_gtin` set); skips null-woo_product_id / no-ean; `--dry-run` writes nothing;
  `--skus` force-publishes; `--limit` caps; command registered.
- `CatalogueGapsPageTest` (extended): Backfill-EAN bulk job `->options === ['--push-to-woo'=>true]`;
  Source-images bulk still `['--push-to-woo'=>true]`; Resync bulk `=== []`. All prior cases stay green.

## Why BackfillMerchantFeedCommand is NOT E2E-tested
Its EAN lookup path crosses a raw `mysqli` boundary into `supplier_db.feeds_products` plus the
EAN-search/Icecat network providers — there is no test harness for that pipeline (documented in
the existing `PublishSourcedImagesCommandTest` / `WooGalleryPublisherTest` convention). The
`--push-to-woo` addition is a thin loop over the already-unit-tested `WooGtinPublisher`, so the
behaviour is proven at the publisher level. Only the flag plumbing (signature + constructor +
guarded block) is new here.

## Verification
- `artisan list | grep publish-sourced-eans` → present.
- `artisan products:backfill-merchant-feed --help | grep -c push-to-woo` → 1.
- `pint` on all 7 files → PASS (auto-fixed formatting only).

## Operator notes
- **Deploy:** push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration, no config).
- **Publish the EAN backlog the in-flight 600-SKU backfill is building — WITHOUT re-looking-up:**
  - `sudo -u stcav php artisan products:publish-sourced-eans --dry-run`  (preview count)
  - `sudo -u stcav php artisan products:publish-sourced-eans`            (push all missing-on-Woo)
  `woo_gtin` is bumped live so the dashboard "missing EAN" count drops. **Duplicate GTINs**
  (shared supplier EANs) are reported as collisions and the local EAN is cleared for those
  (WC 9.x won't accept them) — expected, not an error.
- Going forward the Catalogue Gaps "Backfill EAN" fix is end-to-end (looks up AND publishes the
  GTIN). Use `publish-sourced-eans` when the EAN is ALREADY found locally (e.g. the in-flight run);
  use the fix when you still need to look up.
- Only the GTIN is pushed by `--push-to-woo`; brand/category still use their existing paths
  (resync / product_brand).

## Deviations from Plan
None — plan executed as written. (Two follow-up formatting adjustments by pint on
`BackfillMerchantFeedCommand.php` + a `fully_qualified_strict_types` fixup on
`WooGtinPublisherTest.php` are standard PSR-12 autoformatting, not behaviour changes.)
