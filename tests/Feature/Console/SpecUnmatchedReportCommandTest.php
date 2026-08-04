<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Models\WooAttributeTerm;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| 260728-fwx T6 — spec:unmatched-report (READ-ONLY; no writes, no Woo calls)
|--------------------------------------------------------------------------
|
| Reports how well products' attributes_json specs resolve to the 44 pa_*
| taxonomies. Coverage:
|
|   - coverage counts (scanned / produced-global / total / avg)
|   - per-taxonomy FILL counts (populated vs starved facets)
|   - unmatched-value grouping + reasons
|   - unmapped-label list (spec-only excluded)
|   - band derivation produces a global
|   - --csv writes the expected sections/headers/rows
|   - --limit / --status honoured
|   - NO Woo call, NO write
|
| Resolution is deterministic: we seed woo_attribute_terms (the local cache the
| production WooAttributeTermVocabulary reads) so the injected resolver resolves
| against a known vocabulary — no Woo, no network.
*/

beforeEach(function (): void {
    // Seed the cached term vocabulary the resolver reads (via
    // WooAttributeTermVocabulary → woo_attribute_terms).
    WooAttributeTerm::insert([
        ['attribute_id' => 3429, 'attribute_slug' => 'pa_resolution', 'attribute_name' => 'Resolution', 'term_id' => 500, 'term_name' => '4K UHD (3840x2160)', 'term_slug' => '4k-uhd', 'created_at' => now(), 'updated_at' => now()],
        ['attribute_id' => 3429, 'attribute_slug' => 'pa_resolution', 'attribute_name' => 'Resolution', 'term_id' => 501, 'term_name' => 'Full HD (1920x1080)', 'term_slug' => 'full-hd', 'created_at' => now(), 'updated_at' => now()],
        ['attribute_id' => 3268, 'attribute_slug' => 'pa_colour', 'attribute_name' => 'Colour', 'term_id' => 600, 'term_name' => 'Black', 'term_slug' => 'black', 'created_at' => now(), 'updated_at' => now()],
        ['attribute_id' => 3516, 'attribute_slug' => 'pa_screen-size-band', 'attribute_name' => 'Display Size Band', 'term_id' => 700, 'term_name' => '44-55 inch', 'term_slug' => '44-55-inch', 'created_at' => now(), 'updated_at' => now()],
    ]);
});

/**
 * Create a product with a given status + attributes_json.
 *
 * @param  array<int, array{name:string, value:string}>|null  $attributes
 */
function makeSpecProduct(string $status, ?array $attributes): Product
{
    return Product::factory()->create([
        'status' => $status,
        'attributes_json' => $attributes,
    ]);
}

/**
 * Run the report writing to a fresh temp CSV; return
 * [exitCode, consoleOutput, parsedCsvRows]. Rows are parsed with str_getcsv so
 * enclosure/quoting differences across PHP versions never affect assertions.
 *
 * @return array{0:int, 1:string, 2:array<int, array<int, string>>}
 */
function runSpecReport(array $options = []): array
{
    $csv = $options['--csv'] ?? (sys_get_temp_dir().'/spec-unmatched-'.uniqid().'.csv');
    $options['--csv'] = $csv;

    $exit = Artisan::call('spec:unmatched-report', $options);
    $out = Artisan::output();

    $rows = [];
    if (is_file($csv)) {
        foreach (file($csv, FILE_IGNORE_NEW_LINES) as $line) {
            if ($line === '') {
                continue;
            }
            $rows[] = str_getcsv($line);
        }
        @unlink($csv);
    }

    return [$exit, $out, $rows];
}

it('reports coverage, fill, unmatched values and unmapped labels (status filtered)', function (): void {
    makeSpecProduct('publish', [
        ['name' => 'Resolution', 'value' => '4K'],
        ['name' => 'Colour', 'value' => 'Black'],
        ['name' => 'MPN', 'value' => 'ABC-123'],
        ['name' => 'Connection', 'value' => 'HDMI'],
    ]);
    makeSpecProduct('publish', [
        ['name' => 'Resolution', 'value' => '8K'],
        ['name' => 'Colour', 'value' => 'Rainbow'],
        ['name' => 'Connection', 'value' => 'USB'],
    ]);
    makeSpecProduct('pending', [['name' => 'Resolution', 'value' => '4K']]);
    makeSpecProduct('publish', []);      // empty — skipped, not scanned
    makeSpecProduct('publish', null);    // null — excluded by whereNotNull

    [$exit, $out, $rows] = runSpecReport(['--status' => 'publish']);

    expect($exit)->toBe(0);

    // Coverage headline.
    expect($rows)->toContain(['products_scanned', '2']);
    expect($rows)->toContain(['products_with_global', '1']);
    expect($rows)->toContain(['total_global_attrs', '2']);
    expect($rows)->toContain(['avg_global_attrs_per_product', '1']);

    // Per-taxonomy fill: resolution + colour filled once each; screen-size starved;
    // the exact-brightness 44th flagged local.
    expect($rows)->toContain(['pa_resolution', '3429', 'Resolution', '1', '0']);
    expect($rows)->toContain(['pa_colour', '3268', 'Colour', '1', '0']);
    expect($rows)->toContain(['pa_screen-size-band', '3516', 'Display Size Band', '0', '0']);
    expect($rows)->toContain(['pa_brightness-cdm2', '3531', 'Brightness exact (cd/m²)', '0', '1']);

    // Unmatched values (label mapped, value not a term).
    expect($rows)->toContain(['pa_resolution', '8K', '1', 'value_not_a_term']);
    expect($rows)->toContain(['pa_colour', 'Rainbow', '1', 'value_not_a_term']);

    // Unmapped labels: Connection twice → alias candidate; MPN is known spec-only.
    expect($rows)->toContain(['Connection', '2', 'unmapped']);
    expect($rows)->toContain(['MPN', '1', 'spec_only']);

    // Console carries the section headers.
    expect($out)->toContain('COVERAGE SUMMARY');
    expect($out)->toContain('PER-TAXONOMY FILL');
    expect($out)->toContain('UNMATCHED VALUES');
    expect($out)->toContain('UNMAPPED LABELS');
});

