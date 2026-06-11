<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Quick task 260611-sr7 — products:backfill-brand-from-name
|--------------------------------------------------------------------------
|
| 8 Pest cases A-H + one architectural guard cover every documented engine
| outcome:
|
|   A — Happy path: "Logitech 4k conference camera 960-001503" → brand_id=1.
|   B — SKU-prefix detect: "AV1E3AA#AC3 Poly collaboration device" → brand_id=2
|       (Poly resolved from SECOND word; skipped_sku_prefix counter increments).
|   C — Unresolvable: TaxonomyResolver returns null → unresolved counter +
|       histogram capture for top-30 table.
|   D — --skus override scopes to explicit list; siblings untouched.
|   E — --dry-run writes nothing (every product still brand_id=null).
|   F — Products with brand_id ALREADY set are EXCLUDED by candidate query.
|   G — --min-confidence=0.95 surfaces verbatim in the run banner.
|   H — TaxonomyResolver throws on one candidate → errors counter +1; batch
|       continues for siblings.
|
| Architectural guard — file does NOT import WooClient (no Woo writes contract;
| mirrors 260611-qcq no-Woo invariant via source-text scan rather than a
| throwing container binding).
*/

/**
 * Bind a TaxonomyResolver stub that returns brand IDs from a fixed map.
 *
 * @param  array<string, int>  $brandIdByCandidate  candidate name => brand_id
 * @param  string|null  $throwTrigger  when set, throwing on resolveBrand($throwTrigger)
 */
function bindStubTaxonomyResolver(array $brandIdByCandidate, ?string $throwTrigger = null): void
{
    $stub = new class($brandIdByCandidate, $throwTrigger) extends TaxonomyResolver
    {
        /**
         * @param  array<string, int>  $map
         */
        public function __construct(private readonly array $map, private readonly ?string $throwTrigger)
        {
            // Skip parent constructor — no WooClient needed because every
            // method is overridden.
        }

        public function resolveBrand(?string $brandName): ?int
        {
            if ($this->throwTrigger !== null && $brandName === $this->throwTrigger) {
                throw new RuntimeException(
                    "260611-sr7 stub: forced throw for candidate '{$brandName}'"
                );
            }

            return $this->map[$brandName] ?? null;
        }

        public function allBrands(): array
        {
            // Stable per-test brand list so the engine's pre-load brand-name
            // map populates without a live Woo call. Includes every brand the
            // baseline map can resolve to (ids 1-4) plus extras for visual
            // alignment with the brief.
            return [
                ['id' => 1, 'name' => 'Logitech'],
                ['id' => 2, 'name' => 'Poly'],
                ['id' => 3, 'name' => 'Intel'],
                ['id' => 4, 'name' => 'Sony'],
            ];
        }
    };

    app()->instance(TaxonomyResolver::class, $stub);
}

beforeEach(function (): void {
    // Baseline map covers Cases A, B, D, E, F, G — Logitech / Poly / Sony /
    // Intel all resolve to fixed ids. Cases C + H re-stub with their own maps.
    bindStubTaxonomyResolver([
        'Logitech' => 1,
        'Poly' => 2,
        'Intel' => 3,
        'Sony' => 4,
    ]);
});

it('Case A: happy path — first-word Logitech resolves to brand_id=1', function (): void {
    $product = Product::factory()->create([
        'sku' => '960-001503',
        'name' => 'Logitech 4k conference camera 960-001503',
        'brand_id' => null,
        'status' => 'publish',
    ]);

    $exit = Artisan::call('products:backfill-brand-from-name');

    expect($exit)->toBe(0);
    expect($product->fresh()->brand_id)->toBe(1);

    $output = Artisan::output();
    expect($output)->toContain('resolved');
});

it('Case B: SKU-shaped first token triggers second-word resolution (Poly)', function (): void {
    $product = Product::factory()->create([
        'sku' => 'AV1E3AA#AC3',
        'name' => 'AV1E3AA#AC3 Poly collaboration device',
        'brand_id' => null,
        'status' => 'publish',
    ]);

    $exit = Artisan::call('products:backfill-brand-from-name');

    expect($exit)->toBe(0);
    expect($product->fresh()->brand_id)->toBe(2);

    $output = Artisan::output();
    expect($output)->toContain('skipped_sku_prefix');
    expect($output)->toContain('resolved');
});

