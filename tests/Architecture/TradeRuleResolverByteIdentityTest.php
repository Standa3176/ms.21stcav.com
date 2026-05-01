<?php

declare(strict_types=1);

use App\Domain\TradePricing\Services\TradeRuleResolver;

/*
|--------------------------------------------------------------------------
| Phase 11 Plan 02 — B-03 byte-identity invariant for resolve() body.
|--------------------------------------------------------------------------
|
| Phase 9 Plan 02 shipped TradeRuleResolver::resolve() under a sha256-baseline
| invariant: any future plan that "tweaks" resolve() must trip CI. Phase 11
| ADDED resolveForQuote() (additive thin delegate) so a file-level sha256
| would drift on the legitimate addition. Instead this test extracts the
| body of the resolve() method via ReflectionMethod and asserts the sha256
| of just that span — letting resolveForQuote() (and future additive
| methods) be added without breaking the lock on resolve().
|
| Capture procedure (re-run to re-verify):
|   1. git diff --quiet app/Domain/TradePricing/Services/TradeRuleResolver.php
|      (asserting working tree is clean before capture).
|   2. Build a one-shot script that uses ReflectionMethod::getStartLine /
|      getEndLine + file() + sha256 of the joined slice.
|   3. Pin the resulting hex string into BASELINE_SHA256_PHASE_11 below.
|
| Captured 2026-05-01 — Phase 11 Plan 02 Task 1, BEFORE adding the
| resolveForQuote() additive method. Resolves to the exact bytes Phase 9
| Plan 02 shipped (re-verified after the additive edit — span shifted by
| one line due to the new `use Illuminate\Database\Eloquent\ModelNotFoundException;`
| import, but the span content itself is byte-identical).
|
| This test is PURE Reflection — runs offline, no DB.
*/

it('TradeRuleResolver::resolve method body is byte-identical to Phase 9 baseline', function (): void {
    $rc = new ReflectionClass(TradeRuleResolver::class);
    $method = $rc->getMethod('resolve');
    $start = $method->getStartLine();
    $end = $method->getEndLine();
    $lines = file($method->getFileName());
    $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));
    $hash = hash('sha256', $body);

    $expected = '77f6bdaa02d32b834a76541dd418bd501569c9f0ca70d291a7696f8d1b53dbe2';

    expect($hash)->toBe(
        $expected,
        sprintf(
            "Phase 9 B-03 invariant violated — TradeRuleResolver::resolve body has changed since Phase 9 Plan 02 baseline.\n"
            ."  Captured: %s\n  Expected: %s\n  Span:     lines %d-%d (%d bytes)\n"
            ."If you intentionally edited resolve(), Phase 9's golden-fixture parity is now broken — "
            ."DO NOT bump this hash unless you also re-baseline GoldenFixtureV1UnchangedTest "
            ."and re-verify the 50-triple Phase 3 ship gate against the new body.",
            $hash,
            $expected,
            $start,
            $end,
            strlen($body),
        ),
    );
});

it('TradeRuleResolver exposes both resolve() and resolveForQuote() public methods', function (): void {
    $rc = new ReflectionClass(TradeRuleResolver::class);
    $publicMethods = array_filter(
        $rc->getMethods(ReflectionMethod::IS_PUBLIC),
        fn ($m) => ! $m->isConstructor()
    );
    $names = array_values(array_map(fn ($m) => $m->getName(), $publicMethods));
    sort($names);

    expect($names)->toBe(
        ['resolve', 'resolveForQuote'],
        'TradeRuleResolver public surface drift — expected exactly resolve + resolveForQuote'
    );
});

it('TradeRuleResolver::resolveForQuote signature is (string sku, ?int customerGroupId): PricingResolution', function (): void {
    $rc = new ReflectionClass(TradeRuleResolver::class);
    $m = $rc->getMethod('resolveForQuote');
    $params = $m->getParameters();

    expect($params)->toHaveCount(2);
    expect($params[0]->getName())->toBe('sku');
    expect((string) $params[0]->getType())->toBe('string');
    expect($params[1]->getName())->toBe('customerGroupId');
    // ReflectionType for nullable int reports as ?int via __toString.
    expect((string) $params[1]->getType())->toBe('?int');
    expect((string) $m->getReturnType())->toBe('App\\Domain\\Pricing\\Services\\PricingResolution');
});