it('derives a band and counts it as a filled global facet', function (): void {
    makeSpecProduct('publish', [['name' => 'Display Size', 'value' => '55']]);

    [$exit, , $rows] = runSpecReport(['--status' => 'publish']);

    expect($exit)->toBe(0);
    expect($rows)->toContain(['products_scanned', '1']);
    expect($rows)->toContain(['products_with_global', '1']);
    expect($rows)->toContain(['total_global_attrs', '1']);
    // 55" → "44-55 inch" resolves against the cached band term → global fill.
    expect($rows)->toContain(['pa_screen-size-band', '3516', 'Display Size Band', '1', '0']);
    // Companion "Display Size" label is a mapped taxonomy — NOT an unmapped label.
    $unmappedLabels = array_map(fn ($r) => $r[0], array_filter($rows, fn ($r) => ($r[2] ?? null) === 'unmapped'));
    expect($unmappedLabels)->not->toContain('Display Size');
});

it('honours --limit', function (): void {
    makeSpecProduct('publish', [['name' => 'Colour', 'value' => 'Black']]);
    makeSpecProduct('publish', [['name' => 'Colour', 'value' => 'Black']]);
    makeSpecProduct('publish', [['name' => 'Colour', 'value' => 'Black']]);

    [$exit, , $rows] = runSpecReport(['--status' => 'publish', '--limit' => 1]);

    expect($exit)->toBe(0);
    expect($rows)->toContain(['products_scanned', '1']);
});

it('scans all statuses when --status is omitted', function (): void {
    makeSpecProduct('publish', [['name' => 'Colour', 'value' => 'Black']]);
    makeSpecProduct('publish', [['name' => 'Colour', 'value' => 'Black']]);
    makeSpecProduct('pending', [['name' => 'Colour', 'value' => 'Black']]);
    makeSpecProduct('publish', null); // excluded

    [$exit, , $rows] = runSpecReport([]);

    expect($exit)->toBe(0);
    expect($rows)->toContain(['products_scanned', '3']);
});

it('makes NO Woo call and NO write', function (): void {
    // Spy WooClient: any call is recorded. The command must never touch it.
    $spy = new class extends WooClient
    {
        /** @var array<int, string> */
        public array $calls = [];

        public function __construct() {}

        public function get(string $endpoint, array $query = []): array
        {
            $this->calls[] = "GET {$endpoint}";

            return [];
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->calls[] = "PUT {$endpoint}";

            return [];
        }

        public function post(string $endpoint, array $payload): array
        {
            $this->calls[] = "POST {$endpoint}";

            return [];
        }

        public function patch(string $endpoint, array $payload): array
        {
            $this->calls[] = "PATCH {$endpoint}";

            return [];
        }

        public function delete(string $endpoint, array $payload = []): array
        {
            $this->calls[] = "DELETE {$endpoint}";

            return [];
        }
    };
    app()->instance(WooClient::class, $spy);

    $product = makeSpecProduct('publish', [
        ['name' => 'Resolution', 'value' => '4K'],
        ['name' => 'Colour', 'value' => 'Rainbow'],
    ]);
    $originalJson = $product->attributes_json;
    $termCountBefore = WooAttributeTerm::count();
    $productCountBefore = Product::count();

    [$exit] = runSpecReport(['--status' => 'publish']);

    expect($exit)->toBe(0);
    expect($spy->calls)->toBe([]);                       // zero Woo calls
    expect(WooAttributeTerm::count())->toBe($termCountBefore);
    expect(Product::count())->toBe($productCountBefore);
    expect($product->fresh()->attributes_json)->toBe($originalJson); // product untouched
});
