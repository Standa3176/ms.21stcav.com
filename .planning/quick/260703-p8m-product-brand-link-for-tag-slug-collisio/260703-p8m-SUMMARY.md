---
phase: 260703-p8m-product-brand-link-for-tag-slug-collisio
plan: 01
subsystem: product-brand-taxonomy
tags: [product_brand, woo-rest, brand-slug-collision, resync-to-woo, storefront-brand-link]
requires:
  - ProductBrandTermResolver (260613-pzc slug-collision pre-flight)
  - TaxonomyResolver::allBrands (native Woo brands taxonomy)
  - products:resync-to-woo (brand → product_brand assign loop)
provides:
  - suffix-on-tag-collision brand-create strategy (new default)
  - products:resync-to-woo --brand=<comma,list> filter
affects:
  - app/Domain/ProductAutoCreate/Services/ProductBrandTermResolver.php
  - config/services.php
  - app/Console/Commands/ResyncProductsToWooCommand.php
tech-stack:
  added: []
  patterns: [config-driven-strategy, brand-name-to-brand_id-resolution, TDD-red-green]
key-files:
  created:
    - tests/Feature/Console/ResyncProductsToWooCommandTest.php
  modified:
    - app/Domain/ProductAutoCreate/Services/ProductBrandTermResolver.php
    - config/services.php
    - app/Console/Commands/ResyncProductsToWooCommand.php
    - tests/Feature/ProductAutoCreate/Services/ProductBrandTermResolverTest.php
decisions:
  - "suffix-on-tag-collision only fires on a CONFIRMED product_tag slug collision — safe by construction (no duplicate-pair risk, unlike the deprecated force-suffix)."
  - "Added the resolver unit cases to the EXISTING tests/Feature/... test file (not the tests/Unit/... path in the plan frontmatter) to reuse the makeResolver()/emptyBrandListResponse()/WP_BASE stubs without redeclaring helpers."
metrics:
  duration: ~30m
  completed: 2026-07-03
  tasks: 2
  tests-added: 7
---

# Phase 260703-p8m Plan 01: Product Brand Link for Tag-Slug-Collision Brands Summary

Adds a `suffix-on-tag-collision` `product_brand` create strategy (new default) plus a `--brand=<comma,list>` filter on `products:resync-to-woo`, so brands whose clean slug is already owned by a `product_tag` (Yealink, Cisco, Logitech, Lenovo, Samsung) finally get a clickable storefront Brand link — and a whole brand's products can be relinked in one run.

## Root cause

Yealink/Cisco/Logitech/Lenovo/Samsung have no clickable storefront `Brand:` link because the auto-create pipeline pushes brand-as-tag, so a `product_tag` already owns their clean slug (e.g. `yealink`). WP REST hard-rejects a clean-slug `product_brand` create with `term_exists`, and the resolver's safe `skip-creation` default (adopted after the 2026-06-13 duplicate-pair incident, 260613-pzc) then refused to create anything — leaving those products with no `product_brand` term to link.

## What shipped

### Task 1 — suffix-on-tag-collision strategy + new default
- `ProductBrandTermResolver::createTerm` gains a `suffix-on-tag-collision` branch, inserted **after** the `auto-delete-empty-colliding-tag` block and **before** the final skip-creation warning + `return null`. When the clean-slug create fails **and** `checkProductTagCollision` confirms a real `product_tag` holds that slug, it creates the `{slug}-brand` `product_brand` term (brand NAME stays clean, e.g. "Yealink"; only the slug carries the `-brand` suffix → `/brand/yealink-brand/`), logs `product_brand.suffix_on_tag_collision`, and returns the new id. If the suffixed create also fails it falls through to the existing skip warning + null.
- `config('services.woo.brand_slug_collision_strategy')` default flipped from `skip-creation` to `suffix-on-tag-collision` (env `WOO_BRAND_SLUG_COLLISION_STRATEGY` still overrides; `skip-creation` is the documented off-switch). The config docblock now lists the new strategy first.
- Added it to the `createTerm` strategy doc-comment list.

**Why it is safe (vs the 2026-06-13 force-suffix incident):** the suffixed term is only ever created when the clean slug is *provably* held by an existing `product_tag`. A clean-slug `product_brand` therefore can NEVER be created for that name → no clean/suffixed duplicate PAIR can form. The 2026-06-13 pathology came from `force-suffix` suffixing WITHOUT confirming a collision, so a later clean-slug create produced the pair. `force-suffix`, `auto-delete-empty-colliding-tag`, `skip-creation`, `getCachedMap`'s taxonomy filter, `tryCreate`, `checkProductTagCollision`, and `assignToProduct` are all UNCHANGED. Brands whose clean slug is free still create cleanly via Attempt 1 (also unchanged).

