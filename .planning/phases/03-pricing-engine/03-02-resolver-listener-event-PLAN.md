---
phase: 03-pricing-engine
plan: 02
type: execute
wave: 2
depends_on:
  - 03-01
files_modified:
  - app/Domain/Pricing/Services/RuleResolver.php
  - app/Domain/Pricing/Services/PricingResolution.php
  - app/Domain/Pricing/Exceptions/NoPricingRuleMatchedException.php
  - app/Domain/Pricing/Events/ProductPriceChanged.php
  - app/Domain/Pricing/Listeners/RecomputePriceListener.php
  - app/Providers/EventServiceProvider.php
  - database/migrations/2026_04_19_090200_add_pricing_keys_to_products.php
  - database/migrations/2026_04_19_090300_add_pricing_keys_to_product_variants.php
  - app/Domain/Products/Models/Product.php
  - app/Domain/Products/Models/ProductVariant.php
  - tests/Unit/Pricing/RuleResolverTest.php
  - tests/Unit/Pricing/RuleResolverPurityTest.php
  - tests/Feature/Pricing/RecomputePriceListenerTest.php
  - tests/Feature/Pricing/RecomputePriceListenerZeroPriceTest.php
autonomous: true
requirements:
  - PRCE-02
  - PRCE-07

must_haves:
  truths:
    - "RuleResolver picks the most-specific rule: brand+category → category → brand → default tier"
    - "Resolver sort order is deterministic: specificity DESC → priority DESC → id ASC"
    - "ProductOverride takes precedence over any PricingRule result (D-08)"
    - "RecomputePriceListener subscribes to SupplierPriceChanged and recomputes final price"
    - "ProductPriceChanged event fires ONLY when new sell_price differs from old sell_price in integer pennies (D-13)"
    - "Zero/null supplier price writes an ImportIssue row (issue_type: missing_cost_price) and does NOT touch products.sell_price (D-10)"
    - "ImportIssue handling uses updateOrCreate (idempotent on (product_id, issue_type, resolved_at IS NULL)) (D-11)"
    - "Listener runs on the default queue (D-Claude-discretion), NOT sync-woo-push"
    - "products and product_variants gain nullable brand_id + category_id columns for resolver-layer filtering"
  artifacts:
    - path: "app/Domain/Pricing/Services/RuleResolver.php"
      provides: "Most-specific-wins resolver returning PricingResolution with chain for UI"
      min_lines: 60
    - path: "app/Domain/Pricing/Services/PricingResolution.php"
      provides: "Readonly DTO carrying marginBasisPoints, source, matchedRuleId, overrideId, chain"
      contains: "readonly"
    - path: "app/Domain/Pricing/Exceptions/NoPricingRuleMatchedException.php"
      provides: "Thrown when no rule matches (default tiers missing + buy_price out of range)"
      contains: "forProduct"
    - path: "app/Domain/Pricing/Events/ProductPriceChanged.php"
      provides: "Domain event extending DomainEvent — fires on penny-diff only"
      contains: "extends DomainEvent"
    - path: "app/Domain/Pricing/Listeners/RecomputePriceListener.php"
      provides: "SupplierPriceChanged subscriber — recomputes + writes sell_price or logs issue"
      contains: "SupplierPriceChanged"
    - path: "database/migrations/2026_04_19_090200_add_pricing_keys_to_products.php"
      provides: "Adds brand_id + category_id nullable columns to products"
      contains: "brand_id"
    - path: "database/migrations/2026_04_19_090300_add_pricing_keys_to_product_variants.php"
      provides: "Adds brand_id + category_id nullable columns to product_variants"
      contains: "brand_id"
    - path: "tests/Feature/Pricing/RecomputePriceListenerTest.php"
      provides: "End-to-end: SupplierPriceChanged → listener → sell_price updated → ProductPriceChanged fired"
      contains: "ProductPriceChanged"
    - path: "tests/Feature/Pricing/RecomputePriceListenerZeroPriceTest.php"
      provides: "Zero supplier price → ImportIssue created → no ProductPriceChanged, no sell_price touch"
      contains: "missing_cost_price"
  key_links:
    - from: "app/Domain/Pricing/Listeners/RecomputePriceListener.php"
      to: "app/Domain/Pricing/Services/RuleResolver.php"
      via: "constructor DI"
      pattern: "RuleResolver"
    - from: "app/Domain/Pricing/Listeners/RecomputePriceListener.php"
      to: "app/Domain/Pricing/Services/PriceCalculator.php"
      via: "constructor DI"
      pattern: "PriceCalculator"
    - from: "app/Domain/Pricing/Listeners/RecomputePriceListener.php"
      to: "app/Domain/Sync/Models/ImportIssue.php"
      via: "updateOrCreate on zero-price"
      pattern: "ImportIssue::updateOrCreate"
    - from: "app/Providers/EventServiceProvider.php"
      to: "RecomputePriceListener"
      via: "listen[] array"
      pattern: "SupplierPriceChanged.*RecomputePriceListener"
---

