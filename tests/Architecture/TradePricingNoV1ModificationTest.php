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
    $expected = '3b711b4ac5c41dd7f1ea314436316a976eff1a96c099d1e3159c572ddbfb4e6c';
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
