<?php

declare(strict_types=1);

use App\Domain\Integrations\Enums\IntegrationCredentialKind;

/**
 * Phase 09.1 Plan 01 Task 1 Tests 1.4, 1.5, 1.6.
 *
 * Verifies the IntegrationCredentialKind enum contract per CONTEXT D-04.
 */

it('declares exactly 5 cases with the D-04 string values', function (): void {
    $values = collect(IntegrationCredentialKind::cases())->map(fn ($k) => $k->value)->all();

    expect($values)
        ->toHaveCount(5)
        ->toContain('supplier_api')
        ->toContain('woo_rest')
        ->toContain('bitrix_webhook')
        ->toContain('anthropic_api')
        ->toContain('langfuse_observability');
});

it('returns the per-kind required field shape from requiredFields() per D-04', function (): void {
    expect(IntegrationCredentialKind::SupplierApi->requiredFields())
        ->toBe(['base_url', 'username', 'password']);

    expect(IntegrationCredentialKind::WooRest->requiredFields())
        ->toBe(['base_url', 'consumer_key', 'consumer_secret']);

    expect(IntegrationCredentialKind::BitrixWebhook->requiredFields())
        ->toBe(['webhook_url']);

    expect(IntegrationCredentialKind::AnthropicApi->requiredFields())
        ->toBe(['api_key']);

    expect(IntegrationCredentialKind::LangfuseObservability->requiredFields())
        ->toBe(['host', 'public_key', 'secret_key']);
});

it('returns non-empty label() and color() strings for every case', function (): void {
    foreach (IntegrationCredentialKind::cases() as $kind) {
        expect($kind->label())->toBeString()->not->toBe('');
        expect($kind->color())->toBeString()->not->toBe('');
    }
});
