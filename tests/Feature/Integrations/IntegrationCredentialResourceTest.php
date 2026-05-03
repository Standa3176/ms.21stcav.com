<?php

declare(strict_types=1);

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Filament\Resources\IntegrationCredentialResource\Pages\ListIntegrationCredentials;
use App\Domain\Integrations\Models\IntegrationCredential;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Seed the 4 v1 roles + admin so policy gates resolve correctly.
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $roleName) {
        Role::findOrCreate($roleName);
    }
    $this->seed(RolePermissionSeeder::class);
});

it('admin user can list IntegrationCredentials (Test 3.1 admin path)', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    livewire(ListIntegrationCredentials::class)
        ->actingAs($admin)
        ->assertOk();
})->skip('Filament Livewire test infra requires full panel boot — run via MySQL window');

it('pricing_manager user is denied (403) on the IntegrationCredentialResource (Test 3.1 negative)', function (): void {
    $user = User::factory()->create();
    $user->assignRole('pricing_manager');

    expect($user->can('viewAny', IntegrationCredential::class))->toBeFalse();
});

it('sales user is denied (403) on the IntegrationCredentialResource (Test 3.1 negative)', function (): void {
    $user = User::factory()->create();
    $user->assignRole('sales');

    expect($user->can('viewAny', IntegrationCredential::class))->toBeFalse();
});

it('read_only user is denied (403) on the IntegrationCredentialResource (Test 3.1 negative)', function (): void {
    $user = User::factory()->create();
    $user->assignRole('read_only');

    expect($user->can('viewAny', IntegrationCredential::class))->toBeFalse();
});

it('admin user is granted full CRUD on the IntegrationCredentialResource', function (): void {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $row = IntegrationCredential::factory()->kind(IntegrationCredentialKind::SupplierApi)->create();

    expect($user->can('viewAny', IntegrationCredential::class))->toBeTrue()
        ->and($user->can('view', $row))->toBeTrue()
        ->and($user->can('create', IntegrationCredential::class))->toBeTrue()
        ->and($user->can('update', $row))->toBeTrue()
        ->and($user->can('delete', $row))->toBeTrue();
});

it('UNIQUE(kind) prevents duplicate row creation for the same kind (Test 3.3)', function (): void {
    IntegrationCredential::factory()->kind(IntegrationCredentialKind::WooRest)->create();

    expect(fn () => IntegrationCredential::factory()->kind(IntegrationCredentialKind::WooRest)->create())
        ->toThrow(\Illuminate\Database\QueryException::class);
});
