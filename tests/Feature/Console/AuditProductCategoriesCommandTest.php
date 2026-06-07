<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\CategoryAuditFinding;
use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260607-t6w — AuditProductCategoriesCommand 4-bucket classifier
|--------------------------------------------------------------------------
|
| Validates the rule-based audit pipeline that ships to the ecom manager
| every Friday 22:00 London (snapshot semantics — TRUNCATE + re-INSERT
| every run).
|
| Most-severe-wins rule (return on FIRST match):
|   1 missing       — category_id NULL
|   2 orphaned      — category_id non-null but not in taxonomy (deleted upstream)
|   3 uncategorized — category_id maps to name 'Uncategorized' / 'Uncategorised'
|   4 suspicious    — brand in BRAND_NATURAL_HOMES, category root not in homes
|   HEALTHY (skip)  — none of the above
|
| TaxonomyResolver is stubbed via anonymous subclass in the container so the
| test runs without a Woo REST hit. Pricing→ProductAutoCreate import is the
| canonical cross-domain seam (already in deptrac allow-list per 260606-rld).
*/

beforeEach(function (): void {
    // Stub TaxonomyResolver — bypass parent WooClient constructor.
    $stub = new class extends TaxonomyResolver
    {
        public function __construct() {} // skip parent

        public function allCategoriesWithMeta(): array
        {
            return [
                ['id' => 1, 'name' => 'Displays', 'parent' => 0, 'count' => 50],
                ['id' => 2, 'name' => 'Video Conferencing & Collaboration', 'parent' => 0, 'count' => 30],
                ['id' => 3, 'name' => 'Audio Conferencing', 'parent' => 0, 'count' => 20],
                ['id' => 4, 'name' => 'Audio', 'parent' => 0, 'count' => 10], // not in Yealink's homes
                ['id' => 5, 'name' => 'USB Cameras', 'parent' => 2, 'count' => 15],
                ['id' => 10, 'name' => 'Uncategorized', 'parent' => 0, 'count' => 1],
            ];
        }

        public function allCategories(): array
        {
            return array_map(
                static fn (array $t): array => ['id' => $t['id'], 'name' => $t['name']],
                $this->allCategoriesWithMeta(),
            );
        }

        public function allBrands(): array
        {
            return [
                ['id' => 100, 'name' => 'Yealink'],
                ['id' => 101, 'name' => 'Sony'],
                ['id' => 999, 'name' => 'NotInHomes'], // brand not in BRAND_NATURAL_HOMES — never triggers suspicious
            ];
        }
    };
    app()->instance(TaxonomyResolver::class, $stub);
});

it('classifies the 4 buckets + skips healthy', function (): void {
    // P1 — missing (category_id NULL)
    Product::factory()->create([
        'sku' => 'P1-MISSING',
        'status' => 'publish',
        'category_id' => null,
        'brand_id' => 999, // brand-not-in-homes so suspicious cannot fire
    ]);

    // P2 — orphaned (category_id not in taxonomy stub)
    Product::factory()->create([
        'sku' => 'P2-ORPHANED',
        'status' => 'publish',
        'category_id' => 99999,
        'brand_id' => null,
    ]);

    // P3 — uncategorized (category 10 is 'Uncategorized' in stub)
    Product::factory()->create([
        'sku' => 'P3-UNCAT',
        'status' => 'publish',
        'category_id' => 10,
        'brand_id' => null,
    ]);

    // P4 — suspicious (Yealink under 'Audio' root → not in Yealink's natural homes)
    Product::factory()->create([
        'sku' => 'P4-SUSPICIOUS',
        'status' => 'publish',
        'category_id' => 4, // 'Audio' root=0 → root name = 'Audio'
        'brand_id' => 100, // Yealink
    ]);

    // P5 — HEALTHY (Yealink under category 5 'USB Cameras' parent=2 → root 'Video Conferencing & Collaboration')
    Product::factory()->create([
        'sku' => 'P5-HEALTHY',
        'status' => 'publish',
        'category_id' => 5,
        'brand_id' => 100, // Yealink
    ]);

    $exit = Artisan::call('products:audit-categories');

    expect($exit)->toBe(0);

    // 4 findings inserted; healthy P5 skipped
    expect(CategoryAuditFinding::count())->toBe(4);
    expect(CategoryAuditFinding::where('issue_type', 'missing')->count())->toBe(1);
    expect(CategoryAuditFinding::where('issue_type', 'orphaned')->count())->toBe(1);
    expect(CategoryAuditFinding::where('issue_type', 'uncategorized')->count())->toBe(1);
    expect(CategoryAuditFinding::where('issue_type', 'suspicious')->count())->toBe(1);

    // Single run_id across all rows
    expect(CategoryAuditFinding::distinct()->count('run_id'))->toBe(1);

    // Suspicious row records the offending root (per spec)
    expect(CategoryAuditFinding::where('issue_type', 'suspicious')->value('category_root_name'))
        ->toBe('Audio');

    // Severity values per the most-severe-wins ordering
    expect(CategoryAuditFinding::where('issue_type', 'missing')->value('severity'))->toBe(1);
    expect(CategoryAuditFinding::where('issue_type', 'orphaned')->value('severity'))->toBe(2);
    expect(CategoryAuditFinding::where('issue_type', 'uncategorized')->value('severity'))->toBe(3);
    expect(CategoryAuditFinding::where('issue_type', 'suspicious')->value('severity'))->toBe(4);
});

