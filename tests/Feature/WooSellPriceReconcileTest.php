<?php

declare(strict_types=1);

use App\Domain\Cutover\Services\DivergenceScanner;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Services\WooFieldComparator;
use App\Domain\Sync\Models\SyncDiff;
use App\Domain\Sync\Services\WooClient;
use App\Domain\Sync\Services\WooProductWriter;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| 260822-rmo — sell_price reconciliation (MS is the source of truth)
|--------------------------------------------------------------------------
|
| WooFieldComparator already DETECTED sell_price divergence; nothing could
| correct it, so every price the event-driven push lost to the write throttle
| stayed diverged forever. These cases pin the correction half:
|
|   - internal price wins, and lands on Woo `regular_price`
|   - VAT basis matches PushPriceChangeToWoo (inc-VAT unless configured out)
|   - equal prices produce NO write (idempotent, no churn)
|   - decimal/string formatting does not manufacture false mismatches
|   - an operator's live Woo SALE is never overwritten
*/

beforeEach(function (): void {
    config(['services.woo.push_prices_ex_vat' => false]);
});

/**
 * WooClient stub: canned GET dict, recorded PUTs. Mirrors the
 * bindDivergenceStub pattern in PushDivergenceToWooCommandTest.
 */
function sellPriceWooStub(array $getResponses): WooClient
{
    return new class($getResponses) extends WooClient
    {
        /** @var array<int, array{endpoint:string, payload:array<string,mixed>}> */
        public array $putCalls = [];

        public function __construct(public array $getResponses)
        {
            // Skip parent constructor — stub needs no logger/resolver.
        }

        public function get(string $endpoint, array $query = []): array
        {
            $wooId = preg_match('#^products/(\d+)$#', $endpoint, $m) ? (int) $m[1] : 0;

            return $this->getResponses[$wooId] ?? [];
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->putCalls[] = ['endpoint' => $endpoint, 'payload' => $payload];

            return ['id' => 1];
        }
    };
}

function sellPriceWriter(WooClient $woo): WooProductWriter
{
    return new WooProductWriter($woo, app(App\Domain\Sync\Contracts\SellPriceFormatter::class));
}

// ── Detection: no false mismatches ────────────────────────────────────────

it('sees no divergence when the internal price already matches Woo', function (): void {
    $product = Product::factory()->make(['sell_price' => 12.99]);

    // Woo returns price as a STRING; a naive === would flag every product.
    $diffs = (new WooFieldComparator)->diff($product, [
        'name' => $product->name,
        'slug' => $product->slug,
        'price' => '12.99',
    ]);

    expect(collect($diffs)->pluck('field'))->not->toContain('sell_price');
});

it('tolerates sub-penny float noise rather than reporting a mismatch', function (): void {
    $product = Product::factory()->make(['sell_price' => 12.99]);

    $diffs = (new WooFieldComparator)->diff($product, [
        'name' => $product->name,
        'slug' => $product->slug,
        'price' => '12.994',
    ]);

    expect(collect($diffs)->pluck('field'))->not->toContain('sell_price');
});

it('reports a divergence when the prices genuinely differ', function (): void {
    $product = Product::factory()->make(['sell_price' => 12.99]);

    $diffs = (new WooFieldComparator)->diff($product, [
        'name' => $product->name,
        'slug' => $product->slug,
        'price' => '10.50',
    ]);

    $priceDiff = collect($diffs)->firstWhere('field', 'sell_price');

    expect($priceDiff)->not->toBeNull()
        ->and($priceDiff['laravel'])->toBe(12.99)
        ->and($priceDiff['live'])->toBe(10.50);
});

// ── Correction: the internal price wins ───────────────────────────────────

it('pushes the internal sell_price to Woo regular_price as a 2dp string', function (): void {
    $product = Product::factory()->create([
        'sku' => 'SP-001',
        'woo_product_id' => 7001,
        'sell_price' => 12.9,
    ]);

    $woo = sellPriceWooStub([7001 => ['id' => 7001, 'price' => '10.50', 'meta_data' => []]]);
    $result = sellPriceWriter($woo)->putProductFields($product, ['sell_price'], 'cid-sp1');

    expect($result['status'])->toBe('pushed')
        ->and($result['fields_pushed'])->toBe(['sell_price'])
        ->and($woo->putCalls)->toHaveCount(1)
        ->and($woo->putCalls[0]['payload'])->toBe(['regular_price' => '12.90']);
});

