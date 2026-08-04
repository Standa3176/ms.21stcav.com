<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Models\WooAttributeTerm;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| 260728-fwx T1 — spec:sync-taxonomy-cache (READ-ONLY vs Woo)
|--------------------------------------------------------------------------
|
| Caches every global pa_* attribute's CURRENT term vocabulary into the local
| woo_attribute_terms table. All Woo I/O is GET-only. Coverage:
|
|   - pa_* attributes cached; non-pa_ excluded
|   - pagination followed (full page → next page; short page stops)
|   - transient term-fetch failure RETRIES then succeeds
|   - permanent term-fetch failure: reported as failed, OTHER attributes still cached
|   - --dry-run writes nothing
|   - --only filters to specific slugs
|   - idempotent re-run does not duplicate (unique constraint)
|   - stale terms pruned on re-run
|   - NO Woo write call ever made (GET only)
|
| Boundary strategy mirrors AuditStockDivergenceCommandTest: an anonymous
| WooClient subclass bound via app()->instance() with fixtures + call capture.
*/

beforeEach(function (): void {
    // Zero the retry backoff so the retry tests never actually sleep.
    config()->set('services.woo.taxonomy_terms_backoff_ms', 0);
    config()->set('services.woo.taxonomy_terms_max_attempts', 4);
});

/**
 * @param  array<int, array<string, mixed>>  $attributes  products/attributes payload
 * @param  array<int, array<int, array<int, array<string, mixed>>>>  $termPages  attrId => [page1[], page2[], ...]
 * @param  array<int, int>  $failTimes  attrId => number of leading GET calls that throw (transient)
 * @param  array<int, bool>  $failAlways  attrId => always throw on terms GET
 */
function bindTaxonomyWooStub(array $attributes, array $termPages, array $failTimes = [], array $failAlways = []): object
{
    $stub = new class extends WooClient
    {
        /** @var array<int, array<string, mixed>> */
        public array $attributes = [];

        /** @var array<int, array<int, array<int, array<string, mixed>>>> */
        public array $termPages = [];

        /** @var array<int, int> */
        public array $failTimes = [];

        /** @var array<int, bool> */
        public array $failAlways = [];

        /** @var array<int, array{endpoint:string, query:array<string,mixed>}> */
        public array $getCalls = [];

        /** @var array<int, array{method:string, endpoint:string}> */
        public array $writeCalls = [];

        public function __construct()
        {
            // Skip parent constructor — pure in-memory fixture.
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->getCalls[] = ['endpoint' => $endpoint, 'query' => $query];

            if ($endpoint === 'products/attributes') {
                return $this->attributes;
            }

            if (preg_match('#^products/attributes/(\d+)/terms$#', $endpoint, $m) === 1) {
                $attrId = (int) $m[1];

                if ($this->failAlways[$attrId] ?? false) {
                    throw new RuntimeException("flaky terms endpoint (permanent) for {$attrId}");
                }
                if (($this->failTimes[$attrId] ?? 0) > 0) {
                    $this->failTimes[$attrId]--;
                    throw new RuntimeException("flaky terms endpoint (transient) for {$attrId}");
                }

                $page = (int) ($query['page'] ?? 1);
                $pages = $this->termPages[$attrId] ?? [];

                return $pages[$page - 1] ?? [];
            }

            return [];
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->writeCalls[] = ['method' => 'PUT', 'endpoint' => $endpoint];

            throw new RuntimeException('WRITE attempted — command must be READ-ONLY.');
        }

        public function post(string $endpoint, array $payload): array
        {
            $this->writeCalls[] = ['method' => 'POST', 'endpoint' => $endpoint];

            throw new RuntimeException('WRITE attempted — command must be READ-ONLY.');
        }

        public function patch(string $endpoint, array $payload): array
        {
            $this->writeCalls[] = ['method' => 'PATCH', 'endpoint' => $endpoint];

            throw new RuntimeException('WRITE attempted — command must be READ-ONLY.');
        }

        public function delete(string $endpoint, array $payload = []): array
        {
            $this->writeCalls[] = ['method' => 'DELETE', 'endpoint' => $endpoint];

            throw new RuntimeException('WRITE attempted — command must be READ-ONLY.');
        }
    };

    $stub->attributes = $attributes;
    $stub->termPages = $termPages;
    $stub->failTimes = $failTimes;
    $stub->failAlways = $failAlways;

    app()->instance(WooClient::class, $stub);

    return $stub;
}

/** Helper — a single Woo term row. */
function term(int $id, string $name, string $slug): array
{
    return ['id' => $id, 'name' => $name, 'slug' => $slug];
}

