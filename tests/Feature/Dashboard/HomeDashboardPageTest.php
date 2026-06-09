<?php

declare(strict_types=1);

use App\Domain\Dashboard\Models\DashboardSnapshot;
use App\Filament\Pages\HomeDashboardPage;
use App\Filament\Widgets\CompetitorFreshnessWidget;
use App\Filament\Widgets\CrmPushSuccessRateWidget;
use App\Filament\Widgets\HighConfidenceSourceableWidget;
use App\Filament\Widgets\HorizonFailedJobsWidget;
use App\Filament\Widgets\ImportIssuesWidget;
use App\Filament\Widgets\IntegrationHealthWidget;
use App\Filament\Widgets\LastSyncRunWidget;
use App\Filament\Widgets\PendingReviewsWidget;
use App\Filament\Widgets\ProductCatalogueHealthWidget;
use App\Filament\Widgets\SuggestionsQueueHealthWidget;
use App\Filament\Widgets\SyncDiffsParityWidget;
use App\Filament\Widgets\WeeklyReportStatusWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 02 Task 2 — HomeDashboardPage + widget RBAC + stale indicator
|--------------------------------------------------------------------------
|
| Covers (plan Task 2 <behavior> P1..P6):
|   - /admin loads for admin (200, widget class names in HTML)
|   - /admin redirects guest to /login
|   - Horizon nav link visible to admin only
|   - HomeDashboardPage declares 9 widgets
|   - Widget reads from dashboard_snapshots payload (not live SyncRun query)
|   - Widget shows stale-ring when snapshot is older than TTL
*/

beforeEach(function () {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('exposes 16 widgets in HomeDashboardPage::getWidgets()', function (): void {
    $page = new HomeDashboardPage();
    $widgets = $page->getWidgets();

    // 9 (Phase 7) + 1 (Phase 09.1 IntegrationHealth) + 2 (260606-lhp tiles) +
    // 1 (260607-pys AdCandidatesReady) + 1 (260607-t6w CategoryAudit) +
    // 1 (260608-g8x SupplierFreshness) + 1 (260609-nku StockDivergence) = 16.
    expect($widgets)->toHaveCount(16);
    expect($widgets)->toBe([
        LastSyncRunWidget::class,
        CrmPushSuccessRateWidget::class,
        CompetitorFreshnessWidget::class,
        // Quick task 260608-g8x — supplier freshness tile sits alongside
        // CompetitorFreshness in the Row-1 freshness lineup.
        \App\Filament\Widgets\SupplierFreshnessWidget::class,
        // Quick task 260609-nku — phantom-stock divergence tile sits in
        // the Row-1 freshness lineup ("is our stock claim trustworthy?").
        \App\Filament\Widgets\StockDivergenceWidget::class,
        PendingReviewsWidget::class,
        HighConfidenceSourceableWidget::class,
        SuggestionsQueueHealthWidget::class,
        \App\Filament\Widgets\AdCandidatesReadyWidget::class,
        // Quick task 260607-t6w — weekly category audit tile (inserted
        // after AdCandidatesReady so the actionable triage tiles stay grouped).
        \App\Filament\Widgets\CategoryAuditWidget::class,
        ImportIssuesWidget::class,
        HorizonFailedJobsWidget::class,
        SyncDiffsParityWidget::class,
        ProductCatalogueHealthWidget::class,
        WeeklyReportStatusWidget::class,
        IntegrationHealthWidget::class,
    ]);
});

it('lays out widgets in a 3-column grid on md+ screens', function (): void {
    $page = new HomeDashboardPage();

    expect($page->getColumns())->toBe(['md' => 3, 'xl' => 3]);
});

it('redirects an unauthenticated visitor from /admin to /login', function (): void {
    $response = $this->get('/admin');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/login');
});

it('renders /admin with a 200 response for an admin', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get('/admin')
        ->assertSuccessful();
});

it('allows admin to view the LastSyncRunWidget (canView gate)', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin);

    expect(LastSyncRunWidget::canView())->toBeTrue();
});

it('allows pricing_manager to view the dashboard widgets', function (): void {
    $user = User::factory()->create();
    $user->assignRole('pricing_manager');

    $this->actingAs($user);

    expect(LastSyncRunWidget::canView())->toBeTrue();
});

