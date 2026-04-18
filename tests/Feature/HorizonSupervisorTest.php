<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

it('defines exactly 7 production supervisors', function () {
    $production = config('horizon.environments.production');

    expect($production)->toBeArray();
    expect(array_keys($production))->toHaveCount(7);

    $expected = [
        'webhook-inbound-supervisor',
        'crm-bitrix-supervisor',
        'sync-woo-push-supervisor',
        'sync-bulk-supervisor',
        'competitor-csv-supervisor',
        'critical-supervisor',
        'default-supervisor',
    ];

    foreach ($expected as $name) {
        expect($production)->toHaveKey($name);
    }
});

it('production supervisors cover all 7 named queues', function () {
    $production = config('horizon.environments.production');
    $allQueues = collect($production)->flatMap(fn ($s) => $s['queue'])->unique()->values();

    $expected = collect(['critical', 'sync-woo-push', 'sync-bulk', 'crm-bitrix', 'competitor-csv', 'webhook-inbound', 'default'])
        ->sort()
        ->values();

    expect($allQueues->sort()->values()->all())->toBe($expected->all());
});

it('respects external rate limit ceilings (Bitrix 2/sec, Woo 100/min)', function () {
    $production = config('horizon.environments.production');

    // Bitrix 2 req/sec hard cap → maxProcesses 2
    expect($production['crm-bitrix-supervisor']['maxProcesses'])->toBe(2);

    // Woo 100 req/min → headroom <= 3 concurrent writers
    expect($production['sync-woo-push-supervisor']['maxProcesses'])->toBeLessThanOrEqual(3);
});

it('webhook-inbound-supervisor timeout is short (webhooks drain fast)', function () {
    expect(config('horizon.environments.production.webhook-inbound-supervisor.timeout'))->toBe(60);
});

it('sync-bulk-supervisor timeout is long (30 min for chunked syncs)', function () {
    expect(config('horizon.environments.production.sync-bulk-supervisor.timeout'))->toBe(1800);
});

it('HorizonServiceProvider gate denies non-admin users', function () {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('read_only');

    $this->actingAs($user);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});

it('HorizonServiceProvider gate grants admin users', function () {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin);

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue();
});

it('local environment has a single all-in-one supervisor', function () {
    $local = config('horizon.environments.local');

    expect($local)->toBeArray();
    expect(array_keys($local))->toHaveCount(1);
    expect($local['all-in-one']['queue'])->toContain('webhook-inbound', 'crm-bitrix', 'default');
});

it('Horizon uses the dedicated horizon Redis connection (DB 2 per Plan 01)', function () {
    expect(config('horizon.use'))->toBe('horizon');
});
