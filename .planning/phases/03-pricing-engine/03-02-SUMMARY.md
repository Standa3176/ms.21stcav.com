---
phase: 03-pricing-engine
plan: 02
subsystem: pricing
tags: [pricing, resolver, listener, domain-event, pure-function, deptrac, pest, tdd, correlation-id, queue, import-issue]

requires:
  - phase: 01-foundation
    provides: DomainEvent base class, Context::hydrated queue bridge, ShouldDispatchAfterCommit contract
  - phase: 02-supplier-sync
    provides: SupplierPriceChanged event (trigger), ImportIssue model + enum (zero-price log), Product / ProductVariant mirrors
  - phase: 03-pricing-engine plan 01
    provides: PriceCalculator (integer-pennies, HALF_UP), SupplierPriceUnusableException, PricingRule + ProductOverride models, DefaultPricingTierSeeder
provides:
  - App\Domain\Pricing\Services\RuleResolver (pure most-specific-wins resolver with priority DESC → id ASC tiebreak)
  - App\Domain\Pricing\Services\PricingResolution (readonly DTO carrying margin, source, matchedRuleId, overrideId, chain)
  - App\Domain\Pricing\Exceptions\NoPricingRuleMatchedException (catalogue-incomplete sentinel)
  - App\Domain\Pricing\Events\ProductPriceChanged (DomainEvent — fires on integer-penny diff only)
  - App\Domain\Pricing\Listeners\RecomputePriceListener (SupplierPriceChanged subscriber, default queue)
  - products + product_variants additive columns: brand_id, category_id (nullable, indexed)
  - EventServiceProvider mapping SupplierPriceChanged → RecomputePriceListener
  - RuleResolverPurityTest architectural guard (no config / clock / random / session / cache reads)
affects: [03-03 Filament rule explorer reads PricingResolution.chain, 03-04 bulk recompute command reuses listener core, 05-competitor-ingest remains unchanged]

tech-stack:
  added: []  # no new packages — all on top of Phase 1 + 2 foundations
  patterns:
    - Pure resolver service — DB reads only, purity enforced by source-file grep guards
    - Readonly DTO for "why did this price resolve" UI explainability
    - Integer-pennies equality gate on event emission (D-13 fire-on-diff)
    - updateOrCreate on (composite + resolved_at IS NULL) for idempotent catalogue-health triage rows
    - forceFill + saveQuietly on sync-driven sell_price writes (Phase 2 convention — keeps activity_log clean)
    - Context::add('correlation_id', …) in listener handle() so child DomainEvents inherit CID without manual payload passing

key-files:
  created:
    - app/Domain/Pricing/Services/RuleResolver.php
    - app/Domain/Pricing/Services/PricingResolution.php
    - app/Domain/Pricing/Exceptions/NoPricingRuleMatchedException.php
    - app/Domain/Pricing/Events/ProductPriceChanged.php
    - app/Domain/Pricing/Listeners/RecomputePriceListener.php
    - database/migrations/2026_04_19_090200_add_pricing_keys_to_products.php
    - database/migrations/2026_04_19_090300_add_pricing_keys_to_product_variants.php
    - tests/Unit/Pricing/RuleResolverTest.php
    - tests/Unit/Pricing/RuleResolverPurityTest.php
    - tests/Feature/Pricing/RecomputePriceListenerTest.php
    - tests/Feature/Pricing/RecomputePriceListenerZeroPriceTest.php
  modified:
    - app/Domain/Products/Models/Product.php          # added brand_id + category_id to $fillable + logOnly + getPricingBrandId/CategoryId accessors
    - app/Domain/Products/Models/ProductVariant.php   # additive columns + parent-fallback accessors
    - app/Providers/EventServiceProvider.php          # SupplierPriceChanged → RecomputePriceListener mapping
    - depfile.yaml                                    # Pricing → Sync read-only (RecomputePriceListener consumes Sync\Events + Sync\Models\ImportIssue)
    - deptrac.yaml                                    # same ruleset update (two-file convention)
    - tests/Feature/Phase02DataModelTest.php          # rollback step bumped 9 → 11 for two new additive migrations

