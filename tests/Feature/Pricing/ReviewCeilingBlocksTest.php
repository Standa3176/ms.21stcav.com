<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| 260825-t4m — rank margin-ceiling blocks by cash, not percentage
|--------------------------------------------------------------------------
|
| The 50% ceiling cannot tell a wrong competitor feed from a cheap accessory:
|
|   47590    cost £1.16    competitor £2.22    58.62%  ← normal markup, noise
|   1001072  cost £275.51  competitor £576.67  74.42%  ← ~£300/unit, real money
|
| Both block identically. This command ranks by the cash actually forgone and
| prints a threshold table, so the guard policy ("margin > ceiling AND cash >
| £X") can be set from evidence rather than instinct.
*/

function ceilingBlock(string $sku, float $cost, float $current, float $proposed, int $marginBps, string $status = 'publish', ?string $blockedAt = null): Suggestion
{
    Product::factory()->create([
        'sku' => $sku,
        'buy_price' => $cost,
        'sell_price' => $current,
        'status' => $status,
    ]);

    return Suggestion::create([
        'kind' => 'competitor_price_ceiling_blocked',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => (string) Str::uuid(),
        'payload' => [],
        'evidence' => [
            'sku' => $sku,
            'buy_price_pennies' => (int) round($cost * 100),
            'proposed_sell_price_pennies' => (int) round($proposed * 100),
            'effective_margin_bps' => $marginBps,
            'competitor_price_pennies' => (int) round(($proposed + 0.01) * 100),
            'ceiling_bps' => 5000,
            'blocked_at' => ($blockedAt ?? now()->toDateString()).'T05:00:00+00:00',
        ],
        'proposed_at' => now(),
    ]);
}

it('ranks the high-cash block above the high-percentage one', function (): void {
    // The cable has the JUICIER percentage; the projector has the money.
    ceilingBlock('CHEAP-CABLE', 1.16, 1.60, 2.21, 5862);
    ceilingBlock('BIG-TICKET', 275.51, 400.00, 576.66, 7442);

    $this->artisan('pricing:review-ceiling-blocks')
        ->expectsOutputToContain('BIG-TICKET')
        ->assertExitCode(0);

    // Ordering is asserted through the threshold table: at a £100 floor only
    // the big-ticket row can survive.
    $this->artisan('pricing:review-ceiling-blocks --min-cash=100')
        ->expectsOutputToContain('BIG-TICKET')
        ->doesntExpectOutputToContain('CHEAP-CABLE')
        ->assertExitCode(0);
});

it('drops cheap-accessory noise as the cash floor rises', function (): void {
    foreach (range(1, 5) as $i) {
        ceilingBlock("CABLE-{$i}", 1.16, 1.60, 2.21, 5862);
    }
    ceilingBlock('REAL-ONE', 275.51, 400.00, 576.66, 7442);

    // The whole point of the threshold table: six blocks clog review, one matters.
    $this->artisan('pricing:review-ceiling-blocks --min-cash=50')
        ->expectsOutputToContain('REAL-ONE')
        ->doesntExpectOutputToContain('CABLE-1')
        ->assertExitCode(0);
});

it('measures cash EX VAT — VAT is not margin', function (): void {
    // £400.00 → £576.66 inc VAT is £176.66 gross, but only £147.22 ex VAT.
    // Reporting the inc-VAT figure would overstate every opportunity by 20%.
    ceilingBlock('VAT-CHECK', 275.51, 400.00, 576.66, 7442);

    $this->artisan('pricing:review-ceiling-blocks')
        ->expectsOutputToContain('147.22')
        ->assertExitCode(0);
});

it('can restrict to products that are actually live', function (): void {
    ceilingBlock('LIVE-ONE', 275.51, 400.00, 576.66, 7442, 'publish');
    ceilingBlock('DRAFT-ONE', 275.51, 400.00, 576.66, 7442, 'draft');

    $this->artisan('pricing:review-ceiling-blocks --published-only')
        ->expectsOutputToContain('LIVE-ONE')
        ->doesntExpectOutputToContain('DRAFT-ONE')
        ->assertExitCode(0);
});

it('separates today\'s live blocks from historical ones', function (): void {
    // Suggestions dedupe per SKU while pending, so the table accumulates every
    // SKU ever blocked. A stale row may have a competitor price that has since
    // moved or aged out of the 30-day window.
    ceilingBlock('TODAY', 275.51, 400.00, 576.66, 7442, 'publish', now()->toDateString());
    ceilingBlock('LAST-MONTH', 275.51, 400.00, 576.66, 7442, 'publish', now()->subMonth()->toDateString());

    $this->artisan('pricing:review-ceiling-blocks --since='.now()->toDateString())
        ->expectsOutputToContain('TODAY')
        ->doesntExpectOutputToContain('LAST-MONTH')
        ->assertExitCode(0);
});

it('reports cleanly when there is nothing blocked', function (): void {
    $this->artisan('pricing:review-ceiling-blocks')
        ->expectsOutputToContain('No pending margin-ceiling blocks.')
        ->assertExitCode(0);
});

it('survives a suggestion whose product has since been deleted', function (): void {
    ceilingBlock('GONE', 275.51, 400.00, 576.66, 7442);
    Product::where('sku', 'GONE')->delete();

    $this->artisan('pricing:review-ceiling-blocks')
        ->expectsOutputToContain('1 skipped')
        ->assertExitCode(0);
});
