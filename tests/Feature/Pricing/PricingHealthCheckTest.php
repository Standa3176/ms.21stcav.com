<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Notifications\PricingHealthNotification;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| 260825-n5v — is the LIVE catalogue priced correctly right now?
|--------------------------------------------------------------------------
|
| pricing:audit-movements audits products whose price MOVED. It cannot see one
| that is wrong and simply sitting there — the worse failure, because a moving
| price gets another chance tomorrow while a stuck one stays wrong indefinitely.
| CP4 sat at £1,517.99 against a £24.96 cost for WEEKS without moving, so no
| movement audit would ever have found it.
|
| Floor is 6% (competitor.min_margin_floor_bps). PriceCalculator treats margin as
| markup on cost then adds VAT, so £100 cost at the floor is £127.20.
*/

beforeEach(function (): void {
    config([
        'competitor.min_margin_floor_bps' => 600,
        'competitor.ceiling_cost_fault_bps' => 20000,
        'competitor.ceiling_cost_fault_tolerance_bps' => 5000,
        'pricing.vat_basis_points' => 2000,
    ]);

    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 0,
        'tier_max_pennies' => null,
        'margin_basis_points' => 3500,
    ]);

    Notification::fake();
});

function healthProduct(string $sku, float $cost, float $sell, string $status = 'publish'): Product
{
    return Product::factory()->create([
        'sku' => $sku,
        'buy_price' => $cost,
        'sell_price' => $sell,
        'status' => $status,
    ]);
}

it('passes a catalogue where everything covers cost and the floor', function (): void {
    healthProduct('FINE-1', 100.00, 162.00);   // 35% tier
    healthProduct('FINE-2', 100.00, 127.20);   // exactly the floor is legal

    $this->artisan('pricing:health-check')
        ->expectsOutputToContain('PASS')
        ->assertExitCode(0);
});

it('fails on a product selling below cost', function (): void {
    // £110 inc VAT strips to £91.67 against a £100 cost. Every sale loses money.
    healthProduct('LOSS', 100.00, 110.00);

    $this->artisan('pricing:health-check')
        ->expectsOutputToContain('SELLING BELOW COST')
        ->expectsOutputToContain('FAIL')
        ->assertExitCode(1);
});

it('fails on a product under the agreed margin floor', function (): void {
    healthProduct('THIN', 100.00, 120.00);   // 4.29% against a 6% floor

    $this->artisan('pricing:health-check')
        ->expectsOutputToContain('BELOW THE MINIMUM-MARGIN FLOOR')
        ->assertExitCode(1);
});

it('finds a product that is wrong and has never moved', function (): void {
    // The CP4 shape, and the entire reason this exists alongside the movement
    // audit: no snapshot movement, so nothing else would ever look at it.
    healthProduct('CP4', 24.96, 1517.99);

    $this->artisan('pricing:health-check')
        ->expectsOutputToContain('SUSPECT COST')
        ->expectsOutputToContain('CP4')
        ->assertExitCode(0);
});

it('does not accuse a legitimately fat line of a suspect cost', function (): void {
    // 9H.JND77.1HE runs a real 99.5%, below the 200% absolute floor.
    healthProduct('FAT-BUT-FINE', 227.00, 543.33);

    $this->artisan('pricing:health-check')
        ->doesntExpectOutputToContain('SUSPECT COST')
        ->assertExitCode(0);
});

it('ignores unpublished products unless asked', function (): void {
    healthProduct('DRAFT-LOSS', 100.00, 110.00, 'draft');

    $this->artisan('pricing:health-check')
        ->expectsOutputToContain('PASS')
        ->assertExitCode(0);

    $this->artisan('pricing:health-check --include-unpublished')
        ->expectsOutputToContain('SELLING BELOW COST')
        ->assertExitCode(1);
});

