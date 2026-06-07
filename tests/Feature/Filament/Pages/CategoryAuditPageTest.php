<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\CategoryAuditFinding;
use App\Domain\Products\Models\Product;
use App\Filament\Pages\CategoryAuditPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260607-t6w — CategoryAuditPage Pest feature test
|--------------------------------------------------------------------------
|
| Covers (plan Task 7 <behavior>):
|   1. Admin can mount /admin/category-audit (200).
|   2. pricing_manager can mount (200).
|   3. sales gets 403.
|   4. read_only gets 403.
|   5. Page table renders seeded findings (SKU visible).
|   6. Per-row 'run_claude_review' action invokes
|      products:assign-taxonomy with the row's SKU. Mocked via
|      Artisan::shouldReceive (Mockery facade swap).
|   7. Bulk 'bulk_claude_review' action passes a CSV of selected SKUs.
|   8. Footer summary banner renders the per-issue counts + last-run hint.
|   9. When no rows exist, the footer renders the 'never' empty state.
|
| TaxonomyResolver is stubbed via anonymous subclass bound to the container
| (mirror AdCandidatesPageTest pattern) so the SelectFilter brand options
| don't hit Woo REST.
*/

function categoryAuditUser(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

function bindCategoryAuditTaxonomyStub(): void
{
    $stub = new class extends TaxonomyResolver
    {
        public function __construct() {} // skip parent WooClient constructor

        public function allBrands(): array
        {
            return [
                ['id' => 100, 'name' => 'Yealink'],
                ['id' => 101, 'name' => 'Sony'],
            ];
        }
    };
    app()->instance(TaxonomyResolver::class, $stub);
}

function seedFinding(string $sku, string $issueType = 'missing', int $severity = 1, ?int $brandId = null): CategoryAuditFinding
{
    $product = Product::factory()->create(['sku' => $sku, 'status' => 'publish']);

    return CategoryAuditFinding::create([
        'run_id' => '01HX0000000000000000000000',
        'product_id' => $product->id,
        'sku' => $sku,
        'brand_id' => $brandId,
        'brand_name' => $brandId !== null ? 'Yealink' : '',
        'category_id' => null,
        'category_name' => '',
        'issue_type' => $issueType,
        'severity' => $severity,
        'audited_at' => now(),
    ]);
}

beforeEach(function (): void {
    bindCategoryAuditTaxonomyStub();
});

it('admin can mount the page', function (): void {
    $this->actingAs(categoryAuditUser('admin'));

    Livewire::test(CategoryAuditPage::class)->assertSuccessful();
});

it('pricing_manager can mount the page', function (): void {
    $this->actingAs(categoryAuditUser('pricing_manager'));

    Livewire::test(CategoryAuditPage::class)->assertSuccessful();
});

it('sales role gets 403 on page access', function (): void {
    $this->actingAs(categoryAuditUser('sales'));

    expect(CategoryAuditPage::canAccess())->toBeFalse();

    $this->get('/admin/category-audit')->assertForbidden();
});

it('read_only role gets 403 on page access', function (): void {
    $this->actingAs(categoryAuditUser('read_only'));

    expect(CategoryAuditPage::canAccess())->toBeFalse();

    $this->get('/admin/category-audit')->assertForbidden();
});

it('renders seeded findings in the Livewire table', function (): void {
    $this->actingAs(categoryAuditUser('admin'));

    $f1 = seedFinding('FIND-1', 'missing', 1);
    seedFinding('FIND-2', 'orphaned', 2);

    Livewire::test(CategoryAuditPage::class)
        ->assertCanSeeTableRecords(CategoryAuditFinding::all());
});

it('per-row Claude review action invokes products:assign-taxonomy with the row SKU', function (): void {
    $this->actingAs(categoryAuditUser('admin'));

    $finding = seedFinding('CLAUDE-SKU', 'missing', 1);

    // Mock the Artisan facade BEFORE the action fires. The page action calls
    // Artisan::call('products:assign-taxonomy', ['--skus' => $sku]) — we assert
    // the exact command + arg shape.
    Artisan::shouldReceive('call')
        ->once()
        ->with('products:assign-taxonomy', ['--skus' => 'CLAUDE-SKU'])
        ->andReturn(0);

    Livewire::test(CategoryAuditPage::class)
        ->callTableAction('run_claude_review', $finding)
        ->assertHasNoTableActionErrors();
});

it('bulk Claude review action passes a CSV of selected SKUs', function (): void {
    $this->actingAs(categoryAuditUser('admin'));

    $f1 = seedFinding('BULK-1', 'missing', 1);
    $f2 = seedFinding('BULK-2', 'missing', 1);

    // The action implodes pluck('sku') with comma — the keys don't matter
    // (DB-ordered) but the set does. assert against the SET via a closure.
    Artisan::shouldReceive('call')
        ->once()
        ->withArgs(function (string $cmd, array $args): bool {
            if ($cmd !== 'products:assign-taxonomy') {
                return false;
            }
            $skus = explode(',', $args['--skus'] ?? '');

            return count($skus) === 2
                && in_array('BULK-1', $skus, true)
                && in_array('BULK-2', $skus, true);
        })
        ->andReturn(0);

    Livewire::test(CategoryAuditPage::class)
        ->callTableBulkAction(
            'bulk_claude_review',
            CategoryAuditFinding::whereIn('sku', ['BULK-1', 'BULK-2'])->pluck('id')->all(),
        )
        ->assertHasNoTableBulkActionErrors();
});

it('footer summary banner renders per-issue counts when rows exist', function (): void {
    $this->actingAs(categoryAuditUser('admin'));

    seedFinding('SUM-MISS-1', 'missing', 1);
    seedFinding('SUM-MISS-2', 'missing', 1);
    seedFinding('SUM-ORPH-1', 'orphaned', 2);

    Livewire::test(CategoryAuditPage::class)
        ->assertSee('Total findings')
        ->assertSee('missing')
        ->assertSee('orphaned');

    // Sanity-check the underlying getSummary() shape — drift-prevention vs
    // the blade view's per-issue counts.
    $page = new CategoryAuditPage;
    $summary = $page->getSummary();
    expect($summary['total'])->toBe(3);
    expect($summary['missing'])->toBe(2);
    expect($summary['orphaned'])->toBe(1);
    expect($summary['uncategorized'])->toBe(0);
    expect($summary['suspicious'])->toBe(0);
    expect($summary['last_run_at'])->not->toBeNull();
    expect($summary['next_run_hint'])->toBe('Fri 22:00 London');
});

it('footer shows the "never" empty-state when no findings exist', function (): void {
    $this->actingAs(categoryAuditUser('admin'));

    Livewire::test(CategoryAuditPage::class)
        ->assertSee('never');
});