key-decisions:
  - "RuleResolver layer order (D-07): override → brand_category → category → brand → default_tier; tiebreak priority DESC → id ASC enforced via orderByDesc / orderBy on every layer query"
  - "Purity contract: RuleResolver source MUST have zero config() / now() / Carbon::now / time() / microtime / rand / mt_rand / random_int / Str::uuid / random_bytes / session() / request() / Cache / auth reads — asserted by RuleResolverPurityTest grep guards against the comment-stripped source"
  - "brand_id + category_id additive columns are nullable with no FK constraint (brand / category tables don't yet exist in Phase 3 v1) — Pitfall 7 forward-compat. RuleResolver's null-safe walk falls through to default_tier when a product has neither mapped"
  - "ProductVariant accessors fall back to parent Product (variants inherit brand/category by default; per-variant override remains possible via a variant-level column write)"
  - "D-13 integer-pennies equality gate: ProductPriceChanged fires ONLY when (int) round(sell_price × 100) !== newPennies. No float-compare, no percentage-floor filter"
  - "D-10 zero-price guard is TWO-LAYER: listener checks buyPennies ≤ 0 before calling calculator; calculator's own SupplierPriceUnusableException also caught. Either path writes ImportIssue + returns without touching sell_price"
  - "D-11 idempotency via updateOrCreate on (sku, woo_product_id, woo_variation_id, issue_type, resolved_at IS NULL) — repeat zero-price sync bumps last_seen_at on the existing unresolved row instead of inserting a duplicate"
  - "Listener queue choice: `default`, NOT sync-woo-push. Rationale: sync-woo-push is rate-limited (Woo 100/min) and belongs to the downstream Woo PUT; price math is cheap and should run independently"
  - "Deptrac: Pricing → Sync is a READ-ONLY dependency. Listener reads SupplierPriceChanged event and writes ImportIssue rows (a Sync-owned table with an existing enum that Phase 3 just adds a row to). Pricing does NOT touch sync_runs / sync_errors / products via Sync services"

patterns-established:
  - "Pure resolver + readonly DTO pattern: service is stateless and deterministic; result is a primitive-only readonly class that UI can consume without pulling Eloquent into the view layer"
  - "Purity guard test: file_get_contents(app_path(...)) + preg_match_all on forbidden tokens (after comment stripping) is a cheap CI-blocking invariant check that beats runtime mocking"
  - "Listener signature: implements ShouldQueue + public string \$queue + constructor-injected services + Context::add('correlation_id', …) at the top of handle()"

requirements-completed: [PRCE-02, PRCE-07]

metrics:
  duration: 17min
  completed: 2026-04-19
  tasks_completed: 2
  files_changed: 17
  pest_tests_added: 31
---

# Phase 3 Plan 02: Resolver + Listener + Event Summary

**Deterministic most-specific-wins resolver (override → brand_category → category → brand → default_tier) wired into the Phase 2 supplier-sync pipeline via RecomputePriceListener; ProductPriceChanged fires on integer-penny diff only, zero-price writes an idempotent ImportIssue and never leaks £0 to Woo.**

## Performance

- **Duration:** 17 min
- **Started:** 2026-04-19T08:49:56Z
- **Completed:** 2026-04-19T09:07:07Z
- **Tasks:** 2 / 2
- **Files changed:** 17 (11 created, 6 modified)
- **Pest tests added:** 31 (18 resolver + 13 listener)

## Accomplishments

- RuleResolver ships as a **pure** function of DB state — architecturally enforced by a 5-test purity suite that greps the source file against 15+ forbidden tokens (config, clock, random, session, cache, request, auth). Determinism is asserted by a round-trip test that calls resolve() twice on identical state and expects field-equal PricingResolution instances.
- The event-driven pricing recompute loop closes: Phase 2's `SupplierPriceChanged` now triggers `RecomputePriceListener` on the `default` queue; it delegates to RuleResolver + PriceCalculator and writes `products.sell_price` (or `product_variants.sell_price` for variation-level SKUs) via `forceFill + saveQuietly` to keep activity_log clean.
- Zero / null / negative buy_price path writes a single unresolved `ImportIssue` row with `issue_type='missing_cost_price'` via `updateOrCreate` keyed on `(sku, woo_product_id, woo_variation_id, issue_type, resolved_at IS NULL)`. A second zero-price sync for the same SKU bumps `last_seen_at` on the existing row — no `sell_price` touch, no `ProductPriceChanged` emitted.
- `brand_id` + `category_id` additive columns on `products` and `product_variants` unlock the resolver's brand / category / brand_category layers. Variants' accessors fall back to their parent product's value, so most rows work uniformly without per-variation writes.
- Deptrac extended to allow `Pricing → Sync` (read-only consumer) in both depfile.yaml and deptrac.yaml; DeptracTest + architecture suite remain green.

## Task Commits

1. **Task 1 — RuleResolver + PricingResolution + brand/category keys + 18 tests** — `4603de4`
2. **Task 2 — ProductPriceChanged + RecomputePriceListener + EventServiceProvider + 13 tests** — `f2e3ca1`

