<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;
use App\Filament\Pages\NotificationCentrePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 04 Task 1 — NotificationCentrePage (D-10 / D-11)
|--------------------------------------------------------------------------
|
| Covers:
|   - /admin/notifications responds 200 for authenticated admin
|   - Page exposes 4 tabs via getTabs()
|   - Failed-jobs tab surfaces failed_jobs rows
|   - AlertRecipientResource form gains the 5th receives_* toggle
|   - RBAC: read_only / sales can access the page (read-only ambient intel)
|   - Guest is redirected to login
*/

beforeEach(function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('declares 4 tabs via getTabs()', function (): void {
    $page = new NotificationCentrePage();
    $tabs = $page->getTabs();

    expect(array_keys($tabs))->toBe([
        'failed-jobs',
        'stale-feeds',
        'pending-suggestions',
        'webhook-dlq',
    ]);
});

it('allows an authenticated admin to access /admin/notifications', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get('/admin/notifications')
        ->assertSuccessful();
});

it('redirects an unauthenticated visitor to /login', function (): void {
    $response = $this->get('/admin/notifications');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/login');
});

it('renders failed_jobs rows on the failed-jobs tab', function (): void {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'sync-bulk',
        'payload' => json_encode(['displayName' => 'HypotheticalJob']),
        'exception' => 'RuntimeException: boom',
        'failed_at' => now()->subHours(3),
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin)->get('/admin/notifications');
    $response->assertSuccessful();
    $response->assertSee('sync-bulk');
});

it('exposes receives_weekly_digest on AlertRecipientResource form', function (): void {
    $schema = \App\Domain\Alerting\Filament\Resources\AlertRecipientResource::form(
        \Filament\Forms\Form::make(new \Livewire\Component())
    )->getComponents();

    $names = array_map(fn ($c) => method_exists($c, 'getName') ? $c->getName() : null, $schema);

    expect($names)->toContain('receives_weekly_digest');
});

it('persists receives_weekly_digest via AlertRecipient::create', function (): void {
    $recipient = AlertRecipient::create([
        'email' => 'ops-test@meetingstore.co.uk',
        'name' => 'Ops Test',
        'is_active' => true,
        'receives_weekly_digest' => true,
    ]);

    expect($recipient->fresh()->receives_weekly_digest)->toBeTrue();

    $recipient->update(['receives_weekly_digest' => false]);
    expect($recipient->fresh()->receives_weekly_digest)->toBeFalse();
});

it('allows sales role to access the notification centre (ambient intel)', function (): void {
    $user = User::factory()->create();
    $user->assignRole('sales');

    $this->actingAs($user)
        ->get('/admin/notifications')
        ->assertSuccessful();
});

it('allows read_only role to access the notification centre', function (): void {
    $user = User::factory()->create();
    $user->assignRole('read_only');

    $this->actingAs($user)
        ->get('/admin/notifications')
        ->assertSuccessful();
});

it('renders the page with a wire:poll interval derived from config', function (): void {
    config()->set('dashboard.widget_poll_seconds', 60);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin)->get('/admin/notifications');

    $response->assertSuccessful();
    $response->assertSee('wire:poll', escape: false);
});
