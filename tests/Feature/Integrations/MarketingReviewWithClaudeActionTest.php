<?php

declare(strict_types=1);

use App\Domain\Agents\Jobs\RunAdOptimisationJob;
use App\Domain\Integrations\Filament\Pages\MarketingDashboardPage;
use App\Domain\Integrations\Models\GaChannelMetric;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15b-02 Task 6 — "Review with Claude" header action
|--------------------------------------------------------------------------
|
| Reuses the EXISTING 15b-01 RunAdOptimisationJob (no new agent/integration).
| No-data guard mirrors agents:run-ad-optimisation so button + schedule agree;
| admin-only. Money-safe: advice-only, budget-capped, admin-gated.
*/

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Queue::fake();
});

function reviewGaRow(?string $date = null): GaChannelMetric
{
    return GaChannelMetric::create([
        'date' => $date ?? now()->subDays(2)->toDateString(),
        'channel_group' => 'Paid Search',
        'source_medium' => 'google / cpc',
        'campaign' => 'Brand UK',
        'sessions' => 100,
        'key_events' => 10,
        'transactions' => 3,
        'purchase_revenue_pennies' => 120000,
        'pulled_at' => now(),
    ]);
}

function reviewAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    test()->actingAs($admin);

    return $admin;
}

it('dispatches exactly one RunAdOptimisationJob when recent GA4 data exists', function (): void {
    reviewAdmin();
    reviewGaRow();

    Livewire::test(MarketingDashboardPage::class)
        ->callAction('review_with_claude')
        ->assertHasNoActionErrors();

    Queue::assertPushed(RunAdOptimisationJob::class, 1);
});

it('dispatches NOTHING and warns when there is no recent GA4 data', function (): void {
    reviewAdmin();

    Livewire::test(MarketingDashboardPage::class)
        ->callAction('review_with_claude')
        ->assertNotified();

    Queue::assertNothingPushed();
});

it('does not dispatch when GA4 rows are older than the lookback window', function (): void {
    reviewAdmin();
    reviewGaRow(now()->subDays(60)->toDateString()); // outside the default 14d lookback

    Livewire::test(MarketingDashboardPage::class)
        ->callAction('review_with_claude');

    Queue::assertNothingPushed();
});

it('hides the action for non-admins', function (): void {
    $nonAdmin = User::factory()->create();
    test()->actingAs($nonAdmin);

    // Non-admins may still open the page (authed workspace read) but the
    // admin-gated review action is hidden/denied.
    Livewire::test(MarketingDashboardPage::class)
        ->assertActionHidden('review_with_claude');
});

it('still dispatches in shadow mode (write_enabled=false) — the AgentRun is recorded either way', function (): void {
    config()->set('agents.write_enabled', false);
    reviewAdmin();
    reviewGaRow();

    Livewire::test(MarketingDashboardPage::class)
        ->callAction('review_with_claude');

    Queue::assertPushed(RunAdOptimisationJob::class, 1);
});
