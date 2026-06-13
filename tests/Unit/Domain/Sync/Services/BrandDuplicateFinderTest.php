<?php

declare(strict_types=1);

use App\Domain\Sync\Services\BrandDuplicateFinder;
use App\Domain\Sync\Services\WooClient;

/*
|--------------------------------------------------------------------------
| Quick task 260613-o33 — BrandDuplicateFinder canonical-slug ranking
|--------------------------------------------------------------------------
|
| 7 Pest cases A-G covering the slug-quality canonical ranking that replaces
| the broken count-based ranking which caused the 2026-06-13 Barco-orphan
| incident (operator deleted Woo brand id=3102 thinking it was the dup, because
| BrandDuplicateFinder told them id=13033 was canonical — by stale `count`).
|
|   A — Clean slug beats -brand suffix
|       [{3001 'yealink'}, {12776 'yealink-brand'}] → canonical=3001
|
|   B — Numeric suffix beats -brand suffix
|       [{12776 'yealink-brand'}, {12777 'yealink-1'}] → canonical=12777
|
|   C — Case-mismatch still groups under strtolower; exact base-slug wins
|       [{5 'Barco' slug=barco}, {8 'barco' slug=barco-1}] → canonical=5
|
|   D — rankSlug pure-function asserts via Reflection
|       barco→0, barco-brand→3, barco-1→2, barcoshop→1
|
|   E — Singleton groups dropped (no duplicates)
|       [{1 'Apple'}, {2 'Sony'}] → discover() returns []
|
|   F — Pagination across 3 pages still groups correctly
|       Each page emits a pair → 3 groups, each canonical = clean-slug one
|
|   G — REGRESSION: today's 2026-06-13 11-brand prod dataset
|       Barco {3102 'barco', 13033 'barco-brand'} → canonical=3102
|       Crestron {3012, 12781 -brand} → canonical=3012
|       LG {2904, 12779 -brand} → canonical=2904
|       Neat {3096, 12777 -brand} → canonical=3096
|       Yealink {3001, 12776 -brand} → canonical=3001
|
| Boundary strategy: anonymous-subclass WooClient stub (same pattern as
| DedupeBrandsCommandTest + RetagProductsOnWooCommandTest) — emits `slug`
| alongside id/name/count so the new slug-quality ranking can consume it.
| BrandDuplicateFinder resolves through the container so app()->instance()
| binding propagates automatically.
*/

it('Case A: clean slug beats -brand suffix — yealink (3001) wins over yealink-brand (12776)', function (): void {
    bindBrandsFinderStub([
        1 => [
            ['id' => 3001, 'name' => 'Yealink', 'slug' => 'yealink', 'count' => 1],
            ['id' => 12776, 'name' => 'Yealink', 'slug' => 'yealink-brand', 'count' => 99],
        ],
    ]);

    $plan = app(BrandDuplicateFinder::class)->discover();

    expect($plan)->toHaveKey('yealink');
    expect($plan['yealink']['canonical']['id'])->toBe(3001);
    expect($plan['yealink']['canonical']['name'])->toBe('Yealink');
    expect($plan['yealink']['sources'])->toHaveCount(1);
    expect($plan['yealink']['sources'][0]['id'])->toBe(12776);
});

it('Case B: numeric suffix beats -brand suffix — yealink-1 (12777) wins over yealink-brand (12776)', function (): void {
    bindBrandsFinderStub([
        1 => [
            ['id' => 12776, 'name' => 'Yealink', 'slug' => 'yealink-brand', 'count' => 50],
            ['id' => 12777, 'name' => 'Yealink', 'slug' => 'yealink-1', 'count' => 0],
        ],
    ]);

    $plan = app(BrandDuplicateFinder::class)->discover();

    expect($plan)->toHaveKey('yealink');
    expect($plan['yealink']['canonical']['id'])->toBe(12777);
    expect($plan['yealink']['sources'])->toHaveCount(1);
    expect($plan['yealink']['sources'][0]['id'])->toBe(12776);
});