### Task 2 — products:resync-to-woo --brand filter
- `--brand=<comma,list>` added to the signature. In `perform()`, before the required-input check, brand names are resolved (case-insensitive) to `brand_id`s via `TaxonomyResolver::allBrands`, the SKUs of every `Product` with those `brand_id`s **and** a non-null `woo_product_id` are gathered and merged with any `--skus`. At least one of `--skus`/`--brand` is now required — the old `--skus is required.` error became `Provide --skus or --brand.`
- The price/tags/attributes resync loop (including the split price-then-rest PUT and the `product_brand` assign step) is untouched — only the up-front SKU-resolution was added.

## Tests

- **Resolver** (`tests/Feature/ProductAutoCreate/Services/ProductBrandTermResolverTest.php`, +4 cases → 14 total, all green):
  - Case K: suffix-on-tag-collision + confirmed collision → POSTs slug `yealink-brand`, returns new id, logs `product_brand.suffix_on_tag_collision`, no tag DELETE.
  - Case L: explicit `skip-creation` + collision → null, no suffixed create (unchanged).
  - Case M: suffix strategy + clean slug free → clean create wins on Attempt 1, no collision probe, no suffix.
  - Case N: suffix strategy + suffixed create also fails → null (graceful).
- **Command** (`tests/Feature/Console/ResyncProductsToWooCommandTest.php`, new file, 3 cases, all green):
  - `--brand=Yealink` resyncs the brand's two woo-published products (PUTs land on `products/5001` + `products/5002`, not the Cisco `products/5099`), skips the one without `woo_product_id`.
  - Neither `--skus` nor `--brand` → `Provide --skus or --brand.` (exit 1).
  - `--brand=Nonexistent` → no brand_id → same error (nothing to resync).

**Verification (Herd PHP 8.4):**
- `pest` on both files together → **17 passed (52 assertions)**.
- `pint --test` on `ProductBrandTermResolver.php`, `config/services.php`, `ResyncProductsToWooCommand.php`, and both test files → `{"result":"pass"}`.

Confirmed behaviours: the suffix strategy POSTs slug `yealink-brand` (name stays `Yealink`); `resync-to-woo --brand=Yealink` resolves the brand's products and PUTs to their Woo ids.

## Deviations from Plan

- **[Rule 3 — Blocking, test location] Resolver unit cases added to the existing Feature-suite test file.** The plan frontmatter listed `tests/Unit/ProductAutoCreate/ProductBrandTermResolverTest.php`, but the resolver's existing test lives at `tests/Feature/ProductAutoCreate/Services/ProductBrandTermResolverTest.php` with `makeResolver()` / `emptyBrandListResponse()` / `WP_BASE` helpers. The plan's own instruction was "If the existing test suite already has ProductBrandTermResolverTest, ADD cases to it (don't duplicate the WpRestClient stub)." Adding to the existing file honours that and avoids redeclaring globally-scoped Pest helpers.
- **[Rule 3 — Blocking, verify path typo] Pint target corrected.** The Task 2 verify block referenced `app/Console/Commands/ResyncProductsToWooCommandTest.php` (a test under `app/`, which does not exist). Pinted the actual changed files instead: the command source + the real test file under `tests/`.
- No other deviations — the resolver branch, config default flip, and command filter were implemented exactly per `<interfaces>`.

## Operator relink run (NOT executed by Claude — post-deploy)

Deploy: push `main` → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).

Relink the 5 brands (auto-creates the `{slug}-brand` product_brand terms + assigns them to every product):

```
php artisan tinker --execute='app(App\Domain\ProductAutoCreate\Services\ProductBrandTermResolver::class)->flushCache();'
php artisan products:resync-to-woo --brand=Yealink,Cisco,Logitech,Lenovo,Samsung --dry-run   # preview SKU count
php artisan products:resync-to-woo --brand=Yealink,Cisco,Logitech,Lenovo,Samsung             # live
```

Result: `/brand/yealink-brand/`, `/brand/cisco-brand/`, etc. exist and every product of those brands shows the clickable `Brand:` link. A test term `product_brand #13430 'Yealink' [yealink-brand]` already exists from diagnosis — the resolver reuses it, not duplicate. Future auto-creates for these brands link automatically via the new default strategy. Brand NAME shows correctly ("Yealink"); only the URL slug carries the `-brand` suffix. Off-switch: `WOO_BRAND_SLUG_COLLISION_STRATEGY=skip-creation`.

## Commits

- `cd394ab` — test(260703-p8m): failing resolver case for suffix-on-tag-collision (RED)
- `50860ff` — feat(260703-p8m): suffix-on-tag-collision strategy + default flip (GREEN)
- `664776d` — test(260703-p8m): failing feature test for resync-to-woo --brand (RED)
- `e0c17dd` — feat(260703-p8m): --brand filter on products:resync-to-woo (GREEN)

## Self-Check: PASSED
- Files exist: ProductBrandTermResolver.php, config/services.php, ResyncProductsToWooCommand.php, both test files.
- Commits present on main: cd394ab, 50860ff, 664776d, e0c17dd.
