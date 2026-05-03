<?php

declare(strict_types=1);

use App\Domain\CRM\Services\BitrixClient;
use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Models\IntegrationCredential;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    Cache::flush();
    config()->set('services.bitrix.webhook_url', null);
});

it('BitrixClient consumes resolver for the webhook URL (Test 2.8)', function (): void {
    IntegrationCredential::factory()->kind(IntegrationCredentialKind::BitrixWebhook)->create([
        'payload_encrypted' => ['webhook_url' => 'https://b24.example.com/rest/1/test/'],
    ]);

    $resolver = app(IntegrationCredentialResolver::class);
    $creds = $resolver->for(IntegrationCredentialKind::BitrixWebhook);

    expect($creds['webhook_url'])->toBe('https://b24.example.com/rest/1/test/');

    // BitrixClient is now constructible via the container with resolver injected.
    $client = app(BitrixClient::class);
    expect($client)->toBeInstanceOf(BitrixClient::class);
});

it('BitrixClient::testConnection returns failed result when no creds configured', function (): void {
    // No DB row + no env => sdk() throws RuntimeException; testConnection
    // must catch and return failed.
    $client = app(BitrixClient::class);
    $result = $client->testConnection();

    expect($result->ok)->toBeFalse();
    expect($result->error)->not->toBeNull();
});
