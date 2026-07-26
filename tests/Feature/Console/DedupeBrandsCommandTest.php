<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Quick task 260613-dir — brands:dedupe
|--------------------------------------------------------------------------
|
| 10 Pest cases A-J covering every documented engine outcome:
|
|   A — No duplicates: groups_found=0, no writes, no Woo deletes, no audit rows.
|   B — Canonical = highest count (Poly id=10 count=50 vs poly id=11 count=3).
|   C — Tie-break by lowest id (Bose id=5 vs bose id=8, both count=8 → canonical=5).
|   D — Case mismatch (mixed case) "Poly" / "POLY" / "poly" → all merged into id=10.
|   E — Whitespace trim (" Logitech " vs "Logitech") → grouped under 'logitech'.
|   F — --dry-run writes nothing; stub records ZERO delete invocations.
|   G — --delete-empty-woo-terms happy path: 4 delete calls with force=true;
|       reassign audit rows precede delete audit rows (Phase A → Phase B ordering).
|   H — --delete-empty-woo-terms with Woo 5xx: reassign STILL happened; errors=1;
|       brands.dedupe_woo_term_error audit row exists; exit code = SUCCESS.
|   I — --delete-empty-woo-terms with Woo 404 (idempotent re-run): already_deleted=1,
|       errors=0; brands.dedupe_woo_term_already_deleted audit row exists.
|   J — DB::transaction rollback on per-source UPDATE failure via DB::beforeExecuting:
|       source A rolled back, source B succeeded; brands.dedupe_reassign_failed
|       audit row for A, brands.dedupe_reassigned for B; exit 0.
|
| Boundary strategy: anonymous-subclass WooClient stub with public $brandsByPage +
| $deleteBehaviour + $invocations recording call order (auto-increment counter so
| Phase A → Phase B ordering can be asserted in Case G). Audit log assertions via
| Spatie Activity::query() — Auditor is `final` and cannot be Mockery-mocked.
*/

beforeEach(function (): void {
    // Default stub binding — each test re-binds with its own brandsByPage if needed.
    bindWooBrandsStub([]);
});

it('Case A: no duplicates — groups_found=0, no writes, no Woo deletes', function (): void {
    $stub = bindWooBrandsStub([
        1 => [
            ['id' => 1, 'name' => 'Apple', 'count' => 10],
            ['id' => 2, 'name' => 'Sony', 'count' => 5],
            ['id' => 3, 'name' => 'Logitech', 'count' => 8],
        ],
    ]);

    $exit = Artisan::call('brands:dedupe');

    expect($exit)->toBe(0);
    expect($stub->deleteCalls)->toBe([]);

    $output = Artisan::output();
    expect($output)->toContain('groups_found');

    // No reassign audit rows.
    expect(Activity::query()->where('description', 'like', 'brands.dedupe_%')->count())->toBe(0);
});

it('Case B: canonical = highest count (Poly 50 wins over poly 3)', function (): void {
    bindWooBrandsStub([
        1 => [
            ['id' => 10, 'name' => 'Poly', 'count' => 50],
            ['id' => 11, 'name' => 'poly', 'count' => 3],
        ],
    ]);

    // Seed 3 MS products with brand_id=11.
    seedProductsWithBrand(11, 3, 'B-poly');

    $exit = Artisan::call('brands:dedupe');

    expect($exit)->toBe(0);
    expect(DB::table('products')->where('brand_id', 10)->count())->toBe(3);
    expect(DB::table('products')->where('brand_id', 11)->count())->toBe(0);

    $reassign = Activity::query()
        ->where('description', 'brands.dedupe_reassigned')
        ->get()
        ->first();
    expect($reassign)->not->toBeNull();
    expect($reassign->properties['from_id'])->toBe(11);
    expect($reassign->properties['to_id'])->toBe(10);
    expect($reassign->properties['products_affected'])->toBe(3);
});

it('Case C: tie-break by lowest id when counts equal', function (): void {
    bindWooBrandsStub([
        1 => [
            ['id' => 5, 'name' => 'Bose', 'count' => 8],
            ['id' => 8, 'name' => 'bose', 'count' => 8],
        ],
    ]);

    seedProductsWithBrand(8, 2, 'C-bose');

    $exit = Artisan::call('brands:dedupe');

    expect($exit)->toBe(0);
    // Tie-break: lowest id wins → id=5 is canonical.
    expect(DB::table('products')->where('brand_id', 5)->count())->toBe(2);
    expect(DB::table('products')->where('brand_id', 8)->count())->toBe(0);
});