<objective>
Ship the rule resolution + event-driven recompute pipeline. RuleResolver implements most-specific-wins with deterministic tiebreak. ProductPriceChanged extends DomainEvent and fires ONLY when recomputed price differs from stored price in integer pennies. RecomputePriceListener subscribes to Phase 2's SupplierPriceChanged, calls resolver → calculator, writes products.sell_price (and product_variants.sell_price for variation-level SKUs), and logs ImportIssue rows on zero/null supplier price without touching sell_price.

Purpose: This wires the Phase 2 → Phase 3 → Phase 4 event chain. A SupplierPriceChanged fired by Phase 2's SyncChunkJob now triggers pricing recomputation; any penny-level diff re-emits as ProductPriceChanged which Phase 2's existing Woo-push listener (shadow-gated by WOO_WRITE_ENABLED) picks up. The listener is the integration seam between sync and pricing — it MUST be correct on zero-price handling (never a £0 leak to Woo) and MUST be deterministic on rule resolution (pricing manager must trust "why did this price come out this way").

Output:
- `App\Domain\Pricing\Services\RuleResolver` — pure function: Product → (margin_basis_points, chain, source: 'override' | 'rule' | 'default_tier')
- `App\Domain\Pricing\Services\PricingResolution` — readonly DTO
- `App\Domain\Pricing\Exceptions\NoPricingRuleMatchedException`
- `App\Domain\Pricing\Events\ProductPriceChanged` — extends DomainEvent, carries primitives only
- `App\Domain\Pricing\Listeners\RecomputePriceListener` — queued listener on `default` queue; catches zero-price and writes ImportIssue
- EventServiceProvider registers the listener
- Additive migrations adding brand_id + category_id nullable columns to products + product_variants
- 4 test files: resolver unit tests (resolution order), resolver purity test (no I/O), listener happy path, listener zero-price path
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/03-pricing-engine/03-CONTEXT.md
@.planning/phases/03-pricing-engine/03-01-SUMMARY.md
@.planning/phases/02-supplier-sync/02-CONTEXT.md
@.planning/research/PITFALLS.md
@CLAUDE.md
@app/Foundation/Events/DomainEvent.php
@app/Domain/Sync/Events/SupplierPriceChanged.php
@app/Domain/Sync/Listeners/StubNewSupplierSkuListener.php
@app/Domain/Products/Models/Product.php
@app/Domain/Products/Models/ProductVariant.php
@app/Domain/Sync/Models/ImportIssue.php

<interfaces>
<!-- Contracts Plan 03 + 04 will consume -->

RuleResolver signature:
```php
namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;

final class RuleResolver
{
    /**
     * Resolve the effective pricing for a given product.
     *
     * Resolution chain (most-specific wins, no stacking):
     *   1. ProductOverride on parent product   (D-08, precedence over all rules)
     *   2. PricingRule scope=brand_category    (brand_id + category_id match)
     *   3. PricingRule scope=category          (category_id match)
     *   4. PricingRule scope=brand             (brand_id match)
     *   5. PricingRule scope=default_tier      (tier range encloses supplier buy_price)
     *
     * Tiebreak within a layer: priority DESC, id ASC.
     * Pure function — NO config reads, NO clock, NO random, NO session.
     *
     * @throws NoPricingRuleMatchedException    When no rule matches (catalogue-incomplete state — rare).
     */
    public function resolve(Product $product): PricingResolution;
}
```

PricingResolution DTO (readonly):
```php
namespace App\Domain\Pricing\Services;

final readonly class PricingResolution
{
    public function __construct(
        public int $marginBasisPoints,
        public string $source,            // 'override' | 'brand_category' | 'category' | 'brand' | 'default_tier'
        public ?int $matchedRuleId,       // null when source='override'
        public ?int $overrideId,          // null when source!='override'
        public array $chain,              // ordered list of candidate sources attempted for UI explanation
    ) {}
}
```

ProductPriceChanged event (fires only on integer-penny diff):
```php
namespace App\Domain\Pricing\Events;

use App\Foundation\Events\DomainEvent;

final class ProductPriceChanged extends DomainEvent
{
    public function __construct(
        public readonly int $productId,
        public readonly ?int $variantId,
        public readonly string $sku,
        public readonly int $oldPennies,
        public readonly int $newPennies,
        public readonly int $marginBasisPoints,
        public readonly string $resolutionSource,  // 'override' | 'brand_category' | etc
    ) {
        parent::__construct();
    }
}
```

RecomputePriceListener contract:
```php
namespace App\Domain\Pricing\Listeners;

use App\Domain\Sync\Events\SupplierPriceChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

final class RecomputePriceListener implements ShouldQueue
{
    public string $queue = 'default';  // D-CLAUDE-DISCRETION: not sync-woo-push

    public function handle(SupplierPriceChanged $event): void;
}
```

Behaviour:
1. Load Product by wooProductId (or ProductVariant by wooVariationId if variant-scoped event).
2. Guard: if buy_price is null OR <= 0, catch + log ImportIssue(product_id, issue_type='missing_cost_price', last_seen_at=now()) via updateOrCreate, DO NOT touch sell_price, return.
3. Call RuleResolver->resolve($product) to get PricingResolution.
4. Call PriceCalculator->compute(buyPennies, marginBasisPoints) → newPennies.
5. Read oldPennies from stored sell_price (via (int) round((float) $sellPrice * 100)).
6. If oldPennies === newPennies, return (no event).
7. Else: forceFill + saveQuietly on target (Phase 2 pattern to avoid activity-log bloat); dispatch ProductPriceChanged event.

