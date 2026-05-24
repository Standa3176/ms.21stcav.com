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
    case Icecat = 'icecat';
    case ImageSearch = 'image_search';

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
            // Open Icecat needs only the account username; the Full-Icecat
            // api_token + content_token are optionalFields() (resolver returns
            // them in the payload when saved, but they aren't required for the
            // row to be considered valid — Open Icecat works username-only).
            self::Icecat => ['username'],
            // Web image-search provider (Serper.dev by default) — single API key.
            self::ImageSearch => ['api_key'],
        };
    }

    /**
     * Optional, non-required credential fields rendered in the form after the
     * required ones. The resolver returns these in the decrypted payload when
     * present, but they are NOT part of the validity check in requiredFields().
     *
     * Icecat: Full Icecat (non-sponsored brands — Sony/Barco/ViewSonic/etc.)
     * needs an app_key (QUERY param, from the Icecat "My Profile" page) — that
     * is what unlocks Full Icecat content. api_token + content_token are the
     * newer UUID HEADER tokens (optional); only sent when they are valid UUIDs.
     * Open Icecat ignores all three.
     *
     * @return array<int, string>
     */
    public function optionalFields(): array
    {
        return match ($this) {
            self::Icecat => ['app_key', 'content_token', 'api_token'],
            default => [],
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
            self::Icecat => 'Icecat Product Content',
            self::ImageSearch => 'Web Image Search (Serper)',
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
            // Icecat: username + token fields, no URL field
            // ImageSearch: api_key only, no URL field
            self::AnthropicApi, self::OpenAiApi, self::SupplierDb, self::Icecat, self::ImageSearch => [],
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
            self::Icecat => 'info', // content-enrichment source
            self::ImageSearch => 'info', // content-enrichment source
        };
    }
}
