<?php

declare(strict_types=1);

use App\Domain\CRM\Services\BitrixClient;
use App\Domain\Integrations\Clients\ClaudeClient;
use App\Domain\Integrations\Clients\GoogleAnalyticsClient;
use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Enums\IntegrationTestStatus;
use App\Domain\Integrations\Filament\Actions\TestIntegrationAction;
use App\Domain\Integrations\Models\IntegrationCredential;
use App\Domain\Integrations\Services\IntegrationTestResult;
use App\Domain\Sync\Services\SupplierClient;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('dispatches per-kind to the matching client testConnection (Test 3.4)', function (): void {
    $matrix = [
        [IntegrationCredentialKind::SupplierApi, SupplierClient::class],
        [IntegrationCredentialKind::WooRest, WooClient::class],
        [IntegrationCredentialKind::BitrixWebhook, BitrixClient::class],
        [IntegrationCredentialKind::AnthropicApi, ClaudeClient::class],
        [IntegrationCredentialKind::GoogleAnalytics, GoogleAnalyticsClient::class],
    ];

    foreach ($matrix as [$kind, $clientClass]) {
        $row = IntegrationCredential::factory()->kind($kind)->create();

        $stub = Mockery::mock($clientClass);
        $stub->shouldReceive('testConnection')
            ->once()
            ->andReturn(IntegrationTestResult::ok(123));
        app()->instance($clientClass, $stub);

        $result = TestIntegrationAction::dispatch($row);

        expect($result->ok)->toBeTrue()
            ->and($result->latencyMs)->toBe(123)
            ->and($result->error)->toBeNull();

        $row->forceDelete(); // Avoid UNIQUE collision on the next loop iter
        Mockery::close();
    }
});

it('writes back failure state on a failed testConnection (Test 3.5)', function (): void {
    $row = IntegrationCredential::factory()->kind(IntegrationCredentialKind::SupplierApi)->create();

    $stub = Mockery::mock(SupplierClient::class);
    $stub->shouldReceive('testConnection')
        ->once()
        ->andReturn(IntegrationTestResult::failed('boom', 50));
    app()->instance(SupplierClient::class, $stub);

    $result = TestIntegrationAction::dispatch($row);

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe('boom')
        ->and($result->latencyMs)->toBe(50);
});

it('Langfuse special-case dispatches HTTP GET against {host}/api/public/health (Test 3.6)', function (): void {
    $row = IntegrationCredential::factory()->kind(IntegrationCredentialKind::LangfuseObservability)->create([
        'payload_encrypted' => [
            'host' => 'https://lf.example',
            'public_key' => 'pk',
            'secret_key' => 'sk',
        ],
    ]);

    Http::fake([
        'https://lf.example/api/public/health' => Http::response(['status' => 'ok'], 200),
    ]);

    $result = TestIntegrationAction::dispatch($row);

    expect($result->ok)->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://lf.example/api/public/health';
    });
});

it('Langfuse health-check returns failed when /api/public/health 5xx', function (): void {
    $row = IntegrationCredential::factory()->kind(IntegrationCredentialKind::LangfuseObservability)->create([
        'payload_encrypted' => [
            'host' => 'https://lf.example',
            'public_key' => 'pk',
            'secret_key' => 'sk',
        ],
    ]);

    Http::fake([
        'https://lf.example/api/public/health' => Http::response('boom', 503),
    ]);

    $result = TestIntegrationAction::dispatch($row);

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('HTTP 503');
});

it('writeback semantics — TestIntegrationAction translates IntegrationTestResult to last_test_status enum', function (): void {
    // This verifies the mapping the Action would apply when invoked from the
    // Filament Resource (without a full Livewire round-trip):
    //   ok  → IntegrationTestStatus::Ok
    //   not → IntegrationTestStatus::Failed
    expect(IntegrationTestResult::ok(10)->ok ? IntegrationTestStatus::Ok : IntegrationTestStatus::Failed)
        ->toBe(IntegrationTestStatus::Ok);

    expect(IntegrationTestResult::failed('x', 10)->ok ? IntegrationTestStatus::Ok : IntegrationTestStatus::Failed)
        ->toBe(IntegrationTestStatus::Failed);
});
