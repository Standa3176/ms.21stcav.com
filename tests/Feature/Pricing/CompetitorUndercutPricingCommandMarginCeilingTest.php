<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Events\ProductPriceChanged;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Quick task 260809-jie — Guard 1: margin-ceiling block on competitor-driven
| pricing (CompetitorUndercutPricingCommand's first-ever test coverage).
|--------------------------------------------------------------------------
|
| Born from the 2026-08-09 production incident: SKU 9C941AA was repriced
| £1297.30 -> £4652.02 (280% markup) because competitor_id=3's feed price
| jumped overnight and pricing:undercut-competitors faithfully undercut it
| by 1p. These tests prove a competitor-driven price whose resulting margin
| blows past config('competitor.max_margin_ceiling_bps') is refused (no
| sell_price write, no ProductPriceChanged dispatch) and flagged as a review
| Suggestion, in both dry-run and --live.
*/

it('blocks a feed-error-magnitude competitor price in --live mode: no write, no dispatch, one review Suggestion', function (): void {
    Event::fake([ProductPriceChanged::class]);

    $product = Product::factory()->create([
        'sku' => 'CEIL-JUMP-1',
        'buy_price' => 100.00,
        'sell_price' => 130.00,
    ]);
    CompetitorPrice::factory()->forSku('CEIL-JUMP-1')->create([
        'price_pennies_gross' => 45000, // £450.00 gross — feed-error-magnitude jump
        'price_pennies_ex_vat' => 37500,
        'recorded_at' => now(),
    ]);

    // NOTE: expectsOutputToContain matches at most one expectation per printed
    // line (Mockery consumes one constraint per doWrite call) — so each
    // assertion below targets a DIFFERENT printed line. The individual field
    // values (buy/proposed/margin/competitor) on the blocked detail line are
    // verified via the Suggestion's evidence JSON below instead.
    $this->artisan('pricing:undercut-competitors', ['--skus' => 'CEIL-JUMP-1', '--live' => true])
        ->expectsOutputToContain('CEIL-JUMP-1')
        ->expectsOutputToContain('1 blocked (margin ceiling)')
        ->assertSuccessful();

    expect($product->fresh()->sell_price)->toBe('130.0000');
    Event::assertNotDispatched(ProductPriceChanged::class);

    $suggestions = Suggestion::where('kind', 'competitor_price_ceiling_blocked')->get();
    expect($suggestions)->toHaveCount(1);
    $suggestion = $suggestions->first();
    expect($suggestion->status)->toBe(Suggestion::STATUS_PENDING);
    expect($suggestion->evidence['sku'])->toBe('CEIL-JUMP-1');
    expect((int) $suggestion->evidence['buy_price_pennies'])->toBe(10000);
    expect((int) $suggestion->evidence['proposed_sell_price_pennies'])->toBe(44999);
    expect((int) $suggestion->evidence['competitor_price_pennies'])->toBe(45000);
});

it('blocks the same feed-error-magnitude price in dry-run mode too — Suggestion is the detection mechanism operators rely on', function (): void {
    Event::fake([ProductPriceChanged::class]);

    $product = Product::factory()->create([
        'sku' => 'CEIL-JUMP-2',
        'buy_price' => 100.00,
        'sell_price' => 130.00,
    ]);
    CompetitorPrice::factory()->forSku('CEIL-JUMP-2')->create([
        'price_pennies_gross' => 45000,
        'price_pennies_ex_vat' => 37500,
        'recorded_at' => now(),
    ]);

    // No --live: dry-run is the default.
    $this->artisan('pricing:undercut-competitors', ['--skus' => 'CEIL-JUMP-2'])
        ->expectsOutputToContain('CEIL-JUMP-2')
        ->expectsOutputToContain('1 blocked (margin ceiling)')
        ->assertSuccessful();

    // Dry-run never writes pricing, but the review flag still fires.
    expect($product->fresh()->sell_price)->toBe('130.0000');
    Event::assertNotDispatched(ProductPriceChanged::class);
    expect(Suggestion::where('kind', 'competitor_price_ceiling_blocked')->count())->toBe(1);
});

it('does NOT block a normal competitor-driven price well inside the ceiling — undercuts and writes normally', function (): void {
    Event::fake([ProductPriceChanged::class]);

    $product = Product::factory()->create([
        'sku' => 'CEIL-OK-1',
        'buy_price' => 100.00,
        'sell_price' => 1.00,
    ]);
    CompetitorPrice::factory()->forSku('CEIL-OK-1')->create([
        'price_pennies_gross' => 15601, // undercut 1p -> £156.00 -> ~30% margin
        'price_pennies_ex_vat' => 13001,
        'recorded_at' => now(),
    ]);

    $this->artisan('pricing:undercut-competitors', ['--skus' => 'CEIL-OK-1', '--live' => true])
        ->expectsOutputToContain('CEIL-OK-1')
        ->expectsOutputToContain('0 blocked (margin ceiling)')
        ->assertSuccessful();

    expect($product->fresh()->sell_price)->toBe('156.0000');
    Event::assertDispatched(ProductPriceChanged::class);
    expect(Suggestion::where('kind', 'competitor_price_ceiling_blocked')->count())->toBe(0);
});

it('re-running for the same still-anomalous SKU does not create a duplicate review Suggestion', function (): void {
    Event::fake([ProductPriceChanged::class]);

    $product = Product::factory()->create([
        'sku' => 'CEIL-JUMP-3',
        'buy_price' => 100.00,
        'sell_price' => 130.00,
    ]);
    CompetitorPrice::factory()->forSku('CEIL-JUMP-3')->create([
        'price_pennies_gross' => 45000,
        'price_pennies_ex_vat' => 37500,
        'recorded_at' => now(),
    ]);

    $this->artisan('pricing:undercut-competitors', ['--skus' => 'CEIL-JUMP-3', '--live' => true])->assertSuccessful();
    $firstSuggestion = Suggestion::where('kind', 'competitor_price_ceiling_blocked')->firstOrFail();
    $firstProposedAt = $firstSuggestion->proposed_at;

    // Re-run a moment later — same anomalous data still present.
    $this->travel(1)->minutes();
    $this->artisan('pricing:undercut-competitors', ['--skus' => 'CEIL-JUMP-3', '--live' => true])->assertSuccessful();

    $suggestions = Suggestion::where('kind', 'competitor_price_ceiling_blocked')->get();
    expect($suggestions)->toHaveCount(1);
    expect($suggestions->first()->id)->toBe($firstSuggestion->id);
    expect($suggestions->first()->proposed_at->greaterThan($firstProposedAt))->toBeTrue();

    expect($product->fresh()->sell_price)->toBe('130.0000');
});
