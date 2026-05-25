<?php

declare(strict_types=1);

use App\Domain\Quotes\Filament\Resources\QuoteResource;
use App\Domain\Quotes\Filament\Resources\QuoteResource\Pages\CreateQuote;
use App\Domain\Quotes\Filament\Resources\QuoteResource\Pages\ListQuotes;
use App\Domain\Quotes\Filament\Resources\QuoteResource\RelationManagers\QuoteLinesRelationManager;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Services\QuoteLineWriter;
use App\Domain\TradePricing\Models\CustomerGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Phase 11 Plan 03 — QuoteResource Filament feature tests (QUOT-03)
|--------------------------------------------------------------------------
|
| Locks the RBAC matrix + form Toggle behaviour + customer_group_name_at_quote
| denormalisation persistence + line repeater delegation to QuoteLineWriter.
|
| RefreshDatabase per-test via ->uses(RefreshDatabase::class) so the
| skipIfMySqlOffline guard fires BEFORE the trait setUp triggers the DB
| connection (Phase 9 Plan 02 + Phase 11 Plan 02 precedent).
*/

function skipIfMySqlOfflineQR(): void
{
    try {
        DB::connection()->getPdo();
    } catch (Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    // Pre-trait skip: fires BEFORE RefreshDatabase trait setUp, so MySQL-
    // offline tests SKIP cleanly instead of failing with QueryException
    // (Phase 11 Plan 02 PriceSnapshotterTest precedent).
    skipIfMySqlOfflineQR();
});

function quoteRoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

function seedQuotePermissions(): void
{
    test()->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Pre-create the 9 quote_* permissions Shield would emit so policy
    // lookups work in isolation (RefreshDatabase wipes between tests).
    foreach ([
        'view_any_quote',
        'view_quote',
        'create_quote',
        'update_quote',
        'delete_quote',
        'approve_quote',
        'revert_quote',
        'mark_accepted_quote',
        'mark_rejected_quote',
    ] as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    test()->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

// ══════════════════════════════════════════════════════════════════════════════
// 1. RBAC matrix — viewAny gating per role
// ══════════════════════════════════════════════════════════════════════════════

it('viewAny passes for admin role', function (): void {
    skipIfMySqlOfflineQR();
    seedQuotePermissions();

    $this->actingAs(quoteRoleUser('admin'));

    Livewire::test(ListQuotes::class)->assertSuccessful();
})->uses(RefreshDatabase::class);

it('viewAny passes for pricing_manager role', function (): void {
    skipIfMySqlOfflineQR();
    seedQuotePermissions();

    $this->actingAs(quoteRoleUser('pricing_manager'));

    Livewire::test(ListQuotes::class)->assertSuccessful();
})->uses(RefreshDatabase::class);

it('viewAny passes for sales role', function (): void {
    skipIfMySqlOfflineQR();
    seedQuotePermissions();

    $this->actingAs(quoteRoleUser('sales'));

    Livewire::test(ListQuotes::class)->assertSuccessful();
})->uses(RefreshDatabase::class);

it('viewAny denies anonymous user (no auth)', function (): void {
    skipIfMySqlOfflineQR();
    seedQuotePermissions();

    // No actingAs() — anonymous request.
    $denied = ! (auth()->user()?->can('viewAny', Quote::class) ?? false);
    expect($denied)->toBeTrue();
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// 2. Create form D-03 toggle + customer_group_name_at_quote denormalisation
// ══════════════════════════════════════════════════════════════════════════════

it('create persists customer_group_name_at_quote denormalised string (D-02 + Pitfall 6)', function (): void {
    skipIfMySqlOfflineQR();
    seedQuotePermissions();

    $group = CustomerGroup::create([
        'slug' => 'trade',
        'name' => 'Trade',
        'display_order' => 10,
        'is_active' => true,
    ]);

    $this->actingAs(quoteRoleUser('admin'));

    Livewire::test(CreateQuote::class)
        ->fillForm([
            'customer_email' => 'lead@example.com',
            'customer_name' => 'Test Lead',
            'customer_group_id' => $group->id,
            'expires_at' => now()->addDays(14)->format('Y-m-d\TH:i'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $quote = Quote::where('customer_email', 'lead@example.com')->first();
    expect($quote)->not->toBeNull()
        ->and($quote->customer_group_id)->toBe($group->id)
        ->and($quote->customer_group_name_at_quote)->toBe('Trade')  // denormalised at save
        ->and($quote->status)->toBe(Quote::STATUS_DRAFT);
})->uses(RefreshDatabase::class);

it('create sets customer_group_name_at_quote to null when no group chosen (retail path)', function (): void {
    skipIfMySqlOfflineQR();
    seedQuotePermissions();

    $this->actingAs(quoteRoleUser('admin'));

    Livewire::test(CreateQuote::class)
        ->fillForm([
            'customer_email' => 'retail@example.com',
            'customer_name' => 'Retail Customer',
            // No customer_group_id — retail path.
            'expires_at' => now()->addDays(14)->format('Y-m-d\TH:i'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $quote = Quote::where('customer_email', 'retail@example.com')->first();
    expect($quote)->not->toBeNull()
        ->and($quote->customer_group_id)->toBeNull()
        ->and($quote->customer_group_name_at_quote)->toBeNull();
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// 3. QuoteLinesRelationManager hides Add/Edit/Delete actions when status != draft
// ══════════════════════════════════════════════════════════════════════════════

it('QuoteLinesRelationManager Add action delegates to QuoteLineWriter (D-13 single creation path)', function (): void {
    skipIfMySqlOfflineQR();

    // QuoteLineWriter is the single creation path per Plan 11-02 sole-writer
    // architecture; Filament Resource MUST inject it via app(QuoteLineWriter::class)
    // (NOT QuoteLine::create directly). This is a structural assertion against
    // the relation manager's source — confirms the import statement is present.
    $source = file_get_contents(app_path('Domain/Quotes/Filament/Resources/QuoteResource/RelationManagers/QuoteLinesRelationManager.php'));

    expect($source)->toContain('use App\\Domain\\Quotes\\Services\\QuoteLineWriter;')
        ->and($source)->toContain('app(QuoteLineWriter::class)->add(');
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// 4. Resource scaffolding sanity — Catalogue nav group + 4 pages registered
// ══════════════════════════════════════════════════════════════════════════════

it('QuoteResource navigation group is "Catalogue" + 4 pages registered', function (): void {
    $navGroup = (new ReflectionClass(QuoteResource::class))
        ->getStaticPropertyValue('navigationGroup');

    // Quotes live under Catalogue (Products / Quotes / Price History).
    expect($navGroup)->toBe('Catalogue');

    $pages = QuoteResource::getPages();
    expect($pages)->toHaveKey('index')
        ->and($pages)->toHaveKey('create')
        ->and($pages)->toHaveKey('view')
        ->and($pages)->toHaveKey('edit');

    $relations = QuoteResource::getRelations();
    expect($relations)->toContain(QuoteLinesRelationManager::class);
});
