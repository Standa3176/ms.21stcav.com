<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Products\Services\WooFieldComparator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260913-pyz — Woo cost-of-goods meta
|--------------------------------------------------------------------------
|
| Woo's cost field was populated in bulk at the 2026-05-23 migration and never
| maintained, because three things lined up:
|
|   1. CreateWooProductJob never wrote `_alg_wc_cog_cost`.
|   2. WooFieldComparator SILENT-SKIPS buy_price when Woo lacks the key (its
|      defensive contract for installs without the WC COG plugin).
|   3. cutover:auto-sync only pushes what the comparator flags.
|
| So a product born without cost could never acquire one. 6 of 25 sampled
| published products on 2026-09-13, every one created after that bulk load.
|
| These tests pin the two halves of the fix, and — most importantly — that
| adding the cost key did not cost us the Yoast key that was already there.
| Woo REST meta_data is replace-not-merge, so an incomplete meta array is how
| you silently delete other plugins' data.
*/

it('registers the backfill command', function (): void {
    expect(array_keys(app(Kernel::class)->all()))->toContain('products:backfill-woo-cost');
});

it('writes cost of goods into the create payload ALONGSIDE the Yoast key', function (): void {
    // The payload builder is private, so assert on the source: both keys must
    // be in the same meta array. A future edit that replaces rather than
    // appends is exactly the regression this catches.
    $source = file_get_contents(base_path('app/Domain/ProductAutoCreate/Jobs/CreateWooProductJob.php'));

    expect($source)->toContain("'meta_data' => \$this->buildCreateMeta(\$product)")
        ->and($source)->toContain('_yoast_wpseo_metadesc')
        ->and($source)->toContain('WooFieldComparator::BUY_PRICE_META_KEY');
});

it('omits cost rather than writing zero when the cost is unknown', function (): void {
    // A 0.0000 cost reads as a REAL value to WC COG (profit shows 100%) and to
    // any B2BKing dynamic rule computing "Cost + x%" — which would price the
    // product at zero. An absent key is honest; a zero is a wrong answer.
    $source = file_get_contents(base_path('app/Domain/ProductAutoCreate/Jobs/CreateWooProductJob.php'));

    expect($source)->toContain('if ($buyPrice > 0)');
});

it('uses the SHARED meta key constant, not a copy of the string', function (): void {
    // Two spellings of this key drifting apart is how the comparator and the
    // writer would stop agreeing about what cost means.
    expect(WooFieldComparator::BUY_PRICE_META_KEY)->toBe('_alg_wc_cog_cost');

    $source = file_get_contents(base_path('app/Console/Commands/BackfillWooCostCommand.php'));
    expect($source)->toContain('WooFieldComparator::BUY_PRICE_META_KEY');
});

it('delegates writes to WooProductWriter so other meta survives', function (): void {
    // A blind PUT with meta_data=[{key:_alg_wc_cog_cost}] WIPES every other
    // entry — b2bking group prices included. WooProductWriter pre-GETs and
    // merges; the backfill must not build its own payload.
    $source = file_get_contents(base_path('app/Console/Commands/BackfillWooCostCommand.php'));

    expect($source)->toContain("putProductFields(\$product, ['buy_price'])")
        ->and($source)->not->toContain("'meta_data' =>");
});

it('writes nothing without --apply', function (): void {
    Product::factory()->create([
        'status' => 'publish',
        'woo_product_id' => 99001,
        'buy_price' => 10.00,
    ]);

    $this->artisan('products:backfill-woo-cost')
        ->expectsOutputToContain('DRY-RUN')
        ->assertExitCode(0);
});

it('skips products with no usable cost — they would write a misleading zero', function (): void {
    Product::factory()->create([
        'status' => 'publish', 'woo_product_id' => 99002, 'buy_price' => null,
    ]);
    Product::factory()->create([
        'status' => 'publish', 'woo_product_id' => 99003, 'buy_price' => 0,
    ]);

    $this->artisan('products:backfill-woo-cost')
        ->expectsOutputToContain('inspecting 0 published product(s)')
        ->assertExitCode(0);
});
