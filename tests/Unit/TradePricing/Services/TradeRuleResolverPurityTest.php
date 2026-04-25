<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;
use App\Domain\TradePricing\Models\CustomerGroup;
use App\Domain\TradePricing\Services\TradeRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 02 Task 3 — TradeRuleResolver purity guardrail (TRDE-02)
|--------------------------------------------------------------------------
|
| Mirrors v1 RuleResolverPurityTest. The decorator MUST inherit v1's purity
| contract — any clock / config / random / cache / auth read poisons the
| Filament rule explorer's "why did this product resolve to 22%?" answer.
|
| Two layers of assertion:
|   - Source-file scan (Test 1): the TradeRuleResolver source contains zero
|     matches for forbidden tokens (config(, now(, Carbon::now, auth(,
|     Cache::, cache(, Session::, session(, random_int(, mt_rand(,
|     microtime(, auth()->). This test does NOT need DB access — it only
|     reads the source file via file_get_contents.
|   - Behavioural determinism (Tests 2/3/4): two consecutive calls with
|     identical DB state return PricingResolution objects with equal field
|     values across all four NULL quadrants (null / 0 / non-existent /
|     valid group). These tests need DB access via RefreshDatabase trait
|     applied per-test.
|
| Skip-on-MySQL-offline parity with Phase 6/7/8 + Plan 09-01.
|
| RefreshDatabase is applied per-test (via ->uses(RefreshDatabase::class))
| rather than file-globally so the static source-file scan in Test 1 can
| run even when MySQL is offline — the TradeRuleResolver source file scan
| is a deterministic, DB-free property check that should always run.
*/

function skipIfMySqlOfflineTradePurity(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

function tradeResolverSource(): string
{
    $source = file_get_contents(app_path('Domain/TradePricing/Services/TradeRuleResolver.php'));
    if ($source === false) {
        throw new RuntimeException('Could not read TradeRuleResolver.php for purity scan');
    }

    return $source;
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 1 — source file has no clock / config / random / cache / auth reads
// (DB-free; runs even when MySQL is offline)
// ══════════════════════════════════════════════════════════════════════════════

it('TradeRuleResolver source file has no clock/config/random/cache/auth reads', function (): void {
    $source = tradeResolverSource();
    // Strip docblocks + line comments so a token in a comment doesn't false-positive.
    $stripped = preg_replace('#(/\*.*?\*/|//.*$)#ms', '', $source);

    $forbidden = [
        'config(',
        'now(',
        'Carbon::now',
        'auth(',
        'auth()->',
        'Cache::',
        'cache(',
        'Session::',
        'session(',
        'random_int(',
        'mt_rand(',
        'microtime(',
    ];

    foreach ($forbidden as $token) {
        expect(str_contains($stripped, $token))->toBeFalse(
            "TradeRuleResolver must be pure — found '{$token}'"
        );
    }
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 2 — determinism: two calls on identical DB state return equal resolution
// ══════════════════════════════════════════════════════════════════════════════

it('two calls on identical DB state return equal PricingResolution', function (): void {
    skipIfMySqlOfflineTradePurity();

    $group = CustomerGroup::factory()->create(['slug' => 'trade-purity']);
    $product = Product::factory()->create([
        'brand_id' => 1, 'category_id' => 1, 'buy_price' => '50.0000',
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 1, 'category_id' => 1,
        'customer_group_id' => $group->id,
        'margin_basis_points' => 1800,
        'priority' => 100, 'active' => true,
    ]);

    $resolver = app(TradeRuleResolver::class);
    $a = $resolver->resolve($product->fresh(), $group->id);
    $b = $resolver->resolve($product->fresh(), $group->id);

    expect($a->source)->toBe($b->source);
    expect($a->marginBasisPoints)->toBe($b->marginBasisPoints);
    expect($a->matchedRuleId)->toBe($b->matchedRuleId);
    expect($a->overrideId)->toBe($b->overrideId);
    expect($a->chain)->toBe($b->chain);
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// Test 3 — determinism holds across all four NULL quadrants
// ══════════════════════════════════════════════════════════════════════════════

it('determinism holds across all four NULL quadrants', function (): void {
    skipIfMySqlOfflineTradePurity();

    $group = CustomerGroup::factory()->create(['slug' => 'trade-quad']);
    $product = Product::factory()->create([
        'brand_id' => 1, 'category_id' => 1, 'buy_price' => '50.0000',
    ]);
    // Retail rule so Quadrant A (null) / B (0) / C (99999) have somewhere to land.
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 1, 'category_id' => 1,
        'customer_group_id' => null,
        'margin_basis_points' => 2500,
        'priority' => 100, 'active' => true,
    ]);
    // Trade rule for Quadrant D (valid group).
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 1, 'category_id' => 1,
        'customer_group_id' => $group->id,
        'margin_basis_points' => 1800,
        'priority' => 200, 'active' => true,
    ]);

    $resolver = app(TradeRuleResolver::class);

    foreach ([null, 0, 99999, $group->id] as $cg) {
        $a = $resolver->resolve($product->fresh(), $cg);
        $b = $resolver->resolve($product->fresh(), $cg);

        expect($a->source)->toBe($b->source);
        expect($a->marginBasisPoints)->toBe($b->marginBasisPoints);
        expect($a->matchedRuleId)->toBe($b->matchedRuleId);
        expect($a->overrideId)->toBe($b->overrideId);
        expect($a->chain)->toBe($b->chain);
    }
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// Test 4 — determinism holds when ProductOverride wins Layer 0
// ══════════════════════════════════════════════════════════════════════════════

it('determinism holds when ProductOverride wins Layer 0', function (): void {
    skipIfMySqlOfflineTradePurity();

    $group = CustomerGroup::factory()->create(['slug' => 'trade-override-purity']);
    $product = Product::factory()->create([
        'brand_id' => 1, 'category_id' => 1, 'buy_price' => '50.0000',
    ]);
    ProductOverride::factory()->create([
        'product_id' => $product->id,
        'margin_basis_points' => 1500,
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 1, 'category_id' => null,
        'customer_group_id' => $group->id,
        'margin_basis_points' => 5000,
        'priority' => 200, 'active' => true,
    ]);

    $resolver = app(TradeRuleResolver::class);
    $a = $resolver->resolve($product->fresh(), $group->id);
    $b = $resolver->resolve($product->fresh(), $group->id);

    expect($a->source)->toBe('override');
    expect($a->source)->toBe($b->source);
    expect($a->marginBasisPoints)->toBe($b->marginBasisPoints);
    expect($a->overrideId)->toBe($b->overrideId);
})->uses(RefreshDatabase::class);
