<?php

declare(strict_types=1);

use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Quick task 260726-bwa — brands:audit-woo-membership (READ-ONLY audit)
|--------------------------------------------------------------------------
|
| The audit reads the TRUE live Woo product_brand term membership per duplicate
| group so the operator can pick the right canonical before any merge. It NEVER
| writes to Woo — the stub below throws on put/post/patch/delete and every test
| asserts $stub->writeCalls === [].
|
| Cases:
|   A — bug reproduction (samsung): finder canonical (clean slug) is EMPTY-ish,
|       source term holds most products → suggested_canonical = source,
|       finder_disagrees = true; on-only / on-both / distinct maths verified.
|   B — finder AGREES (poly): canonical holds most products → finder_disagrees
|       = false.
|   C — --csv writes per-product rows including a both-tagged product
|       (on_canonical=1, on_source=1) and a source-only product (on_canonical=0,
|       on_source=1).
|   D — per-term cap hit → brands.audit_term_cap_hit audit row, command continues.
|   E — 404 on a term GET → term treated as empty membership, command continues,
|       brands.audit_term_missing audit row.
|   F — READ-ONLY invariant: ANY write call throws; assert writeCalls === [] and
|       every products GET carried status=any.
|
| Boundary strategy: anonymous-subclass WooClient stub with $brandsByPage +
| $membershipByTerm (termId => [ [id,sku,name], ... ]). The BrandDuplicateFinder
| service resolves through the container and constructor-injects WooClient,
| picking up THIS stub automatically. Audit assertions via Spatie Activity.
*/

beforeEach(function (): void {
    bindAuditWooStub([], []);
});

it('Case A: reproduces the samsung bug — populated source, empty canonical → suggest source, disagrees', function (): void {
    $stub = bindAuditWooStub(
        brandsByPage: [
            1 => [
                ['id' => 10, 'name' => 'Samsung', 'slug' => 'samsung', 'count' => 0],
                ['id' => 20, 'name' => 'Samsung', 'slug' => 'samsung-2', 'count' => 163],
            ],
        ],
        membershipByTerm: [
            // canonical (clean slug) holds ONLY the both-tagged product.
            10 => [
                ['id' => 1001, 'sku' => 'S-both', 'name' => 'Both product'],
            ],
            // source (numeric-suffix slug) holds the both-tagged product + 2 more.
            20 => [
                ['id' => 1001, 'sku' => 'S-both', 'name' => 'Both product'],
                ['id' => 1002, 'sku' => 'S-only-1', 'name' => 'Source only 1'],
                ['id' => 1003, 'sku' => 'S-only-2', 'name' => 'Source only 2'],
            ],
        ],
    );

    $exit = Artisan::call('brands:audit-woo-membership');

    expect($exit)->toBe(0);
    expect($stub->writeCalls)->toBe([]);

    $row = Activity::query()
        ->where('description', 'brands.audit_group')
        ->get()
        ->first(fn ($a) => (int) $a->properties['source_id'] === 20);

    expect($row)->not->toBeNull();
    $p = $row->properties;
    expect((int) $p['canonical_id'])->toBe(10);
    expect((int) $p['source_id'])->toBe(20);
    expect($p['canonical_slug'])->toBe('samsung');
    expect($p['source_slug'])->toBe('samsung-2');
    expect((int) $p['canonical_woo_count'])->toBe(1);
    expect((int) $p['source_woo_count'])->toBe(3);
    expect((int) $p['on_canonical_only'])->toBe(0);
    expect((int) $p['on_source_only'])->toBe(2);
    expect((int) $p['on_both'])->toBe(1);
    expect((int) $p['distinct_total'])->toBe(3);
    // Most-products winner is the source (id 20) — NOT the finder's canonical (10).
    expect((int) $p['suggested_canonical_id'])->toBe(20);
    expect((bool) $p['finder_disagrees'])->toBeTrue();
});

