<?php

declare(strict_types=1);

use App\Domain\Agents\Tools\Pricing\ReadCompetitorPricesTool;
use App\Domain\Agents\Tools\Pricing\ReadMarginHistoryTool;
use App\Domain\Agents\Tools\Pricing\ReadSalesVolume90dTool;
use App\Domain\Agents\Tools\Pricing\ReadSupplierPriceTrendTool;
use App\Domain\Agents\Tools\TruncatingTool;

/*
|--------------------------------------------------------------------------
| Architecture: Phase 12 Plan 01 — TruncatingTool relocation invariant (P12-D)
|--------------------------------------------------------------------------
|
| Phase 12 moves TruncatingTool from app/Domain/Agents/Tools/Pricing/ to the
| shared app/Domain/Agents/Tools/ parent so SeoAgent (Phase 12) + ChatbotAgent
| (Phase 14) + any future agent can extend the same 3-KB cap helper.
|
| This test asserts the relocation succeeded cleanly:
|   1. New FQCN exists (App\Domain\Agents\Tools\TruncatingTool)
|   2. Old FQCN does NOT exist (App\Domain\Agents\Tools\Pricing\TruncatingTool)
|   3. All 4 Phase 10 read_* tools' parent class IS the new FQCN
|   4. The 4 Phase 10 tools are still instantiable via the container
|
| Threat surface: T-12-01-01 — Tampering. Without this test a future tool
| author could accidentally re-introduce a shim at the old FQCN (silently
| splitting subclasses across two parent classes) or forget to update a
| Phase 10 tool's `extends` chain (silently breaking the cap helper). The
| compile-time `is_subclass_of(...)` check below catches both.
*/

it('new TruncatingTool exists at App\\Domain\\Agents\\Tools\\TruncatingTool', function (): void {
    expect(class_exists(TruncatingTool::class))->toBeTrue();
});

it('old TruncatingTool namespace no longer exists (no shim left behind)', function (): void {
    expect(class_exists('App\\Domain\\Agents\\Tools\\Pricing\\TruncatingTool'))->toBeFalse();
});

it('all 4 Phase 10 read_* tools still extend the relocated TruncatingTool', function (): void {
    $phase10Tools = [
        ReadMarginHistoryTool::class,
        ReadCompetitorPricesTool::class,
        ReadSupplierPriceTrendTool::class,
        ReadSalesVolume90dTool::class,
    ];

    foreach ($phase10Tools as $cls) {
        $parent = (new ReflectionClass($cls))->getParentClass();
        expect($parent)->not->toBeFalse(
            $cls.' has no parent class — should extend TruncatingTool'
        );
        expect($parent->getName())->toBe(
            TruncatingTool::class,
            $cls.' parent should be the relocated TruncatingTool'
        );
    }
});

it('all 4 Phase 10 read_* tools still resolve via the container after relocation', function (): void {
    foreach ([
        ReadMarginHistoryTool::class,
        ReadCompetitorPricesTool::class,
        ReadSupplierPriceTrendTool::class,
        ReadSalesVolume90dTool::class,
    ] as $cls) {
        expect(app($cls))->toBeInstanceOf($cls);
    }
});

it('Phase 10 tool name() methods unaffected by relocation (zero behaviour regression)', function (): void {
    expect(app(ReadMarginHistoryTool::class)->name())->toBe('read_margin_history');
    expect(app(ReadCompetitorPricesTool::class)->name())->toBe('read_competitor_prices');
    expect(app(ReadSupplierPriceTrendTool::class)->name())->toBe('read_supplier_price_trend');
    expect(app(ReadSalesVolume90dTool::class)->name())->toBe('read_sales_volume_90d');
});