it('Case D: case mismatch (mixed case) Poly/POLY/poly all merged under id=10', function (): void {
    bindWooBrandsStub([
        1 => [
            ['id' => 10, 'name' => 'Poly', 'count' => 50],
            ['id' => 11, 'name' => 'POLY', 'count' => 30],
            ['id' => 12, 'name' => 'poly', 'count' => 5],
        ],
    ]);

    seedProductsWithBrand(11, 4, 'D-poly-up');
    seedProductsWithBrand(12, 1, 'D-poly-low');

    $exit = Artisan::call('brands:dedupe');

    expect($exit)->toBe(0);
    expect(DB::table('products')->where('brand_id', 10)->count())->toBe(5);
    expect(DB::table('products')->where('brand_id', 11)->count())->toBe(0);
    expect(DB::table('products')->where('brand_id', 12)->count())->toBe(0);

    $reassignCount = Activity::query()
        ->where('description', 'brands.dedupe_reassigned')
        ->count();
    expect($reassignCount)->toBe(2); // sources_merged=2
});

it('Case E: whitespace trim — " Logitech " grouped under "logitech"', function (): void {
    bindWooBrandsStub([
        1 => [
            ['id' => 20, 'name' => 'Logitech', 'count' => 100],
            ['id' => 21, 'name' => ' Logitech ', 'count' => 2],
        ],
    ]);

    seedProductsWithBrand(21, 1, 'E-logi');

    $exit = Artisan::call('brands:dedupe');

    expect($exit)->toBe(0);
    expect(DB::table('products')->where('brand_id', 20)->count())->toBe(1);
    expect(DB::table('products')->where('brand_id', 21)->count())->toBe(0);
});

it('Case F: --dry-run — no writes, no Woo delete invocations, no audit rows', function (): void {
    $stub = bindWooBrandsStub([
        1 => [
            ['id' => 10, 'name' => 'Poly', 'count' => 50],
            ['id' => 11, 'name' => 'poly', 'count' => 3],
        ],
    ]);

    seedProductsWithBrand(11, 3, 'F-poly');

    $exit = Artisan::call('brands:dedupe', ['--dry-run' => true]);

    expect($exit)->toBe(0);
    // Products STILL on id=11 — no writes.
    expect(DB::table('products')->where('brand_id', 11)->count())->toBe(3);
    expect(DB::table('products')->where('brand_id', 10)->count())->toBe(0);

    // No Woo delete calls (and no Woo PUT/POST either since we only stub get/delete).
    expect($stub->deleteCalls)->toBe([]);

    // No audit rows.
    expect(Activity::query()->where('description', 'like', 'brands.dedupe_%')->count())->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('[dry-run]');
    expect($output)->toContain('Section 1 — Duplicate groups');
    expect($output)->toContain('Section 2 — Reassignment plan');
});

it('Case G: --delete-empty-woo-terms happy path — reassigns precede deletes (Phase A → Phase B ordering)', function (): void {
    $stub = bindWooBrandsStub([
        1 => [
            // Group 1: Poly canonical=10 + sources 11, 12.
            ['id' => 10, 'name' => 'Poly', 'count' => 50],
            ['id' => 11, 'name' => 'poly', 'count' => 3],
            ['id' => 12, 'name' => 'POLY', 'count' => 1],
            // Group 2: Bose canonical=20 + sources 21, 22.
            ['id' => 20, 'name' => 'Bose', 'count' => 30],
            ['id' => 21, 'name' => 'bose', 'count' => 2],
            ['id' => 22, 'name' => 'BOSE', 'count' => 1],
        ],
    ]);

    seedProductsWithBrand(11, 1, 'G-poly-1');
    seedProductsWithBrand(12, 1, 'G-poly-2');
    seedProductsWithBrand(21, 1, 'G-bose-1');
    seedProductsWithBrand(22, 1, 'G-bose-2');

    $exit = Artisan::call('brands:dedupe', ['--delete-empty-woo-terms' => true]);

    expect($exit)->toBe(0);

    // DB-side reassigns.
    expect(DB::table('products')->where('brand_id', 10)->count())->toBe(2);
    expect(DB::table('products')->where('brand_id', 20)->count())->toBe(2);

    // 4 delete calls, each carrying force=true and the correct endpoint.
    expect($stub->deleteCalls)->toHaveCount(4);
    $endpoints = array_map(static fn (array $c): string => $c['endpoint'], $stub->deleteCalls);
    sort($endpoints);
    expect($endpoints)->toBe([
        'products/brands/11',
        'products/brands/12',
        'products/brands/21',
        'products/brands/22',
    ]);
    foreach ($stub->deleteCalls as $call) {
        expect($call['payload'])->toBe(['force' => true]);
    }

    // Audit rows: 4× reassigned + 4× woo_term_deleted.
    $reassignRows = Activity::query()
        ->where('description', 'brands.dedupe_reassigned')
        ->orderBy('id')
        ->get();
    $deleteRows = Activity::query()
        ->where('description', 'brands.dedupe_woo_term_deleted')
        ->orderBy('id')
        ->get();
    expect($reassignRows)->toHaveCount(4);
    expect($deleteRows)->toHaveCount(4);

    // CRITICAL ORDERING — every reassign row's id is strictly less than every
    // delete row's id (Phase A completes before Phase B begins).
    $maxReassignId = $reassignRows->max('id');
    $minDeleteId = $deleteRows->min('id');
    expect($maxReassignId)->toBeLessThan($minDeleteId);
});

