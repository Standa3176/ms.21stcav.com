<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 06 Task 2 — TRDE-05 retail-callsite parity guardrail
|--------------------------------------------------------------------------
|
| The 6 v1 retail call-sites enumerated in RESEARCH §Summary must NEVER
| reach for TradeRuleResolver. They are the retail code path and must
| remain byte-identical to Phase 3 ship behaviour:
|   1. App\Domain\Pricing\Services\PriceRecomputer
|   2. App\Domain\Pricing\Services\SimulatedImpactCalculator
|   3. App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages\RuleExplorer
|   4. App\Domain\Competitor\Jobs\ComputeMarginSuggestionJob
|   5. App\Domain\ProductAutoCreate\Jobs\CreateWooProductJob
|   6. RuleResolver itself — terminal v1 service
|
| Plus a layer-isolation sweep over the entire Pricing domain — no file
| under app/Domain/Pricing/ may import TradeRuleResolver.
|
| W-04 fix — the dead glob() brace-expansion line that was in the original
| draft has been REMOVED. Symfony Finder is the SINGLE source of truth for
| the layer-isolation sweep.
|
| These tests are PURE source-grep — they run offline (no DB, no Pest
| RefreshDatabase trait).
*/

it('PriceRecomputer does not import TradeRuleResolver', function (): void {
    $source = file_get_contents(base_path('app/Domain/Pricing/Services/PriceRecomputer.php'));
    expect($source)->not->toContain('TradeRuleResolver');
    expect($source)->not->toContain('App\\Domain\\TradePricing\\');
});

it('SimulatedImpactCalculator does not import TradeRuleResolver', function (): void {
    $source = file_get_contents(base_path('app/Domain/Pricing/Services/SimulatedImpactCalculator.php'));
    expect($source)->not->toContain('TradeRuleResolver');
    expect($source)->not->toContain('App\\Domain\\TradePricing\\');
});

it('RuleExplorer page does not import TradeRuleResolver', function (): void {
    $path = base_path('app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php');
    if (! file_exists($path)) {
        test()->markTestSkipped('RuleExplorer page not present');
    }
    $source = file_get_contents($path);
    expect($source)->not->toContain('TradeRuleResolver');
    expect($source)->not->toContain('App\\Domain\\TradePricing\\');
});

it('ComputeMarginSuggestionJob does not import TradeRuleResolver', function (): void {
    $source = file_get_contents(base_path('app/Domain/Competitor/Jobs/ComputeMarginSuggestionJob.php'));
    expect($source)->not->toContain('TradeRuleResolver');
    expect($source)->not->toContain('App\\Domain\\TradePricing\\');
});

it('CreateWooProductJob does not import TradeRuleResolver', function (): void {
    $source = file_get_contents(base_path('app/Domain/ProductAutoCreate/Jobs/CreateWooProductJob.php'));
    expect($source)->not->toContain('TradeRuleResolver');
    expect($source)->not->toContain('App\\Domain\\TradePricing\\');
});

it('RuleResolver itself does not import TradeRuleResolver (decorator sees v1, not the other way around)', function (): void {
    $source = file_get_contents(base_path('app/Domain/Pricing/Services/RuleResolver.php'));
    expect($source)->not->toContain('TradeRuleResolver');
    expect($source)->not->toContain('App\\Domain\\TradePricing\\');
});

it('no file under app/Domain/Pricing/ imports TradeRuleResolver — layer isolation (W-04: Symfony Finder only)', function (): void {
    // W-04 — dead glob() line removed; Symfony Finder is the single source of truth.
    $offenders = [];
    $finder = \Symfony\Component\Finder\Finder::create()
        ->files()
        ->in(base_path('app/Domain/Pricing'))
        ->name('*.php');

    foreach ($finder as $file) {
        $contents = $file->getContents();
        if (str_contains($contents, 'TradePricing\\Services\\TradeRuleResolver')
            || str_contains($contents, 'use App\\Domain\\TradePricing\\Services\\TradeRuleResolver')
        ) {
            $offenders[] = $file->getRelativePathname();
        }
    }
    expect($offenders)->toBe(
        [],
        'Pricing layer must not import TradeRuleResolver: '.implode(', ', $offenders)
    );
});
