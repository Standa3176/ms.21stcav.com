<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Concerns\ResolvesWooBrandKey;

/*
|--------------------------------------------------------------------------
| Quick task 260702-h50 — ResolvesWooBrandKey trait
|--------------------------------------------------------------------------
|
| resolveBrandKey() [260628-b9t] + firstResolvableBrandKey() [260629-rct]
| were extracted VERBATIM out of DraftFromSuggestionsCommand into this shared
| trait so DraftFromSuggestionsCommand AND the new RefreshBrandsToAddCommand
| share one implementation. This test drives the trait in isolation via an
| anonymous class — no command, no DB — so a regression in the trait shows up
| here even if a consumer changes. The two existing draft-from-suggestions
| unit suites (which call the same methods via app(DraftFromSuggestionsCommand))
| remain the byte-for-byte behaviour guard.
*/

/** Fresh anon-class instance using the trait under test. */
function brandKeyResolver(): object
{
    return new class
    {
        use ResolvesWooBrandKey;
    };
}

/** Lowercased-name => canonical-name, mirroring the chunk-processor's $wooBrandsByLower. */
function traitWooBrandMap(): array
{
    return ['yealink' => 'Yealink', 'lindy' => 'Lindy', 'brightsign' => 'BrightSign'];
}

// ── resolveBrandKey ────────────────────────────────────────────────────────

it('resolveBrandKey resolves an exact lowercase match to the same key', function (): void {
    expect(brandKeyResolver()->resolveBrandKey('yealink', traitWooBrandMap()))->toBe('yealink');
});

it('resolveBrandKey strips a trailing " - <suffix>" and resolves the base brand', function (): void {
    expect(brandKeyResolver()->resolveBrandKey('yealink - headset', traitWooBrandMap()))->toBe('yealink');
});

it('resolveBrandKey strips at the FIRST " - " with multiple suffix segments', function (): void {
    expect(brandKeyResolver()->resolveBrandKey('lindy - cable - uk', traitWooBrandMap()))->toBe('lindy');
});

it('resolveBrandKey defensively trims an untrimmed input', function (): void {
    expect(brandKeyResolver()->resolveBrandKey('  brightsign  ', traitWooBrandMap()))->toBe('brightsign');
});

it('resolveBrandKey returns null when the stripped lead is still unknown', function (): void {
    expect(brandKeyResolver()->resolveBrandKey('acme - widgets', traitWooBrandMap()))->toBeNull();
});

it('resolveBrandKey returns null for a hyphen with no surrounding spaces', function (): void {
    expect(brandKeyResolver()->resolveBrandKey('totally-unknown', traitWooBrandMap()))->toBeNull();
});

it('resolveBrandKey returns null for an empty string', function (): void {
    expect(brandKeyResolver()->resolveBrandKey('', traitWooBrandMap()))->toBeNull();
});

// ── firstResolvableBrandKey ─────────────────────────────────────────────────

it('firstResolvableBrandKey resolves a single resolvable manufacturer', function (): void {
    expect(brandKeyResolver()->firstResolvableBrandKey(['BrightSign'], traitWooBrandMap()))
        ->toBe(['brightsign', 'BrightSign']);
});

it('firstResolvableBrandKey picks the brand-resolving mfr over a non-brand add-on', function (): void {
    expect(brandKeyResolver()->firstResolvableBrandKey(['Protect Plus', 'BrightSign'], traitWooBrandMap()))
        ->toBe(['brightsign', 'BrightSign']);
});

it('firstResolvableBrandKey returns [null, null] when nothing resolves', function (): void {
    expect(brandKeyResolver()->firstResolvableBrandKey(['Protect Plus'], traitWooBrandMap()))
        ->toBe([null, null]);
});

it('firstResolvableBrandKey returns [null, null] for an empty manufacturer list', function (): void {
    expect(brandKeyResolver()->firstResolvableBrandKey([], traitWooBrandMap()))
        ->toBe([null, null]);
});