it('Case B: finder agrees when canonical holds most products → finder_disagrees false', function (): void {
    $stub = bindAuditWooStub(
        brandsByPage: [
            1 => [
                ['id' => 30, 'name' => 'Poly', 'slug' => 'poly', 'count' => 50],
                ['id' => 31, 'name' => 'poly', 'slug' => 'poly-2', 'count' => 2],
            ],
        ],
        membershipByTerm: [
            30 => [
                ['id' => 2001, 'sku' => 'P-1', 'name' => 'P1'],
                ['id' => 2002, 'sku' => 'P-2', 'name' => 'P2'],
                ['id' => 2003, 'sku' => 'P-3', 'name' => 'P3'],
                ['id' => 2004, 'sku' => 'P-4', 'name' => 'P4'],
                ['id' => 2005, 'sku' => 'P-both', 'name' => 'P both'],
            ],
            31 => [
                ['id' => 2005, 'sku' => 'P-both', 'name' => 'P both'],
            ],
        ],
    );

    $exit = Artisan::call('brands:audit-woo-membership');

    expect($exit)->toBe(0);
    expect($stub->writeCalls)->toBe([]);

    $row = Activity::query()
        ->where('description', 'brands.audit_group')
        ->get()
        ->first(fn ($a) => (int) $a->properties['source_id'] === 31);

    expect($row)->not->toBeNull();
    $p = $row->properties;
    expect((int) $p['canonical_woo_count'])->toBe(5);
    expect((int) $p['source_woo_count'])->toBe(1);
    expect((int) $p['on_canonical_only'])->toBe(4);
    expect((int) $p['on_source_only'])->toBe(0);
    expect((int) $p['on_both'])->toBe(1);
    expect((int) $p['distinct_total'])->toBe(5);
    expect((int) $p['suggested_canonical_id'])->toBe(30);
    expect((bool) $p['finder_disagrees'])->toBeFalse();
});

it('Case C: --csv writes per-product rows with correct on_canonical / on_source flags', function (): void {
    $stub = bindAuditWooStub(
        brandsByPage: [
            1 => [
                ['id' => 10, 'name' => 'Samsung', 'slug' => 'samsung', 'count' => 0],
                ['id' => 20, 'name' => 'Samsung', 'slug' => 'samsung-2', 'count' => 3],
            ],
        ],
        membershipByTerm: [
            10 => [
                ['id' => 1001, 'sku' => 'S-both', 'name' => 'Both product'],
            ],
            20 => [
                ['id' => 1001, 'sku' => 'S-both', 'name' => 'Both product'],
                ['id' => 1002, 'sku' => 'S-only-1', 'name' => 'Source only 1'],
            ],
        ],
    );

    $csvPath = sys_get_temp_dir().'/bwa-audit-'.uniqid().'.csv';

    $exit = Artisan::call('brands:audit-woo-membership', ['--csv' => $csvPath]);

    expect($exit)->toBe(0);
    expect($stub->writeCalls)->toBe([]);
    expect(file_exists($csvPath))->toBeTrue();

    $csv = file_get_contents($csvPath);

    // Header + both-tagged row (1,1) + source-only row (0,1).
    expect($csv)->toContain('product_id');
    expect($csv)->toContain('on_canonical');
    expect($csv)->toContain('on_source');

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv($lines[0]);
    $rows = [];
    foreach (array_slice($lines, 1) as $line) {
        $cells = str_getcsv($line);
        $rows[] = array_combine($header, $cells);
    }

    $both = collect($rows)->first(fn ($r) => (int) $r['product_id'] === 1001);
    $sourceOnly = collect($rows)->first(fn ($r) => (int) $r['product_id'] === 1002);

    expect($both)->not->toBeNull();
    expect((int) $both['on_canonical'])->toBe(1);
    expect((int) $both['on_source'])->toBe(1);

    expect($sourceOnly)->not->toBeNull();
    expect((int) $sourceOnly['on_canonical'])->toBe(0);
    expect((int) $sourceOnly['on_source'])->toBe(1);

    @unlink($csvPath);
});

it('Case D: per-term cap hit → brands.audit_term_cap_hit audit row, command continues', function (): void {
    $stub = bindAuditWooStub(
        brandsByPage: [
            1 => [
                ['id' => 30, 'name' => 'Poly', 'slug' => 'poly', 'count' => 50],
                ['id' => 31, 'name' => 'poly', 'slug' => 'poly-2', 'count' => 3],
            ],
        ],
        membershipByTerm: [
            30 => [
                ['id' => 3001, 'sku' => 'C-1', 'name' => 'C1'],
                ['id' => 3002, 'sku' => 'C-2', 'name' => 'C2'],
                ['id' => 3003, 'sku' => 'C-3', 'name' => 'C3'],
            ],
            31 => [
                ['id' => 3003, 'sku' => 'C-3', 'name' => 'C3'],
            ],
        ],
    );

    $exit = Artisan::call('brands:audit-woo-membership', ['--per-term-cap' => 2]);

    expect($exit)->toBe(0);
    expect($stub->writeCalls)->toBe([]);
    expect(Activity::query()->where('description', 'brands.audit_term_cap_hit')->count())->toBeGreaterThan(0);
});

