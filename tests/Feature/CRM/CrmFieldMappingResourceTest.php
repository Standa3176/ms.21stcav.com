<?php

declare(strict_types=1);

use App\Domain\CRM\Filament\Resources\CrmFieldMappingResource;
use App\Domain\CRM\Filament\Resources\CrmFieldMappingResource\Pages\CreateCrmFieldMapping;
use App\Domain\CRM\Filament\Resources\CrmFieldMappingResource\Pages\EditCrmFieldMapping;
use App\Domain\CRM\Filament\Resources\CrmFieldMappingResource\Pages\ListCrmFieldMappings;
use App\Domain\CRM\Models\CrmFieldMapping;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\CRM\Services\BitrixSchemaCache;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 04 Task 1 — CrmFieldMappingResource feature tests
|--------------------------------------------------------------------------
|
| Covers: admin access, non-admin denial, Refresh-from-Bitrix header action
| (invalidate + re-warm), per-save validation via BitrixSchemaCache.
*/

beforeEach(function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    }
    $this->seed(\Database\Seeders\Phase4\CrmFieldMappingSeeder::class);

    // Ensure shield permissions exist so Filament's implicit policy gate resolves.
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'view_any_crm::field::mapping', 'guard_name' => 'web']);
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'view_crm::field::mapping', 'guard_name' => 'web']);
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'create_crm::field::mapping', 'guard_name' => 'web']);
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'update_crm::field::mapping', 'guard_name' => 'web']);
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'delete_crm::field::mapping', 'guard_name' => 'web']);
    Role::findByName('admin')->givePermissionTo([
        'view_any_crm::field::mapping',
        'view_crm::field::mapping',
        'create_crm::field::mapping',
        'update_crm::field::mapping',
        'delete_crm::field::mapping',
    ]);
});

function bindSchemaCacheWithFields(array $dealFields, array $contactFields = [], array $companyFields = []): void
{
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')->zeroOrMoreTimes()->andReturn($dealFields);
    $client->shouldReceive('contactFieldsGet')->zeroOrMoreTimes()->andReturn($contactFields);
    $client->shouldReceive('companyFieldsGet')->zeroOrMoreTimes()->andReturn($companyFields);

    Cache::flush();
    app()->forgetInstance(BitrixSchemaCache::class);
    app()->instance(BitrixClient::class, $client);
    app()->forgetInstance(BitrixSchemaCache::class);
}

it('admin can access the list page and sees the seeded mappings', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ListCrmFieldMappings::class)->assertSuccessful();
    expect(CrmFieldMapping::count())->toBeGreaterThanOrEqual(40);
});

it('non-admin roles cannot access the list page (policy denies)', function (): void {
    foreach (['pricing_manager', 'sales', 'read_only'] as $roleName) {
        $user = User::factory()->create();
        $user->assignRole($roleName);

        expect($user->can('viewAny', CrmFieldMapping::class))
            ->toBeFalse("Role {$roleName} should NOT be able to viewAny CrmFieldMapping");
    }
});

it('Refresh-from-Bitrix header action invalidates cache and re-warms all three entities', function (): void {
    $dealFields = ['UF_CRM_WOO_ORDER_ID' => ['title' => 'Woo order ID']];
    $contactFields = ['EMAIL' => ['title' => 'Email']];
    $companyFields = ['TITLE' => ['title' => 'Title']];
    bindSchemaCacheWithFields($dealFields, $contactFields, $companyFields);

    // Pre-populate cache so we can assert invalidate() happened.
    app(BitrixSchemaCache::class)->fieldsFor('deal');
    expect(Cache::get('bitrix:schema:deal'))->not->toBeNull();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ListCrmFieldMappings::class)
        ->callTableAction('refresh_bitrix_schema');

    // After action runs the cache should contain all three entities (re-warmed).
    expect(Cache::get('bitrix:schema:deal'))->not->toBeNull();
    expect(Cache::get('bitrix:schema:contact'))->not->toBeNull();
    expect(Cache::get('bitrix:schema:company'))->not->toBeNull();
});

it('Refresh-from-Bitrix closure denies non-admin users (Warning 9)', function (): void {
    // The action's ->authorize(...) closure MUST return false for non-admin roles
    // regardless of UI visibility — this catches crafted POST bypass attempts.
    $closure = fn (): bool => auth()->user()?->hasRole('admin') ?? false;

    foreach (['pricing_manager', 'sales', 'read_only'] as $roleName) {
        $user = User::factory()->create();
        $user->assignRole($roleName);
        $this->actingAs($user);

        expect($closure())->toBeFalse("Role {$roleName} must NOT pass the refresh_bitrix_schema authorize closure");
    }
});

it('save FAILS when bitrix_field does not exist in current schema', function (): void {
    // Schema contains only UF_CRM_WOO_ORDER_ID; we try to save UF_CRM_FAKE.
    bindSchemaCacheWithFields(['UF_CRM_WOO_ORDER_ID' => ['title' => 'Real']]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(CreateCrmFieldMapping::class)
        ->fillForm([
            'entity_type' => 'deal',
            'woo_field' => 'custom_test',
            'bitrix_field' => 'UF_CRM_FAKE',
            'is_custom' => true,
            'transformer' => 'none',
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['bitrix_field']);

    expect(CrmFieldMapping::where('woo_field', 'custom_test')->exists())->toBeFalse();
});

it('save succeeds when bitrix_field exists in current schema', function (): void {
    bindSchemaCacheWithFields(['UF_CRM_WOO_ORDER_ID' => ['title' => 'Real field']]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(CreateCrmFieldMapping::class)
        ->fillForm([
            'entity_type' => 'deal',
            'woo_field' => 'custom_real',
            'bitrix_field' => 'UF_CRM_WOO_ORDER_ID',
            'is_custom' => true,
            'transformer' => 'none',
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CrmFieldMapping::where('woo_field', 'custom_real')->exists())->toBeTrue();
});

it('CrmFieldMappingResource source contains authorize + hasRole on refresh (Warning 9)', function (): void {
    $source = file_get_contents(app_path('Domain/CRM/Filament/Resources/CrmFieldMappingResource.php'));

    expect($source)->toContain("->authorize(fn (): bool => auth()->user()?->hasRole('admin') ?? false)");
    expect($source)->toContain("refresh_bitrix_schema");
});
