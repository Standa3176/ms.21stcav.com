<?php

declare(strict_types=1);

use App\Domain\Sync\Filament\Resources\SyncRunResource;
use App\Domain\Sync\Filament\Resources\SyncRunResource\Pages\ListSyncRuns;
use App\Domain\Sync\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    // Make sure the 4 roles exist (RolePermissionSeeder seeds them on deploy; RefreshDatabase wipes).
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $role) {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

it('admin can reach the sync-runs list', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin);
    SyncRun::factory()->completed()->create();

    Livewire::test(ListSyncRuns::class)->assertSuccessful();
});

it('read_only can reach the sync-runs list (viewAny=true for all 4 roles)', function (): void {
    $user = User::factory()->create();
    $user->assignRole('read_only');

    $this->actingAs($user);
    SyncRun::factory()->completed()->create();

    Livewire::test(ListSyncRuns::class)->assertSuccessful();
});

it('eager-loads errors + items counts (N+1 prevention)', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    SyncRun::factory()->count(5)->completed()->create();

    // Re-check via the Resource's own getEloquentQuery — verify withCount is applied.
    $query = SyncRunResource::getEloquentQuery();
    $first = $query->first();

    expect($first->getAttributes())->toHaveKey('errors_count');
    expect($first->getAttributes())->toHaveKey('items_count');
});

it('filter by status=aborted returns only aborted runs', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $completed = SyncRun::factory()->completed()->create();
    $aborted = SyncRun::factory()->aborted()->create();

    Livewire::test(ListSyncRuns::class)
        ->filterTable('status', [SyncRun::STATUS_ABORTED])
        ->assertCanSeeTableRecords([$aborted])
        ->assertCanNotSeeTableRecords([$completed]);
});

it('retry action visibility + authorize closures are admin-only (Pitfall K)', function (): void {
    // Closure truth-table — the EXACT expression used in the Action's authorize().
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $pricing = User::factory()->create();
    $pricing->assignRole('pricing_manager');
    $sales = User::factory()->create();
    $sales->assignRole('sales');
    $readOnly = User::factory()->create();
    $readOnly->assignRole('read_only');

    $closure = fn (): bool => auth()->user()?->hasRole('admin') ?? false;

    $this->actingAs($admin);
    expect($closure())->toBeTrue();

    $this->actingAs($pricing);
    expect($closure())->toBeFalse();

    $this->actingAs($sales);
    expect($closure())->toBeFalse();

    $this->actingAs($readOnly);
    expect($closure())->toBeFalse();
});

it('SyncRunResource source contains authorize + hasRole on the retry action (Warning 9)', function (): void {
    $source = file_get_contents(app_path('Domain/Sync/Filament/Resources/SyncRunResource.php'));

    expect($source)->toContain("->authorize(fn (): bool => auth()->user()?->hasRole('admin') ?? false)");
    expect($source)->toContain("->visible(fn (): bool => auth()->user()?->hasRole('admin') ?? false)");
});
