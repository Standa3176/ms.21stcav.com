<?php

declare(strict_types=1);

use App\Domain\Dashboard\Models\DashboardSnapshot;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Services\WooFieldComparator;
use App\Domain\Sync\Models\SyncDiff;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 05 Task 1 — cutover:divergence-scan (CUT-01)
|--------------------------------------------------------------------------
|
| Covers behaviour tests D1..D8 from 07-05-PLAN:
|   D1 — dry-run does NOT write sync_diffs
|   D2 — --live writes sync_diffs with provider='divergence-scan'
|   D3 — skips products whose Laravel + Woo values match
|   D4 — detects price divergence (sell_price)
|   D5 — detects title divergence (name)
|   D6 — detects missing-in-Woo (empty Woo response)
|   D7 — schedule entry present when env flag enabled
|   D8 — single correlation_id threads through every diff row in one scan
*/

beforeEach(function (): void {
    // Fresh WooClient fake per test — each test rebinds with its own shape.
    $this->wooResponses = [];
    $this->mockWoo = new class($this->wooResponses) extends WooClient
    {
        /** @var array<string, array|Throwable> */
        public array $responses;

        public function __construct(array &$responses)
        {
            $this->responses = &$responses;
        }

        public function get(string $endpoint, array $query = []): array
        {
            $sku = $query['sku'] ?? '__unknown__';
            $entry = $this->responses[$sku] ?? [];
            if ($entry instanceof Throwable) {
                throw $entry;
            }

            return $entry;
        }
    };

    app()->instance(WooClient::class, $this->mockWoo);
});

it('dry-run does NOT write sync_diffs rows', function (): void {
    $p = Product::factory()->create(['sku' => 'ABC-1', 'name' => 'Laravel Title']);
    $this->mockWoo->responses['ABC-1'] = [['sku' => 'ABC-1', 'name' => 'DIFFERENT Title']];

    $exit = Artisan::call('cutover:divergence-scan'); // no --live

    expect($exit)->toBe(0);
    expect(SyncDiff::where('provider', 'divergence-scan')->count())->toBe(0);
});

it('--live writes sync_diffs rows with provider=divergence-scan', function (): void {
    Product::factory()->create(['sku' => 'ABC-1', 'name' => 'Laravel Title']);
    $this->mockWoo->responses['ABC-1'] = [['sku' => 'ABC-1', 'name' => 'DIFFERENT Title']];

    $exit = Artisan::call('cutover:divergence-scan', ['--live' => true]);

    expect($exit)->toBe(0);
    $diff = SyncDiff::where('provider', 'divergence-scan')->first();
    expect($diff)->not->toBeNull();
    expect($diff->payload['field'])->toBe('name');
    expect($diff->payload['laravel'])->toBe('Laravel Title');
    expect($diff->payload['live'])->toBe('DIFFERENT Title');
});

it('skips products whose Laravel and Woo values match (no divergence row)', function (): void {
    Product::factory()->create([
        'sku' => 'IDENT-1',
        'name' => 'Same',
        'slug' => 'same',
        'short_description' => null,
        'long_description' => null,
        'sell_price' => 10.00,
        'image_url' => null,
    ]);
    // 260610-qc4 — fixture extended with stock_status to match real Woo REST
    // shape now that WooFieldComparator covers 13 fields. ProductFactory
    // defaults stock_status='instock'; Woo always returns this top-level
    // column too. Real production Woo never omits it, so the original
    // minimal fixture was an artificial gap. category_id/brand_id/ean/
    // buy_price/stock_quantity stay null on both sides → silent (no diff).
    $this->mockWoo->responses['IDENT-1'] = [[
        'sku' => 'IDENT-1',
        'name' => 'Same',
        'slug' => 'same',
        'short_description' => '',
        'description' => '',
        'price' => '10.00',
        'images' => [],
        'stock_status' => 'instock',
    ]];

    Artisan::call('cutover:divergence-scan', ['--live' => true]);

    expect(SyncDiff::where('provider', 'divergence-scan')->count())->toBe(0);
});

