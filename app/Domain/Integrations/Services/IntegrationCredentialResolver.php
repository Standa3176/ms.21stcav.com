<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Services;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Exceptions\IntegrationCredentialMissingException;
use App\Domain\Integrations\Models\IntegrationCredential;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 09.1 Plan 01 — IntegrationCredentialResolver (D-06).
 *
 * Single source of truth for runtime credential resolution. Lookup order:
 *   1. DB row WHERE kind=$kind AND is_active=true → decrypted payload_encrypted
 *   2. env fallback via config('services.X') / config('agents.langfuse.X')
 *   3. throw IntegrationCredentialMissingException (D-06 case 3)
 *
 * Cached per-kind for 60s (Cache::remember). Cache invalidated by
 * IntegrationCredentialObserver on every save/delete/forceDelete event so
 * operator credential rotation takes effect within ≤60s.
 *
 * D-08 — env fallback is PERMANENT, not deprecation-targeted. CI/test envs
 * + initial-deployment ergonomics rely on it. DB row wins; absence falls back.
 */
class IntegrationCredentialResolver
{
    public const CACHE_TTL_SECONDS = 60;

    public const CACHE_KEY_PREFIX = 'integrations.cred.';

    /**
     * Resolve credentials for the given integration kind.
     *
     * @return array<string, string> Field map per IntegrationCredentialKind::requiredFields()
     *
     * @throws IntegrationCredentialMissingException
     */
    public function for(IntegrationCredentialKind $kind): array
    {
        $cacheKey = self::cacheKeyFor($kind);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($kind): array {
            // Step 1: DB row wins.
            $row = IntegrationCredential::query()
                ->where('kind', $kind->value)
                ->where('is_active', true)
                ->first();

            if ($row !== null) {
                $payload = $row->payload_encrypted; // already decoded via 'encrypted:array' cast
                if (is_array($payload) && $this->payloadHasAllRequiredFields($kind, $payload)) {
                    return $payload;
                }
            }

            // Step 2: env fallback per kind.
            $fallback = $this->resolveFromEnv($kind);
            if ($fallback !== null) {
                return $fallback;
            }

            // Step 3: nothing available.
            throw IntegrationCredentialMissingException::for($kind);
        });
    }

    public static function cacheKeyFor(IntegrationCredentialKind $kind): string
    {
        return self::CACHE_KEY_PREFIX . $kind->value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHasAllRequiredFields(IntegrationCredentialKind $kind, array $payload): bool
    {
        foreach ($kind->requiredFields() as $field) {
            if (! array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveFromEnv(IntegrationCredentialKind $kind): ?array
    {
        $envMap = match ($kind) {
            IntegrationCredentialKind::SupplierApi => [
                'base_url' => (string) config('services.supplier.url', ''),
                'username' => (string) config('services.supplier.username', ''),
                'password' => (string) config('services.supplier.password', ''),
            ],
            IntegrationCredentialKind::WooRest => [
                'base_url' => (string) config('services.woo.url', ''),
                'consumer_key' => (string) config('services.woo.consumer_key', ''),
                'consumer_secret' => (string) config('services.woo.consumer_secret', ''),
            ],
            IntegrationCredentialKind::BitrixWebhook => [
                'webhook_url' => (string) config('services.bitrix.webhook_url', ''),
            ],
            IntegrationCredentialKind::AnthropicApi => [
                'api_key' => (string) config('prism.providers.anthropic.api_key', ''),
            ],
            IntegrationCredentialKind::OpenAiApi => [
                'api_key' => (string) config('services.openai.api_key', ''),
            ],
            IntegrationCredentialKind::LangfuseObservability => [
                'host' => (string) config('agents.langfuse.host', ''),
                'public_key' => (string) config('agents.langfuse.public_key', ''),
                'secret_key' => (string) config('agents.langfuse.secret_key', ''),
            ],
            IntegrationCredentialKind::SupplierDb => [
                'host' => (string) config('services.supplier_db.host', ''),
                'port' => (string) config('services.supplier_db.port', '3306'),
                'database' => (string) config('services.supplier_db.database', ''),
                'username' => (string) config('services.supplier_db.username', ''),
                'password' => (string) config('services.supplier_db.password', ''),
            ],
            IntegrationCredentialKind::Icecat => [
                'username' => (string) config('services.icecat.username', ''),
                // Optional (Full Icecat). Empty string when unset — IcecatClient
                // treats blanks as absent and falls back to Open Icecat. app_key
                // (My Profile page) unlocks Full content; tokens are UUID headers.
                'app_key' => (string) config('services.icecat.app_key', ''),
                'api_token' => (string) config('services.icecat.api_token', ''),
                'content_token' => (string) config('services.icecat.content_token', ''),
            ],
            IntegrationCredentialKind::EanSearch => [
                'token' => (string) config('services.ean_search.token', ''),
            ],
            IntegrationCredentialKind::ImageSearch => [
                'api_key' => (string) config('services.image_search.api_key', ''),
            ],
            IntegrationCredentialKind::GoogleAnalytics => [
                'service_account_json' => (string) config('services.google_analytics.service_account_json', ''),
                'property_id' => (string) config('services.google_analytics.property_id', ''),
            ],
        };

        return $this->payloadHasAllRequiredFields($kind, $envMap) ? $envMap : null;
    }
}
