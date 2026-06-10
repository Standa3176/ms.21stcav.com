<?php

declare(strict_types=1);

use App\Domain\Cutover\Services\WooFieldComparator;
use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| 260610-qc4 — WooFieldComparator per-field coverage
|--------------------------------------------------------------------------
|
| One case per new field (A-F) + one case for the brand pa_brand-only
| deferred-fallback contract (G). Each case verifies the three-state
| matrix: exact-match (no diff), mismatch (diff), Woo-side-absent
| (silent skip OR canonical-absence-diff per the comparator's defensive
| contract — see PLAN.md Drift-prevention section).
|
| Helper $base() returns a fully-shaped Woo response with neutral values
| for the 7 ORIGINAL fields so we can mutate just the field under test
| without tripping unrelated diffs.
*/

beforeEach(function (): void {
    $this->comparator = new WooFieldComparator;

    // Neutral Woo baseline. Mirrors ProductFactory defaults for the 13 fields
    // so any diff emitted points unambiguously at the field under test.
    $this->base = fn (array $overrides = []): array => array_merge([
        'sku' => 'TEST-1',
        'name' => 'Same',
        'slug' => 'same',
        'short_description' => '',
        'description' => '',
        'price' => '0.00',
        'images' => [],
        'meta_data' => [],
        'categories' => [],
        'attributes' => [],
        'stock_quantity' => null,
        'stock_status' => 'instock', // matches ProductFactory default
    ], $overrides);
});

// ── Case A — stock_quantity ─────────────────────────────────────────────────

it('A: stock_quantity — emits diff on mismatch, silent on exact match, emits on Woo absence with local value', function (): void {
    // Exact match → 0 diffs.
    $p = Product::factory()->create([
        'sku' => 'TEST-1', 'name' => 'Same', 'slug' => 'same',
        'sell_price' => 0, 'stock_quantity' => 5, 'stock_status' => 'instock',
    ]);
    $diffs = $this->comparator->diff($p, ($this->base)(['stock_quantity' => 5]));
    expect($diffs)->toBe([]);

    // Mismatch → exactly 1 diff for stock_quantity.
    $diffs = $this->comparator->diff($p, ($this->base)(['stock_quantity' => 0]));
    expect($diffs)->toHaveCount(1);
    expect($diffs[0]['field'])->toBe('stock_quantity');
    expect($diffs[0]['laravel'])->toBe(5);
    expect($diffs[0]['live'])->toBe(0);
    expect($diffs[0]['pin_column'])->toBeNull();

    // Woo top-level absent + local has value → canonical-absence diff EMITTED
    // (this is the 260609-nku Ergotron class).
    $wooNoStockKey = ($this->base)([]);
    unset($wooNoStockKey['stock_quantity']);
    $diffs = $this->comparator->diff($p, $wooNoStockKey);
    expect(collect($diffs)->pluck('field')->all())->toContain('stock_quantity');
});

// ── Case B — stock_status ───────────────────────────────────────────────────

it('B: stock_status — emits diff on mismatch, silent on exact match, case-insensitive compare', function (): void {
    $p = Product::factory()->create([
        'sku' => 'TEST-1', 'name' => 'Same', 'slug' => 'same',
        'sell_price' => 0, 'stock_quantity' => null, 'stock_status' => 'instock',
    ]);

    // Exact match (case-insensitive).
    $diffs = $this->comparator->diff($p, ($this->base)(['stock_status' => 'instock']));
    expect($diffs)->toBe([]);

    // Mismatch.
    $diffs = $this->comparator->diff($p, ($this->base)(['stock_status' => 'outofstock']));
    expect($diffs)->toHaveCount(1);
    expect($diffs[0]['field'])->toBe('stock_status');
});

// ── Case C — buy_price (meta-only field — silent skip on Woo absence) ───────

