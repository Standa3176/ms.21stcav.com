<?php

declare(strict_types=1);

use App\Domain\Competitor\Services\MarginAnalyser;
use App\Domain\Pricing\Services\PriceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 03 Task 2 — Pitfall P5-E min-margin-floor guard
|--------------------------------------------------------------------------
|
| When the computed proposal would drive margin below config
| 'competitor.min_margin_floor_bps' (default 500 = 5%), return null AND
| log a `suggestion_suppressed_low_margin` warning so ops can observe the
| guard triggering.
*/

it('logs suggestion_suppressed_low_margin warning when proposal is below floor', function (): void {
    config(['competitor.min_margin_floor_bps' => 500]);
    Log::spy();

    $analyser = new MarginAnalyser(new PriceCalculator());

    // Wrong-direction scenario: competitor cheaper than our supplier cost
    $result = $analyser->computeProposal(
        competitorGrossPennies: 5000,
        supplierExVatPennies: 4500,
    );

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->with('suggestion_suppressed_low_margin', \Mockery::on(function (array $ctx): bool {
            return isset($ctx['supplier_ex_vat_pennies'])
                && isset($ctx['competitor_ex_vat_pennies'])
                && isset($ctx['proposed_margin_bps'])
                && isset($ctx['floor_bps'])
                && $ctx['floor_bps'] === 500;
        }))
        ->once();
});

it('does NOT log the warning when proposal is above the floor', function (): void {
    config(['competitor.min_margin_floor_bps' => 500]);
    Log::spy();

    $analyser = new MarginAnalyser(new PriceCalculator());

    // Healthy margin path
    $result = $analyser->computeProposal(
        competitorGrossPennies: 8999,
        supplierExVatPennies: 4000,
    );

    expect($result)->not->toBeNull();
    Log::shouldNotHaveReceived('warning', ['suggestion_suppressed_low_margin']);
});