it('detects price divergence and writes a sell_price diff row', function (): void {
    Product::factory()->create(['sku' => 'PRICE-1', 'name' => 'X', 'slug' => 'x', 'sell_price' => 9.99]);
    $this->mockWoo->responses['PRICE-1'] = [[
        'sku' => 'PRICE-1', 'name' => 'X', 'slug' => 'x', 'price' => '8.99',
        'short_description' => '', 'description' => '', 'images' => [],
    ]];

    Artisan::call('cutover:divergence-scan', ['--live' => true]);

    $diff = SyncDiff::where('provider', 'divergence-scan')->first();
    expect($diff)->not->toBeNull();
    expect($diff->payload['field'])->toBe('sell_price');
    expect($diff->payload['laravel'])->toBe(9.99);
    expect($diff->payload['live'])->toBe(8.99);
});

it('detects title divergence', function (): void {
    Product::factory()->create(['sku' => 'TITLE-1', 'name' => 'Alpha', 'slug' => 'alpha', 'sell_price' => 5.00]);
    $this->mockWoo->responses['TITLE-1'] = [[
        'sku' => 'TITLE-1', 'name' => 'Beta', 'slug' => 'alpha', 'price' => '5.00',
        'short_description' => '', 'description' => '', 'images' => [],
    ]];

    Artisan::call('cutover:divergence-scan', ['--live' => true]);

    $nameDiff = SyncDiff::where('provider', 'divergence-scan')
        ->get()
        ->first(fn (SyncDiff $d) => ($d->payload['field'] ?? null) === 'name');
    expect($nameDiff)->not->toBeNull();
    expect($nameDiff->payload['pin_column'])->toBe('pin_title');
});

it('detects missing-in-Woo when the response is empty', function (): void {
    Product::factory()->create(['sku' => 'MISSING-1', 'name' => 'Ghost']);
    $this->mockWoo->responses['MISSING-1'] = []; // empty array — no match in Woo

    Artisan::call('cutover:divergence-scan', ['--live' => true]);

    $diff = SyncDiff::where('provider', 'divergence-scan')->first();
    expect($diff)->not->toBeNull();
    expect($diff->payload['field'])->toBe('exists');
    expect($diff->payload['laravel'])->toBe(true);
    expect($diff->payload['live'])->toBe(false);
});

it('registers cutover:divergence-scan in the artisan registry', function (): void {
    expect(array_keys(Artisan::all()))->toContain('cutover:divergence-scan');
});

it('writes a single correlation_id for every diff row in one scan', function (): void {
    Product::factory()->create(['sku' => 'MULTI-1', 'name' => 'A', 'slug' => 'a']);
    Product::factory()->create(['sku' => 'MULTI-2', 'name' => 'B', 'slug' => 'b']);
    $this->mockWoo->responses['MULTI-1'] = [[
        'sku' => 'MULTI-1', 'name' => 'CHANGED-A', 'slug' => 'a',
        'short_description' => '', 'description' => '', 'price' => '0', 'images' => [],
    ]];
    $this->mockWoo->responses['MULTI-2'] = [[
        'sku' => 'MULTI-2', 'name' => 'CHANGED-B', 'slug' => 'b',
        'short_description' => '', 'description' => '', 'price' => '0', 'images' => [],
    ]];

    Artisan::call('cutover:divergence-scan', ['--live' => true]);

    $correlations = SyncDiff::where('provider', 'divergence-scan')
        ->pluck('correlation_id')
        ->unique();
    expect($correlations)->toHaveCount(1);
});

it('--live writes dashboard_snapshots.sync_diffs_parity with source=cutover:divergence-scan', function (): void {
    Product::factory()->create(['sku' => 'PAR-1', 'name' => 'Same', 'slug' => 's', 'sell_price' => 1.00]);
    // 260610-qc4 — fixture extended with stock_status (see IDENT-1 comment above).
    $this->mockWoo->responses['PAR-1'] = [[
        'sku' => 'PAR-1', 'name' => 'Same', 'slug' => 's', 'price' => '1.00',
        'short_description' => '', 'description' => '', 'images' => [],
        'stock_status' => 'instock',
    ]];

    Artisan::call('cutover:divergence-scan', ['--live' => true]);

    $snap = DashboardSnapshot::where('metric_key', 'sync_diffs_parity')->first();
    expect($snap)->not->toBeNull();
    expect($snap->metric_value_json['source'])->toBe('cutover:divergence-scan');
    expect($snap->metric_value_json['parity_percent'])->toBe(100);
});