it('Case H: --delete-empty-woo-terms with Woo 5xx — reassign still happened, errors=1', function (): void {
    bindWooBrandsStub(
        [
            1 => [
                ['id' => 10, 'name' => 'Poly', 'count' => 50],
                ['id' => 11, 'name' => 'poly', 'count' => 3],
            ],
        ],
        deleteBehaviour: [11 => '5xx'],
    );

    seedProductsWithBrand(11, 2, 'H-poly');

    $exit = Artisan::call('brands:dedupe', ['--delete-empty-woo-terms' => true]);

    // Per-candidate errors non-fatal — run completes SUCCESS.
    expect($exit)->toBe(0);

    // Phase A independent of Phase B failure.
    expect(DB::table('products')->where('brand_id', 10)->count())->toBe(2);
    expect(DB::table('products')->where('brand_id', 11)->count())->toBe(0);

    expect(Activity::query()->where('description', 'brands.dedupe_reassigned')->count())->toBe(1);
    expect(Activity::query()->where('description', 'brands.dedupe_woo_term_error')->count())->toBe(1);
    expect(Activity::query()->where('description', 'brands.dedupe_woo_term_deleted')->count())->toBe(0);
    expect(Activity::query()->where('description', 'brands.dedupe_woo_term_already_deleted')->count())->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('errors');
});

it('Case I: --delete-empty-woo-terms with Woo 404 — already_deleted=1, errors=0', function (): void {
    bindWooBrandsStub(
        [
            1 => [
                ['id' => 10, 'name' => 'Poly', 'count' => 50],
                ['id' => 11, 'name' => 'poly', 'count' => 3],
            ],
        ],
        deleteBehaviour: [11 => '404'],
    );

    seedProductsWithBrand(11, 1, 'I-poly');

    $exit = Artisan::call('brands:dedupe', ['--delete-empty-woo-terms' => true]);

    expect($exit)->toBe(0);

    // Reassign STILL happened.
    expect(DB::table('products')->where('brand_id', 10)->count())->toBe(1);

    // 404 → already_deleted, NOT errors.
    expect(Activity::query()->where('description', 'brands.dedupe_woo_term_already_deleted')->count())->toBe(1);
    expect(Activity::query()->where('description', 'brands.dedupe_woo_term_error')->count())->toBe(0);
    expect(Activity::query()->where('description', 'brands.dedupe_reassigned')->count())->toBe(1);
});

