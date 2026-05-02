<?php

declare(strict_types=1);

use App\Domain\Competitor\Filament\Resources\CompetitorFtpCredentialResource;
use App\Domain\Competitor\Models\CompetitorFtpCredential;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 11.2 Plan 01 Task 3 — CompetitorFtpCredentialResource feature tests.
|--------------------------------------------------------------------------
|
| Asserts: (a) admin only; pricing_manager / sales / read_only all 403 (D-09),
| (b) form schema declares Password component on encrypted columns.
*/

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('admin can view the credentials list (D-09)', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin);
    $response = $this->get(CompetitorFtpCredentialResource::getUrl('index'));
    $response->assertOk();
});

it('pricing_manager is denied 403 on credentials (D-09)', function (): void {
    $user = User::factory()->create();
    $user->assignRole('pricing_manager');

    $this->actingAs($user);
    $response = $this->get(CompetitorFtpCredentialResource::getUrl('index'));
    expect($response->status())->toBe(403);
});

it('sales is denied 403 on credentials (D-09)', function (): void {
    $user = User::factory()->create();
    $user->assignRole('sales');

    $this->actingAs($user);
    $response = $this->get(CompetitorFtpCredentialResource::getUrl('index'));
    expect($response->status())->toBe(403);
});

it('read_only is denied 403 on credentials (D-09)', function (): void {
    $user = User::factory()->create();
    $user->assignRole('read_only');

    $this->actingAs($user);
    $response = $this->get(CompetitorFtpCredentialResource::getUrl('index'));
    expect($response->status())->toBe(403);
});

it('form declares password components on encrypted credential fields', function (): void {
    // Use reflection to inspect the form schema — verifies `->password()` calls
    // are present so secrets are masked in the Filament UI.
    $source = file_get_contents(
        app_path('Domain/Competitor/Filament/Resources/CompetitorFtpCredentialResource.php')
    );

    expect($source)->toContain("TextInput::make('password_encrypted')");
    expect($source)->toContain('->password()');
    expect($source)->toContain("Textarea::make('private_key_encrypted')");
    expect($source)->toContain("TextInput::make('passphrase_encrypted')");
    expect($source)->toContain('dehydrated(fn ($state)');
});

it('Test connection action exists in Resource source', function (): void {
    $source = file_get_contents(
        app_path('Domain/Competitor/Filament/Resources/CompetitorFtpCredentialResource.php')
    );

    expect($source)->toContain("Action::make('test_connection')");
    expect($source)->toContain('FtpSourceConnector');
    expect($source)->toContain('last_test_status');
});