## Files Created / Modified

### Resolver + DTO + exception (Task 1)

- `app/Domain/Pricing/Services/RuleResolver.php` — 5-layer walk with `priority DESC → id ASC` tiebreak; `active=true` filter at query level; null-safe buy_price casting for default_tier.
- `app/Domain/Pricing/Services/PricingResolution.php` — `final readonly class` carrying `marginBasisPoints`, `source`, `matchedRuleId`, `overrideId`, `chain`.
- `app/Domain/Pricing/Exceptions/NoPricingRuleMatchedException.php` — thrown when every layer misses; sentinel for Filament + bulk command surfacing.

### Migrations + model accessors (Task 1)

- `database/migrations/2026_04_19_090200_add_pricing_keys_to_products.php` — `brand_id` + `category_id` unsignedBigInteger nullable + indexed. Documents Phase 6 auto-create as the future populator.
- `database/migrations/2026_04_19_090300_add_pricing_keys_to_product_variants.php` — identical columns at the variant level.
- `app/Domain/Products/Models/Product.php` — `$fillable` updated, LogsActivity's `logOnly` extended, added `getPricingBrandId()` + `getPricingCategoryId()`.
- `app/Domain/Products/Models/ProductVariant.php` — same additions, with parent-product fallback: `$this->brand_id ?? $this->product?->getPricingBrandId()`.

### Event + listener + wiring (Task 2)

- `app/Domain/Pricing/Events/ProductPriceChanged.php` — `extends DomainEvent`, readonly primitives only (no Eloquent leaks via SerializesModels).
- `app/Domain/Pricing/Listeners/RecomputePriceListener.php` — constructor-injected `RuleResolver` + `PriceCalculator`; variant-first target lookup; 3 guard paths (zero-price, SupplierPriceUnusableException, NoPricingRuleMatchedException); integer-pennies equality gate; idempotent `ImportIssue::updateOrCreate`.
- `app/Providers/EventServiceProvider.php` — new entry: `SupplierPriceChanged::class => [RecomputePriceListener::class]`.

### Deptrac + rollback-step fix (deviation commits, see below)

- `depfile.yaml` + `deptrac.yaml` — `Pricing: [Foundation, Products, Sync]` (was `[Foundation, Products]`).
- `tests/Feature/Phase02DataModelTest.php` — rollback step 9 → 11 to account for the two new additive migrations.

## RuleResolver Algorithm

```text
resolve(Product $product): PricingResolution

  Layer 0 — ProductOverride (D-08, terminal, beats everything)
    SELECT … FROM product_overrides WHERE product_id = ?
    → if row: return PricingResolution(override.margin, 'override', null, override.id, ['override'])

  Layer 1 — brand_category (requires BOTH brand_id AND category_id set on product)
    SELECT … FROM pricing_rules
      WHERE scope = 'brand_category' AND active = true
            AND brand_id = ? AND category_id = ?
      ORDER BY priority DESC, id ASC LIMIT 1

  Layer 2 — category  (requires category_id set)
  Layer 3 — brand     (requires brand_id set)
  Layer 4 — default_tier
    SELECT … FROM pricing_rules
      WHERE scope = 'default_tier' AND active = true AND is_default_tier = true
            AND tier_min_pennies <= ?buyPennies
            AND (tier_max_pennies IS NULL OR tier_max_pennies >= ?buyPennies)
      ORDER BY priority DESC, id ASC LIMIT 1

  If every layer misses → throw NoPricingRuleMatchedException::forProduct(product.id)
```

`chain` accumulates every layer actually queried (e.g. brand-resolution yields `['brand_category', 'category', 'brand']`). Override short-circuits to `['override']`. Future Filament rule explorer renders this as the "why did this price come out" trail.

## ProductPriceChanged Contract

```php
final class ProductPriceChanged extends DomainEvent
{
    public function __construct(
        public readonly int $productId,
        public readonly ?int $variantId,
        public readonly string $sku,
        public readonly int $oldPennies,
        public readonly int $newPennies,
        public readonly int $marginBasisPoints,
        public readonly string $resolutionSource,  // override | brand_category | category | brand | default_tier
    );
}
```

**Fire-on-diff only (D-13):** listener's last step is `if ($oldPennies === $newPennies) return;` — integer equality, no float compare, no percentage-floor filter. `correlation_id` inherited from `DomainEvent::__construct` which reads `Context::get('correlation_id')` — the listener's `Context::add(…, $event->correlationId)` at the top of `handle()` ensures child events carry the same CID as the originating `SupplierPriceChanged`.

## Listener Invocation Matrix

