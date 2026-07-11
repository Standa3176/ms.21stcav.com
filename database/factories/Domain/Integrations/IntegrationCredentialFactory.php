<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Integrations;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Models\IntegrationCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Phase 09.1 Plan 01 — IntegrationCredential factory.
 *
 * Defaults to SupplierApi shape; payload_encrypted is set as a plain array
 * — the model's `'encrypted:array'` cast applies AES-256 + JSON serialisation
 * on save (D-03).
 *
 * `kind()` state switches the kind + supplies a sensible default payload per
 * IntegrationCredentialKind::requiredFields().
 *
 * @extends Factory<IntegrationCredential>
 */
class IntegrationCredentialFactory extends Factory
{
    protected $model = IntegrationCredential::class;

    public function definition(): array
    {
        return [
            'kind' => IntegrationCredentialKind::SupplierApi,
            'name' => 'Production Supplier API',
            'payload_encrypted' => [
                'base_url' => 'https://21stcav.com',
                'username' => 'test-user',
                'password' => 'test-password',
            ],
            'is_active' => true,
        ];
    }

    public function kind(IntegrationCredentialKind $kind): static
    {
        return $this->state(function () use ($kind) {
            $payload = match ($kind) {
                IntegrationCredentialKind::SupplierApi => [
                    'base_url' => 'https://21stcav.com',
                    'username' => 'u',
                    'password' => 'p',
                ],
                IntegrationCredentialKind::WooRest => [
                    'base_url' => 'https://meetingstore.co.uk',
                    'consumer_key' => 'ck_x',
                    'consumer_secret' => 'cs_x',
                ],
                IntegrationCredentialKind::BitrixWebhook => [
                    'webhook_url' => 'https://b24.example.com/rest/1/abc/',
                ],
                IntegrationCredentialKind::AnthropicApi => [
                    'api_key' => 'sk-ant-test',
                ],
                IntegrationCredentialKind::LangfuseObservability => [
                    'host' => 'https://lf.example',
                    'public_key' => 'pk',
                    'secret_key' => 'sk',
                ],
                IntegrationCredentialKind::GoogleAnalytics => [
                    'service_account_json' => '{"type":"service_account"}',
                    'property_id' => '123456789',
                ],
            };

            return [
                'kind' => $kind,
                'name' => $kind->label(),
                'payload_encrypted' => $payload,
            ];
        });
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
