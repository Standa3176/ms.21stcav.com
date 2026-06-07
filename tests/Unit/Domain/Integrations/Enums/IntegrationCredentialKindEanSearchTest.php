<?php

declare(strict_types=1);

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260607-hxa — IntegrationCredentialKind::EanSearch enum coverage
|--------------------------------------------------------------------------
|
| Adds EAN-search.org as a first-class IntegrationCredentialKind so the
| existing admin Filament Credentials Resource picks it up via
| auto-discovery (requiredFields() drives the form) and IntegrationHealthWidget
| surfaces a tile automatically (it iterates cases()).
|
| The enum is the single source of truth: a missing case here = an admin form
| that refuses to render. Six behaviour cases pin the shape (value, required
| fields, label, color, urlFields, resolver env fallback).
*/

it('IntegrationCredentialKind::EanSearch is a valid enum case with value ean_search', function (): void {
    $case = IntegrationCredentialKind::from('ean_search');
    expect($case)->toBe(IntegrationCredentialKind::EanSearch);
    expect($case->value)->toBe('ean_search');
});

it('requiredFields() returns exactly [token] for EanSearch', function (): void {
    expect(IntegrationCredentialKind::EanSearch->requiredFields())->toBe(['token']);
});

it('label() returns a non-empty string containing EAN-search for EanSearch', function (): void {
    $label = IntegrationCredentialKind::EanSearch->label();
    expect($label)->toBeString();
    expect($label)->not->toBe('');
    expect($label)->toContain('EAN-search');
});

it('color() returns a non-empty string for EanSearch', function (): void {
    $color = IntegrationCredentialKind::EanSearch->color();
    expect($color)->toBeString();
    expect($color)->not->toBe('');
});

it('urlFields() returns [] for EanSearch (API base URL hard-coded, not a credential field)', function (): void {
    expect(IntegrationCredentialKind::EanSearch->urlFields())->toBe([]);
});

it('resolver env fallback returns [token => ...] when services.ean_search.token is set', function (): void {
    // Clear any leftover cache from prior tests / earlier kinds.
    Cache::forget(IntegrationCredentialResolver::cacheKeyFor(IntegrationCredentialKind::EanSearch));

    config(['services.ean_search.token' => 'demo-token-123']);

    $payload = app(IntegrationCredentialResolver::class)
        ->for(IntegrationCredentialKind::EanSearch);

    expect($payload)->toBeArray();
    expect($payload['token'] ?? null)->toBe('demo-token-123');
});