it('strips VAT first when the store is configured ex-VAT', function (): void {
    // The basis MUST match PushPriceChangeToWoo — a mismatch is a silent 20%
    // price error on every reconciled product.
    config(['services.woo.push_prices_ex_vat' => true]);

    $product = Product::factory()->create([
        'sku' => 'SP-002',
        'woo_product_id' => 7002,
        'sell_price' => 12.00,
    ]);

    $woo = sellPriceWooStub([7002 => ['id' => 7002, 'price' => '9.00', 'meta_data' => []]]);
    sellPriceWriter($woo)->putProductFields($product, ['sell_price'], 'cid-sp2');

    // 1200p inc-VAT at 20% → 1000p ex-VAT.
    expect($woo->putCalls[0]['payload'])->toBe(['regular_price' => '10.00']);
});

it('never pushes a price for a product with no local price', function (): void {
    $product = Product::factory()->create([
        'sku' => 'SP-003',
        'woo_product_id' => 7003,
        'sell_price' => null,
    ]);

    $woo = sellPriceWooStub([7003 => ['id' => 7003, 'price' => '10.50', 'meta_data' => []]]);
    $result = sellPriceWriter($woo)->putProductFields($product, ['sell_price'], 'cid-sp3');

    // Pushing "0.00" over a live price would be far worse than doing nothing.
    expect($woo->putCalls)->toBeEmpty()
        ->and($result['fields_pushed'])->toBeEmpty()
        ->and($result['fields_skipped'])->toBe(['sell_price' => 'no_local_price']);
});

// ── Sale safety: operator-owned pricing is never overwritten ──────────────

it('refuses to reprice a product Woo has on sale', function (): void {
    $product = Product::factory()->create([
        'sku' => 'SP-004',
        'woo_product_id' => 7004,
        'sell_price' => 12.99,
    ]);

    // `price` is the SALE price while a sale runs, so the comparator flags
    // this product as diverged every single scan. Pushing regular_price here
    // would rewrite the sale's "was" price behind the operator's back.
    $woo = sellPriceWooStub([7004 => [
        'id' => 7004,
        'price' => '9.99',
        'regular_price' => '12.99',
        'sale_price' => '9.99',
        'on_sale' => true,
        'meta_data' => [],
    ]]);

    $result = sellPriceWriter($woo)->putProductFields($product, ['sell_price'], 'cid-sp4');

    expect($woo->putCalls)->toBeEmpty()
        ->and($result['fields_skipped'])->toBe(['sell_price' => 'on_sale']);
});

it('treats a bare sale_price as a sale even when on_sale is absent', function (): void {
    $product = Product::factory()->create([
        'sku' => 'SP-005',
        'woo_product_id' => 7005,
        'sell_price' => 12.99,
    ]);

    $woo = sellPriceWooStub([7005 => [
        'id' => 7005,
        'price' => '9.99',
        'sale_price' => '9.99',
        'meta_data' => [],
    ]]);

    $result = sellPriceWriter($woo)->putProductFields($product, ['sell_price'], 'cid-sp5');

    expect($woo->putCalls)->toBeEmpty()
        ->and($result['fields_skipped'])->toBe(['sell_price' => 'on_sale']);
});

it('still pushes when Woo carries an empty sale_price (no active sale)', function (): void {
    $product = Product::factory()->create([
        'sku' => 'SP-006',
        'woo_product_id' => 7006,
        'sell_price' => 12.99,
    ]);

    // Woo returns sale_price:"" for the overwhelming majority of products —
    // treating that as a sale would disable the reconciler entirely.
    $woo = sellPriceWooStub([7006 => [
        'id' => 7006,
        'price' => '10.50',
        'sale_price' => '',
        'on_sale' => false,
        'meta_data' => [],
    ]]);

    $result = sellPriceWriter($woo)->putProductFields($product, ['sell_price'], 'cid-sp6');

    expect($result['fields_pushed'])->toBe(['sell_price'])
        ->and($woo->putCalls[0]['payload'])->toBe(['regular_price' => '12.99']);
});

// ── Idempotency + other-field isolation ───────────────────────────────────

it('leaves Woo alone on the second pass once the price agrees', function (): void {
    $product = Product::factory()->create([
        'sku' => 'SP-007',
        'woo_product_id' => 7007,
        'sell_price' => 12.99,
    ]);

    // Pass 1 — diverged, so a diff is emitted and the push corrects it.
    $comparator = new WooFieldComparator;
    $before = $comparator->diff($product, ['name' => $product->name, 'slug' => $product->slug, 'price' => '10.50']);
    expect(collect($before)->pluck('field'))->toContain('sell_price');

    $woo = sellPriceWooStub([7007 => ['id' => 7007, 'price' => '10.50', 'meta_data' => []]]);
    sellPriceWriter($woo)->putProductFields($product, ['sell_price'], 'cid-sp7');
    $pushed = $woo->putCalls[0]['payload']['regular_price'];

    // Pass 2 — Woo now reports what we pushed; no diff, so nothing to push.
    $after = $comparator->diff($product, ['name' => $product->name, 'slug' => $product->slug, 'price' => $pushed]);
    expect(collect($after)->pluck('field'))->not->toContain('sell_price');
});

