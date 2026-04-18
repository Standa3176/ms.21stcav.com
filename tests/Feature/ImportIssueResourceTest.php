<?php

declare(strict_types=1);

use App\Domain\Sync\Filament\Resources\ImportIssueResource\Pages\ListImportIssues;
use App\Domain\Sync\Models\ImportIssue;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $role) {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

it('admin + pricing_manager + sales + read_only can reach the list', function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $roleName) {
        $user = User::factory()->create();
        $user->assignRole($roleName);

        $this->actingAs($user);

        Livewire::test(ListImportIssues::class)->assertSuccessful();
    }
});

it('filter by issue_type=unknown_sku returns only that type', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $unknown = ImportIssue::factory()->create([
        'issue_type' => ImportIssue::TYPE_UNKNOWN_SKU,
    ]);
    $missing = ImportIssue::factory()->create([
        'issue_type' => ImportIssue::TYPE_MISSING_AT_SUPPLIER,
    ]);

    Livewire::test(ListImportIssues::class)
        ->filterTable('issue_type', [ImportIssue::TYPE_UNKNOWN_SKU])
        ->assertCanSeeTableRecords([$unknown])
        ->assertCanNotSeeTableRecords([$missing]);
});

it('markResolved bulk action closures are admin + pricing_manager only (Warning 9)', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $pricing = User::factory()->create();
    $pricing->assignRole('pricing_manager');
    $sales = User::factory()->create();
    $sales->assignRole('sales');
    $readOnly = User::factory()->create();
    $readOnly->assignRole('read_only');

    $closure = fn (): bool => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;

    $this->actingAs($admin);
    expect($closure())->toBeTrue();

    $this->actingAs($pricing);
    expect($closure())->toBeTrue();

    $this->actingAs($sales);
    expect($closure())->toBeFalse();

    $this->actingAs($readOnly);
    expect($closure())->toBeFalse();
});

it('ImportIssueResource source contains authorize + hasAnyRole on markResolved (Warning 9)', function (): void {
    $source = file_get_contents(app_path('Domain/Sync/Filament/Resources/ImportIssueResource.php'));

    expect($source)->toContain("->authorize(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false)");
    expect($source)->toContain("->visible(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false)");
});
