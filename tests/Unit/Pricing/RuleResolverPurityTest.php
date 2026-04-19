<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 3 Plan 02 Task 1 — RuleResolver purity guardrail.
|--------------------------------------------------------------------------
|
| RuleResolver MUST be a pure function over DB state (T-03-02-02 mitigation).
| A Filament rule explorer that asks "why did this product resolve to 22%?"
| needs deterministic answers — any clock / random / session / config read
| poisons that contract.
|
| Two layers of assertion:
|   - Determinism: two consecutive calls with identical DB state return
|     equal PricingResolution objects.
|   - Grep guard on the source file: no config(), now(), time(), Carbon::now,
|     microtime(), rand(), mt_rand, random_int, Str::uuid, session().
*/

function resolverSource(): string
{
    $source = file_get_contents(app_path('Domain/Pricing/Services/RuleResolver.php'));
    if ($source === false) {
        throw new RuntimeException('Could not read RuleResolver.php for purity scan');
    }

    return $source;
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 1 — determinism: same inputs → equal outputs
// ══════════════════════════════════════════════════════════════════════════════

it('returns equal PricingResolution instances across two consecutive calls on identical DB state', function () {
    $product = Product::factory()->create([
        'brand_id' => 33, 'category_id' => 44, 'buy_price' => '50.0000',
    ]);

    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 33, 'category_id' => 44,
        'margin_basis_points' => 2500,
    ]);

    $resolver = app(RuleResolver::class);

    $a = $resolver->resolve($product->fresh());
    $b = $resolver->resolve($product->fresh());

    expect($a->marginBasisPoints)->toBe($b->marginBasisPoints);
    expect($a->source)->toBe($b->source);
    expect($a->matchedRuleId)->toBe($b->matchedRuleId);
    expect($a->overrideId)->toBe($b->overrideId);
    expect($a->chain)->toBe($b->chain);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 2 — no config() read (prevents env-driven drift)
// ══════════════════════════════════════════════════════════════════════════════

it('RuleResolver source contains zero config() calls', function () {
    $source = resolverSource();
    // Strip comments so a reference in a docblock doesn't false-positive.
    $stripped = preg_replace('#(/\*.*?\*/|//.*$)#ms', '', $source);
    expect(substr_count($stripped, 'config('))
        ->toBe(0, 'RuleResolver must not read config() — purity violation');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 3 — no clock read
// ══════════════════════════════════════════════════════════════════════════════

it('RuleResolver source contains no clock / time reads', function () {
    $source = resolverSource();
    $stripped = preg_replace('#(/\*.*?\*/|//.*$)#ms', '', $source);
    expect(preg_match_all('/\b(now\(|Carbon::now|time\(\)|microtime\()/', $stripped))
        ->toBe(0, 'RuleResolver must not read the clock — purity violation');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 4 — no random read
// ══════════════════════════════════════════════════════════════════════════════

it('RuleResolver source contains no randomness sources', function () {
    $source = resolverSource();
    $stripped = preg_replace('#(/\*.*?\*/|//.*$)#ms', '', $source);
    expect(preg_match_all('/\b(rand\(|mt_rand|random_int|Str::uuid|random_bytes)/', $stripped))
        ->toBe(0, 'RuleResolver must not use randomness — purity violation');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 5 — no session / request state read
// ══════════════════════════════════════════════════════════════════════════════

it('RuleResolver source contains no session / request / cache reads', function () {
    $source = resolverSource();
    $stripped = preg_replace('#(/\*.*?\*/|//.*$)#ms', '', $source);
    expect(preg_match_all('/\b(session\(|request\(|Cache::|cache\(|Context::get|auth\()/', $stripped))
        ->toBe(0, 'RuleResolver must not read session / request / cache / auth — purity violation');
});