it('C: buy_price — silent skip when Woo lacks _alg_wc_cog_cost meta, diff on mismatch', function (): void {
    $p = Product::factory()->create([
        'sku' => 'TEST-1', 'name' => 'Same', 'slug' => 'same',
        'sell_price' => 0, 'buy_price' => 12.50, 'stock_quantity' => null, 'stock_status' => 'instock',
    ]);

    // Woo response has NO _alg_wc_cog_cost meta → silent skip (this is the
    // defensive contract — most Woo installs don't have the WC COG plugin).
    $diffs = $this->comparator->diff($p, ($this->base)([]));
    expect(collect($diffs)->pluck('field')->all())->not->toContain('buy_price');

    // Exact match (within 0.005 tolerance like sell_price).
    $woo = ($this->base)(['meta_data' => [['key' => '_alg_wc_cog_cost', 'value' => '12.50']]]);
    expect($this->comparator->diff($p, $woo))->toBe([]);

    // Mismatch outside tolerance.
    $woo = ($this->base)(['meta_data' => [['key' => '_alg_wc_cog_cost', 'value' => '11.00']]]);
    $diffs = $this->comparator->diff($p, $woo);
    expect($diffs)->toHaveCount(1);
    expect($diffs[0]['field'])->toBe('buy_price');
    expect($diffs[0]['laravel'])->toBe(12.50);
    expect($diffs[0]['live'])->toBe(11.00);
});

// ── Case D — category_id ────────────────────────────────────────────────────

it('D: category_id — diff on mismatch, canonical absence emits diff', function (): void {
    $p = Product::factory()->create([
        'sku' => 'TEST-1', 'name' => 'Same', 'slug' => 'same',
        'sell_price' => 0, 'category_id' => 42, 'stock_quantity' => null, 'stock_status' => 'instock',
    ]);

    // Exact match.
    $woo = ($this->base)(['categories' => [['id' => 42, 'name' => 'AV Hardware']]]);
    expect($this->comparator->diff($p, $woo))->toBe([]);

    // Mismatch.
    $woo = ($this->base)(['categories' => [['id' => 99, 'name' => 'Misc']]]);
    $diffs = $this->comparator->diff($p, $woo);
    expect($diffs)->toHaveCount(1);
    expect($diffs[0]['field'])->toBe('category_id');
    expect($diffs[0]['laravel'])->toBe(42);
    expect($diffs[0]['live'])->toBe(99);

    // Canonical absence — Woo has empty categories array but local has a value → diff EMITTED.
    $diffs = $this->comparator->diff($p, ($this->base)(['categories' => []]));
    expect(collect($diffs)->pluck('field')->all())->toContain('category_id');
});

// ── Case E — brand_id via _product_brand_id meta (silent skip when meta absent)

it('E: brand_id — extracts from meta _product_brand_id, silent skip on absence', function (): void {
    $p = Product::factory()->create([
        'sku' => 'TEST-1', 'name' => 'Same', 'slug' => 'same',
        'sell_price' => 0, 'brand_id' => 17, 'stock_quantity' => null, 'stock_status' => 'instock',
    ]);

    // Exact match.
    $woo = ($this->base)(['meta_data' => [['key' => '_product_brand_id', 'value' => '17']]]);
    expect($this->comparator->diff($p, $woo))->toBe([]);

    // Mismatch.
    $woo = ($this->base)(['meta_data' => [['key' => '_product_brand_id', 'value' => '99']]]);
    $diffs = $this->comparator->diff($p, $woo);
    expect($diffs)->toHaveCount(1);
    expect($diffs[0]['field'])->toBe('brand_id');

    // Absence — silent skip.
    $diffs = $this->comparator->diff($p, ($this->base)([]));
    expect(collect($diffs)->pluck('field')->all())->not->toContain('brand_id');
});

// ── Case F — ean across 3 meta key fallback chain ───────────────────────────

