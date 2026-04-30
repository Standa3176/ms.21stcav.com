<?php

declare(strict_types=1);

use App\Domain\Agents\Services\Tools\Tool;
use App\Domain\Agents\Tools\Pricing\ProposeMarginBandTool;
use App\Domain\Agents\Tools\Pricing\ReadCompetitorPricesTool;
use App\Domain\Agents\Tools\Pricing\ReadMarginHistoryTool;
use App\Domain\Agents\Tools\Pricing\ReadSalesVolume90dTool;
use App\Domain\Agents\Tools\Pricing\ReadSupplierPriceTrendTool;

/*
|--------------------------------------------------------------------------
| Phase 10 Plan 01 Task 2 — PricingAgent tool stub contract
|--------------------------------------------------------------------------
|
| Plan 10-01 ships compile-time STUBS only — Plan 10-02 will replace each
| `using()` callable body with real DB queries (90-day windows + 3 KB soft
| caps + `_truncated` hints per CONTEXT D-04 + D-05). This test pins the
| contract surface (class names, name() prefixes, asPrismTool() return type,
| stub-marker payload) so Plan 10-02 ships against a stable interface.
|
| Why unit (not feature): no DB access — pure container resolution + Prism
| Tool builder shape. Avoids the MySQL deferral chain that all Feature
| tests inherit from RefreshDatabase.
*/

it('all 5 PricingAgent tool stubs resolve from the container', function (): void {
    foreach ([
        ReadMarginHistoryTool::class,
        ReadCompetitorPricesTool::class,
        ReadSupplierPriceTrendTool::class,
        ReadSalesVolume90dTool::class,
        ProposeMarginBandTool::class,
    ] as $class) {
        expect(app($class))->toBeInstanceOf($class);
        expect(app($class))->toBeInstanceOf(Tool::class);
    }
});

it('each tool name matches the AGNT-05 prefix and the literal Plan 10-01 names', function (): void {
    expect(app(ReadMarginHistoryTool::class)->name())->toBe('read_margin_history');
    expect(app(ReadCompetitorPricesTool::class)->name())->toBe('read_competitor_prices');
    expect(app(ReadSupplierPriceTrendTool::class)->name())->toBe('read_supplier_price_trend');
    expect(app(ReadSalesVolume90dTool::class)->name())->toBe('read_sales_volume_90d');
    expect(app(ProposeMarginBandTool::class)->name())->toBe('propose_margin_band');
});

it('each tool description is a non-empty string', function (): void {
    foreach ([
        ReadMarginHistoryTool::class,
        ReadCompetitorPricesTool::class,
        ReadSupplierPriceTrendTool::class,
        ReadSalesVolume90dTool::class,
        ProposeMarginBandTool::class,
    ] as $class) {
        $desc = app($class)->description();
        expect($desc)->toBeString()->not->toBeEmpty();
        expect(strlen($desc))->toBeGreaterThan(20);
    }
});

it('asPrismTool returns a Prism Tool instance for every tool', function (): void {
    foreach ([
        ReadMarginHistoryTool::class,
        ReadCompetitorPricesTool::class,
        ReadSupplierPriceTrendTool::class,
        ReadSalesVolume90dTool::class,
        ProposeMarginBandTool::class,
    ] as $class) {
        expect(app($class)->asPrismTool())->toBeInstanceOf(\Prism\Prism\Tool::class);
    }
});

it('asPrismTool name on the Prism Tool matches the tool class name()', function (): void {
    foreach ([
        ReadMarginHistoryTool::class => 'read_margin_history',
        ReadCompetitorPricesTool::class => 'read_competitor_prices',
        ReadSupplierPriceTrendTool::class => 'read_supplier_price_trend',
        ReadSalesVolume90dTool::class => 'read_sales_volume_90d',
        ProposeMarginBandTool::class => 'propose_margin_band',
    ] as $class => $expectedName) {
        $prismTool = app($class)->asPrismTool();
        expect($prismTool->name())->toBe($expectedName);
    }
});