| Condition                                | sell_price                | ImportIssue row           | ProductPriceChanged |
| ---------------------------------------- | ------------------------- | ------------------------- | ------------------- |
| buy_price > 0 AND newPennies != old      | written (number_format 4) | none                      | dispatched          |
| buy_price > 0 AND newPennies == old      | untouched                 | none                      | NOT dispatched (D-13) |
| buy_price null / 0 / negative            | untouched                 | updateOrCreate (D-10+D-11)| NOT dispatched      |
| NoPricingRuleMatchedException            | untouched                 | none (logged at ERROR)    | NOT dispatched      |
| Product / Variant not found              | untouched                 | none (logged at WARNING)  | NOT dispatched      |

Listener runs on `$queue = 'default'` per CONTEXT.md Claude-discretion — `sync-woo-push` is Woo-rate-limited and is reserved for the downstream Woo PUT emitted by Phase 2's existing listener on `ProductPriceChanged`.

## Purity Guarantees (T-03-02-02 Mitigation)

`tests/Unit/Pricing/RuleResolverPurityTest.php` asserts, via source-file grep (with comments stripped to avoid false-positives from docblock references):

| Forbidden token(s)                                            | Count must be | Guard tests |
| ------------------------------------------------------------- | ------------- | ----------- |
| `config(`                                                     | 0             | Test 2      |
| `now(`, `Carbon::now`, `time()`, `microtime(`                 | 0             | Test 3      |
| `rand(`, `mt_rand`, `random_int`, `Str::uuid`, `random_bytes` | 0             | Test 4      |
| `session(`, `request(`, `Cache::`, `cache(`, `Context::get`, `auth(` | 0      | Test 5      |

Plus Test 1 — functional determinism: two `resolve()` calls on identical DB state MUST return pair-wise equal fields on `PricingResolution`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking issue] Deptrac Pricing → Sync dependency allow**
- **Found during:** Task 2 post-listener-write `vendor/bin/deptrac analyse` — 6 violations (RecomputePriceListener imports `Sync\Events\SupplierPriceChanged` and `Sync\Models\ImportIssue`).
- **Issue:** Phase 3 Plan 01 allowed `Pricing: [Foundation, Products]`. Task 2's listener unavoidably consumes Sync's event type and issue model.
- **Fix:** Extended to `Pricing: [Foundation, Products, Sync]` in BOTH `depfile.yaml` and `deptrac.yaml` (project keeps two files synchronised per Phase 3 Plan 01 deviation 2). Cleared `.deptrac.cache`. Inline comment documents the read-only consumer intent. Deptrac runs clean — 0 violations, 49 allowed.
- **Files modified:** depfile.yaml, deptrac.yaml
- **Commit:** f2e3ca1

**2. [Rule 3 — Blocking issue] Phase02DataModelTest rollback step 9 → 11**
- **Found during:** Task 1 Phase02 regression run (after migrations landed).
- **Issue:** The rollback round-trip test hard-coded `--step=9` (matching the Phase 2 + Phase 3 Plan 01 migration count). Plan 02's 2 additive migrations (`2026_04_19_090200_add_pricing_keys_to_products` + `2026_04_19_090300_add_pricing_keys_to_product_variants`) push the step requirement to 11. First run failed because products/product_variants were still present post-rollback.
- **Fix:** Bumped step to 11 and updated the inline comment to enumerate every migration in rollback order.
- **File modified:** tests/Feature/Phase02DataModelTest.php
- **Commit:** 4603de4

**3. [Rule 1 — Test-fixture bug, self-inflicted] correlation_id test fixtures longer than 36 chars**
- **Found during:** Task 2 GREEN first run on `RecomputePriceListenerZeroPriceTest` Test Z5.
- **Issue:** `import_issues.correlation_id` is a `uuid` column (36 chars). My initial fixture string was 44 chars ("cid-zero-77777777-aaaa-bbbb-cccc-deadbeefdead") → `SQLSTATE[22001]: String data, right truncated`. Similar 17-char fixture in the happy-path Test 7 would have failed on a follow-up run.
- **Fix:** Replaced both fixtures with valid 36-char UUID-shaped strings (`"77777777-aaaa-bbbb-cccc-deadbeefdead"` and `"11111111-2222-4333-8444-555555555555"`).
- **File modified:** tests/Feature/Pricing/RecomputePriceListenerTest.php, tests/Feature/Pricing/RecomputePriceListenerZeroPriceTest.php
- **Commit:** f2e3ca1

### Manual Policy Decisions

