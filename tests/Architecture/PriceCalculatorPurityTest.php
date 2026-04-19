<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 3 Plan 05 Task 2 — PriceCalculator purity guardrail (T-03-05-02)
|--------------------------------------------------------------------------
|
| PriceCalculator is the Phase 3 SHIP GATE unit (PRCE-06). Any leak of
| Eloquent / events / logging / HTTP / mail / clock / random / session /
| float into the source file invisibly breaks the golden-fixture contract
| because the fixture assumes:
|
|   - Pure integer-pennies arithmetic (Pitfall 5 D-03)
|   - Exactly ONE round() per public method (Pitfall 5 D-01, D-02)
|   - Rounding mode read from config('pricing.rounding_mode') at call time,
|     and ONLY that config key (D-02 lock)
|   - No float type hints (Pitfall 5 — float drift is the #1 VAT rounding bug)
|
| The grep-based assertions below catch any future PR that adds a forbidden
| pattern to the calculator. Failure tells the author "fix the code, not
| the test" — this is not a relaxable gate.
|
| See: Pitfall 5 (VAT rounding drift) — the design rationale for every rule.
*/

function priceCalculatorSource(): string
{
    $source = file_get_contents(app_path('Domain/Pricing/Services/PriceCalculator.php'));
    if ($source === false) {
        throw new RuntimeException('Could not read PriceCalculator.php for purity scan');
    }

    return $source;
}

// Strip comments/docblocks so a reference in a PHPDoc never false-positives.
// Two-pass: first strip /* ... */ blocks (multi-line), then // line comments.
// The combined alternation with `ms` flags greedily matches newline characters
// and eats large executable regions, so we keep the passes separate.
function strippedPriceCalculatorSource(): string
{
    $source = priceCalculatorSource();
    $noBlocks = (string) preg_replace('#/\*.*?\*/#s', '', $source);

    return (string) preg_replace('#//[^\n]*#', '', $noBlocks);
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 1 — no Eloquent / events / logging / HTTP / mail
// ══════════════════════════════════════════════════════════════════════════════

it('PriceCalculator has no Eloquent / events / logging / HTTP / mail', function (): void {
    $source = strippedPriceCalculatorSource();
    $forbidden = [
        'Model::', 'Eloquent', 'DB::', 'Log::', 'Event::', 'Mail::', 'Queue::',
        'Context::', 'Http::', 'dispatch(', 'fire(',
    ];
    foreach ($forbidden as $token) {
        expect($source)->not->toContain(
            $token,
            "PriceCalculator must be pure — found forbidden token: {$token}",
        );
    }
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 2 — config() reads are scoped to rounding_mode only
// ══════════════════════════════════════════════════════════════════════════════

it('PriceCalculator reads config only for rounding_mode, exactly once per public method', function (): void {
    $source = strippedPriceCalculatorSource();

    // compute() + stripVat() each read rounding_mode once → at most 2 config() calls.
    expect(substr_count($source, 'config('))->toBeLessThanOrEqual(
        2,
        'PriceCalculator should read config at most twice (rounding_mode in compute + stripVat); found more',
    );

    // And every config() read MUST be pricing.rounding_mode — not app.name or similar.
    $roundingModeMatches = [];
    preg_match_all('/config\([\'"]pricing\.rounding_mode[\'"]/u', $source, $roundingModeMatches);
    expect(count($roundingModeMatches[0]))->toBeGreaterThan(
        0,
        'PriceCalculator does not read pricing.rounding_mode via config — D-02 violation',
    );

    // Count of "pricing.rounding_mode" reads must equal count of total config() calls.
    expect(count($roundingModeMatches[0]))->toBe(
        substr_count($source, 'config('),
        'PriceCalculator has a config() call that is NOT pricing.rounding_mode — D-02 lock violation',
    );
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 3 — no clock / random / session / auth leaks
// ══════════════════════════════════════════════════════════════════════════════

it('PriceCalculator has no clock / random / session leaks', function (): void {
    $source = strippedPriceCalculatorSource();
    $forbidden = [
        'now(', 'Carbon::', 'time()', 'microtime(', 'rand(', 'mt_rand',
        'random_int', 'Str::uuid', 'session(', 'auth()', 'request(',
    ];
    foreach ($forbidden as $token) {
        expect($source)->not->toContain(
            $token,
            "PriceCalculator must be pure — found forbidden token: {$token}",
        );
    }
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 4 — no float type hints (Pitfall 5 — biggest VAT-drift cause)
// ══════════════════════════════════════════════════════════════════════════════

it('PriceCalculator has no float type leak', function (): void {
    $source = strippedPriceCalculatorSource();

    // ': float' as return/param type and 'public float $x' property all forbidden.
    expect(preg_match_all('/:\s*float\b/u', $source))->toBe(
        0,
        'PriceCalculator signatures must not use float (Pitfall 5 — float drift is the #1 VAT rounding bug)',
    );

    // No 'float' keyword anywhere in executable code (stripped source).
    // Note: `(float)` casts are also forbidden — any float cast in a rounding
    // pipeline reintroduces the drift we are trying to prevent.
    expect(preg_match_all('/\bfloat\b/u', $source))->toBe(
        0,
        'PriceCalculator must not type-hint or cast float (Pitfall 5)',
    );
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 5 — round() called at most twice (compute + stripVat)
// ══════════════════════════════════════════════════════════════════════════════

it('PriceCalculator applies round() at most twice (once per public method)', function (): void {
    $source = strippedPriceCalculatorSource();
    $roundCount = substr_count($source, 'round(');

    expect($roundCount)->toBeLessThanOrEqual(
        2,
        "PriceCalculator must call round() at most twice — compute() + stripVat(). Found: {$roundCount}. "
            .'Pitfall 5 warns about compound rounding — multiple round() calls reintroduce drift.',
    );

    expect($roundCount)->toBeGreaterThan(
        0,
        'PriceCalculator should call round() at least once — single round at the return boundary is Pitfall 5 prescribed',
    );
});