it('F: ean — walks _global_unique_id then _ean then _alg_ean, silent skip on absence', function (): void {
    $p = Product::factory()->create([
        'sku' => 'TEST-1', 'name' => 'Same', 'slug' => 'same',
        'sell_price' => 0, 'ean' => '7090043790993', 'stock_quantity' => null, 'stock_status' => 'instock',
    ]);

    // Match via _global_unique_id (priority 1).
    $woo = ($this->base)(['meta_data' => [['key' => '_global_unique_id', 'value' => '7090043790993']]]);
    expect($this->comparator->diff($p, $woo))->toBe([]);

    // Match via _ean (priority 2 — when priority 1 absent).
    $woo = ($this->base)(['meta_data' => [['key' => '_ean', 'value' => '7090043790993']]]);
    expect($this->comparator->diff($p, $woo))->toBe([]);

    // Match via _alg_ean (priority 3 — when priorities 1+2 absent).
    $woo = ($this->base)(['meta_data' => [['key' => '_alg_ean', 'value' => '7090043790993']]]);
    expect($this->comparator->diff($p, $woo))->toBe([]);

    // Mismatch via _global_unique_id.
    $woo = ($this->base)(['meta_data' => [['key' => '_global_unique_id', 'value' => '0000000000000']]]);
    $diffs = $this->comparator->diff($p, $woo);
    expect($diffs)->toHaveCount(1);
    expect($diffs[0]['field'])->toBe('ean');
    expect($diffs[0]['laravel'])->toBe('7090043790993');

    // Absence — silent skip (most Woo installs not backfilled yet).
    $diffs = $this->comparator->diff($p, ($this->base)([]));
    expect(collect($diffs)->pluck('field')->all())->not->toContain('ean');
});

// ── Case G — brand_id pa_brand-attribute-only response stays SILENT ─────────

it('G: brand_id — pa_brand attribute-only Woo response produces NO diff (TaxonomyResolver fallback deferred)', function (): void {
    // Scenario: Woo carries brand only via the legacy pa_brand global attribute,
    // not via the _product_brand_id meta. The comparator deliberately does NOT
    // resolve pa_brand → id (would require TaxonomyResolver HTTP dependency,
    // breaking the pure-function contract). Documented in WooFieldComparator
    // class docblock + 260610-qc4 PLAN.md as a deferred follow-up.
    //
    // Contract: the comparator stays SILENT on brand_id in this case so it
    // doesn't false-positive flood sync_diffs. A follow-up quick can add the
    // TaxonomyResolver fallback if pa_brand-only divergence becomes a real signal.
    $p = Product::factory()->create([
        'sku' => 'TEST-1', 'name' => 'Same', 'slug' => 'same',
        'sell_price' => 0, 'brand_id' => 17, 'stock_quantity' => null, 'stock_status' => 'instock',
    ]);

    $woo = ($this->base)([
        'attributes' => [['slug' => 'pa_brand', 'options' => ['Logitech']]],
        // no _product_brand_id in meta_data
    ]);

    $diffs = $this->comparator->diff($p, $woo);
    expect(collect($diffs)->pluck('field')->all())->not->toContain('brand_id');
});

// ── Regression — original 7-field comparisons still emit correctly ──────────

it('regression: original 7-field comparisons still emit correctly', function (): void {
    $p = Product::factory()->create([
        'sku' => 'TEST-1', 'name' => 'Laravel Name', 'slug' => 'laravel-slug',
        'short_description' => 'short L', 'long_description' => 'long L',
        'meta_description' => 'meta L', 'sell_price' => 9.99, 'image_url' => 'https://a.test/L.jpg',
        'stock_quantity' => null, 'stock_status' => 'instock',
    ]);

    $woo = ($this->base)([
        'name' => 'Woo Name', 'slug' => 'woo-slug',
        'short_description' => 'short W', 'description' => 'long W',
        'price' => '8.99',
        'images' => [['src' => 'https://a.test/W.jpg']],
        // meta_description test: provide Yoast key so the comparison engages
        'meta_data' => [['key' => '_yoast_wpseo_metadesc', 'value' => 'meta W']],
    ]);

    $fields = collect($this->comparator->diff($p, $woo))->pluck('field')->all();
    expect($fields)->toContain('name', 'slug', 'short_description', 'long_description',
        'meta_description', 'sell_price', 'image_url');
});

// ── Existing missing-product sentinel ────────────────────────────────────────

it('emits exists=false sentinel when Woo response is null', function (): void {
    $p = Product::factory()->create(['sku' => 'TEST-1']);

    $diffs = $this->comparator->diff($p, null);
    expect($diffs)->toHaveCount(1);
    expect($diffs[0]['field'])->toBe('exists');
    expect($diffs[0]['laravel'])->toBe(true);
    expect($diffs[0]['live'])->toBe(false);
});