NoPricingRuleMatchedException:
```php
namespace App\Domain\Pricing\Exceptions;

final class NoPricingRuleMatchedException extends \RuntimeException
{
    public static function forProduct(int $productId): self
    {
        return new self("No PricingRule matched product_id={$productId} — default tiers may be missing or buy_price out of all tier ranges");
    }
}
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Additive migrations (brand_id + category_id) + RuleResolver + PricingResolution + NoPricingRuleMatchedException</name>
  <files>
    database/migrations/2026_04_19_090200_add_pricing_keys_to_products.php,
    database/migrations/2026_04_19_090300_add_pricing_keys_to_product_variants.php,
    app/Domain/Products/Models/Product.php,
    app/Domain/Products/Models/ProductVariant.php,
    app/Domain/Pricing/Services/RuleResolver.php,
    app/Domain/Pricing/Services/PricingResolution.php,
    app/Domain/Pricing/Exceptions/NoPricingRuleMatchedException.php,
    tests/Unit/Pricing/RuleResolverTest.php,
    tests/Unit/Pricing/RuleResolverPurityTest.php
  </files>
  <read_first>
    .planning/phases/03-pricing-engine/03-CONTEXT.md,
    .planning/phases/03-pricing-engine/03-01-SUMMARY.md,
    app/Domain/Pricing/Models/PricingRule.php,
    app/Domain/Pricing/Models/ProductOverride.php,
    app/Domain/Products/Models/Product.php,
    app/Domain/Products/Models/ProductVariant.php,
    database/factories/Domain/Pricing/PricingRuleFactory.php,
    database/factories/Domain/Pricing/ProductOverrideFactory.php,
    database/migrations/2026_04_18_200000_create_products_table.php
  </read_first>
  <behavior>
    - Test 1 (override precedence): Product with ProductOverride(margin=4000) + matching brand_category rule(margin=2200). Resolver returns (4000, 'override'). ✓ D-08.
    - Test 2 (brand_category beats category): brand_category rule (2500) + category rule (2000). Returns (2500, 'brand_category').
    - Test 3 (category beats brand): category (2700) + brand (2400). Returns (2700, 'category').
    - Test 4 (brand beats default_tier): brand (2300) + default_tier covering range (3500). Returns (2300, 'brand').
    - Test 5 (default_tier fallback): only default_tier rules. buy_price £50.00 selects the <£100 tier (3500).
    - Test 6 (default_tier boundary — upper inclusive): buy_price £99.99 (9999 pennies) → <£100 tier. £100.00 (10000) → £100-499 tier.
    - Test 7 (default_tier open-ended upper): buy_price £5000 selects £500+ tier (tier_max_pennies=null).
    - Test 8 (priority tiebreak): two brand_category rules, priority 100 vs 200 → higher priority wins.
    - Test 9 (id tiebreak): two brand_category rules, priority=100 both → earlier id wins.
    - Test 10 (active=false skipped): inactive rule is not returned; falls through to next layer.
    - Test 11 (no match throws): empty pricing_rules → throws NoPricingRuleMatchedException.
    - Test 12 (chain order): 'brand' resolution → chain = ['brand_category', 'category', 'brand'].
    - Test 13 (purity — determinism): two calls on same state return equal PricingResolution.
    - Test 14 (purity — no config read): `grep -c "config(" RuleResolver.php` == 0.
    - Test 15 (purity — no clock read): no `now()`, `Carbon::now`, `time()`, `microtime`.
    - Test 16 (purity — no random): no `rand(`, `mt_rand`, `random_int`, `Str::uuid`.
  </behavior>
  <action>
    Step 1 — author migration `2026_04_19_090200_add_pricing_keys_to_products.php`:
    ```php
    return new class extends Migration {
        public function up(): void {
            Schema::table('products', function (Blueprint $t) {
                $t->unsignedBigInteger('brand_id')->nullable()->index()->after('type');
                $t->unsignedBigInteger('category_id')->nullable()->index()->after('brand_id');
            });
        }
        public function down(): void {
            Schema::table('products', function (Blueprint $t) {
                $t->dropIndex(['brand_id']);
                $t->dropIndex(['category_id']);
                $t->dropColumn(['brand_id', 'category_id']);
            });
        }
    };
    ```
    Docblock: cites Pitfall 7 (nullable forward-compat) and "Phase 6 auto-create populates these from Woo taxonomy; Phase 3 v1 leaves them NULL for most products → resolver falls through to default_tier".

    Step 2 — author migration `2026_04_19_090300_add_pricing_keys_to_product_variants.php` with identical column set on product_variants.

    Step 3 — update `app/Domain/Products/Models/Product.php`:
    - Add `'brand_id'`, `'category_id'` to `$fillable` array.
    - No cast entries needed (integers handled natively).
    - Add public method at end of class:
    ```php
    public function getPricingBrandId(): ?int { return $this->brand_id === null ? null : (int) $this->brand_id; }
    public function getPricingCategoryId(): ?int { return $this->category_id === null ? null : (int) $this->category_id; }
    ```
    - Add `'brand_id'`, `'category_id'` to the `logOnly([...])` list inside getActivitylogOptions so admin changes audit.

    Step 4 — update `app/Domain/Products/Models/ProductVariant.php` the same way, but variant getters fall back to parent:
    ```php
    public function getPricingBrandId(): ?int {
        if ($this->brand_id !== null) return (int) $this->brand_id;
        return $this->product?->getPricingBrandId();
    }
    public function getPricingCategoryId(): ?int { /* parallel */ }
    ```

    Step 5 — author PricingResolution DTO (final readonly class, shown in <interfaces>) under `app/Domain/Pricing/Services/`.

    Step 6 — author NoPricingRuleMatchedException under `app/Domain/Pricing/Exceptions/` per <interfaces>.

    Step 7 — RED: author tests/Unit/Pricing/RuleResolverTest.php covering Tests 1-12. Use RefreshDatabase. Each test uses factories and the helpers added in Step 3:
    ```php
    $product = Product::factory()->create([
        'buy_price' => '50.0000',
        'brand_id' => 10,
        'category_id' => 20,
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 10, 'category_id' => 20,
        'margin_basis_points' => 2500,
    ]);
    $res = app(RuleResolver::class)->resolve($product->fresh());
    expect($res->marginBasisPoints)->toBe(2500);
    expect($res->source)->toBe('brand_category');
    ```

    Step 8 — RED: author tests/Unit/Pricing/RuleResolverPurityTest.php. Tests 13-16 load the source file via `file_get_contents(app_path('Domain/Pricing/Services/RuleResolver.php'))` and assert via `substr_count` / `preg_match_all` on forbidden tokens. Test 13 creates a fixture, calls resolve() twice, expects equal results.

    Step 9 — run: `vendor/bin/pest tests/Unit/Pricing --filter=RuleResolver --stop-on-failure` — MUST FAIL (RuleResolver class not found).

    Step 10 — GREEN: author `app/Domain/Pricing/Services/RuleResolver.php` per the implementation sketch below. Use `PricingRule::SCOPE_*` constants (defined in Plan 01). Use `->orderByDesc('priority')->orderBy('id')` in every layer for deterministic tiebreak.

    ```php
    namespace App\Domain\Pricing\Services;

    use App\Domain\Pricing\Exceptions\NoPricingRuleMatchedException;
    use App\Domain\Pricing\Models\PricingRule;
    use App\Domain\Pricing\Models\ProductOverride;
    use App\Domain\Products\Models\Product;

    /**
     * D-07 deterministic most-specific-wins resolver (PRCE-02).
     * Pure: no config reads, no clock, no random, no session. DB reads only.
     */
    final class RuleResolver
    {
        public function resolve(Product $product): PricingResolution
        {
            // Layer 0: ProductOverride (D-08)
            $override = ProductOverride::where('product_id', $product->id)->first();
            if ($override !== null) {
                return new PricingResolution($override->margin_basis_points, 'override', null, $override->id, ['override']);
            }

            $chain = [];
            $brandId = $product->getPricingBrandId();
            $categoryId = $product->getPricingCategoryId();
            $buyPennies = $product->buy_price === null ? 0 : (int) round(((float) $product->buy_price) * 100);

            // Layer 1: brand_category
            if ($brandId !== null && $categoryId !== null) {
                $chain[] = 'brand_category';
                $rule = PricingRule::query()
                    ->where('scope', PricingRule::SCOPE_BRAND_CATEGORY)
                    ->where('active', true)
                    ->where('brand_id', $brandId)
                    ->where('category_id', $categoryId)
                    ->orderByDesc('priority')->orderBy('id')
                    ->first();
                if ($rule !== null) {
                    return new PricingResolution($rule->margin_basis_points, 'brand_category', $rule->id, null, $chain);
                }
            }

            // Layer 2: category
            if ($categoryId !== null) {
                $chain[] = 'category';
                $rule = PricingRule::query()
                    ->where('scope', PricingRule::SCOPE_CATEGORY)
                    ->where('active', true)
                    ->where('category_id', $categoryId)
                    ->orderByDesc('priority')->orderBy('id')
                    ->first();
                if ($rule !== null) {
                    return new PricingResolution($rule->margin_basis_points, 'category', $rule->id, null, $chain);
                }
            }

            // Layer 3: brand
            if ($brandId !== null) {
                $chain[] = 'brand';
                $rule = PricingRule::query()
                    ->where('scope', PricingRule::SCOPE_BRAND)
                    ->where('active', true)
                    ->where('brand_id', $brandId)
                    ->orderByDesc('priority')->orderBy('id')
                    ->first();
                if ($rule !== null) {
                    return new PricingResolution($rule->margin_basis_points, 'brand', $rule->id, null, $chain);
                }
            }

            // Layer 4: default_tier
            $chain[] = 'default_tier';
            $tierRule = PricingRule::query()
                ->where('scope', PricingRule::SCOPE_DEFAULT_TIER)
                ->where('active', true)
                ->where('is_default_tier', true)
                ->where('tier_min_pennies', '<=', $buyPennies)
                ->where(function ($q) use ($buyPennies) {
                    $q->whereNull('tier_max_pennies')->orWhere('tier_max_pennies', '>=', $buyPennies);
                })
                ->orderByDesc('priority')->orderBy('id')
                ->first();
            if ($tierRule !== null) {
                return new PricingResolution($tierRule->margin_basis_points, 'default_tier', $tierRule->id, null, $chain);
            }

            throw NoPricingRuleMatchedException::forProduct($product->id);
        }
    }
    ```

    Step 11 — run tests: `vendor/bin/pest tests/Unit/Pricing --filter=RuleResolver --stop-on-failure` — all 16 MUST pass.

    **DO NOT:**
    - Do NOT add caching in RuleResolver (purity requirement; failed purity test).
    - Do NOT call config(...), now(), random functions in the resolver source file.
    - Do NOT load all rules into a collection then filter in PHP — use Eloquent queries per layer.
    - Do NOT allow override AND rule match to both return — override is terminal, early return.
  </action>
  <verify>
    <automated>php artisan migrate --env=testing --no-interaction && vendor/bin/pest tests/Unit/Pricing --filter=RuleResolver --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `test -f app/Domain/Pricing/Services/RuleResolver.php` returns 0
    - `test -f app/Domain/Pricing/Services/PricingResolution.php` returns 0
    - `test -f app/Domain/Pricing/Exceptions/NoPricingRuleMatchedException.php` returns 0
    - `grep -q "final class RuleResolver" app/Domain/Pricing/Services/RuleResolver.php`
    - `grep -q "final readonly class PricingResolution" app/Domain/Pricing/Services/PricingResolution.php`
    - `grep -q "SCOPE_BRAND_CATEGORY" app/Domain/Pricing/Services/RuleResolver.php`
    - `grep -q "orderByDesc" app/Domain/Pricing/Services/RuleResolver.php`
    - `grep -c "config(" app/Domain/Pricing/Services/RuleResolver.php` returns `0`
    - `grep -cE "now\\(|Carbon::now|time\\(\\)|microtime\\(" app/Domain/Pricing/Services/RuleResolver.php` returns `0`
    - `grep -cE "rand\\(|mt_rand|random_int|Str::uuid" app/Domain/Pricing/Services/RuleResolver.php` returns `0`
    - `test -f database/migrations/2026_04_19_090200_add_pricing_keys_to_products.php` returns 0
    - `test -f database/migrations/2026_04_19_090300_add_pricing_keys_to_product_variants.php` returns 0
    - `grep -q "brand_id" app/Domain/Products/Models/Product.php`
    - `grep -q "getPricingBrandId" app/Domain/Products/Models/Product.php`
    - `grep -q "getPricingCategoryId" app/Domain/Products/Models/ProductVariant.php`
    - `php artisan migrate --env=testing --no-interaction` exits 0
    - `vendor/bin/pest tests/Unit/Pricing/RuleResolverTest.php --stop-on-failure` exits 0
    - `vendor/bin/pest tests/Unit/Pricing/RuleResolverPurityTest.php --stop-on-failure` exits 0
    - Combined count: `vendor/bin/pest tests/Unit/Pricing --filter=RuleResolver` reports >= 16 tests passed
  </acceptance_criteria>
  <done>
    RuleResolver is pure, deterministic, and resolves brand+category → category → brand → default_tier with priority+id tiebreak. ProductOverride takes precedence. products + product_variants have brand_id + category_id columns. Ready for the listener in Task 2.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: ProductPriceChanged event + RecomputePriceListener + EventServiceProvider wiring</name>
  <files>
    app/Domain/Pricing/Events/ProductPriceChanged.php,
    app/Domain/Pricing/Listeners/RecomputePriceListener.php,
    app/Providers/EventServiceProvider.php,
    tests/Feature/Pricing/RecomputePriceListenerTest.php,
    tests/Feature/Pricing/RecomputePriceListenerZeroPriceTest.php
  </files>
  <read_first>
    .planning/phases/03-pricing-engine/03-CONTEXT.md,
    .planning/phases/03-pricing-engine/03-01-SUMMARY.md,
    app/Foundation/Events/DomainEvent.php,
    app/Domain/Sync/Events/SupplierPriceChanged.php,
    app/Domain/Sync/Listeners/StubNewSupplierSkuListener.php,
    app/Providers/EventServiceProvider.php,
    app/Domain/Sync/Models/ImportIssue.php,
    app/Domain/Products/Models/Product.php,
    app/Domain/Products/Models/ProductVariant.php
  </read_first>
  <behavior>
    Happy-path tests (RecomputePriceListenerTest.php):
    - Test 1 (simple product): buy_price=50.00, sell_price=80.00, default-tier rule (3500 @ <£100). SupplierPriceChanged(sku, wooProductId, null, old=40, new=50). After listener: sell_price = 5000 * 13500 * 12000 / 100_000_000 / 100 pennies → check the fixture math; ProductPriceChanged fired with oldPennies=8000, newPennies=81.00-equiv, no ImportIssue row.
    - Test 2 (no diff — no event): existing sell_price already matches recomputed value → no ProductPriceChanged.
    - Test 3 (variant path): SupplierPriceChanged with wooVariationId → updates product_variants.sell_price; ProductPriceChanged.variantId populated.
    - Test 4 (override precedence): ProductOverride(margin=4000) → resolutionSource='override' in emitted event.
    - Test 5 (ShouldQueue): `new ReflectionClass(RecomputePriceListener::class)->implementsInterface(ShouldQueue::class)` is true.
    - Test 6 (default queue): instance's $queue property is 'default'.
    - Test 7 (correlation_id threads): dispatched ProductPriceChanged.correlationId === originating SupplierPriceChanged.correlationId.
    - Test 8 (EventServiceProvider registers): `Event::getRawListeners()[SupplierPriceChanged::class]` contains RecomputePriceListener::class (or via `$provider->listens()`).

    Zero-price tests (RecomputePriceListenerZeroPriceTest.php):
    - Test Z1 (null buy_price): creates ImportIssue(issue_type='missing_cost_price'), sell_price UNCHANGED, no ProductPriceChanged fires.
    - Test Z2 (zero buy_price): same as Z1.
    - Test Z3 (negative buy_price): same as Z1.
    - Test Z4 (idempotent): two listener runs for same zero-price product → ONE ImportIssue row (updateOrCreate on resolved_at IS NULL); last_seen_at bumped on second run.
    - Test Z5 (correlation_id on issue): ImportIssue.correlation_id === event.correlationId.
  </behavior>
  <action>
    Step 1 — RED: author `app/Domain/Pricing/Events/ProductPriceChanged.php` per <interfaces>. Final class extending DomainEvent, all fields readonly primitives.

    Step 2 — RED: author tests/Feature/Pricing/RecomputePriceListenerTest.php. Structure:
    ```php
    uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

    beforeEach(function () {
        $this->seed(\Database\Seeders\Phase3\DefaultPricingTierSeeder::class);
        Event::fake([ProductPriceChanged::class]);
    });

    it('happy path simple product', function () {
        $product = Product::factory()->create(['buy_price' => '50.0000', 'sell_price' => '80.0000']);
        $listener = app(RecomputePriceListener::class);
        $event = new SupplierPriceChanged('SKU-001', $product->woo_product_id, null, '40.00', '50.00');
        $listener->handle($event);
        // 5000 * 13500 * 12000 / 100_000_000 = 8100 pennies → 81.00
        expect($product->fresh()->sell_price)->toBe('81.0000');
        Event::assertDispatched(ProductPriceChanged::class, fn($e) => $e->newPennies === 8100);
    });

    // ... remaining 7 tests
    ```

    Step 3 — RED: author tests/Feature/Pricing/RecomputePriceListenerZeroPriceTest.php with the 5 Z-tests.

    Step 4 — run: `vendor/bin/pest tests/Feature/Pricing --filter=RecomputePrice --stop-on-failure` — MUST FAIL (listener class not found).

    Step 5 — GREEN: author `app/Domain/Pricing/Listeners/RecomputePriceListener.php` per the sketch below. Key points:
    - Implements `ShouldQueue` (picks up default queue worker).
    - Public `string $queue = 'default'` — explicit, covered by Test 6.
    - Constructor-injects RuleResolver + PriceCalculator.
    - Sets Context correlation_id from event BEFORE any child dispatch (so ProductPriceChanged inherits it).
    - Uses `saveQuietly` after `forceFill` on the updated model.
    - Uses `ImportIssue::updateOrCreate` on matching `(sku, woo_product_id, woo_variation_id, issue_type, resolved_at IS NULL)` to be idempotent per D-11.

    ```php
    namespace App\Domain\Pricing\Listeners;

    use App\Domain\Pricing\Events\ProductPriceChanged;
    use App\Domain\Pricing\Exceptions\NoPricingRuleMatchedException;
    use App\Domain\Pricing\Exceptions\SupplierPriceUnusableException;
    use App\Domain\Pricing\Services\PriceCalculator;
    use App\Domain\Pricing\Services\RuleResolver;
    use App\Domain\Products\Models\Product;
    use App\Domain\Products\Models\ProductVariant;
    use App\Domain\Sync\Events\SupplierPriceChanged;
    use App\Domain\Sync\Models\ImportIssue;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Support\Facades\Context;
    use Illuminate\Support\Facades\Log;

    /**
     * Phase 3 Plan 02 — Subscribes to Phase 2's SupplierPriceChanged.
     *
     * Pipeline: event → (resolver) → (calculator) → sell_price write + ProductPriceChanged emit.
     *
     * D-10: zero/null buy_price writes ImportIssue(missing_cost_price), never touches sell_price.
     * D-11: ImportIssue idempotent via updateOrCreate on (..., resolved_at IS NULL).
     * D-13: ProductPriceChanged fires only when newPennies !== oldPennies.
     *
     * Queue: `default` (not sync-woo-push — that's for the downstream Woo PUT emitted by Phase 2).
     */
    final class RecomputePriceListener implements ShouldQueue
    {
        public string $queue = 'default';

        public function __construct(
            private readonly RuleResolver $resolver,
            private readonly PriceCalculator $calculator,
        ) {}

        public function handle(SupplierPriceChanged $event): void
        {
            Context::add('correlation_id', $event->correlationId);

            $variant = $event->wooVariationId !== null
                ? ProductVariant::where('woo_variation_id', $event->wooVariationId)->first()
                : null;
            $product = $variant?->product ?? Product::where('woo_product_id', $event->wooProductId)->first();

            if ($product === null) {
                Log::warning('RecomputePriceListener: product not found', [
                    'woo_product_id' => $event->wooProductId,
                    'woo_variation_id' => $event->wooVariationId,
                    'correlation_id' => $event->correlationId,
                ]);
                return;
            }

            $buyPrice = $variant?->buy_price ?? $product->buy_price;
            $buyPennies = $buyPrice === null ? 0 : (int) round(((float) $buyPrice) * 100);

            if ($buyPennies <= 0) {
                $this->logImportIssue($event, $buyPennies);
                return;
            }

            try {
                $resolution = $this->resolver->resolve($product);
                $newPennies = $this->calculator->compute($buyPennies, $resolution->marginBasisPoints);
            } catch (SupplierPriceUnusableException) {
                $this->logImportIssue($event, $buyPennies);
                return;
            } catch (NoPricingRuleMatchedException $e) {
                Log::error('RecomputePriceListener: no pricing rule matched', [
                    'product_id' => $product->id,
                    'correlation_id' => $event->correlationId,
                ]);
                return;
            }

            $target = $variant ?? $product;
            $oldPennies = $target->sell_price === null ? 0 : (int) round(((float) $target->sell_price) * 100);

            if ($oldPennies === $newPennies) {
                return; // D-13: no diff, no event
            }

            $target->forceFill(['sell_price' => number_format($newPennies / 100, 4, '.', '')])->saveQuietly();

            ProductPriceChanged::dispatch(
                $product->id,
                $variant?->id,
                $event->sku,
                $oldPennies,
                $newPennies,
                $resolution->marginBasisPoints,
                $resolution->source,
            );
        }

        private function logImportIssue(SupplierPriceChanged $event, int $buyPennies): void
        {
            ImportIssue::updateOrCreate(
                [
                    'sku' => $event->sku,
                    'woo_product_id' => $event->wooProductId,
                    'woo_variation_id' => $event->wooVariationId,
                    'issue_type' => ImportIssue::TYPE_MISSING_COST_PRICE,
                    'resolved_at' => null,
                ],
                [
                    'detected_at' => now(),
                    'last_seen_at' => now(),
                    'notes' => "Supplier buy_price is {$buyPennies} pennies (zero/null/negative) — recompute skipped (D-10)",
                    'correlation_id' => $event->correlationId,
                ],
            );
        }
    }
    ```

    Step 6 — GREEN: edit `app/Providers/EventServiceProvider.php`. Locate the existing `protected $listen = [...]` array (Phase 1 + Phase 2 entries present). ADD:
    ```php
    \App\Domain\Sync\Events\SupplierPriceChanged::class => [
        \App\Domain\Pricing\Listeners\RecomputePriceListener::class,
    ],
    ```
    Keep other listeners intact. Preserve existing `NewSupplierSkuDetected => [StubNewSupplierSkuListener::class]` from Phase 2.

    Step 7 — run tests: `vendor/bin/pest tests/Feature/Pricing --filter=RecomputePrice --stop-on-failure` — all 13 MUST pass.

    Step 8 — belt-and-braces: `php artisan event:list 2>&1 | grep -i RecomputePriceListener` should show the listener registered.

    **DO NOT:**
    - Do NOT bypass the integer-penny equality check (D-13 requires fire-only-on-diff).
    - Do NOT create a new ImportIssue row on every zero-price invocation — updateOrCreate on (resolved_at IS NULL) is mandatory per D-11.
    - Do NOT touch `last_synced_at` or any Phase 2 columns — listener scope is sell_price ONLY.
    - Do NOT implement retry logic in the listener (Horizon default tries=3 is sufficient; ThrottledFailedJobNotifier catches failures).
    - Do NOT use Model::save() for the sell_price write — forceFill + saveQuietly is the Phase 2 pattern (keeps activity_log clean from sync-driven writes; admin edits still log via LogsActivity on the model).
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Pricing --filter=RecomputePrice --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `test -f app/Domain/Pricing/Events/ProductPriceChanged.php` returns 0
    - `grep -q "extends DomainEvent" app/Domain/Pricing/Events/ProductPriceChanged.php`
    - `test -f app/Domain/Pricing/Listeners/RecomputePriceListener.php` returns 0
    - `grep -q "implements ShouldQueue" app/Domain/Pricing/Listeners/RecomputePriceListener.php`
    - `grep -q "public string \$queue = 'default'" app/Domain/Pricing/Listeners/RecomputePriceListener.php`
    - `grep -q "SupplierPriceChanged" app/Domain/Pricing/Listeners/RecomputePriceListener.php`
    - `grep -q "ImportIssue::updateOrCreate" app/Domain/Pricing/Listeners/RecomputePriceListener.php`
    - `grep -q "saveQuietly" app/Domain/Pricing/Listeners/RecomputePriceListener.php`
    - `grep -q "TYPE_MISSING_COST_PRICE" app/Domain/Pricing/Listeners/RecomputePriceListener.php`
    - `grep -q "RecomputePriceListener" app/Providers/EventServiceProvider.php`
    - `grep -q "SupplierPriceChanged" app/Providers/EventServiceProvider.php`
    - `vendor/bin/pest tests/Feature/Pricing/RecomputePriceListenerTest.php --stop-on-failure` exits 0
    - `vendor/bin/pest tests/Feature/Pricing/RecomputePriceListenerZeroPriceTest.php --stop-on-failure` exits 0
    - Test count: combined report >= 13 passing tests
    - `php artisan event:list 2>&1` contains `RecomputePriceListener`
  </acceptance_criteria>
  <done>
    SupplierPriceChanged → RecomputePriceListener → RuleResolver → PriceCalculator → sell_price write + ProductPriceChanged (on penny diff only). Zero/null supplier price logs ImportIssue idempotently with correlation_id and NEVER touches sell_price. Listener runs on default queue.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Phase 2 event bus → Phase 3 listener | SupplierPriceChanged crosses here; event carries primitives (sku, ids, prices), no Eloquent models |
| Listener → DB writes (products.sell_price, import_issues) | Transactional Laravel write; forceFill + saveQuietly by design |
| Listener → Phase 3 event bus (ProductPriceChanged) | Emitted primitive-only event; downstream Phase 2 Woo-push listener consumes (WOO_WRITE_ENABLED shadow gate) |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-03-02-01 (maps to T3 zero-price leak) | T (Tampering) | RecomputePriceListener on zero supplier price | mitigate | Guard checks buyPennies <= 0 BEFORE calling calculator. Calculator's own guard throws SupplierPriceUnusableException as second layer. Neither writes sell_price. ImportIssue row created via updateOrCreate — idempotent. Covered by Task 2 Test Z1-Z5 + grep-verified guard in acceptance criteria. |
| T-03-02-02 (maps to T4 resolver non-determinism) | T (Tampering) | RuleResolver — two equally-specific rules | mitigate | Explicit priority DESC → id ASC tiebreak on every query. Architectural purity test asserts resolver reads no config/clock/random. Task 1 Tests 8 + 9 cover priority and id tiebreak. |
| T-03-02-03 | I (Information Disclosure) | ProductPriceChanged event fields | accept | Fields are internal (product_id, sku, pennies, margin_bps, source) — no PII. Primitive-only per DomainEvent convention. |
| T-03-02-04 | E (Elevation of Privilege) | Listener bypasses policies | accept | Sync-driven writes legitimately bypass policies (sync is the system actor). forceFill + saveQuietly avoids writing an admin-impersonation row in activity_log. |
| T-03-02-05 (maps to T7 bulk DoS) | D (Denial of Service) | Listener on default queue — could saturate during large sync | accept | Supplier sync is bounded (~15k SKUs/day). Horizon default queue has multiple workers. If volume ever becomes a problem, swap `$queue` value without code change. Phase 7 dashboard observability catches saturation. |
| T-03-02-06 | T (Tampering) | ImportIssue correlation_id missing | mitigate | Listener explicitly sets correlation_id on updateOrCreate second arg. Task 2 Test Z5 asserts this. |
| T-03-02-07 | S (Spoofing) | SupplierPriceChanged event forged | accept | Internal event, not web-facing. Requires local RCE to forge. Outside Phase 3 threat scope. |
</threat_model>

<verification>
- `vendor/bin/pest tests/Unit/Pricing --filter=RuleResolver --stop-on-failure` — Task 1 tests
- `vendor/bin/pest tests/Feature/Pricing --filter=RecomputePrice --stop-on-failure` — Task 2 tests
- `php artisan migrate --env=testing --no-interaction` — two new additive migrations apply
- Full Phase 3 Wave 1+2 regression: `vendor/bin/pest tests/Unit/Pricing tests/Feature/Pricing --stop-on-failure`
</verification>

<success_criteria>
- RuleResolver picks most-specific rule with deterministic tiebreak (brand_category > category > brand > default_tier; priority DESC → id ASC)
- ProductOverride takes precedence over all rules
- Purity test confirms RuleResolver has no config/clock/random reads
- ProductPriceChanged extends DomainEvent and fires ONLY on integer-penny diff
- RecomputePriceListener subscribes via EventServiceProvider; queue=default
- Zero/null supplier price creates idempotent ImportIssue (missing_cost_price) with correlation_id; never touches sell_price
- correlation_id threads from SupplierPriceChanged → ProductPriceChanged
- All 29+ new tests pass; no existing tests regress
</success_criteria>

<output>
Create `.planning/phases/03-pricing-engine/03-02-SUMMARY.md` covering:
- RuleResolver layer order + tiebreak algorithm
- Purity constraints enforced (no config/clock/random)
- brand_id/category_id column additions (with Phase 6 forward-compat note)
- ProductPriceChanged signature + diff-only emission contract
- Listener queue choice (default) + correlation_id threading
- ImportIssue idempotency semantics
- Pointer for Plan 03: Filament rule explorer reads PricingResolution.chain for "brand+cat → brand → cat → default" display
- Pointer for Plan 04: bulk recompute reuses the listener's core logic (dedicated job class)
</output>
