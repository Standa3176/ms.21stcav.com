<?php

declare(strict_types=1);

use App\Domain\Integrations\Filament\Widgets\LatestMarketingAdviceWidget;
use App\Domain\Integrations\Filament\Widgets\MarketingOverviewStats;
use App\Domain\Integrations\Filament\Widgets\MarketingRevenueTrendChart;
use App\Filament\Pages\HomeDashboardPage;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;
use Livewire\Mechanisms\ComponentRegistry;

/*
|--------------------------------------------------------------------------
| 260712-mdx — REGRESSION: Marketing dashboard 500 (widgets not registered)
|--------------------------------------------------------------------------
|
| Reproduces the prod 500 on admin/marketing-dashboard. The chart widget lazy-
| loads its data on a follow-up Livewire AJAX request, which resolves the widget
| by its component NAME through Livewire's ComponentRegistry. Because
| AdminPanelProvider had no ->discoverWidgets pointer for
| App\Domain\Integrations\Filament\Widgets, the three Marketing widgets were
| never registered as Livewire components and getClass($name) threw
| ComponentNotFoundException:
|
|   Unable to find component:
|   [app.domain.integrations.filament.widgets.marketing-revenue-trend-chart]
|
| Filament page tests (Livewire::test) register the component directly and never
| exercise this cross-request registry lookup, so they stayed green while prod
| 500'd. This test boots the real admin panel (Panel::register() runs
| registerLivewireComponents() — exactly what populates the registry at boot)
| and asserts the name<->class round-trip that failed in prod.
*/

$marketingWidgets = [
    'MarketingOverviewStats' => MarketingOverviewStats::class,
    'MarketingRevenueTrendChart' => MarketingRevenueTrendChart::class,
    'LatestMarketingAdviceWidget' => LatestMarketingAdviceWidget::class,
];

beforeEach(function (): void {
    // Build + register the real admin panel so its discovered widgets are
    // registered as Livewire components (mirrors production boot). This is the
    // same provider the app boots; Panel::register() calls
    // registerLivewireComponents(), which is precisely the mechanism whose
    // absence threw ComponentNotFoundException in prod.
    $panel = (new AdminPanelProvider(app()))->panel(Panel::make());
    $panel->register();
});

it('registers each Marketing widget as a resolvable Livewire component', function (string $widget): void {
    $registry = app(ComponentRegistry::class);

    // getName() derives the dotted component name from the class (never throws).
    $name = $registry->getName($widget);

    // getClass() resolves that name back to the class via the alias map. When the
    // widget was never registered it throws ComponentNotFoundException — the exact
    // prod failure. A successful round-trip proves the widget is registered.
    expect($registry->getClass($name))->toBe($widget);
})->with($marketingWidgets);

it('keeps the Marketing widgets OFF the home dashboard (no leak)', function () use ($marketingWidgets): void {
    // Belt-and-braces: discovery only registers the Livewire components; it must
    // NOT add them to the home dashboard, whose composition is the sole
    // responsibility of HomeDashboardPage::getWidgets().
    $homeWidgets = (new HomeDashboardPage)->getWidgets();

    foreach ($marketingWidgets as $widget) {
        expect($homeWidgets)->not->toContain($widget);
    }
});
