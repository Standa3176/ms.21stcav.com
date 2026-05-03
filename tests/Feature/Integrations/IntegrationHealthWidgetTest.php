<?php

declare(strict_types=1);

use App\Domain\Dashboard\Models\DashboardSnapshot;
use App\Domain\Dashboard\Services\SnapshotAggregator;
use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Enums\IntegrationTestStatus;
use App\Domain\Integrations\Models\IntegrationCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('SnapshotAggregator::computeIntegrationHealth returns all 5 kinds (Test 3.8)', function (): void {
    IntegrationCredential::factory()->kind(IntegrationCredentialKind::SupplierApi)->create([
        'last_test_status' => IntegrationTestStatus::Ok,
        'last_test_at' => now(),
    ]);
    IntegrationCredential::factory()->kind(IntegrationCredentialKind::WooRest)->create([
        'last_test_status' => IntegrationTestStatus::Failed,
        'last_test_at' => now(),
    ]);
    IntegrationCredential::factory()->kind(IntegrationCredentialKind::BitrixWebhook)->create();

    $aggregator = app(SnapshotAggregator::class);
    $health = $aggregator->computeIntegrationHealth();

    expect($health)->toHaveCount(5);
    foreach (IntegrationCredentialKind::cases() as $kind) {
        expect($health)->toHaveKey($kind->value);
        expect($health[$kind->value])->toHaveKeys(['status', 'last_test_at']);
    }

    expect($health[IntegrationCredentialKind::SupplierApi->value]['status'])->toBe('ok')
        ->and($health[IntegrationCredentialKind::SupplierApi->value]['last_test_at'])->not->toBeNull();

    expect($health[IntegrationCredentialKind::WooRest->value]['status'])->toBe('failed');

    // Bitrix row exists but never tested → status='unknown', last_test_at=null
    expect($health[IntegrationCredentialKind::BitrixWebhook->value]['status'])->toBe('unknown')
        ->and($health[IntegrationCredentialKind::BitrixWebhook->value]['last_test_at'])->toBeNull();

    // No row for Anthropic / Langfuse → both 'unknown'
    expect($health[IntegrationCredentialKind::AnthropicApi->value]['status'])->toBe('unknown');
    expect($health[IntegrationCredentialKind::LangfuseObservability->value]['status'])->toBe('unknown');
});

it('IntegrationHealthWidget reads dashboard_snapshots metric_key=integration_health (Test 3.7)', function (): void {
    $payload = [
        IntegrationCredentialKind::SupplierApi->value => ['status' => 'ok', 'last_test_at' => now()->toIso8601String()],
        IntegrationCredentialKind::WooRest->value => ['status' => 'failed', 'last_test_at' => now()->toIso8601String()],
        IntegrationCredentialKind::BitrixWebhook->value => ['status' => 'unknown', 'last_test_at' => null],
        IntegrationCredentialKind::AnthropicApi->value => ['status' => 'ok', 'last_test_at' => now()->toIso8601String()],
        IntegrationCredentialKind::LangfuseObservability->value => ['status' => 'ok', 'last_test_at' => now()->toIso8601String()],
    ];

    DashboardSnapshot::create([
        'metric_key' => 'integration_health',
        'metric_value_json' => $payload,
        'computed_at' => now(),
    ]);

    $widget = new \App\Filament\Widgets\IntegrationHealthWidget;

    $reflection = new ReflectionMethod($widget, 'getStats');
    $reflection->setAccessible(true);
    $stats = $reflection->invoke($widget);

    expect($stats)->toHaveCount(5, 'Widget must render 5 tiles — one per integration kind');
});

it('SnapshotAggregator::computeAll includes integration_health metric_key (D-15 wiring)', function (): void {
    $aggregator = app(SnapshotAggregator::class);
    $all = $aggregator->computeAll();

    expect($all)->toHaveKey('integration_health');
    expect($all['integration_health'])->toBeArray()->toHaveCount(5);
});