it('Case J: DB::transaction rollback on per-source UPDATE failure — batch continues', function (): void {
    bindWooBrandsStub([
        1 => [
            // Group 1: source A (id=11) — UPDATE will be poisoned.
            ['id' => 10, 'name' => 'Poly', 'count' => 50],
            ['id' => 11, 'name' => 'poly', 'count' => 3],
            // Group 2: source B (id=21) — UPDATE runs cleanly.
            ['id' => 20, 'name' => 'Bose', 'count' => 30],
            ['id' => 21, 'name' => 'bose', 'count' => 2],
        ],
    ]);

    seedProductsWithBrand(11, 2, 'J-poly');
    seedProductsWithBrand(21, 3, 'J-bose');

    // Poison the source A UPDATE via DB::beforeExecuting. We detect by the
    // specific SQL bindings: an UPDATE that targets brand_id=11 in the
    // WHERE clause AND sets brand_id=10 in the SET clause.
    DB::beforeExecuting(function (string $query, array $bindings) {
        if (! str_starts_with(strtolower(trim($query)), 'update')) {
            return;
        }
        // Bindings order: [brand_id_new, updated_at, brand_id_old]
        // (brand_id, updated_at) SET ... WHERE brand_id = ?
        if (in_array(11, $bindings, true) && in_array(10, $bindings, true)) {
            throw new RuntimeException('260613-dir test: forced UPDATE failure for source brand_id=11');
        }
    });

    $exit = Artisan::call('brands:dedupe');

    expect($exit)->toBe(0);

    // Source A — transaction rolled back; products STILL on brand_id=11.
    expect(DB::table('products')->where('brand_id', 11)->count())->toBe(2);
    expect(DB::table('products')->where('brand_id', 10)->count())->toBe(0);

    // Source B — succeeded; products migrated 21 → 20.
    expect(DB::table('products')->where('brand_id', 20)->count())->toBe(3);
    expect(DB::table('products')->where('brand_id', 21)->count())->toBe(0);

    expect(Activity::query()->where('description', 'brands.dedupe_reassign_failed')->count())->toBe(1);
    expect(Activity::query()->where('description', 'brands.dedupe_reassigned')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Quick task 260726-deg — fail-safe live Woo emptiness guard (Phase B)
|--------------------------------------------------------------------------
|
| The 260726-bwa audit proved the LOCAL products.brand_id view cannot see Woo
| product_brand many-to-many membership: a source term with 0 local products can
| still hold hundreds of live Woo products. Phase B must NEVER delete a term the
| live Woo `products?brand=<id>` read still reports as non-empty.
|
|   K — non-empty Woo term is NOT deleted: skipped_non_empty=1, no delete call,
|       brands.dedupe_woo_term_not_empty_skipped audit row (RED-first).
|   L — empty Woo term IS still deleted exactly as today (regression).
|   M — emptiness check throws NON-404 ⇒ fail-safe skip (never delete when we
|       cannot prove empty): brands.dedupe_woo_emptiness_check_failed, no delete.
|   N — emptiness check 404 (term already gone) ⇒ delete proceeds ⇒ existing
|       already_deleted path still works.
|   O — --dry-run performs GET reads but ZERO deletes, and Section 3 reflects
|       skip-vs-delete per term.
*/

it('Case K: non-empty Woo term is NOT deleted (skipped_non_empty + audit, no delete call)', function (): void {
    $stub = bindWooBrandsStub(
        [
            1 => [
                ['id' => 10, 'name' => 'Yealink', 'count' => 285],
                ['id' => 11, 'name' => 'yealink', 'count' => 0],
            ],
        ],
        // Live Woo still returns a product for the source term id=11 despite
        // Phase A having "reassigned" it locally (many-to-many membership).
        productsByBrand: [11 => [['id' => 9001]]],
    );

    // Locally there are zero products on id=11 (the trap: local view says "empty").
    $exit = Artisan::call('brands:dedupe', ['--delete-empty-woo-terms' => true]);

    expect($exit)->toBe(0);

    // HARD SAFETY INVARIANT: no DELETE against the still-populated source term.
    $deleteEndpoints = array_map(static fn (array $c): string => $c['endpoint'], $stub->deleteCalls);
    expect($deleteEndpoints)->not->toContain('products/brands/11');
    expect($stub->deleteCalls)->toBe([]);

    // Skip counter + audit row.
    $output = Artisan::output();
    expect($output)->toContain('skipped_non_empty');

    $skipRow = Activity::query()
        ->where('description', 'brands.dedupe_woo_term_not_empty_skipped')
        ->first();
    expect($skipRow)->not->toBeNull();
    expect($skipRow->properties['source_id'])->toBe(11);
    expect($skipRow->properties['canonical_id'])->toBe(10);

    // The term was NOT counted as deleted.
    expect(Activity::query()->where('description', 'brands.dedupe_woo_term_deleted')->count())->toBe(0);
});

it('Case L: empty Woo term is still deleted exactly as today (regression)', function (): void {
    $stub = bindWooBrandsStub(
        [
            1 => [
                ['id' => 10, 'name' => 'Poly', 'count' => 50],
                ['id' => 11, 'name' => 'poly', 'count' => 3],
            ],
        ],
        // No productsByBrand entry ⇒ live Woo read returns [] ⇒ proven empty ⇒ delete.
    );

    seedProductsWithBrand(11, 3, 'L-poly');

    $exit = Artisan::call('brands:dedupe', ['--delete-empty-woo-terms' => true]);

    expect($exit)->toBe(0);
    expect(DB::table('products')->where('brand_id', 10)->count())->toBe(3);

    // Delete fired exactly once, with force=true, against the empty term.
    expect($stub->deleteCalls)->toHaveCount(1);
    expect($stub->deleteCalls[0]['endpoint'])->toBe('products/brands/11');
    expect($stub->deleteCalls[0]['payload'])->toBe(['force' => true]);

    expect(Activity::query()->where('description', 'brands.dedupe_woo_term_deleted')->count())->toBe(1);
    expect(Activity::query()->where('description', 'brands.dedupe_woo_term_not_empty_skipped')->count())->toBe(0);
});

it('Case M: emptiness check throws NON-404 ⇒ fail-safe skip (never delete when unproven empty)', function (): void {
    $stub = bindWooBrandsStub(
        [
            1 => [
                ['id' => 10, 'name' => 'Logitech', 'count' => 176],
                ['id' => 11, 'name' => 'logitech', 'count' => 0],
            ],
        ],
        emptinessCheckBehaviour: [11 => '5xx'],
    );

    $exit = Artisan::call('brands:dedupe', ['--delete-empty-woo-terms' => true]);

    expect($exit)->toBe(0);

    // Fail-safe: no delete when the guard read could not prove the term empty.
    expect($stub->deleteCalls)->toBe([]);

    $failRow = Activity::query()
        ->where('description', 'brands.dedupe_woo_emptiness_check_failed')
        ->first();
    expect($failRow)->not->toBeNull();
    expect($failRow->properties['source_id'])->toBe(11);

    expect(Activity::query()->where('description', 'brands.dedupe_woo_term_deleted')->count())->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('skipped_non_empty');
});

it('Case N: emptiness check 404 (term gone) ⇒ delete proceeds ⇒ already_deleted path preserved', function (): void {
    $stub = bindWooBrandsStub(
        [
            1 => [
                ['id' => 10, 'name' => 'Samsung', 'count' => 163],
                ['id' => 11, 'name' => 'samsung', 'count' => 0],
            ],
        ],
        deleteBehaviour: [11 => '404'],
        emptinessCheckBehaviour: [11 => '404'],
    );

    $exit = Artisan::call('brands:dedupe', ['--delete-empty-woo-terms' => true]);

    expect($exit)->toBe(0);

    // 404 on the check ⇒ treat as empty ⇒ the existing delete runs (and 404s).
    expect($stub->deleteCalls)->toHaveCount(1);
    expect($stub->deleteCalls[0]['endpoint'])->toBe('products/brands/11');

    expect(Activity::query()->where('description', 'brands.dedupe_woo_term_already_deleted')->count())->toBe(1);
    expect(Activity::query()->where('description', 'brands.dedupe_woo_emptiness_check_failed')->count())->toBe(0);
    expect(Activity::query()->where('description', 'brands.dedupe_woo_term_not_empty_skipped')->count())->toBe(0);
});

it('Case O: --dry-run performs GET reads but ZERO deletes, and Section 3 reflects skip-vs-delete', function (): void {
    $stub = bindWooBrandsStub(
        [
            1 => [
                // Group 1: non-empty source 11 ⇒ Section 3 must show SKIP.
                ['id' => 10, 'name' => 'Yealink', 'count' => 285],
                ['id' => 11, 'name' => 'yealink', 'count' => 0],
                // Group 2: empty source 21 ⇒ Section 3 must show a delete.
                ['id' => 20, 'name' => 'Bose', 'count' => 30],
                ['id' => 21, 'name' => 'bose', 'count' => 2],
            ],
        ],
        productsByBrand: [11 => [['id' => 9001]]],
    );

    seedProductsWithBrand(11, 0, 'O-noop'); // no-op, keeps intent explicit
    seedProductsWithBrand(21, 2, 'O-bose');

    $exit = Artisan::call('brands:dedupe', ['--dry-run' => true, '--delete-empty-woo-terms' => true]);

    expect($exit)->toBe(0);

    // Dry-run is READ-ONLY: guard GET reads happen, but ZERO deletes.
    expect($stub->deleteCalls)->toBe([]);
    $productReads = array_filter(
        $stub->getCalls,
        static fn (array $c): bool => $c['endpoint'] === 'products',
    );
    expect($productReads)->not->toBe([]);

    // No writes / no audit rows in dry-run.
    expect(Activity::query()->where('description', 'like', 'brands.dedupe_%')->count())->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('Section 3 — Woo term deletes');
    expect($output)->toContain('SKIP');
});

/**
 * Bind an anonymous-subclass WooClient stub into the container.
 *
 * @param  array<int, array<int, array{id:int,name:string,count:int}>>  $brandsByPage  page number => list of brand rows
 * @param  array<int, 'ok'|'404'|'5xx'>  $deleteBehaviour  source_id => per-call delete outcome
 * @param  array<int, array<int, array{id:int}>>  $productsByBrand  source_id => LIVE Woo product rows returned by the emptiness guard read (`products?brand=<id>`)
 * @param  array<int, '404'|'5xx'>  $emptinessCheckBehaviour  source_id => make the guard `products?brand=<id>` read THROW (404 = term gone, 5xx = non-404 uncertainty)
 */
function bindWooBrandsStub(
    array $brandsByPage,
    array $deleteBehaviour = [],
    array $productsByBrand = [],
    array $emptinessCheckBehaviour = [],
): object {
    $stub = new class($brandsByPage, $deleteBehaviour, $productsByBrand, $emptinessCheckBehaviour) extends WooClient
    {
        /** @var array<int, array{endpoint:string, query:array<string,mixed>}> */
        public array $getCalls = [];

        /** @var array<int, array{endpoint:string, payload:array<string,mixed>}> */
        public array $deleteCalls = [];

        public function __construct(
            /** @var array<int, array<int, array{id:int,name:string,count:int}>> */
            public array $brandsByPage,
            /** @var array<int, 'ok'|'404'|'5xx'> */
            public array $deleteBehaviour,
            /** @var array<int, array<int, array{id:int}>> */
            public array $productsByBrand = [],
            /** @var array<int, '404'|'5xx'> */
            public array $emptinessCheckBehaviour = [],
        ) {
            // Skip parent constructor — no IntegrationLogger / resolver needed.
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->getCalls[] = ['endpoint' => $endpoint, 'query' => $query];

            // Emptiness-guard read: GET products?brand=<sourceId>&per_page=1&status=any.
            if ($endpoint === 'products') {
                $brand = (int) ($query['brand'] ?? 0);

                $behaviour = $this->emptinessCheckBehaviour[$brand] ?? null;
                if ($behaviour === '404') {
                    throw new RuntimeException('rest_term_invalid: Term does not exist', 404);
                }
                if ($behaviour === '5xx') {
                    throw new RuntimeException('Stub emptiness-check 5xx for brand='.$brand, 500);
                }

                return $this->productsByBrand[$brand] ?? [];
            }

            if ($endpoint !== 'products/brands') {
                return [];
            }
            $page = (int) ($query['page'] ?? 1);

            return $this->brandsByPage[$page] ?? [];
        }

        public function delete(string $endpoint, array $payload = []): array
        {
            $this->deleteCalls[] = ['endpoint' => $endpoint, 'payload' => $payload];

            // Parse "products/brands/{sourceId}" → sourceId for behaviour lookup.
            $sourceId = 0;
            if (preg_match('#^products/brands/(\d+)$#', $endpoint, $m)) {
                $sourceId = (int) $m[1];
            }

            $behaviour = $this->deleteBehaviour[$sourceId] ?? 'ok';
            if ($behaviour === '404') {
                throw new RuntimeException('rest_term_invalid: Term does not exist', 404);
            }
            if ($behaviour === '5xx') {
                throw new RuntimeException('Stub 5xx for source_id='.$sourceId, 500);
            }

            return ['deleted' => true, 'id' => $sourceId];
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}

/**
 * Seed N Products all pointing at a given brand_id.
 */
function seedProductsWithBrand(int $brandId, int $count, string $skuPrefix): void
{
    for ($i = 1; $i <= $count; $i++) {
        Product::factory()->create([
            'sku' => "{$skuPrefix}-{$i}",
            'brand_id' => $brandId,
            'status' => 'publish',
        ]);
    }
}
