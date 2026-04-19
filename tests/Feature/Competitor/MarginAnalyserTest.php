<?php

declare(strict_types=1);

use App\Domain\Competitor\Services\MarginAnalyser;
use App\Domain\Competitor\Services\MarginProposal;
use App\Domain\Pricing\Services\PriceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 03 Task 2 — MarginAnalyser reverse-margin calculation
|--------------------------------------------------------------------------
|
| Algorithm:
|   competitorExVat = PriceCalculator::stripVat(gross, 2000)
|   targetSellExVat = competitorExVat - beat_by_pennies (default 1)
|   marginBps       = intdiv((targetSellExVat - supplier) * 10000, supplier)
|
| Guards:
|   - supplierExVatPennies <= 0 → null (Phase 3 D-10 analogue)
|   - marginBps < min_margin_floor_bps (Pitfall P5-E) → null + Log::warning
|
| COMP-06: MUST call PriceCalculator::stripVat — NEVER reimplement VAT math.
*/

it('returns a MarginProposal with penny-exact integer arithmetic (happy path)', function (): void {
    $analyser = new MarginAnalyser(new PriceCalculator());

    // Competitor gross = 8999p, Supplier ex-VAT = 4000p
    // stripVat(8999, 2000) = round(8999 * 10000 / 12000) = round(7499.166...) = 7499 HALF_UP
    // targetSellExVat = 7499 - 1 (beat_by_pennies) = 7498
    // marginBps = intdiv((7498 - 4000) * 10000, 4000) = intdiv(34_980_000, 4000) = 8745
    $proposal = $analyser->computeProposal(
        competitorGrossPennies: 8999,
        supplierExVatPennies: 4000,
    );

    expect($proposal)->toBeInstanceOf(MarginProposal::class);
    expect($proposal->competitorExVatPennies)->toBe(7499);
    expect($proposal->supplierExVatPennies)->toBe(4000);
    expect($proposal->beatByPennies)->toBe(1);
    expect($proposal->proposedMarginBasisPoints)->toBe(8745);
});

it('returns null when proposed margin is below min_margin_floor_bps (Pitfall P5-E)', function (): void {
    // With gross=5000p and supplier=4500p:
    //   stripVat(5000, 2000) = round(5000*10000/12000) = round(4166.67) = 4167 HALF_UP
    //   targetSellExVat = 4166
    //   marginBps = intdiv((4166 - 4500) * 10000, 4500) = intdiv(-3340000, 4500) = -742
    // which is below min_margin_floor_bps (500) → null.
    config(['competitor.min_margin_floor_bps' => 500]);
    $analyser = new MarginAnalyser(new PriceCalculator());

    $proposal = $analyser->computeProposal(
        competitorGrossPennies: 5000,
        supplierExVatPennies: 4500,
    );

    expect($proposal)->toBeNull();
});

it('returns null when supplier price is zero (D-10 analogue guard)', function (): void {
    $analyser = new MarginAnalyser(new PriceCalculator());

    $proposal = $analyser->computeProposal(
        competitorGrossPennies: 8999,
        supplierExVatPennies: 0,
    );

    expect($proposal)->toBeNull();
});

it('returns null when supplier price is negative', function (): void {
    $analyser = new MarginAnalyser(new PriceCalculator());

    $proposal = $analyser->computeProposal(
        competitorGrossPennies: 8999,
        supplierExVatPennies: -100,
    );

    expect($proposal)->toBeNull();
});

it('respects config competitor.beat_by_pennies (override to 10p)', function (): void {
    config(['competitor.beat_by_pennies' => 10]);
    $analyser = new MarginAnalyser(new PriceCalculator());

    // stripVat(8999, 2000) = 7499
    // targetSellExVat = 7499 - 10 = 7489
    // marginBps = intdiv((7489 - 4000) * 10000, 4000) = intdiv(34_890_000, 4000) = 8722
    $proposal = $analyser->computeProposal(
        competitorGrossPennies: 8999,
        supplierExVatPennies: 4000,
    );

    expect($proposal)->not->toBeNull();
    expect($proposal->beatByPennies)->toBe(10);
    expect($proposal->proposedMarginBasisPoints)->toBe(8722);
});

it('uses integer math (no float drift) — MarginProposal is readonly', function (): void {
    $analyser = new MarginAnalyser(new PriceCalculator());

    $proposal = $analyser->computeProposal(
        competitorGrossPennies: 19999,
        supplierExVatPennies: 8000,
    );

    // stripVat(19999, 2000) = round(19999*10000/12000) = round(16665.833..) = 16666 HALF_UP
    // targetSellExVat = 16665
    // marginBps = intdiv((16665 - 8000) * 10000, 8000) = intdiv(86_650_000, 8000) = 10831
    expect($proposal->competitorExVatPennies)->toBe(16666);
    expect($proposal->proposedMarginBasisPoints)->toBe(10831);

    // Attempting to mutate fails — final readonly DTO
    expect(fn () => $proposal->proposedMarginBasisPoints = 0)
        ->toThrow(Error::class);
});
