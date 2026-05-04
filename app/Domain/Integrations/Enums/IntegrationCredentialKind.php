<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Enums;

/**
 * Phase 09.1 Plan 01 — IntegrationCredentialKind (D-04).
 *
 * Six integration kinds backed by the integration_credentials.kind string column.
 * Each kind documents its required payload field shape via requiredFields(),
 * its display label via label(), and its Filament badge color via color().
 *
 * Quick task 260503-rul added OpenAiApi (parity with AnthropicApi shape — single
 * api_key field). OpenAiClient test-connection support deferred; TestIntegrationAction
 * falls through to its default branch and returns "Unknown kind" until wired.
 */
enum IntegrationCredentialKind: string
{
    case SupplierApi = 'supplier_api';
    case WooRest = 'woo_rest';
    case BitrixWebhook = 'bitrix_webhook';
    case AnthropicApi = 'anthropic_api';
    case OpenAiApi = 'openai_api';
    case LangfuseObservability = 'langfuse_observability';
    case SupplierDb = 'supplier_db';

    /**
     * Field names required in payload_encrypted per D-04.
     *
     * @return array<int, string>
     */
    public function requiredFields(): array
    {
        return match ($this) {
            self::SupplierApi => ['base_url', 'username', 'password'],
            self::WooRest => ['base_url', 'consumer_key', 'consumer_secret'],
            self::BitrixWebhook => ['webhook_url'],
            self::AnthropicApi => ['api_key'],
            self::OpenAiApi => ['api_key'],
            self::LangfuseObservability => ['host', 'public_key', 'secret_key'],
            self::SupplierDb => ['host', 'port', 'database', 'username', 'password'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SupplierApi => 'Supplier API (21stcav.com)',
            self::WooRest => 'WooCommerce REST',
            self::BitrixWebhook => 'Bitrix24 Webhook',
            self::AnthropicApi => 'Anthropic Claude API',
            self::OpenAiApi => 'OpenAI / ChatGPT API',
            self::LangfuseObservability => 'Langfuse Observability',
            self::SupplierDb => 'Supplier DB (Remote MySQL)',
        };
    }

    /**
     * Quick task 260504-ld8b — fields that should get URL validation in the form.
     *
     * Defaults to substring-matching on "url" was too aggressive: it caught the
     * MySQL-style `host` field on SupplierDb and rejected raw IPs / hostnames
     * without `https://`. Each kind now explicitly declares its URL fields so
     * the form behaves correctly per integration.
     *
     * Langfuse's `host` IS a URL (Http::get appends /api/public/health) so it
     * stays in the list. SupplierDb's `host` is a MySQL hostname/IP and is NOT
     * URL-validated.
     *
     * @return array<int, string>
     */
    public function urlFields(): array
    {
        return match ($this) {
            self::SupplierApi, self::WooRest => ['base_url'],
            self::BitrixWebhook => ['webhook_url'],
            self::LangfuseObservability => ['host'],
            // AnthropicApi, OpenAiApi: no URL fields (just api_key)
            // SupplierDb: host is MySQL hostname or IP — NOT URL-validated
            self::AnthropicApi, self::OpenAiApi, self::SupplierDb => [],
        };
    }

    /** Filament badge / Stat color per kind. */
    public function color(): string
    {
        return match ($this) {
            self::SupplierApi => 'info',
            self::WooRest => 'warning',
            self::BitrixWebhook => 'success',
            self::AnthropicApi => 'danger', // expensive — visually distinct
            self::OpenAiApi => 'danger', // expensive — parity with Anthropic visual treatment
            self::LangfuseObservability => 'gray',
            self::SupplierDb => 'success', // data-side green palette (Catalogue group)
        };
    }
}