it('caches pa_* attributes, follows pagination, and excludes non-pa_ attributes', function (): void {
    // 3542 has 150 terms across two pages (100 + 50) → pagination must follow.
    $page1 = [];
    for ($i = 1; $i <= 100; $i++) {
        $page1[] = term(10_000 + $i, "Term {$i}", "term-{$i}");
    }
    $page2 = [];
    for ($i = 101; $i <= 150; $i++) {
        $page2[] = term(10_000 + $i, "Term {$i}", "term-{$i}");
    }

    $stub = bindTaxonomyWooStub(
        attributes: [
            ['id' => 3364, 'slug' => 'pa_material', 'name' => 'Material'],
            ['id' => 3542, 'slug' => 'pa_light-source', 'name' => 'Light Source'],
            // Non-pa_ attribute — must be excluded.
            ['id' => 9999, 'slug' => 'custom_thing', 'name' => 'Custom Thing'],
        ],
        termPages: [
            3364 => [[term(500, 'Aluminium', 'aluminium'), term(501, 'Steel', 'steel')]],
            3542 => [$page1, $page2],
        ],
    );

    $exit = Artisan::call('spec:sync-taxonomy-cache');
    expect($exit)->toBe(0);

    // pa_material: 2 terms; pa_light-source: 150 terms; custom_thing: none.
    expect(WooAttributeTerm::query()->where('attribute_id', 3364)->count())->toBe(2);
    expect(WooAttributeTerm::query()->where('attribute_id', 3542)->count())->toBe(150);
    expect(WooAttributeTerm::query()->where('attribute_id', 9999)->count())->toBe(0);

    // A specific row is correct.
    $row = WooAttributeTerm::query()->where('attribute_id', 3364)->where('term_id', 500)->first();
    expect($row->attribute_slug)->toBe('pa_material');
    expect($row->attribute_name)->toBe('Material');
    expect($row->term_name)->toBe('Aluminium');
    expect($row->term_slug)->toBe('aluminium');

    // Pagination: page 1 and page 2 fetched for 3542; page 3 never requested
    // (page 2 was short → loop stops).
    $termCalls = collect($stub->getCalls)
        ->filter(fn ($c) => $c['endpoint'] === 'products/attributes/3542/terms')
        ->map(fn ($c) => (int) ($c['query']['page'] ?? 1))
        ->values()
        ->all();
    expect($termCalls)->toBe([1, 2]);

    // Custom (non-pa_) attribute terms endpoint never hit.
    expect(collect($stub->getCalls)->contains(fn ($c) => $c['endpoint'] === 'products/attributes/9999/terms'))->toBeFalse();

    // READ-ONLY: no writes.
    expect($stub->writeCalls)->toBeEmpty();
});

it('retries a transient term-fetch failure then caches successfully', function (): void {
    // First TWO GETs to the terms endpoint throw; the 3rd attempt succeeds.
    $stub = bindTaxonomyWooStub(
        attributes: [['id' => 3268, 'slug' => 'pa_colour', 'name' => 'Colour']],
        termPages: [3268 => [[term(700, 'Black', 'black'), term(701, 'White', 'white')]]],
        failTimes: [3268 => 2],
    );

    $exit = Artisan::call('spec:sync-taxonomy-cache');
    expect($exit)->toBe(0);

    // Eventually cached despite the two transient failures.
    expect(WooAttributeTerm::query()->where('attribute_id', 3268)->count())->toBe(2);

    // The terms endpoint was retried (>= 3 calls: 2 failed + at least 1 success).
    $calls = collect($stub->getCalls)->filter(fn ($c) => $c['endpoint'] === 'products/attributes/3268/terms')->count();
    expect($calls)->toBeGreaterThanOrEqual(3);

    expect($stub->writeCalls)->toBeEmpty();
});

it('reports a permanently-failing attribute but still caches the others', function (): void {
    $stub = bindTaxonomyWooStub(
        attributes: [
            ['id' => 3268, 'slug' => 'pa_colour', 'name' => 'Colour'],
            ['id' => 3429, 'slug' => 'pa_resolution', 'name' => 'Resolution'],
        ],
        termPages: [
            3429 => [[term(800, '4K UHD (3840x2160)', '4k-uhd')]],
        ],
        failAlways: [3268 => true],
    );

    $exit = Artisan::call('spec:sync-taxonomy-cache');
    // Per-attribute failure is reported, NOT fatal — run still exits 0.
    expect($exit)->toBe(0);

    // The healthy attribute cached; the failing one did not.
    expect(WooAttributeTerm::query()->where('attribute_id', 3429)->count())->toBe(1);
    expect(WooAttributeTerm::query()->where('attribute_id', 3268)->count())->toBe(0);

    // Failure reported in output (never silently dropped).
    $output = Artisan::output();
    expect($output)->toContain('pa_colour');
    expect($output)->toContain('FAILED');

    expect($stub->writeCalls)->toBeEmpty();
});