it('Case C: unresolvable brand increments unresolved counter + top-30 capture', function (): void {
    // Re-stub with empty map → every candidate resolves to null.
    bindStubTaxonomyResolver([]);

    $product = Product::factory()->create([
        'sku' => 'XYZ-001',
        'name' => 'Unknown OEM XYZ-001',
        'brand_id' => null,
        'status' => 'publish',
    ]);

    $exit = Artisan::call('products:backfill-brand-from-name');

    expect($exit)->toBe(0);
    expect($product->fresh()->brand_id)->toBeNull();

    $output = Artisan::output();
    expect($output)->toContain('Unknown');
    expect($output)->toContain('unresolved');
});

it('Case D: --skus override scopes to explicit list; siblings untouched', function (): void {
    $target = Product::factory()->create([
        'sku' => 'ABC-1',
        'name' => 'Logitech foo',
        'brand_id' => null,
        'status' => 'publish',
    ]);
    $sibling = Product::factory()->create([
        'sku' => 'DEF-2',
        'name' => 'Sony bar',
        'brand_id' => null,
        'status' => 'publish',
    ]);

    $exit = Artisan::call('products:backfill-brand-from-name', ['--skus' => 'ABC-1']);

    expect($exit)->toBe(0);
    expect($target->fresh()->brand_id)->toBe(1);
    // Sibling not in --skus list → untouched.
    expect($sibling->fresh()->brand_id)->toBeNull();
});

it('Case E: --dry-run writes nothing; resolved counter still increments', function (): void {
    $products = [];
    for ($i = 1; $i <= 5; $i++) {
        $products[] = Product::factory()->create([
            'sku' => sprintf('LOGI-%03d', $i),
            'name' => "Logitech widget {$i}",
            'brand_id' => null,
            'status' => 'publish',
        ]);
    }

    $exit = Artisan::call('products:backfill-brand-from-name', ['--dry-run' => true]);

    expect($exit)->toBe(0);

    foreach ($products as $p) {
        expect($p->fresh()->brand_id)->toBeNull(); // NO writes happened
    }

    $output = Artisan::output();
    expect($output)->toContain('dry-run');
    expect($output)->toContain('resolved');
});

it('Case F: products with brand_id already set are EXCLUDED by candidate query', function (): void {
    $alreadyMapped = Product::factory()->create([
        'sku' => 'F-already-1',
        'name' => 'Logitech preexisting',
        'brand_id' => 99,
        'status' => 'publish',
    ]);
    $needsBackfill = Product::factory()->create([
        'sku' => 'F-needs-2',
        'name' => 'Sony fresh candidate',
        'brand_id' => null,
        'status' => 'publish',
    ]);

    $exit = Artisan::call('products:backfill-brand-from-name');

    expect($exit)->toBe(0);
    // Pre-mapped product UNCHANGED.
    expect($alreadyMapped->fresh()->brand_id)->toBe(99);
    // Null-brand product → resolved.
    expect($needsBackfill->fresh()->brand_id)->toBe(4);
});

it('Case G: --min-confidence=0.95 surfaces verbatim in the run banner', function (): void {
    Product::factory()->create([
        'sku' => 'G-001',
        'name' => 'Logitech minconf-test',
        'brand_id' => null,
        'status' => 'publish',
    ]);

    $exit = Artisan::call('products:backfill-brand-from-name', ['--min-confidence' => '0.95']);

    expect($exit)->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('min-confidence=0.95');
});

it('Case H: TaxonomyResolver throws → errors counter increments; batch continues', function (): void {
    bindStubTaxonomyResolver(
        ['Logitech' => 1, 'Sony' => 4],
        throwTrigger: 'Bomb',
    );

    $h1 = Product::factory()->create([
        'sku' => 'H-1',
        'name' => 'Logitech foo',
        'brand_id' => null,
        'status' => 'publish',
    ]);
    $h2 = Product::factory()->create([
        'sku' => 'H-2',
        'name' => 'Bomb bar',
        'brand_id' => null,
        'status' => 'publish',
    ]);
    $h3 = Product::factory()->create([
        'sku' => 'H-3',
        'name' => 'Sony baz',
        'brand_id' => null,
        'status' => 'publish',
    ]);

    $exit = Artisan::call('products:backfill-brand-from-name');

    expect($exit)->toBe(0);

    expect($h1->fresh()->brand_id)->toBe(1);
    expect($h2->fresh()->brand_id)->toBeNull(); // errored — batch continued
    expect($h3->fresh()->brand_id)->toBe(4);

    $output = Artisan::output();
    expect($output)->toContain('errors');
    expect($output)->toContain('resolved');
});

it('does not import WooClient (no-Woo invariant — mirrors 260611-qcq guard)', function (): void {
    $source = file_get_contents(__DIR__.'/../../../app/Console/Commands/BackfillProductBrandFromNameCommand.php');
    expect($source)->not->toBeFalse();
    expect($source)->not->toContain('App\\Domain\\Sync\\Services\\WooClient');
});
