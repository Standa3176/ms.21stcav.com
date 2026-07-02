---
phase: 260702-qd8-auto-create-missing-woo-brand-when-creat
plan: 01
subsystem: ProductAutoCreate
tags: [woo-brands, taxonomy, auto-create, draft-from-suggestions]
requires:
  - TaxonomyResolver::allBrands (WC-native products/brands term list)
  - WooClient::post (shadow-aware write gate)
  - ResolvesWooBrandKey (existing brand-key resolution)
provides:
  - WooBrandCreator::ensureBrandTermId (find-or-create a normalised, junk-guarded Woo brand term)
  - NormalisesBrandNames concern (shared normaliseBrandName + isJunkBrand)
  - config product_auto_create.auto_create_missing_brands (on/off switch)
affects:
  - CreateWooProductJob (per-row "Approve — create product")
  - DraftFromSuggestionsCommand (bulk pipeline)
  - RunAutoCreatePipelineJob (Filament explicit-selection path)
tech-stack:
  added: []
  patterns:
    - Shared trait extraction for brand normalisation (single source of truth)
    - Never-throw service returning null on any failure (skip/park fallback)
    - Nullable-default method injection to preserve existing direct-call test harness
key-files:
  created:
    - app/Domain/ProductAutoCreate/Concerns/NormalisesBrandNames.php
    - app/Domain/ProductAutoCreate/Services/WooBrandCreator.php
    - tests/Unit/ProductAutoCreate/WooBrandCreatorTest.php
    - tests/Feature/ProductAutoCreate/CreateWooProductJobBrandTest.php
    - tests/Feature/Console/DraftFromSuggestionsCreateBrandTest.php
  modified:
    - app/Console/Commands/RefreshBrandsToAddCommand.php
    - app/Domain/ProductAutoCreate/Jobs/CreateWooProductJob.php
    - app/Console/Commands/DraftFromSuggestionsCommand.php
    - app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php
    - config/product_auto_create.php
decisions:
  - Extract normaliseBrandName + isJunkBrand VERBATIM into a shared concern (no behaviour change; BrandsToAddIndexTest stays green)
  - WooBrandCreator NEVER throws — returns null on blank/junk/shadow/error so the caller falls back to skip/park
  - Single config switch (auto_create_missing_brands, default true) governs BOTH create paths — operator can disable without a deploy
  - Extract a pure promoteMissingBrand() decision on the command so the promotion is unit-testable without the un-fakeable mysqli supplier walk
metrics:
  tasks: 3
  files_created: 5
  files_modified: 5
  tests_added: 16
  completed: 2026-07-02
---

# Quick Task 260702-qd8: Auto-create the missing Woo brand when creating a product Summary

Creating a product from Suggestions whose manufacturer isn't yet a Woo brand (e.g. Trantec `S4.04-B-EB-GD5`) now auto-creates the (normalised, junk-guarded) brand term and lets the product proceed to Woo, on BOTH the per-row approve and bulk pipeline paths — gated behind a single config switch, additive and opt-in.

## The two dead-end paths this fixes

1. **Bulk (`products:draft-from-suggestions`)** — a `brand_not_on_woo` SKU was bucketed into the skip list and dropped (no product created).
2. **Per-row (`CreateWooProductJob` step 7)** — a null `resolveBrand` forced `auto_create_status='needs_brand_or_category_assignment'` and short-circuited BEFORE the Woo POST (a local draft was created but nothing went live).

## What was built

- **`NormalisesBrandNames` concern** — `normaliseBrandName` (HTML-decode + trim + collapse whitespace) and `isJunkBrand` (config `brands_to_add_exclude`, case-insensitive) extracted VERBATIM out of `RefreshBrandsToAddCommand` into `app/Domain/ProductAutoCreate/Concerns/NormalisesBrandNames.php`. The command now `use`s the trait; `pickCanonicalBrand` stays command-private. Behaviour is byte-identical — the 260702-om7 `BrandsToAddIndexTest` stays green.
- **`WooBrandCreator::ensureBrandTermId(?string): ?int`** — normalises the name; returns null for blank/junk (never creates `Specials`/`Un-Branded`); returns the EXISTING Woo brand term id when one matches case-insensitively (no POST); else POSTs `products/brands` with the normalised name, forgets the `taxonomy.brands` cache and returns the new id. Treats `woocommerce_term_exists` as success (re-reads the id after a cache-forget). Returns null in shadow mode (`WOO_WRITE_ENABLED=false` → no real id) and on any failure. **NEVER throws to callers.**
- **`config('product_auto_create.auto_create_missing_brands')`** — default `true`; single on/off switch for both paths.
- **`CreateWooProductJob`** — method-injects `WooBrandCreator` (nullable default). When `resolveBrand` returns null AND the switch is on, `ensureBrandTermId` runs before the needs-assignment short-circuit; a real brand id lets the product proceed to the Woo POST as a draft. Junk/failed brand (creator → null) or switch OFF → parks exactly as before. Category-unresolved still short-circuits independently.
- **`DraftFromSuggestionsCommand`** — new `--create-missing-brands` option; injects `WooBrandCreator`. In the `brand_not_on_woo` branch, `promoteMissingBrand()` find-or-creates the brand and the SKU becomes a candidate; the new brand is added to `$wooBrandsByLower` (captured **by-reference** in the chunk closure) so sibling SKUs resolve without a second create. Without the flag: byte-identical skip.
- **`RunAutoCreatePipelineJob`** — passes `--create-missing-brands` to the command when the config switch is on (the Filament explicit-selection path); omits it when off.