it('Case C: case-mismatch still groups under strtolower — exact base-slug "barco" (5) wins over "barco-1" (8)', function (): void {
    bindBrandsFinderStub([
        1 => [
            ['id' => 5, 'name' => 'Barco', 'slug' => 'barco', 'count' => 1],
            ['id' => 8, 'name' => 'barco', 'slug' => 'barco-1', 'count' => 99],
        ],
    ]);

    $plan = app(BrandDuplicateFinder::class)->discover();

    expect($plan)->toHaveKey('barco');
    expect($plan['barco']['canonical']['id'])->toBe(5);
    expect($plan['barco']['sources'])->toHaveCount(1);
    expect($plan['barco']['sources'][0]['id'])->toBe(8);
});

it('Case D: rankSlug pure-function via Reflection — barco=0, barco-brand=3, barco-1=2, barcoshop=1', function (): void {
    // Resolve a fresh service; the stub binding only needs to satisfy the
    // constructor — Case D doesn't call discover().
    bindBrandsFinderStub([]);
    $service = app(BrandDuplicateFinder::class);

    $rm = new ReflectionMethod(BrandDuplicateFinder::class, 'rankSlug');
    $rm->setAccessible(true);

    expect($rm->invoke($service, 'barco', 'barco'))->toBe(0);          // exact base-slug
    expect($rm->invoke($service, 'barcoshop', 'barco'))->toBe(1);      // clean non-base
    expect($rm->invoke($service, 'barco-1', 'barco'))->toBe(2);        // numeric suffix
    expect($rm->invoke($service, 'barco-2', 'barco'))->toBe(2);        // numeric suffix
    expect($rm->invoke($service, 'barco-brand', 'barco'))->toBe(3);    // -brand suffix
});

it('Case E: singleton groups dropped — no duplicates returns []', function (): void {
    bindBrandsFinderStub([
        1 => [
            ['id' => 1, 'name' => 'Apple', 'slug' => 'apple', 'count' => 10],
            ['id' => 2, 'name' => 'Sony', 'slug' => 'sony', 'count' => 5],
            ['id' => 3, 'name' => 'Logitech', 'slug' => 'logitech', 'count' => 8],
        ],
    ]);

    $plan = app(BrandDuplicateFinder::class)->discover();

    expect($plan)->toBe([]);
});

it('Case F: pagination across 3 pages — all dup groups discovered, clean slug wins each', function (): void {
    // Page 1: full 100-row page — one Poly pair embedded among 98 singletons.
    $page1 = [];
    for ($i = 1; $i <= 98; $i++) {
        $page1[] = ['id' => 100 + $i, 'name' => "Brand{$i}", 'slug' => "brand{$i}", 'count' => 1];
    }
    $page1[] = ['id' => 500, 'name' => 'Poly', 'slug' => 'poly', 'count' => 1];
    $page1[] = ['id' => 501, 'name' => 'Poly', 'slug' => 'poly-brand', 'count' => 50];

    // Page 2: full 100-row page — one Bose pair embedded among 98 singletons.
    $page2 = [];
    for ($i = 1; $i <= 98; $i++) {
        $page2[] = ['id' => 200 + $i, 'name' => "BrandX{$i}", 'slug' => "brandx{$i}", 'count' => 1];
    }
    $page2[] = ['id' => 600, 'name' => 'Bose', 'slug' => 'bose', 'count' => 1];
    $page2[] = ['id' => 601, 'name' => 'Bose', 'slug' => 'bose-brand', 'count' => 50];

    // Page 3: short page (signals end) — Logitech pair.
    $page3 = [
        ['id' => 700, 'name' => 'Logitech', 'slug' => 'logitech', 'count' => 1],
        ['id' => 701, 'name' => 'Logitech', 'slug' => 'logitech-brand', 'count' => 50],
    ];

    bindBrandsFinderStub([
        1 => $page1,
        2 => $page2,
        3 => $page3,
    ]);

    $plan = app(BrandDuplicateFinder::class)->discover();

    expect($plan)->toHaveKey('poly');
    expect($plan)->toHaveKey('bose');
    expect($plan)->toHaveKey('logitech');
    expect($plan['poly']['canonical']['id'])->toBe(500);
    expect($plan['bose']['canonical']['id'])->toBe(600);
    expect($plan['logitech']['canonical']['id'])->toBe(700);
});

