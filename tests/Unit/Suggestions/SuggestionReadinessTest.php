<?php

declare(strict_types=1);

use App\Domain\Suggestions\Filament\Resources\SuggestionResource;

/*
|--------------------------------------------------------------------------
| Quick task 260707-gsy — pure readinessFrom() verdict
|--------------------------------------------------------------------------
|
| readinessFrom(bool $sourceable, ?string $brand) is the PURE verdict behind
| the Suggestions Readiness badge. No DB, no engine dependency — the per-row
| memoised readiness() wrapper feeds it a plain sourceable boolean (from a
| supplier_sku_cache exists() check) + evidence.brand. Verdict rules:
|   - not sourceable            → 'Not sourceable' / gray
|   - sourceable + blank/junk   → 'Needs brand'    / warning
|   - sourceable + usable brand → 'Ready'          / success
| Junk = config('product_auto_create.brands_to_add_exclude') (case-insensitive).
*/

it('returns Ready/success for a sourceable, usably-branded row', function (): void {
    expect(SuggestionResource::readinessFrom(true, 'Barco'))
        ->toBe(['label' => 'Ready', 'color' => 'success']);
});

it('returns Needs brand/warning for a sourceable row with a blank brand', function (): void {
    expect(SuggestionResource::readinessFrom(true, ''))
        ->toBe(['label' => 'Needs brand', 'color' => 'warning']);
});

it('treats config junk brands (Specials) as Needs brand', function (): void {
    expect(SuggestionResource::readinessFrom(true, 'Specials'))
        ->toBe(['label' => 'Needs brand', 'color' => 'warning']);
});

it('treats config junk brands (Un-Branded) as Needs brand', function (): void {
    expect(SuggestionResource::readinessFrom(true, 'Un-Branded'))
        ->toBe(['label' => 'Needs brand', 'color' => 'warning']);
});

it('returns Not sourceable/gray when the SKU is not in the supplier feed', function (): void {
    expect(SuggestionResource::readinessFrom(false, 'Barco'))
        ->toBe(['label' => 'Not sourceable', 'color' => 'gray']);
});
