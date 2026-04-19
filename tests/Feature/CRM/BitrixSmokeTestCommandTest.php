<?php

declare(strict_types=1);

use App\Domain\CRM\Console\Commands\BitrixSmokeTestCommand;
use App\Foundation\Integration\Models\IntegrationEvent;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 01 Task 1 — bitrix:smoke-test guarded sandbox probe
|--------------------------------------------------------------------------
|
| The smoke-test command walks the SDK's concrete method signatures against
| a real tenant before Plan 04-02 locks the BitrixClient wrapper interface.
| Two-layer gate protects production (BITRIX_SMOKE_TEST_ALLOWED + WEBHOOK_URL).
|
| Tests fake the command's internal `probe()` helper so we never hit Bitrix
| during CI — the command is designed so probes are overridable.
*/

it('blocks when BITRIX_SMOKE_TEST_ALLOWED is false', function (): void {
    config(['services.bitrix.smoke_test_allowed' => false]);

    $this->artisan('bitrix:smoke-test')
        ->expectsOutputToContain('BitrixSmokeTestCommand: blocked')
        ->assertExitCode(1);
});

it('runs 7 probes when flag enabled and records 7 integration_events rows', function (): void {
    config([
        'services.bitrix.smoke_test_allowed' => true,
        'services.bitrix.webhook_url' => 'https://example.bitrix24.com/rest/1/fake-token/',
    ]);

    // Install a test-only probe runner that returns deterministic results
    // WITHOUT calling the SDK. The real probe helper is replaced via a
    // container binding so the 7-probe flow is exercised end-to-end.
    $this->app->instance(BitrixSmokeTestCommand::PROBE_RUNNER_KEY, function (string $sdkMethod, array $args, string $correlationId): array {
        app(\App\Foundation\Integration\Services\IntegrationLogger::class)->log([
            'channel' => 'bitrix',
            'endpoint' => $sdkMethod,
            'direction' => 'outbound',
            'correlation_id' => $correlationId,
            'method' => 'POST',
            'operation' => 'smoke-test:'.$sdkMethod,
            'http_status' => 200,
            'status' => 'success',
        ]);

        return ['ok' => true, 'count' => 0];
    });

    $this->artisan('bitrix:smoke-test')->assertExitCode(0);

    expect(IntegrationEvent::where('channel', 'bitrix')->count())->toBe(7);

    $cids = IntegrationEvent::where('channel', 'bitrix')->pluck('correlation_id')->unique();
    expect($cids)->toHaveCount(1);
});

it('returns exit code 1 when any probe throws', function (): void {
    config([
        'services.bitrix.smoke_test_allowed' => true,
        'services.bitrix.webhook_url' => 'https://example.bitrix24.com/rest/1/fake-token/',
    ]);

    // One probe throws — command must return non-zero even though
    // the other 6 probe rows succeed (so ops sees red in CI).
    $this->app->instance(BitrixSmokeTestCommand::PROBE_RUNNER_KEY, function (string $sdkMethod, array $args, string $correlationId): array {
        if ($sdkMethod === 'crm.duplicate.findbycomm') {
            throw new RuntimeException('Simulated probe failure');
        }

        return ['ok' => true, 'count' => 0];
    });

    $this->artisan('bitrix:smoke-test')->assertExitCode(1);
});