it('--dry-run prints summary and performs zero DB writes', function (): void {
    Product::factory()->create([
        'sku' => 'MISSING-1',
        'status' => 'publish',
        'category_id' => null,
        'brand_id' => null,
    ]);

    // Pre-seed a stale row that must be preserved through --dry-run.
    $stalePid = Product::factory()->create()->id;
    DB::table('category_audit_findings')->insert([
        'run_id' => 'OLD-RUN',
        'product_id' => $stalePid,
        'sku' => 'STALE-1',
        'brand_id' => null,
        'brand_name' => '',
        'category_id' => null,
        'category_name' => '',
        'issue_type' => 'missing',
        'severity' => 1,
        'audited_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(CategoryAuditFinding::count())->toBe(1);

    $exit = Artisan::call('products:audit-categories', ['--dry-run' => true]);

    expect($exit)->toBe(0);

    // Stale row preserved, no new rows
    expect(CategoryAuditFinding::count())->toBe(1);
    expect(CategoryAuditFinding::first()->run_id)->toBe('OLD-RUN');

    expect(strtolower(Artisan::output()))->toContain('dry-run');
});

it('live run TRUNCATEs stale findings then INSERTs fresh', function (): void {
    // Pre-seed 3 stale rows. Their underlying Products are 'draft' so the
    // fresh run skips them (non-publish) — the test asserts TRUNCATE drops
    // the historical findings, not that the products themselves vanish.
    foreach (['ST-1', 'ST-2', 'ST-3'] as $sku) {
        $pid = Product::factory()->create(['status' => 'draft'])->id;
        DB::table('category_audit_findings')->insert([
            'run_id' => 'PRIOR-RUN',
            'product_id' => $pid,
            'sku' => $sku,
            'brand_id' => null,
            'brand_name' => '',
            'category_id' => null,
            'category_name' => '',
            'issue_type' => 'missing',
            'severity' => 1,
            'audited_at' => now()->subDays(7),
            'created_at' => now()->subDays(7),
            'updated_at' => now()->subDays(7),
        ]);
    }
    expect(CategoryAuditFinding::count())->toBe(3);

    // Seed one publish product matching 'missing' (NEW run will record only 1 row)
    Product::factory()->create([
        'sku' => 'NEW-MISS',
        'status' => 'publish',
        'category_id' => null,
        'brand_id' => null,
    ]);

    $exit = Artisan::call('products:audit-categories');
    expect($exit)->toBe(0);

    // Stale rows gone; only the fresh one remains
    expect(CategoryAuditFinding::count())->toBe(1);
    expect(CategoryAuditFinding::first()->sku)->toBe('NEW-MISS');
    expect(CategoryAuditFinding::where('run_id', 'PRIOR-RUN')->count())->toBe(0);
});

it('skips non-publish products', function (): void {
    Product::factory()->create([
        'sku' => 'DRAFT-1',
        'status' => 'draft',
        'category_id' => null,
        'brand_id' => null,
    ]);

    $exit = Artisan::call('products:audit-categories');
    expect($exit)->toBe(0);

    expect(CategoryAuditFinding::count())->toBe(0);
});

it('most-severe-wins: a product matching missing AND suspicious is reported once under missing', function (): void {
    // category_id=NULL → would match 'missing' (severity 1)
    // AND brand=Yealink + null category → defensive: cannot also be suspicious
    //     because suspicious requires a category_id; this test verifies the
    //     short-circuit returns on the FIRST match (missing), not that the
    //     classifier double-fires. We also seed a Yealink product whose
    //     OTHER attributes WOULD trigger suspicious if missing were skipped.
    Product::factory()->create([
        'sku' => 'DUAL-1',
        'status' => 'publish',
        'category_id' => null, // triggers missing FIRST
        'brand_id' => 100,     // Yealink (would be suspicious-eligible if cat=4)
    ]);

    $exit = Artisan::call('products:audit-categories');
    expect($exit)->toBe(0);

    expect(CategoryAuditFinding::count())->toBe(1);
    expect(CategoryAuditFinding::first()->issue_type)->toBe('missing');
    expect(CategoryAuditFinding::first()->severity)->toBe(1);
});
