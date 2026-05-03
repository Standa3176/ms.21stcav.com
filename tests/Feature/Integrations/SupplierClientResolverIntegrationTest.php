<?php

declare(strict_types=1);

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Models\IntegrationCredential;
use App\Domain\Sync\Services\SupplierClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    Cache::flush();
    config()->set('services.supplier.url', null);
    config()->set('services.supplier.username', null);
    config()->set('services.supplier.password', null);
});

it('SupplierClient::testConnection POSTs against the resolver-supplied base_url + creds (Test 2.7)', function (): void {
    IntegrationCredential::factory()->kind(IntegrationCredentialKind::SupplierApi)->create([
        'payload_encrypted' => [
            'base_url' => 'https://db-supplier.example',
            'username' => 'db-user',
            'password' => 'db-pass',
        ],
    ]);

    Http::fake([
        'https://db-supplier.example/generate_token.php' => Http::response(['token' => 'jwt-test'], 200),
    ]);

    $client = app(SupplierClient::class);
    $result = $client->testConnection();

    expect($result->ok)->toBeTrue();
    expect($result->error)->toBeNull();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://db-supplier.example/generate_token.php'
            && $request['username'] === 'db-user'
            && $request['password'] === 'db-pass';
    });
});

it('SupplierClient::testConnection works via env fallback when no DB row exists (Test 2.7 env path)', function (): void {
    config()->set('services.supplier.url', 'https://env-supplier.example');
    config()->set('services.supplier.username', 'env-user');
    config()->set('services.supplier.password', 'env-pass');

    Http::fake([
        'https://env-supplier.example/generate_token.php' => Http::response(['token' => 'jwt-env'], 200),
    ]);

    $client = app(SupplierClient::class);
    $result = $client->testConnection();

    expect($result->ok)->toBeTrue();

    Http::assertSent(function ($request) {
        return str_starts_with($request->url(), 'https://env-supplier.example/');
    });
});

it('SupplierClient::testConnection returns failed result when token missing in response', function (): void {
    IntegrationCredential::factory()->kind(IntegrationCredentialKind::SupplierApi)->create([
        'payload_encrypted' => [
            'base_url' => 'https://db.example',
            'username' => 'u',
            'password' => 'p',
        ],
    ]);

    Http::fake([
        'https://db.example/generate_token.php' => Http::response(['error' => 'invalid'], 401),
    ]);

    $result = app(SupplierClient::class)->testConnection();

    expect($result->ok)->toBeFalse();
    expect($result->error)->toContain('HTTP 401');
});