it('emails subscribers when a hard fault is found', function (): void {
    $recipient = AlertRecipient::create([
        'email' => 'ops@meetingstore.co.uk',
        'name' => 'Ops',
        'is_active' => true,
        'receives_pricing_alerts' => true,
    ]);

    healthProduct('LOSS', 100.00, 110.00);

    $this->artisan('pricing:health-check --notify')->assertExitCode(1);

    Notification::assertSentTo($recipient, PricingHealthNotification::class);
});

it('does not email for a suspect cost alone', function (): void {
    // A known data-quality backlog. An alarm that fires daily for a backlog is
    // one people mute — and then the real one is missed too.
    AlertRecipient::create([
        'email' => 'ops@meetingstore.co.uk',
        'name' => 'Ops',
        'is_active' => true,
        'receives_pricing_alerts' => true,
    ]);

    healthProduct('CP4', 24.96, 1517.99);

    $this->artisan('pricing:health-check --notify')->assertExitCode(0);

    Notification::assertNothingSent();
});

it('still fails the run when nobody is subscribed', function (): void {
    // The exit code is the durable signal; the email is a courtesy. An empty
    // recipient list must never swallow the finding.
    healthProduct('LOSS', 100.00, 110.00);

    $this->artisan('pricing:health-check --notify')
        ->expectsOutputToContain('No active recipient')
        ->assertExitCode(1);

    Notification::assertNothingSent();
});

it('does not alert a recipient who has not opted into pricing alerts', function (): void {
    AlertRecipient::create([
        'email' => 'someone@meetingstore.co.uk',
        'name' => 'Other',
        'is_active' => true,
        'receives_pricing_alerts' => false,
        'receives_competitor_alerts' => true,
    ]);

    healthProduct('LOSS', 100.00, 110.00);

    $this->artisan('pricing:health-check --notify')->assertExitCode(1);

    Notification::assertNothingSent();
});

it('writes nothing — it is a read-only check', function (): void {
    $product = healthProduct('UNTOUCHED', 100.00, 110.00);

    $this->artisan('pricing:health-check --notify')->assertExitCode(1);

    expect((float) $product->fresh()->sell_price)->toBe(110.00)
        ->and((float) $product->fresh()->buy_price)->toBe(100.00);
});

// ── 260825-n5v follow-up — the min-cost gate, calibrated on the first run ──

it('does not report a cheap accessory with a fat percentage', function (): void {
    // Real rows from the first live run: four of six "suspect costs" were
    // ordinary accessory markup, every one under £4 cost. This report runs
    // DAILY, and four permanent false positives is how a report earns the habit
    // of being skimmed.
    healthProduct('88501', 1.62, 15.33);           // 688.9%
    healthProduct('88502', 2.22, 16.49);           // 518.9%
    healthProduct('88503', 3.13, 18.64);           // 396.2%
    healthProduct('80-04000006G000', 3.61, 18.08); // 317.5%

    $this->artisan('pricing:health-check')
        ->doesntExpectOutputToContain('SUSPECT COST')
        ->expectsOutputToContain('0 below cost, 0 below floor, 0 suspect cost')
        ->assertExitCode(0);
});

it('still reports a suspect cost on a product worth investigating', function (): void {
    // The two from the same run that ARE worth a look, both well above the £10
    // floor. The gate must remove noise without removing findings.
    healthProduct('PULSE-PRO-FRAME3', 96.93, 415.77);  // 257.5%
    healthProduct('2200-66700-025', 200.00, 1013.99);  // 322.5%

    $this->artisan('pricing:health-check')
        ->expectsOutputToContain('SUSPECT COST')
        ->expectsOutputToContain('2 suspect cost')
        ->assertExitCode(0);
});

it('keeps reporting a below-cost cheap item — the gate is not a blanket exemption', function (): void {
    // The min-cost gate suppresses a MARGIN judgement on a tiny cost. It must
    // never suppress selling at a loss, however small the item.
    healthProduct('TINY-LOSS', 1.62, 1.50);

    $this->artisan('pricing:health-check')
        ->expectsOutputToContain('SELLING BELOW COST')
        ->assertExitCode(1);
});