None. Plan 03-02 had no `checkpoint:decision` tasks and every D-0x lock (D-07, D-08, D-10, D-11, D-13 + Claude-discretion "default queue") was honoured exactly.

## Pointer for Plan 03 (Filament Rule Explorer)

- `PricingResolutionViewer` Filament page can inject `RuleResolver` and render `$res->chain` as a breadcrumb with coloured badges per source (reuse Phase 2's `InfoList` entries pattern).
- `$res->matchedRuleId` / `$res->overrideId` are direct drill-down targets — the explorer can link straight to the `PricingRuleResource` detail page (Plan 03 lands the Resource).
- The "Preview effective price" UI is 2 lines: `$res = $resolver->resolve($product); $final = $calculator->compute(buy_pennies, $res->marginBasisPoints);`. No special Filament infrastructure required.
- Inactive rules (`active=false`) don't appear in the chain — if the explorer wants to surface "this rule WOULD match but is disabled" guidance, it needs a separate query that intentionally ignores the `active` filter.

## Pointer for Plan 04 (`pricing:recompute --all`)

- Bulk command reuses the exact pipeline from `RecomputePriceListener::handle` — consider extracting the "core" into a `RecomputePriceAction` service that both the listener and the bulk job call, or have the bulk job dispatch a tiny per-SKU job that hydrates a `SupplierPriceChanged` and re-runs the listener. The latter preserves the D-11 idempotency path for free.
- `--dry-run` (default per D-12) must skip the `sell_price` write AND the `ProductPriceChanged::dispatch(...)` — log the diff counts only.
- ImportIssue rows from bulk recompute MUST use the same `updateOrCreate` shape so a bulk-run triggering zero-price on 100 SKUs doesn't pollute the triage queue with 100 duplicate rows.

## Threat Flags

None. This plan added no net-new network endpoints, auth paths, or trust boundaries beyond what `<threat_model>` already enumerated (T-03-02-01 through T-03-02-07, all disposition=mitigate or accept with mitigations covered by Tests 8 / Z1-Z5 / purity suite).

## Self-Check: PASSED

**Files verified on disk (all present):**
- ✅ `app/Domain/Pricing/Services/RuleResolver.php` — `final class RuleResolver` + `SCOPE_BRAND_CATEGORY` + `orderByDesc` + zero forbidden-token grep hits
- ✅ `app/Domain/Pricing/Services/PricingResolution.php` — `final readonly class PricingResolution`
- ✅ `app/Domain/Pricing/Exceptions/NoPricingRuleMatchedException.php` — `forProduct` factory
- ✅ `app/Domain/Pricing/Events/ProductPriceChanged.php` — `extends DomainEvent`
- ✅ `app/Domain/Pricing/Listeners/RecomputePriceListener.php` — `implements ShouldQueue` + `$queue = 'default'` + `ImportIssue::updateOrCreate` + `TYPE_MISSING_COST_PRICE` + `saveQuietly` + `SupplierPriceChanged`
- ✅ `app/Providers/EventServiceProvider.php` — references `SupplierPriceChanged` + `RecomputePriceListener`
- ✅ `database/migrations/2026_04_19_090200_add_pricing_keys_to_products.php`
- ✅ `database/migrations/2026_04_19_090300_add_pricing_keys_to_product_variants.php`
- ✅ `app/Domain/Products/Models/Product.php` — `brand_id` + `getPricingBrandId` present
- ✅ `app/Domain/Products/Models/ProductVariant.php` — `getPricingCategoryId` present with parent fallback
- ✅ 4 new test files present in `tests/Unit/Pricing/` + `tests/Feature/Pricing/`
- ✅ `tests/Feature/Phase02DataModelTest.php` — rollback step = 11

**Commits verified (both present in git log):**
- ✅ `4603de4` — feat(03-02): add RuleResolver + PricingResolution + brand/category keys
- ✅ `f2e3ca1` — feat(03-02): wire SupplierPriceChanged → RecomputePriceListener

**End-to-end verification:**
- ✅ `php artisan migrate:fresh --env=testing --seed --no-interaction` — clean boot, 3 default tiers seeded
- ✅ `vendor/bin/pest tests/Unit/Pricing tests/Feature/Pricing tests/Architecture` — 127 passed, 3608 assertions
- ✅ `vendor/bin/pest` (full suite) — 352 passed, 2 skipped, 0 failed, 4419 assertions (203.84s)
- ✅ `vendor/bin/deptrac analyse` — 0 violations, 49 allowed
- ✅ `php artisan event:list` — shows `App\Domain\Sync\Events\SupplierPriceChanged` with `App\Domain\Pricing\Listeners\RecomputePriceListener (ShouldQueue)`