it('--dry-run reports counts without writing any rows', function (): void {
    $stub = bindTaxonomyWooStub(
        attributes: [['id' => 3364, 'slug' => 'pa_material', 'name' => 'Material']],
        termPages: [3364 => [[term(500, 'Aluminium', 'aluminium'), term(501, 'Steel', 'steel')]]],
    );

    $exit = Artisan::call('spec:sync-taxonomy-cache', ['--dry-run' => true]);
    expect($exit)->toBe(0);

    // Nothing persisted.
    expect(WooAttributeTerm::query()->count())->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('DRY-RUN');

    expect($stub->writeCalls)->toBeEmpty();
});

it('--only filters the sync to the named slugs', function (): void {
    $stub = bindTaxonomyWooStub(
        attributes: [
            ['id' => 3364, 'slug' => 'pa_material', 'name' => 'Material'],
            ['id' => 3268, 'slug' => 'pa_colour', 'name' => 'Colour'],
        ],
        termPages: [
            3364 => [[term(500, 'Aluminium', 'aluminium')]],
            3268 => [[term(700, 'Black', 'black')]],
        ],
    );

    $exit = Artisan::call('spec:sync-taxonomy-cache', ['--only' => 'pa_colour']);
    expect($exit)->toBe(0);

    expect(WooAttributeTerm::query()->where('attribute_id', 3268)->count())->toBe(1);
    expect(WooAttributeTerm::query()->where('attribute_id', 3364)->count())->toBe(0);

    // The filtered-out attribute's terms endpoint was never hit.
    expect(collect($stub->getCalls)->contains(fn ($c) => $c['endpoint'] === 'products/attributes/3364/terms'))->toBeFalse();

    expect($stub->writeCalls)->toBeEmpty();
});

it('--only tolerates a slug without the pa_ prefix', function (): void {
    bindTaxonomyWooStub(
        attributes: [['id' => 3268, 'slug' => 'pa_colour', 'name' => 'Colour']],
        termPages: [3268 => [[term(700, 'Black', 'black')]]],
    );

    $exit = Artisan::call('spec:sync-taxonomy-cache', ['--only' => 'colour']);
    expect($exit)->toBe(0);

    expect(WooAttributeTerm::query()->where('attribute_id', 3268)->count())->toBe(1);
});

it('is idempotent — a re-run does not duplicate rows', function (): void {
    bindTaxonomyWooStub(
        attributes: [['id' => 3364, 'slug' => 'pa_material', 'name' => 'Material']],
        termPages: [3364 => [[term(500, 'Aluminium', 'aluminium'), term(501, 'Steel', 'steel')]]],
    );

    Artisan::call('spec:sync-taxonomy-cache');
    Artisan::call('spec:sync-taxonomy-cache');

    // Unique(attribute_id, term_id) → still exactly 2 rows.
    expect(WooAttributeTerm::query()->where('attribute_id', 3364)->count())->toBe(2);
    expect(WooAttributeTerm::query()->count())->toBe(2);
});

it('prunes stale terms that are no longer present on a re-run', function (): void {
    // NOTE: Artisan caches the resolved command (with its injected WooClient)
    // across calls in one process, so we mutate the SAME stub's fixtures
    // between runs rather than rebinding a fresh stub.
    $stub = bindTaxonomyWooStub(
        attributes: [['id' => 3364, 'slug' => 'pa_material', 'name' => 'Material']],
        termPages: [3364 => [[
            term(500, 'Aluminium', 'aluminium'),
            term(501, 'Steel', 'steel'),
            term(502, 'Plastic', 'plastic'),
        ]]],
    );
    Artisan::call('spec:sync-taxonomy-cache');
    expect(WooAttributeTerm::query()->where('attribute_id', 3364)->count())->toBe(3);

    // Second run — term 502 removed upstream → must be pruned locally.
    $stub->termPages = [3364 => [[
        term(500, 'Aluminium', 'aluminium'),
        term(501, 'Steel', 'steel'),
    ]]];
    Artisan::call('spec:sync-taxonomy-cache');

    expect(WooAttributeTerm::query()->where('attribute_id', 3364)->count())->toBe(2);
    expect(WooAttributeTerm::query()->where('attribute_id', 3364)->where('term_id', 502)->exists())->toBeFalse();
});

it('updates changed term metadata in place on a re-run', function (): void {
    $stub = bindTaxonomyWooStub(
        attributes: [['id' => 3364, 'slug' => 'pa_material', 'name' => 'Material']],
        termPages: [3364 => [[term(500, 'Aluminium', 'aluminium')]]],
    );
    Artisan::call('spec:sync-taxonomy-cache');

    // Mutate the same stub (Artisan caches the command instance across calls).
    $stub->termPages = [3364 => [[term(500, 'Aluminium Alloy', 'aluminium-alloy')]]];
    Artisan::call('spec:sync-taxonomy-cache');

    $row = WooAttributeTerm::query()->where('attribute_id', 3364)->where('term_id', 500)->first();
    expect($row->term_name)->toBe('Aluminium Alloy');
    expect($row->term_slug)->toBe('aluminium-alloy');
    expect(WooAttributeTerm::query()->count())->toBe(1);
});
