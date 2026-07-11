<?php

declare(strict_types=1);

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15a-01 — IntegrationCredentialKind::GoogleAnalytics coverage
|--------------------------------------------------------------------------
|
| Adds GA4 (Data API) as a first-class IntegrationCredentialKind so the admin
| Filament Credentials Resource picks it up via auto-discovery (requiredFields()
| drives the form) and IntegrationHealthWidget surfaces a tile automatically
| (it iterates cases()).
|
| READ-ONLY integration: a service-account key (JSON) + a GA4 property id are the
| only two fields the operator pastes. No Google approval flow — GA4 read access
| is granted directly on the property.
*/

it('IntegrationCredentialKind::GoogleAnalytics is a valid enum case with value google_analytics', function (): void {
    $case = IntegrationCredentialKind::from('google_analytics');
    expect($case)->toBe(IntegrationCredentialKind::GoogleAnalytics);
    expect($case->value)->toBe('google_analytics');
});

it('requiredFields() returns exactly [service_account_json, property_id] for GoogleAnalytics', function (): void {
    expect(IntegrationCredentialKind::GoogleAnalytics->requiredFields())
        ->toBe(['service_account_json', 'property_id']);
});

it('optionalFields() returns [] for GoogleAnalytics', function (): void {
    expect(IntegrationCredentialKind::GoogleAnalytics->optionalFields())->toBe([]);
});

it('label() returns the GA4 Data API label for GoogleAnalytics', function (): void {
    $label = IntegrationCredentialKind::GoogleAnalytics->label();
    expect($label)->toBeString();
    expect($label)->not->toBe('');
    expect($label)->toBe('Google Analytics 4 (Data API)');
});

it('color() returns info for GoogleAnalytics (data-source parity)', function (): void {
    expect(IntegrationCredentialKind::GoogleAnalytics->color())->toBe('info');
});

it('urlFields() returns [] for GoogleAnalytics (property id + JSON key, no URL field)', function (): void {
    expect(IntegrationCredentialKind::GoogleAnalytics->urlFields())->toBe([]);
});

it('resolver env fallback returns [service_account_json, property_id] when services.google_analytics is set', function (): void {
    Cache::forget(IntegrationCredentialResolver::cacheKeyFor(IntegrationCredentialKind::GoogleAnalytics));

    config([
        'services.google_analytics.service_account_json' => '{"type":"service_account"}',
        'services.google_analytics.property_id' => '123456789',
    ]);

    $payload = app(IntegrationCredentialResolver::class)
        ->for(IntegrationCredentialKind::GoogleAnalytics);

    expect($payload)->toBeArray();
    expect($payload['service_account_json'] ?? null)->toBe('{"type":"service_account"}');
    expect($payload['property_id'] ?? null)->toBe('123456789');
});