## Tests

- `tests/Unit/ProductAutoCreate/WooBrandCreatorTest.php` — 8 cases: junk/blank/whitespace/null → null + no POST; existing brand → id, no POST; new brand → POST once + cache forgotten + id; HTML-entity name normalised before POST; `term_exists` → re-read id; shadow mode → null; non-term-exists failure → null (never throws).
- `tests/Feature/ProductAutoCreate/CreateWooProductJobBrandTest.php` — 3 cases: Trantec (non-Woo) created + product POSTed as draft (`brand_id` set, `auto_create_status='draft'`); Specials (junk) → creator null → parks, no POST; switch OFF → creator never consulted → parks.
- `tests/Feature/Console/DraftFromSuggestionsCreateBrandTest.php` — 5 cases: `promoteMissingBrand` promotes a real brand (flag on), returns null for junk (flag on) and when the flag is off (creator never consulted); `RunAutoCreatePipelineJob` passes/omits `--create-missing-brands` per the config switch.

Full plan-verification suite: **25 passed (83 assertions)**. `grep -rn ensureBrandTermId app/` → present in `CreateWooProductJob` + `DraftFromSuggestionsCommand` (+ defined in `WooBrandCreator`). `pint --test` PASS on all changed source. Existing `DraftFromSuggestions*` + `AutoCreateResultBody` suites: 24 passed, no regressions.

## Deviations from Plan

### Auto-fixed / adjusted

**1. [Rule 3 — Testability] Nullable-default `WooBrandCreator` param on `CreateWooProductJob::handle()`.**
- The plan said "inject via method injection." A bare required param would have broken the existing `CreateWooProductJobTest::runCreateJob` helper (calls `handle()` with 9 positional args). Made it `?WooBrandCreator $brandCreator = null` with `$brandCreator ??= app(WooBrandCreator::class)`; the container still injects the real service on the queue path, and the existing harness stays untouched.

**2. [Rule 3 — Testability] Extracted `promoteMissingBrand(array $mfrs, bool $flag): ?string` on `DraftFromSuggestionsCommand`.**
- The plan showed the brand_not_on_woo promotion inline in the chunk closure, but that closure depends on a live `mysqli` supplier connection that cannot be faked in-process (the command returns FAILURE at connect time in tests). The decision was pulled into a small pure/public helper (the closure calls it) so the promotion is unit-testable with a mockable `WooBrandCreator` — exactly the fallback the plan sanctioned ("targeted test of the extracted decision"). Runtime behaviour is identical to the inline version.

**3. [Rule 3 — Test-env constraint] Task 2 happy-path test uses supplier `price => 0`.**
- To exercise the full POST path without mocking `RuleResolver`/`PriceCalculator` (both `final` — see Deferred), the test supplies `price => 0` so `computeSellPennies` short-circuits before pricing. The brand-creation + POST path under test is unaffected.

## Deferred Issues (pre-existing, out of scope)

- **`tests/Feature/ProductAutoCreate/CreateWooProductJobTest.php` fails on `main` independently of this task** — 5 of its cases do `Mockery::mock(RuleResolver::class)`, but `RuleResolver` is a `final` class and Mockery cannot mock it (`class ... is marked final and its methods cannot be replaced`). This fails with or without this task's changes (confirmed by running the file alone) and is a test-side Mockery limitation unrelated to the brand feature. Logged for a future cleanup (make the mocked pricing services non-final, or refactor those tests to real instances). This task's own tests deliberately avoid mocking the final pricing classes.

## Operator notes

- Re-try `S4.04-B-EB-GD5` (Trantec): per-row "Approve — create product" OR bulk "Auto-create" now creates the brand (normalised, junk-excluded), links the local `brand_id`, and creates + publishes the product. "Trantec" appears under Products → Brands.
- **OFF SWITCH:** set `config('product_auto_create.auto_create_missing_brands')` to `false` (config file or cached-config override) to restore the old skip/park behaviour without touching code.
- Junk names in `config('product_auto_create.brands_to_add_exclude')` (`specials`/`un-branded`/`unbranded`) are NEVER auto-created — those SKUs still park/skip. Add more as needed.
- Requires `WOO_WRITE_ENABLED=true` (already true in prod). With writes off, brands aren't created and SKUs fall back to skip/park.
- The storefront clickable Brand: link still comes from the `product_brand` (WP REST) taxonomy at publish (`PublishProductJob`) — unchanged; this task only ensures the WC-native brand term + local `brand_id` exist.
- **Deploy (NOT done here):** push main → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).

## Commits

- `7a9a483` feat(260702-qd8): NormalisesBrandNames concern + WooBrandCreator service
- `2e06770` feat(260702-qd8): CreateWooProductJob auto-creates the missing Woo brand
- `ca01b99` feat(260702-qd8): draft-from-suggestions --create-missing-brands + pipeline wiring

## Self-Check: PASSED
