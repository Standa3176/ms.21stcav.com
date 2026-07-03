---
phase: 260703-hn1-add-products-publish-drafts-command-to-b
plan: 01
subsystem: product-auto-create / console
tags: [publish, drafts, auto-create, woo, PublishProductJob, cli]
requires:
  - PublishProductJob (Path B create-on-Woo + 260702-pes live-stock hydration)
  - Product::autoCreated() scope
  - BaseCommand (perform() contract)
provides:
  - products:publish-drafts — bulk publisher for COMPLETE, never-pushed auto-created drafts
affects:
  - Auto-create Health page workflow (the "— not pushed —" complete-draft backlog now has a CLI to clear it)
tech-stack:
  added: []
  patterns:
    - "reuse PublishProductJob::dispatchSync + published/shadowed/failed counters (mirror DraftFromSuggestions --auto-approve loop)"
    - "driver-aware JSON_LENGTH>0 for --require-images (mirror AutoCreateHealthPage::emptyImagesExpr, non-empty case)"
    - "additive only — NEW command + NEW test; PublishProductJob / DraftFromSuggestionsCommand untouched"
    - "unique hn1* test-helper names to avoid redeclare fatal with PublishProductStockTest's unguarded bindWooStockStub/bindLiveStockResolver"
key-files:
  created:
    - app/Console/Commands/PublishDraftsCommand.php
    - tests/Feature/Console/PublishDraftsCommandTest.php
  modified: []
decisions:
  - "No completeness-threshold gate — this is an explicit operator action on drafts they can already see on the Auto-create Health page. Only the two hard 'never go live without taxonomy' invariants (brand_id + category_id NOT NULL) and the 'never re-push' invariant (woo_product_id IS NULL) are enforced."
  - "Test helpers are prefixed hn1* rather than reusing PublishProductStockTest's bindWooStockStub/bindLiveStockResolver: those are declared WITHOUT a function_exists guard, so a same-named helper here (even guarded) redeclare-fatals when both files load in one suite run. Unique names keep this file self-contained standalone AND collision-free in the full suite."
metrics:
  tasks: 1
  duration: ~30m
  completed: 2026-07-03
---

# Quick Task 260703-hn1: products:publish-drafts Bulk Publisher Summary

Bulk-publish COMPLETE, never-pushed auto-created drafts to Woo through the same `PublishProductJob` path the review-inbox Approve uses — skipping taxonomy-incomplete and already-pushed rows.

## The gap

The Auto-create Health page lists complete auto-created drafts (brand + category + images present) that were never pushed to Woo — they show `auto_create_status=draft` and Woo ID "— not pushed —". There was **no CLI to publish existing drafts**:

- `PublishProductJob` was only wired to the suggestions pipeline (the review-inbox Approve action and `products:draft-from-suggestions --auto-approve`).
- `products:resync-to-woo` only re-pushes products that **already** carry a `woo_product_id`.

So a draft that was generated (brand + category assigned) but never approved had no batch route to go live. `products:publish-drafts` closes that gap.

## What was built

`app/Console/Commands/PublishDraftsCommand.php` (extends `BaseCommand`, same base as `RetryMissingImagesCommand` / `HydrateProductStockFromOffersCommand`).

### Selection predicate (all conditions required)

```php
Product::query()
    ->autoCreated()                 // auto_create_status != 'manual'
    ->whereNull('woo_product_id')   // never pushed
    ->whereNotNull('brand_id')      // complete taxonomy — never a needs-assignment row
    ->whereNotNull('category_id')
    ->whereIn('auto_create_status', $statuses);  // default ['draft']
```

Options:
- `--status=draft` — comma-separated `auto_create_status` values eligible (default `draft`).
- `--skus=A,B` — explicit SKU list, still filtered to complete + not-pushed.
- `--require-images` — restrict to drafts with `>=1` gallery image, via a driver-aware expr (`json_array_length > 0` on SQLite, `JSON_LENGTH > 0` on MariaDB — mirrors `AutoCreateHealthPage::emptyImagesExpr`, non-empty case). Default: publish regardless (Woo uses its placeholder for image-less products).
- `--limit=0` — cap products this run (0 = unbounded).
- `--dry-run` — list matching SKUs (sku, status, image count, brand_id, category_id) + "Would publish N"; dispatch nothing.

### Dispatch + counters (mirrors DraftFromSuggestions `--auto-approve`)

Per matched product: `PublishProductJob::dispatchSync((int) $product->id, $userId)` (userId = `auth()->id() ?? 0`, captured before the loop), then `$product->refresh()`:
- `woo_product_id > 0` → `published++` (Path B back-filled the id; row is now `auto_create_status=published`, `status=publish`).
- no Woo id back-filled → `shadowed++` (WOO_WRITE_ENABLED=false shadow path — row stays a draft, NOT falsely marked published).
- throw → `failed++`, batch continues.