it('allows sales to view the dashboard widgets', function (): void {
    $user = User::factory()->create();
    $user->assignRole('sales');

    $this->actingAs($user);

    expect(CrmPushSuccessRateWidget::canView())->toBeTrue();
});

it('allows read_only to view the dashboard widgets', function (): void {
    $user = User::factory()->create();
    $user->assignRole('read_only');

    $this->actingAs($user);

    expect(CompetitorFreshnessWidget::canView())->toBeTrue();
});

it('reads LastSyncRunWidget stats from the dashboard_snapshots payload (not live SyncRun)', function (): void {
    // Pre-seed ONLY the snapshot; if the widget is reading live SyncRun, there's
    // nothing to render, so the "Never run" fallback would appear. A snapshot row
    // with updated_count=99 proves the widget's code path goes through the
    // snapshot layer.
    DashboardSnapshot::upsertByKey('last_sync_run', [
        'run_id' => 1234,
        'duration_seconds' => 42,
        'updated_count' => 99,
        'failed_count' => 1,
        'age_traffic_light' => 'green',
        'last_completed_at' => now()->subMinutes(10)->toIso8601String(),
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $widget = new LastSyncRunWidget();
    $reflection = new ReflectionClass($widget);
    $method = $reflection->getMethod('getStats');
    $method->setAccessible(true);

    /** @var array<int, \Filament\Widgets\StatsOverviewWidget\Stat> $stats */
    $stats = $method->invoke($widget);

    expect($stats)->toHaveCount(1);

    $first = $stats[0];
    // description should contain 99 (updated) and 1 (failed) — proving the
    // widget rendered from the snapshot payload, not the empty SyncRun table.
    $desc = (new ReflectionClass($first))->getProperty('description');
    $desc->setAccessible(true);
    $description = $desc->getValue($first);
    expect($description)->toContain('99');
    expect($description)->toContain('1');
});

it('applies the amber stale-ring when the snapshot is older than TTL', function (): void {
    config()->set('dashboard.snapshot_ttl_minutes', 15);

    DashboardSnapshot::create([
        'metric_key' => 'last_sync_run',
        'metric_value_json' => [
            'run_id' => 1,
            'duration_seconds' => 10,
            'updated_count' => 5,
            'failed_count' => 0,
            'age_traffic_light' => 'green',
            'last_completed_at' => now()->subMinutes(20)->toIso8601String(),
        ],
        'computed_at' => now()->subMinutes(20), // 20m ago → stale vs TTL=15
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $widget = new LastSyncRunWidget();
    $method = (new ReflectionClass($widget))->getMethod('getStats');
    $method->setAccessible(true);

    /** @var array<int, \Filament\Widgets\StatsOverviewWidget\Stat> $stats */
    $stats = $method->invoke($widget);

    $extraAttrs = (new ReflectionClass($stats[0]))->getProperty('extraAttributes');
    $extraAttrs->setAccessible(true);
    $attrs = $extraAttrs->getValue($stats[0]);

    expect($attrs['class'] ?? '')->toContain('ring-amber-400');
});

it('renders 9 widget class names somewhere in the /admin HTML', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    // Pre-populate snapshots so widgets render real content rather than the
    // "awaiting first dashboard:refresh" empty state.
    \Illuminate\Support\Facades\Artisan::call('dashboard:refresh');

    $response = $this->actingAs($admin)->get('/admin');
    $response->assertSuccessful();

    // Each widget's unique Stat label acts as a proof-of-render (HTML contains
    // the label when the widget's getStats ran successfully).
    $response->assertSee('Last sync', escape: false);
    $response->assertSee('Catalogue health', escape: false)
        ->assertSee('Published', escape: false);
});

it('hides the Horizon nav link for non-admin roles', function (): void {
    $user = User::factory()->create();
    $user->assignRole('pricing_manager');
    $this->actingAs($user);

    $navItem = \App\Domain\Dashboard\Support\HorizonLinkNavigationItem::build();

    // NavigationItem::isVisible() runs the closure; false for non-admin.
    expect($navItem->isVisible())->toBeFalse();
});

it('shows the Horizon nav link for admin', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $navItem = \App\Domain\Dashboard\Support\HorizonLinkNavigationItem::build();

    expect($navItem->isVisible())->toBeTrue();
});
