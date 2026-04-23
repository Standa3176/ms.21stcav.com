<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 01 Task 1 — SupplierProbeSingleSkuCommand
|--------------------------------------------------------------------------
| Q1 probe artisan command. Http::fake() stubs the supplier API; the command
| must call /api/index.php?endpoint=products&sku={sku}&per_page=1 and dump
| the FULL decoded first-row to storage/app/research/supplier-probe.json
| (raw shape — no field filtering).
*/

beforeEach(function (): void {
    Cache::flush();
    config([
        'services.supplier.url' => 'https://fake-supplier.test',
        'services.supplier.username' => 'probeuser',
        'services.supplier.password' => 'probepass',
    ]);

    // Ensure no stale probe from a previous run lingers.
    $path = storage_path('app/research/supplier-probe.json');
    if (file_exists($path)) {
        unlink($path);
    }
});

afterEach(function (): void {
    $path = storage_path('app/research/supplier-probe.json');
    if (file_exists($path)) {
        unlink($path);
    }
});

it('fetches a single SKU and dumps the full supplier row to storage/app/research/supplier-probe.json', function (): void {
    Http::fake([
        'https://fake-supplier.test/generate_token.php' => Http::response(
            ['token' => 'probe-jwt', 'expires_in' => 3600],
            200
        ),
        'https://fake-supplier.test/api/index.php*' => Http::response([
            'data' => [[
                'sku' => 'PROBE-001',
                'price' => '199.00',
                'stock' => 7,
                'name' => 'Probe Test Cam',
                'brand' => 'Poly',
                'category' => 'Cameras',
                'description' => 'Full supplier description pass-through.',
                'image_url' => 'https://cdn.example/probe-001.jpg',
                'image_fallback_urls' => ['https://cdn.example/probe-001-alt.jpg'],
                'features' => ['A', 'B', 'C'],
            ]],
            'next_page' => null,
        ], 200),
    ]);

    $this->artisan('supplier:probe-single-sku', ['sku' => 'PROBE-001'])
        ->expectsOutputToContain('Probe response written to: storage/app/research/supplier-probe.json')
        ->assertSuccessful();

    $path = storage_path('app/research/supplier-probe.json');
    expect(file_exists($path))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);
    expect($decoded)
        ->toBeArray()
        ->and($decoded['sku'])->toBe('PROBE-001')
        ->and($decoded['price'])->toBe('199.00')
        ->and($decoded['stock'])->toBe(7)
        ->and($decoded['brand'])->toBe('Poly')
        ->and($decoded['image_url'])->toBe('https://cdn.example/probe-001.jpg')
        ->and($decoded['image_fallback_urls'])->toBe(['https://cdn.example/probe-001-alt.jpg'])
        ->and($decoded['features'])->toBe(['A', 'B', 'C']);
});

it('emits a warning when the supplier has no data for the SKU', function (): void {
    Http::fake([
        'https://fake-supplier.test/generate_token.php' => Http::response(
            ['token' => 'probe-jwt', 'expires_in' => 3600],
            200
        ),
        'https://fake-supplier.test/api/index.php*' => Http::response(
            ['data' => [], 'next_page' => null],
            200
        ),
    ]);

    $this->artisan('supplier:probe-single-sku', ['sku' => 'UNKNOWN-SKU'])
        ->expectsOutputToContain('Supplier returned no data for SKU: UNKNOWN-SKU')
        ->expectsOutputToContain('Probe response written to: storage/app/research/supplier-probe.json')
        ->assertSuccessful();

    // File still written — an empty [] JSON is the canonical "miss" signal.
    $path = storage_path('app/research/supplier-probe.json');
    expect(file_exists($path))->toBeTrue();
    expect(json_decode((string) file_get_contents($path), true))->toBe([]);
});
