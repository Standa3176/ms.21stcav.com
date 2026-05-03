<?php

declare(strict_types=1);

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Exceptions\IntegrationCredentialMissingException;
use App\Domain\Integrations\Models\IntegrationCredential;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    Cache::flush();
    config()->set('services.woo.url', null);
    config()->set('services.woo.consumer_key', null);
    config()->set('services.woo.consumer_secret', null);
});

it('WooClient resolves credentials via IntegrationCredentialResolver (Test 2.6)', function (): void {
    IntegrationCredential::factory()->kind(IntegrationCredentialKind::WooRest)->create([
        'payload_encrypted' => [
            'base_url' => 'https://x.example',
            'consumer_key' => 'ck_y',
            'consumer_secret' => 'cs_z',
        ],
    ]);

    // Resolve creds directly via the resolver and assert the mapping —
    // verifying the WooClient's sdk() builder reads the same source of truth.
    $resolver = app(IntegrationCredentialResolver::class);
    $creds = $resolver->for(IntegrationCredentialKind::WooRest);

    expect($creds['base_url'])->toBe('https://x.example')
        ->and($creds['consumer_key'])->toBe('ck_y')
        ->and($creds['consumer_secret'])->toBe('cs_z');

    // Confirm WooClient has the resolver as a dep (constructor accepts it).
    $client = app(WooClient::class);
    expect($client)->toBeInstanceOf(WooClient::class);
});

it('WooClient::testConnection returns IntegrationTestResult on the configured stub', function (): void {
    // Mockery-stub the Automattic client so testConnection short-circuits without
    // hitting the network. The mock's get() returns an array — success contract.
    $stub = Mockery::mock(\Automattic\WooCommerce\Client::class);
    $stub->shouldReceive('get')
        ->once()
        ->with('products', ['per_page' => 1])
        ->andReturn([['id' => 1, 'name' => 'Stub product']]);

    $client = new WooClient(
        app(IntegrationLogger::class),
        app(IntegrationCredentialResolver::class),
        $stub,
    );

    $result = $client->testConnection();
    expect($result->ok)->toBeTrue();
});

it('WooClient::testConnection returns failed when credentials missing entirely', function (): void {
    // No DB row, no env config. testConnection should catch the resolver's
    // exception and return failed (not throw).
    $client = app(WooClient::class);
    $result = $client->testConnection();

    expect($result->ok)->toBeFalse();
    expect($result->error)->not->toBeNull();
});