Final line: `scanned / published / shadowed / failed`. Because it runs the full `PublishProductJob` path, each publish also hydrates live stock (260702-pes) and links the brand.

### Invariants

- NEVER publishes a row missing `brand_id` OR `category_id` (they stay for the Brands-to-Add / assign-taxonomy flow).
- Skips already-pushed products (`woo_product_id` set) — those are `products:resync-to-woo`'s job.
- No completeness-threshold gate (documented in the class docblock as intentional — explicit operator action).

## Tests

`tests/Feature/Console/PublishDraftsCommandTest.php` — 9 cases, 31 assertions, all GREEN.

Test seams (mirror `PublishProductStockTest`): a `WooClient` anon-subclass stub whose `post()` returns `['id'=>4242,'slug'=>..]` so `PublishProductJob` Path B back-fills `woo_product_id`; a null-returning `LiveSupplierStockResolver` Mockery double so the 260702-pes stock hydration is a harmless no-op; an empty-`allBrands()` `TaxonomyResolver` so the post-publish brand linkage is skipped without a live Woo REST call.

Coverage: complete draft publishes (woo_product_id set + `published`); `published: 1` counter; incomplete `brand_id=null` skipped (stays draft); incomplete `category_id=null` skipped; already-pushed (`woo_product_id` set) untouched; `--dry-run` no-op + lists SKU + "Would publish 1"; `--require-images` excludes a 0-image draft, includes a gallery draft; `--skus=A` publishes only A when B also qualifies; clean no-op ("No complete not-pushed drafts match.") when only a `manual` product exists.

**Redeclare-fatal avoided:** `PublishProductStockTest` declares `bindWooStockStub` / `bindLiveStockResolver` WITHOUT a `function_exists` guard. A same-named helper here — even behind `function_exists` — still redeclare-fatals, because this file loads first (`Console` < `ProductAutoCreate` alphabetically) and the OTHER file's unconditional declaration then collides. Fixed by prefixing all local helpers `hn1*` (`hn1BindWooStub`, `hn1BindLiveStock`, `hn1BindNoBrandsTaxonomy`, `hn1CompleteDraft`) — verified: both files run together (14 passed) and this file runs standalone (9 passed).

## Verification (Herd php)

- `pest tests/Feature/Console/PublishDraftsCommandTest.php` → **9 passed (31 assertions)**.
- both files together (`PublishDraftsCommandTest` + `PublishProductStockTest`) → **14 passed (59 assertions)** (no redeclare fatal).
- `pint --test app/Console/Commands/PublishDraftsCommand.php` → PASS. `pint` on the test file → PASS.
- `artisan list | grep publish-drafts` → present.

## Deviations from Plan

None functional — plan executed exactly as written (RED → GREEN, no refactor). The only judgement call was helper naming: the plan suggested "reuse PublishProductStockTest's bindWooStockStub if loadable, else a local stub". A `function_exists` guard does not make that safe (the other file's declaration is unguarded and this file loads first), so unique `hn1*` names were used instead — which the plan's own note anticipated ("do NOT redeclare a helper that a loaded test file already declares (redeclare fatal)").

## Commits

- `7b6e46b` test(260703-hn1): failing test for products:publish-drafts (RED)
- `423ba34` feat(260703-hn1): products:publish-drafts bulk publisher (GREEN)
- `3b86453` fix(260703-hn1): uniquely-name test helpers to avoid redeclare fatal

## Operator two-step (post-deploy)

Deploy: push `main` → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration). Then, to clear the Auto-create Health list:

1. Source images for the 0-image ones and push them live:
   - `php artisan products:retry-missing-images --resync --dry-run` (preview)
   - `php artisan products:retry-missing-images --resync` (live)
2. Publish the complete not-pushed drafts (now image-ready):
   - `php artisan products:publish-drafts --dry-run` (preview count + SKUs)
   - `php artisan products:publish-drafts` (live)

Notes:
- `publish-drafts` NEVER publishes a draft missing brand or category (those need Brands-to-Add / assign-taxonomy first). Add `--require-images` to publish only drafts that already have a gallery.
- Each publish runs the full `PublishProductJob` path, so it also hydrates live stock (260702-pes) and links the brand.
- Requires `WOO_WRITE_ENABLED=true` (prod). With writes off, rows report 'shadowed' and are NOT marked published.

## Self-Check: PASSED

- `app/Console/Commands/PublishDraftsCommand.php` — FOUND
- `tests/Feature/Console/PublishDraftsCommandTest.php` — FOUND
- commit `7b6e46b` — FOUND
- commit `423ba34` — FOUND
- commit `3b86453` — FOUND
