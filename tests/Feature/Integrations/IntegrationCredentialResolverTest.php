<?php

declare(strict_types=1);

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Exceptions\IntegrationCredentialMissingException;
use App\Domain\Integrations\Models\IntegrationCredential;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Use the array cache driver so per-test cache state is isolated.
    config()->set('cache.default', 'array');
    Cache::flush();

    // Wipe known env-fallback config keys so each test starts from a known state.
    config()->set('services.supplier.url', null);
    config()->set('services.supplier.username', null);
    config()->set('services.supplier.password', null);
    config()->set('services.woo.url', null);
    config()->set('services.woo.consumer_key', null);
    config()->set('services.woo.consumer_secret', null);
    config()->set('services.bitrix.webhook_url', null);
    config()->set('prism.providers.anthropic.api_key', null);
    config()->set('services.openai.api_key', null);
    config()->set('agents.langfuse.host', null);
    config()->set('agents.langfuse.public_key', null);
    config()->set('agents.langfuse.secret_key', null);
});

/**
 * Phase 09.1 Plan 01 Task 2 Tests 2.1, 2.2, 2.3, 2.4, 2.5, 2.10.
 *
 * Verifies the IntegrationCredentialResolver contract per CONTEXT D-06 + D-08.
 */

it('returns DB row payload when an active row exists for the kind (Test 2.1 — DB beats env)', function (): void {
    IntegrationCredential::factory()->kind(IntegrationCredentialKind::SupplierApi)
        ->create([
            'payload_encrypted' => [
                'base_url' => 'https://db-supplier.example',
                'username' => 'db-user',
                'password' => 'db-pass',
            ],
        ]);

    config()->set('services.supplier.url', 'https://env-supplier.example');
    config()->set('services.supplier.username', 'env-user');
    config()->set('services.supplier.password', 'env-pass');

    $resolver = app(IntegrationCredentialResolver::class);
    $creds = $resolver->for(IntegrationCredentialKind::SupplierApi);

    expect($creds['base_url'])->toBe('https://db-supplier.example')
        ->and($creds['username'])->toBe('db-user')
        ->and($creds['password'])->toBe('db-pass');
});

it('falls back to env config when no DB row exists (Test 2.2 — env fallback)', function (): void {
    config()->set('services.supplier.url', 'https://env-only.example');
    config()->set('services.supplier.username', 'env-user');
    config()->set('services.supplier.password', 'env-pass');

    $resolver = app(IntegrationCredentialResolver::class);
    $creds = $resolver->for(IntegrationCredentialKind::SupplierApi);

    expect($creds)->toBe([
        'base_url' => 'https://env-only.example',
        'username' => 'env-user',
        'password' => 'env-pass',
    ]);
});

it('throws IntegrationCredentialMissingException when both DB + env are empty (Test 2.3)', function (): void {
    $resolver = app(IntegrationCredentialResolver::class);

    expect(fn () => $resolver->for(IntegrationCredentialKind::SupplierApi))
        ->toThrow(IntegrationCredentialMissingException::class);
});

it('caches per-kind for 60s and the observer invalidates on Eloquent save (Test 2.4)', function (): void {
    $row = IntegrationCredential::factory()->kind(IntegrationCredentialKind::AnthropicApi)
        ->create(['payload_encrypted' => ['api_key' => 'v1']]);

    $resolver = app(IntegrationCredentialResolver::class);
    expect($resolver->for(IntegrationCredentialKind::AnthropicApi)['api_key'])->toBe('v1');

    // Bypass Eloquent — observer does NOT fire — cached value stays warm.
    DB::table('integration_credentials')
        ->where('id', $row->id)
        ->update(['payload_encrypted' => encrypt(json_encode(['api_key' => 'v2-raw']))]);

    expect($resolver->for(IntegrationCredentialKind::AnthropicApi)['api_key'])
        ->toBe('v1', 'Cache hit must persist when observer not triggered');

    // Now go through Eloquent — observer fires + invalidates cache.
    $row->update(['payload_encrypted' => ['api_key' => 'v3-via-eloquent']]);

    expect($resolver->for(IntegrationCredentialKind::AnthropicApi)['api_key'])
        ->toBe('v3-via-eloquent', 'Observer must invalidate cache on save');
});

it('env fallback resolves a correctly-shaped payload for every kind (Test 2.5)', function (): void {
    $resolver = app(IntegrationCredentialResolver::class);

    config()->set('services.supplier.url', 'https://s.example');
    config()->set('services.supplier.username', 'u');
    config()->set('services.supplier.password', 'p');
    expect($resolver->for(IntegrationCredentialKind::SupplierApi))
        ->toBe(['base_url' => 'https://s.example', 'username' => 'u', 'password' => 'p']);

    Cache::flush();
    config()->set('services.woo.url', 'https://w.example');
    config()->set('services.woo.consumer_key', 'ck');
    config()->set('services.woo.consumer_secret', 'cs');
    expect($resolver->for(IntegrationCredentialKind::WooRest))
        ->toBe(['base_url' => 'https://w.example', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']);

    Cache::flush();
    config()->set('services.bitrix.webhook_url', 'https://b24.example/rest/1/abc/');
    expect($resolver->for(IntegrationCredentialKind::BitrixWebhook))
        ->toBe(['webhook_url' => 'https://b24.example/rest/1/abc/']);

    Cache::flush();
    config()->set('prism.providers.anthropic.api_key', 'sk-ant-env');
    expect($resolver->for(IntegrationCredentialKind::AnthropicApi))
        ->toBe(['api_key' => 'sk-ant-env']);

    Cache::flush();
    config()->set('agents.langfuse.host', 'https://lf.example');
    config()->set('agents.langfuse.public_key', 'pk');
    config()->set('agents.langfuse.secret_key', 'sk');
    expect($resolver->for(IntegrationCredentialKind::LangfuseObservability))
        ->toBe(['host' => 'https://lf.example', 'public_key' => 'pk', 'secret_key' => 'sk']);
});

it('caches per-kind, NOT globally — invalidating one kind preserves another (Test 2.10)', function (): void {
    IntegrationCredential::factory()->kind(IntegrationCredentialKind::SupplierApi)
        ->create(['payload_encrypted' => ['base_url' => 'https://s.example', 'username' => 'u', 'password' => 'p']]);
    $anthropic = IntegrationCredential::factory()->kind(IntegrationCredentialKind::AnthropicApi)
        ->create(['payload_encrypted' => ['api_key' => 'sk-1']]);

    $resolver = app(IntegrationCredentialResolver::class);

    // Warm both caches.
    expect($resolver->for(IntegrationCredentialKind::SupplierApi)['username'])->toBe('u');
    expect($resolver->for(IntegrationCredentialKind::AnthropicApi)['api_key'])->toBe('sk-1');

    // Trigger observer on AnthropicApi only — should invalidate ONLY anthropic.
    $anthropic->update(['payload_encrypted' => ['api_key' => 'sk-2']]);

    expect(Cache::has(IntegrationCredentialResolver::cacheKeyFor(IntegrationCredentialKind::AnthropicApi)))
        ->toBeFalse('AnthropicApi cache should be invalidated');
    expect(Cache::has(IntegrationCredentialResolver::cacheKeyFor(IntegrationCredentialKind::SupplierApi)))
        ->toBeTrue('SupplierApi cache should remain warm — per-kind isolation');
});
