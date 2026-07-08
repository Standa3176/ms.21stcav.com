---
phase: 260708-kg4-make-woo-maintenance-source-images-end-t
plan: 01
subsystem: woo-maintenance / product-images
tags: [woo, images, catalogue-gaps, source-images, publisher]
requires:
  - WooClient (shadow-mode-aware put)
  - RunCatalogueGapFixJob (260708-jou)
  - CatalogueGapsPage (260708-cey/fyh)
provides:
  - WooGalleryPublisher (push local gallery to Woo + bump woo_image_count)
  - products:source-images --push-to-woo flag
  - RunCatalogueGapFixJob options pass-through
affects:
  - Woo Maintenance "Source images" fix (row + bulk) is now end-to-end
key-files:
  created:
    - app/Domain/Products/Services/WooGalleryPublisher.php
    - tests/Feature/Products/WooGalleryPublisherTest.php
  modified:
    - app/Console/Commands/SourceProductImagesCommand.php
    - app/Filament/Pages/CatalogueGapsPage.php
    - app/Domain/Products/Jobs/RunCatalogueGapFixJob.php
    - tests/Feature/Products/RunCatalogueGapFixJobTest.php
    - tests/Feature/Products/CatalogueGapsPageTest.php
decisions:
  - "--push-to-woo defaults OFF so the review-first auto-create/draft flow is byte-behaviourally unchanged."
  - "Publisher SKIPS (returns false, no Woo call) when woo_product_id is empty or no real images — never pushes a placeholder."
  - "SourceProductImagesCommand itself is NOT end-to-end tested (no mysqli/image harness); the push is a one-line call to the unit-tested publisher."
metrics:
  tasks: 1
  files: 7
---

# Phase 260708-kg4 Plan 01: Make Woo Maintenance "Source images" End-to-End Summary

The Woo Maintenance "Source images" fix now closes the loop: after sourcing a REAL image
for a product already LIVE on Woo it PUTs the new gallery to that Woo product (a Woo
images-PUT REPLACES the gallery → the placeholder is removed and the real image shows on
the storefront), and bumps the local `woo_image_count` so the dashboard "missing images"
count drops immediately without waiting for the nightly reconcile. Previously the command
only stored images locally, so placeholders lingered and the count never moved.

## What changed

- **NEW `app/Domain/Products/Services/WooGalleryPublisher.php`** — `publish(Product, array $urls): bool`.
  Filters blank URLs, then SKIPS (returns `false`, no Woo call) when `woo_product_id` is empty
  (draft / not live) or there are no real images. Otherwise `$woo->put("products/{id}", ['images'=>[{src},…]])`
  (no leading slash — mirrors `ResyncProductsToWooCommand`; `WooClient::put` is shadow-mode aware via
  `services.woo.write_enabled`) and bumps `woo_image_count = count(urls)` via `saveQuietly`, then logs.

- **`SourceProductImagesCommand`** — added `--push-to-woo` flag (default OFF), injected
  `WooGalleryPublisher`, and after the existing local persist calls `publisher->publish($product, $urls)`
  ONLY when the flag is set. The closing hint reflects whether images were pushed to Woo or kept local.
  With the flag ABSENT the command is byte-behaviourally unchanged — the review-first auto-create/draft
  flow stays local-only.

- **`RunCatalogueGapFixJob`** — added a 4th constructor arg `array $options = []`, merged into
  `Artisan::call($command, array_merge(['--skus'=>$csv], $options))`. `ALLOWED_COMMANDS`, `tries=1`,
  and `onQueue('sync-bulk')` are unchanged. Options come only from the trusted admin-only fix action.

- **`CatalogueGapsPage`** — `fixAction()` + `bulkFixAction()` gained a trailing `array $extraOptions = []`
  param (row merges it into `Artisan::call`; bulk threads it into the job dispatch). The Source-images
  row + bulk actions pass `['--push-to-woo' => true]`; Backfill-EAN + Resync pass nothing (unchanged).
  Source-images tooltips updated to say the fix now sources AND publishes to Woo (replaces the placeholder).

## Tests

- **`WooGalleryPublisherTest` (NEW — core proof):** live product (woo_product_id=555) + 2 urls → `publish()`
  true, `WooClient::put` received `('products/555', payload)` with `payload['images'] === [['src'=>url1],['src'=>url2]]`,
  `woo_image_count == 2`; null `woo_product_id` → false, `put` NOT called, count unchanged; empty/blank urls →
  false, `put` NOT called. WooClient is a Mockery stub bound via `app()->instance`.
- **`RunCatalogueGapFixJobTest` (extended):** options `['--push-to-woo'=>true]` → `Artisan::call` receives BOTH
  `--skus` and `--push-to-woo`; default (no options) → only `--skus`. Existing allow-list / no-op cases kept green.
- **`CatalogueGapsPageTest` (extended):** Source-images BULK dispatches jobs whose `->options === ['--push-to-woo'=>true]`;
  Backfill-EAN + Resync bulk dispatch `->options === []`. The existing Source-images ROW test was updated to expect
  `['--skus'=>…, '--push-to-woo'=>true]`. All prior jou/cey/fyh cases stay green.

All three files: **Tests: 30 passed (164 assertions)**.

## Why the command itself isn't end-to-end tested

`SourceProductImagesCommand` drives a raw mysqli `supplier_db` connection plus the Icecat / web-image /
Claude-vision clients — there is no test harness for that pipeline (confirmed in the plan's `<context>`).
The command's push is a single one-line call to `WooGalleryPublisher::publish()`, which IS unit-tested
directly. Testing the publisher in isolation is the correct proof; a full command E2E test would require
standing up the mysqli + image stack with no existing fixtures.

## Deviations from Plan

None — plan executed exactly as written.

## Driver portability

No SQL changes. `woo_image_count` is a nullable-int cast already used by the Catalogue Gaps columns;
`saveQuietly` on a forceFilled integer is driver-portable (SQLite tests / MariaDB prod).

## Deploy + operator notes

- Deploy: push `main` → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration). Horizon already running.
- After deploy: on Catalogue Gaps, "Source images" (row or bulk) now SOURCES the image AND publishes it to the
  live Woo product — the placeholder is replaced and the real image shows on the shop. Only products already live
  on Woo that get a REAL image are pushed (placeholder-only / manual-review products are left flagged). The
  dashboard "missing images" count drops as products are published (woo_image_count updated live).
- Backfill-EAN + Resync fixes are unchanged. `products:source-images` with no flag is still local-only (the
  review-first auto-create/draft flow is unaffected).
- The already-sourced-but-not-published products from the earlier local-only run: re-click "Source images" on them
  (it will re-source + push), OR run `products:source-images --skus=<csv> --push-to-woo` directly.
