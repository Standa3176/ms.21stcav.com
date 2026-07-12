<?php

declare(strict_types=1);

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Filament\Resources\IntegrationCredentialResource;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/*
|--------------------------------------------------------------------------
| HOTFIX 260712-gaj — GA4 service_account_json renders as a Textarea
|--------------------------------------------------------------------------
|
| Blocker: the operator could not save GA4 credentials because
| service_account_json rendered as a single-line TextInput with maxLength(2048).
| A GA4 service-account key is multi-line JSON ~2.5KB, so the <input> kept only
| the first line and/or truncated the value → json_decode failed with
| "service_account_json is not valid JSON".
|
| These tests lock the fix: service_account_json must be a Textarea with a large
| maxLength; every OTHER credential field stays a single-line TextInput.
*/

/**
 * @return array<string, Component>
 */
function gajFieldsByName(IntegrationCredentialKind $kind): array
{
    return collect(IntegrationCredentialResource::payloadFieldComponents($kind))
        ->keyBy(fn ($component) => $component->getName())
        ->all();
}

it('renders GoogleAnalytics service_account_json as a Textarea with a large maxLength', function (): void {
    $field = gajFieldsByName(IntegrationCredentialKind::GoogleAnalytics)['payload_encrypted.service_account_json'] ?? null;

    expect($field)->toBeInstanceOf(Textarea::class);
    expect($field->getMaxLength())->toBeGreaterThanOrEqual(8192);
});

it('keeps the other GoogleAnalytics field (property_id) as a single-line TextInput', function (): void {
    $field = gajFieldsByName(IntegrationCredentialKind::GoogleAnalytics)['payload_encrypted.property_id'] ?? null;

    expect($field)->toBeInstanceOf(TextInput::class);
});

it('leaves every field of a non-GoogleAnalytics kind as a single-line TextInput', function (IntegrationCredentialKind $kind): void {
    $components = IntegrationCredentialResource::payloadFieldComponents($kind);

    expect($components)->not->toBeEmpty();
    foreach ($components as $component) {
        expect($component)->toBeInstanceOf(TextInput::class);
    }
})->with([
    'SupplierApi' => [IntegrationCredentialKind::SupplierApi],
    'WooRest' => [IntegrationCredentialKind::WooRest],
    'Icecat' => [IntegrationCredentialKind::Icecat],
]);