it('does not disturb buy_price meta when only sell_price is reconciled', function (): void {
    $product = Product::factory()->create([
        'sku' => 'SP-008',
        'woo_product_id' => 7008,
        'sell_price' => 12.99,
        'buy_price' => 8.00,
    ]);

    $woo = sellPriceWooStub([7008 => [
        'id' => 7008,
        'price' => '10.50',
        'meta_data' => [
            ['key' => '_yoast_wpseo_metadesc', 'value' => 'Foo'],
            ['key' => '_alg_wc_cog_cost', 'value' => '8.0000'],
        ],
    ]]);

    sellPriceWriter($woo)->putProductFields($product, ['sell_price'], 'cid-sp8');

    // A sell_price-only push must not carry meta_data at all — sending a
    // partial array is how the COG/Yoast/EAN entries get wiped.
    expect($woo->putCalls[0]['payload'])->not->toHaveKey('meta_data');
});

// ── Command wiring ────────────────────────────────────────────────────────

it('accepts sell_price as a pushable field on the divergence push command', function (): void {
    $product = Product::factory()->create([
        'sku' => 'SP-009',
        'woo_product_id' => 7009,
        'sell_price' => 12.99,
    ]);

    SyncDiff::create([
        'provider' => DivergenceScanner::PROVIDER,
        'channel' => 'woo',
        'method' => 'GET',
        'endpoint' => 'internal',
        'payload' => [
            'product_id' => $product->id,
            'sku' => 'SP-009',
            'field' => 'sell_price',
            'laravel' => 12.99,
            'live' => 10.50,
            'pin_column' => null,
        ],
        'correlation_id' => 'cid-sp9',
        'created_at' => now(),
        'status' => 'pending',
    ]);

    app()->instance(WooClient::class, sellPriceWooStub([
        7009 => ['id' => 7009, 'price' => '10.50', 'meta_data' => []],
    ]));

    // Before 260822-rmo this exited 1 with "Unsupported field: sell_price".
    $exit = Artisan::call('products:push-divergence-to-woo', [
        '--field' => 'sell_price',
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('field:sell_price');
});

it('reports SKU, internal price, Woo price and the difference in dry-run', function (): void {
    $product = Product::factory()->create([
        'sku' => 'SP-010',
        'woo_product_id' => 7010,
        'sell_price' => 12.99,
    ]);

    SyncDiff::create([
        'provider' => DivergenceScanner::PROVIDER,
        'channel' => 'woo',
        'method' => 'GET',
        'endpoint' => 'internal',
        'payload' => [
            'product_id' => $product->id,
            'sku' => 'SP-010',
            'field' => 'sell_price',
            'laravel' => 12.99,
            'live' => 10.50,
            'pin_column' => null,
        ],
        'correlation_id' => 'cid-sp10',
        'created_at' => now(),
        'status' => 'pending',
    ]);

    $woo = sellPriceWooStub([7010 => ['id' => 7010, 'price' => '10.50', 'meta_data' => []]]);
    app()->instance(WooClient::class, $woo);

    Artisan::call('products:push-divergence-to-woo', [
        '--field' => 'sell_price',
        '--dry-run' => true,
    ]);

    $output = Artisan::output();

    // The operator must be able to size the divergence BEFORE authorising a
    // write — that is the whole point of the dry-run report.
    expect($output)->toContain('SP-010')
        ->and($output)->toContain('12.99')
        ->and($output)->toContain('10.50')
        ->and($output)->toContain('+2.49');

    // …and dry-run must write nothing.
    expect($woo->putCalls)->toBeEmpty();
});

it('never reconciles a price for a product that is not on the storefront', function (): void {
    // 260823 — DivergenceScanner walks EVERY product, so drafts/pending rows
    // dominate the diff set. PushPriceChangeToWoo has skipped non-published
    // products since 260701-n4y; the reconciler must not undo that rule and
    // start pushing prices for things the storefront doesn't sell.
    $product = Product::factory()->create([
        'sku' => 'SP-011',
        'woo_product_id' => 7011,
        'sell_price' => 12.99,
        'status' => 'draft',
    ]);

    $woo = sellPriceWooStub([7011 => ['id' => 7011, 'price' => '10.50', 'meta_data' => []]]);
    $result = sellPriceWriter($woo)->putProductFields($product, ['sell_price'], 'cid-sp11');

    expect($woo->putCalls)->toBeEmpty()
        ->and($result['fields_skipped'])->toBe(['sell_price' => 'not_published']);
});
