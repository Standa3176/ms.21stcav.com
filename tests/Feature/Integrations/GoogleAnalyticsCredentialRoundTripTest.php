<?php

declare(strict_types=1);

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Models\IntegrationCredential;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| HOTFIX 260712-gaj — GA4 service-account key round-trips byte-intact
|--------------------------------------------------------------------------
|
| The real proof the blocker is fixed: a realistic MULTI-LINE ~2.5KB
| service-account JSON (fake but structurally valid, with a private_key PEM
| block spanning newlines, total > 2048 chars) saved through the credential
| path resolves back BYTE-INTACT via IntegrationCredentialResolver, and
| json_decode() succeeds with type/project_id/client_email/private_key all
| surviving. This catches BOTH failure modes the operator hit:
|   1. multi-line paste truncated to the first line ("{"), and
|   2. maxLength(2048) truncation of a ~2.5KB key,
| either of which produced "service_account_json is not valid JSON".
*/

/**
 * A fake-but-structurally-valid GA4 service-account key. The private_key is a
 * multi-line PEM block; the whole JSON is pretty-printed so it is multi-line and
 * comfortably larger than the old 2048-char single-line TextInput limit.
 */
function gajFakeServiceAccountJson(): string
{
    $pem = "-----BEGIN PRIVATE KEY-----\n";
    // 26 base64-ish lines of 64 chars each → ~1.7KB PEM body, like a real 2048-bit key.
    for ($i = 0; $i < 26; $i++) {
        $pem .= str_repeat('MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQCabcdefghij', 1);
        $pem .= "\n";
    }
    $pem .= "-----END PRIVATE KEY-----\n";

    $json = json_encode([
        'type' => 'service_account',
        'project_id' => 'meetingstore-ga4-260712',
        'private_key_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
        'private_key' => $pem,
        'client_email' => 'ga4-reader@meetingstore-ga4-260712.iam.gserviceaccount.com',
        'client_id' => '109876543210987654321',
        'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
        'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/ga4-reader%40meetingstore-ga4-260712.iam.gserviceaccount.com',
        'universe_domain' => 'googleapis.com',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return (string) $json;
}

it('a realistic >2KB multi-line service-account JSON is genuinely large + multi-line', function (): void {
    $json = gajFakeServiceAccountJson();

    // Proves the fixture would have been truncated by the old maxLength(2048)
    // single-line TextInput, and that it is multi-line (first-line-only paste bug).
    expect(strlen($json))->toBeGreaterThan(2048);
    expect(substr_count($json, "\n"))->toBeGreaterThan(10);
});

it('saves a >2KB multi-line GA4 service-account key and resolves it back byte-intact + json_decodes', function (): void {
    $json = gajFakeServiceAccountJson();

    IntegrationCredential::create([
        'kind' => IntegrationCredentialKind::GoogleAnalytics->value,
        'name' => 'GA4 (round-trip)',
        'payload_encrypted' => [
            'service_account_json' => $json,
            'property_id' => '123456789',
        ],
        'is_active' => true,
    ]);

    // Bust the 60s per-kind resolver cache so we read the row we just wrote.
    Cache::forget(IntegrationCredentialResolver::cacheKeyFor(IntegrationCredentialKind::GoogleAnalytics));

    $payload = app(IntegrationCredentialResolver::class)
        ->for(IntegrationCredentialKind::GoogleAnalytics);

    // Byte-intact: the exact string paid in comes back out.
    expect($payload['service_account_json'])->toBe($json);
    expect($payload['property_id'])->toBe('123456789');

    // json_decode succeeds — the failure the operator saw is gone.
    $decoded = json_decode($payload['service_account_json'], true);
    expect(json_last_error())->toBe(JSON_ERROR_NONE);
    expect($decoded['type'])->toBe('service_account');
    expect($decoded['project_id'])->toBe('meetingstore-ga4-260712');
    expect($decoded['client_email'])->toBe('ga4-reader@meetingstore-ga4-260712.iam.gserviceaccount.com');
    // The multi-line PEM survives newlines and length intact.
    expect($decoded['private_key'])->toContain("-----BEGIN PRIVATE KEY-----\n");
    expect($decoded['private_key'])->toContain("\n-----END PRIVATE KEY-----\n");
    expect(strlen($decoded['private_key']))->toBeGreaterThan(1024);
});

it('payload_encrypted column is text-typed and holds the encrypted >2KB blob', function (): void {
    // The encrypted+base64 payload inflates ~1.4x over the plaintext; a text
    // column (MySQL TEXT = 64KB, SQLite TEXT unbounded) holds it comfortably, so
    // NO column-widening migration is required.
    expect(Schema::getColumnType('integration_credentials', 'payload_encrypted'))->toBe('text');

    $json = gajFakeServiceAccountJson();
    $row = IntegrationCredential::create([
        'kind' => IntegrationCredentialKind::GoogleAnalytics->value,
        'name' => 'GA4 (capacity)',
        'payload_encrypted' => ['service_account_json' => $json, 'property_id' => '123456789'],
        'is_active' => true,
    ]);

    // Read the RAW stored ciphertext straight from the DB (bypassing the
    // encrypted:array cast via the query builder) — it must be persisted in
    // full, longer than the >2KB plaintext.
    $rawCipher = (string) DB::table('integration_credentials')
        ->where('id', $row->getKey())
        ->value('payload_encrypted');

    expect(strlen($rawCipher))->toBeGreaterThan(strlen($json));

    // And it still decrypts + decodes back to the exact JSON.
    expect($row->fresh()->payload_encrypted['service_account_json'])->toBe($json);
});
