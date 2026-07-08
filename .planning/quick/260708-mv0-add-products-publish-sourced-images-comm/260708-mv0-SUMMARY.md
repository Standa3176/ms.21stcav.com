---
phase: 260708-mv0-add-products-publish-sourced-images-comm
plan: 01
subsystem: products / woo-sync
tags: [woo, images, backlog, cli, tdd]
requires:
  - "WooGalleryPublisher::publish (260708-kg4)"
provides:
  - "products:publish-sourced-images command (publish-only, zero re-source)"
affects:
  - "Woo product galleries (placeholder replacement) + Product.woo_image_count"
tech-stack:
  added: []
  patterns:
    - "Thin BaseCommand (perform(): int) over an existing domain service"
    - "Driver-portable whereNull-or-zero closure (SQLite tests / MariaDB prod)"
    - "Mockery WooClient stub via app()->instance so the REAL publisher runs"
key-files:
  created:
    - app/Console/Commands/PublishSourcedImagesCommand.php
    - tests/Feature/Products/PublishSourcedImagesCommandTest.php
  modified: []
decisions:
  - "Reuse WooGalleryPublisher unchanged — the command is pure selection + iteration, no new Woo logic."
  - "Auto-select excludes woo_image_count>0 so re-runs are idempotent; --skus force-publishes regardless."
metrics:
  duration: ~10m
  completed: 2026-07-08
---

# Phase 260708-mv0 Plan 01: products:publish-sourced-images Summary

A publish-only artisan command that pushes ALREADY-SOURCED local galleries to Woo with ZERO
re-sourcing cost, reusing the 260708-kg4 `WooGalleryPublisher` to clear the ~180-product backlog
that was sourced locally before the end-to-end fix existed.

## What was built

`products:publish-sourced-images` — a thin `BaseCommand` (`perform(): int`, mirroring
`ResyncProductsToWooCommand`) that constructor-injects `WooGalleryPublisher`. For each selected
product it calls `$publisher->publish($product, (array) $product->gallery_image_urls)`, which does
the Woo images-PUT (replaces the gallery → removes the placeholder) and bumps `woo_image_count`
via `saveQuietly`. No Icecat / web / Claude-vision sourcing is ever touched — the only writes are
the Woo image-PUT and the local count bump, both inside the already-tested publisher.

### Selection logic

Base query: `status = publish`, `woo_product_id NOT NULL`, `gallery_image_urls NOT NULL AND != '[]'
AND != ''`.

- **`--skus=A,B,C`** — target exactly those SKUs (force-publish, even if they already have Woo
  images).
- **no `--skus`** — auto-target the backlog: additionally require the product to still be missing on
  Woo, via a driver-portable closure `where(fn ($q) => $q->whereNull('woo_image_count')->orWhere('woo_image_count', 0))`.
  Products that already have Woo images are left alone → re-running is idempotent.
- **`--dry-run`** — list the selection + counts, NO Woo write.
- **`--limit=N`** — cap how many are processed (0 = no cap).

Reports `selected / published / skipped` counts. The publisher itself still skips any non-live or
empty-gallery product that slips through and never pushes a placeholder.

## Tests

`tests/Feature/Products/PublishSourcedImagesCommandTest.php` — binds a Mockery `WooClient` stub via
`app()->instance()` so the REAL `WooGalleryPublisher` runs against it, then asserts `put()` call
counts + endpoints. Fixture: P1 (live #101, gallery, count 0 = the backlog SKU), P2 (woo id NULL =
draft), P3 (#103, empty gallery), P4 (#104, gallery, count 5 = already on Woo).

- Auto-select (no `--skus`) publishes ONLY P1 — `put` once for `products/101`; P4 excluded by the
  `woo_image_count` filter; P2/P3 not selected. `P1.woo_image_count` → 1.
- `--dry-run` calls `put` ZERO times and lists P1.
- `--skus=MV0-DONE` force-publishes P4 (`put` for `products/104`) despite it already having images.
- `--skus=MV0-DRAFT,MV0-EMPTY` selects 0 and never pushes (null woo id + empty gallery excluded).
- `--limit=1` caps to a single product.
- Command is registered (asserted via the console Kernel).

Result: **Tests: 6 passed (25 assertions)**. `artisan list` shows `products:publish-sourced-images`.
`pint` clean.

## Deviations from Plan

None — plan executed as written. (Pint reordered imports in the test file; no logic change.)

## Operator notes

- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration).
- Publish the already-sourced backlog to Woo WITHOUT paying to re-source:
  - `sudo -u stcav php artisan products:publish-sourced-images --dry-run` (preview count)
  - `sudo -u stcav php artisan products:publish-sourced-images` (publish all missing-on-Woo)
  Placeholders are replaced and the dashboard 'missing images' count drops as it runs
  (`woo_image_count` bumped).
- Use `--skus=A,B,C` to target specific products, `--limit=N` to do a first batch. Products that
  already have Woo images are left alone (auto-select excludes `woo_image_count>0`). Idempotent:
  re-running skips the done ones.
- This is the cheap counterpart to the Catalogue Gaps 'Source images' fix (which re-sources +
  publishes). Use this command when the gallery is ALREADY sourced locally; use the fix when you
  still need to source.

## Self-Check: PASSED
- FOUND: app/Console/Commands/PublishSourcedImagesCommand.php
- FOUND: tests/Feature/Products/PublishSourcedImagesCommandTest.php
- FOUND: .planning/quick/260708-mv0-add-products-publish-sourced-images-comm/260708-mv0-SUMMARY.md