it('Case E: 404 on a term GET → term treated as empty membership, command continues', function (): void {
    $stub = bindAuditWooStub(
        brandsByPage: [
            1 => [
                ['id' => 30, 'name' => 'Poly', 'slug' => 'poly', 'count' => 50],
                ['id' => 31, 'name' => 'poly', 'slug' => 'poly-2', 'count' => 2],
            ],
        ],
        membershipByTerm: [
            30 => [
                ['id' => 4001, 'sku' => 'E-1', 'name' => 'E1'],
            ],
            // term 31 membership omitted → stub 404s it.
        ],
        termFailBehaviour: [31 => '404'],
    );

    $exit = Artisan::call('brands:audit-woo-membership');

    expect($exit)->toBe(0);
    expect($stub->writeCalls)->toBe([]);
    expect(Activity::query()->where('description', 'brands.audit_term_missing')->count())->toBeGreaterThan(0);

    $row = Activity::query()
        ->where('description', 'brands.audit_group')
        ->get()
        ->first(fn ($a) => (int) $a->properties['source_id'] === 31);
    expect($row)->not->toBeNull();
    expect((int) $row->properties['source_woo_count'])->toBe(0);
});

it('Case F: READ-ONLY — no write calls, every products GET carries status=any', function (): void {
    $stub = bindAuditWooStub(
        brandsByPage: [
            1 => [
                ['id' => 30, 'name' => 'Poly', 'slug' => 'poly', 'count' => 50],
                ['id' => 31, 'name' => 'poly', 'slug' => 'poly-2', 'count' => 2],
            ],
        ],
        membershipByTerm: [
            30 => [['id' => 6001, 'sku' => 'F-1', 'name' => 'F1']],
            31 => [['id' => 6001, 'sku' => 'F-1', 'name' => 'F1']],
        ],
    );

    $exit = Artisan::call('brands:audit-woo-membership');

    expect($exit)->toBe(0);
    expect($stub->writeCalls)->toBe([]);

    $productGets = array_filter(
        $stub->getCalls,
        static fn (array $c): bool => $c['endpoint'] === 'products',
    );
    expect($productGets)->not->toBeEmpty();
    foreach ($productGets as $call) {
        expect($call['query']['status'] ?? null)->toBe('any');
    }
});

/**
 * Bind an anonymous-subclass WooClient stub into the container.
 *
 * @param  array<int, array<int, array<string,mixed>>>  $brandsByPage  page → list of brand rows
 * @param  array<int, array<int, array<string,mixed>>>  $membershipByTerm  termId => [ [id,sku,name], ... ]
 * @param  array<int, '404'>  $termFailBehaviour  termId → per-term GET failure
 */
function bindAuditWooStub(
    array $brandsByPage = [],
    array $membershipByTerm = [],
    array $termFailBehaviour = [],
): object {
    $stub = new class($brandsByPage, $membershipByTerm, $termFailBehaviour) extends WooClient
    {
        /** @var array<int, array{endpoint:string, query:array<string,mixed>}> */
        public array $getCalls = [];

        /** @var array<int, array{method:string, endpoint:string}> */
        public array $writeCalls = [];

        public function __construct(
            public array $brandsByPage,
            public array $membershipByTerm,
            public array $termFailBehaviour,
        ) {
            // Skip parent constructor — no IntegrationLogger / resolver needed.
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->getCalls[] = ['endpoint' => $endpoint, 'query' => $query];

            if ($endpoint === 'products/brands') {
                $page = (int) ($query['page'] ?? 1);

                return $this->brandsByPage[$page] ?? [];
            }

            if ($endpoint === 'products' && isset($query['brand'])) {
                $term = (int) $query['brand'];

                if (($this->termFailBehaviour[$term] ?? null) === '404') {
                    throw new RuntimeException('rest_term_invalid: Term does not exist', 404);
                }

                $page = (int) ($query['page'] ?? 1);
                $perPage = (int) ($query['per_page'] ?? 100);
                $all = $this->membershipByTerm[$term] ?? [];
                $offset = ($page - 1) * $perPage;

                return array_slice($all, $offset, $perPage);
            }

            return [];
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->writeCalls[] = ['method' => 'PUT', 'endpoint' => $endpoint];
            throw new RuntimeException("READ-ONLY audit performed a PUT to {$endpoint}");
        }

        public function post(string $endpoint, array $payload): array
        {
            $this->writeCalls[] = ['method' => 'POST', 'endpoint' => $endpoint];
            throw new RuntimeException("READ-ONLY audit performed a POST to {$endpoint}");
        }

        public function patch(string $endpoint, array $payload): array
        {
            $this->writeCalls[] = ['method' => 'PATCH', 'endpoint' => $endpoint];
            throw new RuntimeException("READ-ONLY audit performed a PATCH to {$endpoint}");
        }

        public function delete(string $endpoint, array $payload = []): array
        {
            $this->writeCalls[] = ['method' => 'DELETE', 'endpoint' => $endpoint];
            throw new RuntimeException("READ-ONLY audit performed a DELETE to {$endpoint}");
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}
