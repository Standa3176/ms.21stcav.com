<?php

declare(strict_types=1);

use App\Domain\Pricing\Services\PricingResolution;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 06 Task 2 — D-03 invariant guardrail (B-03 hardening)
|--------------------------------------------------------------------------
|
| Phase 3 RuleResolver and PriceCalculator must remain BYTE-IDENTICAL to
| their Phase 3 ship-gate versions. If anyone modifies them post-Phase 9,
| the golden fixture invariant breaks (Phase 3 50-triple ship gate is
| signed against the unchanged signatures).
|
| The hash literals below were captured under git-clean preconditions
| (B-03) — the test author asserted `git diff --quiet <file>` exited 0
| BEFORE running `hash_file('sha256', ...)`. The captured working-tree
| state is therefore the committed state, not local drift.
|
| Capture commands (re-run to re-verify):
|   git diff --quiet app/Domain/Pricing/Services/RuleResolver.php
|   git diff --quiet app/Domain/Pricing/Services/PriceCalculator.php
|   php -r 'echo hash_file("sha256", "app/Domain/Pricing/Services/RuleResolver.php");'
|   php -r 'echo hash_file("sha256", "app/Domain/Pricing/Services/PriceCalculator.php");'
|
| These tests are PURE source-grep + reflection — they run offline (no DB).
*/

it('RuleResolver.php sha256 is byte-identical to pre-Phase-9 snapshot', function (): void {
    $hash = hash_file('sha256', base_path('app/Domain/Pricing/Services/RuleResolver.php'));
    // 260710-efw — v1 byte-lock INTENTIONALLY re-pinned. The original lock predated trade
    // rules sharing the pricing_rules table; RuleResolver's 4 retail layers now carry
    // ->whereNull('customer_group_id') so v1 retail/anonymous resolution stays trade-free
    // (no customer-group rule can leak a trade-discounted price to the public). This is the
    // minimal correctness patch; retail behaviour is unchanged (retail rules are null-group).
    // The PriceCalculator pin below is UNCHANGED.
    $expected = 'd40af7e7ff07f20424fcd9203f5d89058b0b13d27b98ca810767889bd6a32a23';
    expect($hash)->toBe(
        $expected,
        'app/Domain/Pricing/Services/RuleResolver.php has drifted — Phase 3 retail invariant broken (CONTEXT.md D-03).'
    );
});

it('PriceCalculator.php sha256 is byte-identical to pre-Phase-9 snapshot', function (): void {
    $hash = hash_file('sha256', base_path('app/Domain/Pricing/Services/PriceCalculator.php'));
    $expected = '43efcb555c7dadc6c7ca583f8f231b82610d63d9caf6775fb0dc93ce9920ed4c';
    expect($hash)->toBe(
        $expected,
        'app/Domain/Pricing/Services/PriceCalculator.php has drifted — Phase 3 retail invariant broken (CONTEXT.md D-03).'
    );
});

it('RuleResolver public signature is exactly resolve(Product): PricingResolution', function (): void {
    $reflection = new \ReflectionClass(RuleResolver::class);
    $publicMethods = array_filter(
        $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        fn ($m) => ! $m->isConstructor()
    );
    $publicMethodNames = array_values(array_map(fn ($m) => $m->getName(), $publicMethods));

    expect($publicMethodNames)->toBe(
        ['resolve'],
        'RuleResolver may have only one public method: resolve()'
    );

    $resolveMethod = $reflection->getMethod('resolve');
    $params = $resolveMethod->getParameters();

    expect($params)->toHaveCount(1, 'RuleResolver::resolve must take exactly one parameter (Product)');
    expect($params[0]->getName())->toBe('product');
    expect((string) $params[0]->getType())->toBe(Product::class);
    expect((string) $resolveMethod->getReturnType())->toBe(PricingResolution::class);
});