it("Case G: regression — today's 11-brand prod dataset picks clean slugs (Barco=3102, Crestron=3012, LG=2904, Neat=3096, Yealink=3001)", function (): void {
    // The exact 2026-06-13 incident dataset — counts deliberately set so the
    // -brand-suffix rows have higher count (proves count is irrelevant).
    bindBrandsFinderStub([
        1 => [
            // Singletons we still want exercised through the grouping path.
            ['id' => 1001, 'name' => 'Apple', 'slug' => 'apple', 'count' => 5],

            // Barco — clean wins despite lower count.
            ['id' => 3102, 'name' => 'Barco', 'slug' => 'barco', 'count' => 1],
            ['id' => 13033, 'name' => 'Barco', 'slug' => 'barco-brand', 'count' => 99],

            // Crestron.
            ['id' => 3012, 'name' => 'Crestron', 'slug' => 'crestron', 'count' => 1],
            ['id' => 12781, 'name' => 'Crestron', 'slug' => 'crestron-brand', 'count' => 99],

            // LG.
            ['id' => 2904, 'name' => 'LG', 'slug' => 'lg', 'count' => 1],
            ['id' => 12779, 'name' => 'LG', 'slug' => 'lg-brand', 'count' => 99],

            // Neat.
            ['id' => 3096, 'name' => 'Neat', 'slug' => 'neat', 'count' => 1],
            ['id' => 12777, 'name' => 'Neat', 'slug' => 'neat-brand', 'count' => 99],

            // Yealink.
            ['id' => 3001, 'name' => 'Yealink', 'slug' => 'yealink', 'count' => 1],
            ['id' => 12776, 'name' => 'Yealink', 'slug' => 'yealink-brand', 'count' => 99],
        ],
    ]);

    $plan = app(BrandDuplicateFinder::class)->discover();

    // Apple singleton must be absent.
    expect($plan)->not->toHaveKey('apple');

    // The clean-slug term wins every group (NOT the -brand-suffix one).
    expect($plan['barco']['canonical']['id'])->toBe(3102);
    expect($plan['barco']['sources'][0]['id'])->toBe(13033);

    expect($plan['crestron']['canonical']['id'])->toBe(3012);
    expect($plan['crestron']['sources'][0]['id'])->toBe(12781);

    expect($plan['lg']['canonical']['id'])->toBe(2904);
    expect($plan['lg']['sources'][0]['id'])->toBe(12779);

    expect($plan['neat']['canonical']['id'])->toBe(3096);
    expect($plan['neat']['sources'][0]['id'])->toBe(12777);

    expect($plan['yealink']['canonical']['id'])->toBe(3001);
    expect($plan['yealink']['sources'][0]['id'])->toBe(12776);
});

/**
 * Bind an anonymous-subclass WooClient stub into the container so
 * BrandDuplicateFinder picks it up via constructor injection.
 *
 * Each brand row may carry: id, name, slug, count. The new slug-quality
 * ranking reads `slug`; the existing display path still reads `count`.
 *
 * @param  array<int, array<int, array{id:int,name:string,slug?:string,count?:int}>>  $brandsByPage
 */
function bindBrandsFinderStub(array $brandsByPage): object
{
    $stub = new class($brandsByPage) extends WooClient
    {
        public function __construct(
            /** @var array<int, array<int, array{id:int,name:string,slug?:string,count?:int}>> */
            public array $brandsByPage,
        ) {
            // Skip parent constructor — no IntegrationLogger / resolver needed.
        }

        public function get(string $endpoint, array $query = []): array
        {
            if ($endpoint !== 'products/brands') {
                return [];
            }
            $page = (int) ($query['page'] ?? 1);

            return $this->brandsByPage[$page] ?? [];
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}
