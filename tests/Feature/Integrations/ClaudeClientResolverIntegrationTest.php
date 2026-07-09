<?php

declare(strict_types=1);

use App\Domain\Integrations\Clients\ClaudeClient;
use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Models\IntegrationCredential;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    Cache::flush();
    config()->set('prism.providers.anthropic.api_key', null);
});

it('ClaudeClient consumes resolver for the Anthropic api_key (Test 2.9)', function (): void {
    IntegrationCredential::factory()->kind(IntegrationCredentialKind::AnthropicApi)->create([
        'payload_encrypted' => ['api_key' => 'sk-from-db'],
    ]);

    $resolver = app(IntegrationCredentialResolver::class);
    expect($resolver->for(IntegrationCredentialKind::AnthropicApi)['api_key'])->toBe('sk-from-db');

    // ClaudeClient is constructible via the container with the resolver injected.
    $client = app(ClaudeClient::class);
    expect($client)->toBeInstanceOf(ClaudeClient::class);
});

it('ClaudeClient::testConnection returns failed result when api_key missing', function (): void {
    // No DB row + no env => resolver throws; testConnection must catch + return failed.
    $client = app(ClaudeClient::class);
    $result = $client->testConnection();

    expect($result->ok)->toBeFalse();
    expect($result->error)->not->toBeNull();
});
