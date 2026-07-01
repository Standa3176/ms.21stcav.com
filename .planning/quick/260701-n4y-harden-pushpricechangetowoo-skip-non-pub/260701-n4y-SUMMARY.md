---
phase: 260701-n4y-harden-pushpricechangetowoo-skip-non-pub
plan: 01
subsystem: pricing / woo-sync
tags: [woo, pricing, auto-reprice, self-heal, cleanup-command]
requires:
  - app/Domain/Pricing/Listeners/PushPriceChangeToWoo.php (existing listener)
  - app/Domain/Sync/Services/WooClient.php (get/put wrapper)
  - app/Console/Commands/BaseCommand.php (correlation_id threading)
provides:
  - PushPriceChangeToWoo skip-non-publish guard + invalid-id self-heal (no rethrow)
  - products:reconcile-stale-woo-ids batch cleanup command
affects:
  - the nightly auto-reprice (fewer failed_jobs — stale-draft ids no longer PUT)
tech-stack:
  added: []
  patterns:
    - anonymous WooClient subclass stub (mirrors bindWooStub in BackfillCategoryFromWooCommandTest)
    - str_contains on WC error CODE (woocommerce_rest_product_invalid_id) for self-heal detection
key-files:
  created:
    - app/Console/Commands/ReconcileStaleWooIdsCommand.php
    - tests/Feature/Pricing/PushPriceChangeToWooStaleIdTest.php
    - tests/Feature/Console/ReconcileStaleWooIdsCommandTest.php
  modified:
    - app/Domain/Pricing/Listeners/PushPriceChangeToWoo.php
    - app/Providers/AppServiceProvider.php
decisions:
  - "Null woo_product_id even on the variant path — an invalid parent product id makes the variation write moot (per plan <interfaces>)."
  - "Reconcile command is operator-triggered, NOT scheduled — the listener guards prevent future backlog, so a recurring cron is unnecessary."
metrics:
  duration: ~35m
  completed: 2026-07-01
  tasks: 2
  files_created: 3
  files_modified: 2
  commits: 2 (task) + 1 (docs)
---

# Phase 260701-n4y Plan 01: Harden PushPriceChangeToWoo (skip non-publish + clear stale ids) Summary

Stopped the auto-reprice from failing (and cluttering `failed_jobs`) on products whose Woo product was deleted, by skipping non-publish products at the root and self-healing invalid Woo ids on published products, plus a one-pass cleanup command for the existing backlog.

## Blast-radius finding (2026-07-01)

Of 6,237 products with a `woo_product_id`, **204 are STALE** (Woo returns `woocommerce_rest_product_invalid_id` — no such id) and **ALL 204 are `status=draft`** (old `manual` imports). The **live shop is fine** — every PUBLISHED product has a valid id, so live prices sync correctly. The failures were pure noise: `PushPriceChangeToWoo` PUT a price for these drafts, Woo replied invalid-id, the job threw and retried.

## What shipped

### Task 1 — PushPriceChangeToWoo hardening (`08bf0dc`)

Two behaviour changes; working live-price sync for PUBLISHED products is **unchanged**:

1. **Skip non-publish.** After the existing null-woo-id skip, `handle()` returns early when `$product->status !== 'publish'`, logging `pricing.woo_push_skipped_not_published` (product_id, sku, status). Drafts/pending never reach Woo — this removes the 204 stale-draft push failures at the source.
2. **Self-heal invalid id (no rethrow).** Both `put()` sites (product path and variant path) route through a new private `putOrClearStale()`. A Woo error whose message contains `woocommerce_rest_product_invalid_id` is caught → logs `pricing.woo_push_stale_id_cleared` → NULLs `woo_product_id` via `forceFill([...])->saveQuietly()` → **returns without rethrowing** (job succeeds, stops retrying, product flagged for re-link). **Any other exception rethrows** so genuine/transient errors (5xx, 429-exhaustion, auth) still retry.

Nulling `woo_product_id` is the correct recovery even on the variant path — an invalid parent product id makes the variation write moot.

### Task 2 — products:reconcile-stale-woo-ids command (`3755020`)

New `ReconcileStaleWooIdsCommand` (`app/Console/Commands/ReconcileStaleWooIdsCommand.php`) extends `BaseCommand`, constructor-injects `WooClient`. Signature: `products:reconcile-stale-woo-ids {--dry-run} {--chunk=100}`.

- Pulls every product with `woo_product_id > 0`, chunks (default 100), asks Woo which ids still exist via `GET products?include=<csv>&per_page=100&_fields=id&status=any`, and NULLs (`saveQuietly`) the `woo_product_id` of any product whose id Woo no longer returns.
- `--dry-run` reports total-with-id / stale count / by-status breakdown with **zero writes**.
- Registered in `AppServiceProvider`'s command list, mirroring `SyncSupplierFeedDatesCommand`.

## Tests

- `tests/Feature/Pricing/PushPriceChangeToWooStaleIdTest.php` — Cases A–D: invalid-id clears id + no throw / draft → zero puts / generic error rethrows / happy path pushes once + id unchanged. **4 passed (12 assertions).**
- `tests/Feature/Console/ReconcileStaleWooIdsCommandTest.php` — dry-run reports stale:1 with no write / live nulls the stale id only / command registered. **3 passed (11 assertions).**
- Full regression `tests/Feature/Pricing/ tests/Feature/Console/`: **248 passed (1020 assertions), 0 failures, 0 regressions.**
- Pint `--test` on both changed source files: **PASS.**
- `artisan list | grep reconcile-stale-woo-ids`: **present.**

Both tests use an anonymous `WooClient` subclass stub (skips parent constructor, records calls, optionally throws), mirroring `bindWooStub` in `BackfillCategoryFromWooCommandTest`.

## Deviations from Plan

None — plan executed exactly as written. No auth gates. Pint applied an idempotent auto-format to the new command file (fully-qualified-strict-types / spacing); no logic changed.

## Operator deploy + reconcile steps (NOT executed by Claude)

1. **Deploy:** push `main` → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
2. **Clean the existing 204 stale (draft) ids — preview first:**
   `php artisan products:reconcile-stale-woo-ids --dry-run` (expect `stale=204`, by-status `draft: 204`), then run without `--dry-run` to apply.
3. After deploy, the morning reprice no longer fails on stale-draft ids (skipped as non-publish), and any future stale id on a **published** product self-heals (id nulled + logged `pricing.woo_push_stale_id_cleared`).
4. **Live-price sync for published products is unchanged.**

## Self-Check: PASSED

- `app/Domain/Pricing/Listeners/PushPriceChangeToWoo.php` — FOUND (modified)
- `app/Console/Commands/ReconcileStaleWooIdsCommand.php` — FOUND (created)
- `app/Providers/AppServiceProvider.php` — FOUND (modified)
- `tests/Feature/Pricing/PushPriceChangeToWooStaleIdTest.php` — FOUND (created)
- `tests/Feature/Console/ReconcileStaleWooIdsCommandTest.php` — FOUND (created)
- Commit `08bf0dc` (Task 1) — FOUND
- Commit `3755020` (Task 2) — FOUND
