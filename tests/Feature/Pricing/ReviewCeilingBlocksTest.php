<?php

declare(strict_types=1);

use App\Domain\Pricing\Services\CeilingBlockClassifier;
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
            'severity' => (new CeilingBlockClassifier(20000, 500))->classify(
                $marginBps,
                CeilingBlockClassifier::cashUpliftPence((int) round($proposed * 100), (int) round($current * 100)),
            ),
            'cash_uplift_pence' => CeilingBlockClassifier::cashUpliftPence((int) round($proposed * 100), (int) round($current * 100)),
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

// ── 260825-t4m — severity triage ──────────────────────────────────────────

it('hides noise from the default view but still records it', function (): void {
    // 20 of the 48 published blocks on 2026-08-25 were worth GBP 0.00 between
    // them. They are why the nine that mattered went unread.
    ceilingBlock('QUIET-CABLE', 1.16, 2.20, 2.21, 5862);
    ceilingBlock('WORTH-READING', 3209.11, 5149.68, 6756.91, 7550);

    $this->artisan('pricing:review-ceiling-blocks')
        ->expectsOutputToContain('WORTH-READING')
        ->doesntExpectOutputToContain('QUIET-CABLE')
        ->expectsOutputToContain('hidden')
        ->assertExitCode(0);

    // Recorded, not discarded — the audit trail survives.
    $this->artisan('pricing:review-ceiling-blocks --include-noise')
        ->expectsOutputToContain('QUIET-CABLE')
        ->assertExitCode(0);
});

it('always shows a fault, even though it is not an opportunity', function (): void {
    // CP4's shape: a huge margin against a cost that belongs to another part.
    // Since 260825-z8q this attributes to COST FAULT rather than the generic
    // label — our price agrees with the competitor's, so the cost is the
    // outlier. The point of the test is unchanged: faults are never hidden.
    ceilingBlock('CP4', 24.96, 1517.99, 1748.39, 573730);

    $this->artisan('pricing:review-ceiling-blocks')
        ->expectsOutputToContain('1 COST FAULT')
        ->assertExitCode(0);
});

it('does not present a competitor at or below our price as an opportunity', function (): void {
    ceilingBlock('NO-UPSIDE', 100.00, 400.00, 350.00, 6000);

    $this->artisan('pricing:review-ceiling-blocks')
        ->doesntExpectOutputToContain('NO-UPSIDE')
        ->assertExitCode(0);
});

it('can be filtered to a single severity', function (): void {
    ceilingBlock('FAULTY', 24.96, 1517.99, 1748.39, 573730);
    ceilingBlock('REVIEWABLE', 3209.11, 5149.68, 6756.91, 7550);

    $this->artisan('pricing:review-ceiling-blocks --severity=data_fault')
        ->expectsOutputToContain('FAULTY')
        ->doesntExpectOutputToContain('REVIEWABLE')
        ->assertExitCode(0);
});

it('classifies a legacy row that predates the severity key', function (): void {
    // Rows blocked before 260825-t4m carry no severity; they must still triage.
    $s = ceilingBlock('LEGACY', 3209.11, 5149.68, 6756.91, 7550);
    $e = (array) $s->evidence;
    unset($e['severity'], $e['cash_uplift_pence']);
    $s->forceFill(['evidence' => $e])->save();

    $this->artisan('pricing:review-ceiling-blocks')
        ->expectsOutputToContain('LEGACY')
        ->assertExitCode(0);
});

// ── 260825-z8q — cost fault vs competitor fault, end to end ───────────────

it('splits a legacy data_fault row into a cost fault on read', function (): void {
    // CP4 as stored BEFORE the split: severity=data_fault, no current_margin_bps.
    $s = ceilingBlock('CP4', 24.96, 1517.99, 1748.39, 573730);
    $e = (array) $s->evidence;
    $e['severity'] = CeilingBlockClassifier::DATA_FAULT;
    unset($e['current_margin_bps']);
    $s->forceFill(['evidence' => $e])->save();

    // Re-derived from the product, not trusted from the row.
    $this->artisan('pricing:review-ceiling-blocks')
        ->expectsOutputToContain('1 COST FAULT')
        ->assertExitCode(0);
});

it('splits a legacy data_fault row into a competitor fault on read', function (): void {
    // 92L53AA#ABU: our 22% is textbook default tier; their 622% is not.
    $s = ceilingBlock('92L53AA#ABU', 667.19, 976.77, 5781.30, 62210);
    $e = (array) $s->evidence;
    $e['severity'] = CeilingBlockClassifier::DATA_FAULT;
    unset($e['current_margin_bps']);
    $s->forceFill(['evidence' => $e])->save();

    $this->artisan('pricing:review-ceiling-blocks')
        ->expectsOutputToContain('1 competitor fault')
        ->assertExitCode(0);
});

it('keeps a high-cash 50-100% block as review after the split', function (): void {
    ceilingBlock('FW-98BZ30L', 3209.11, 5149.68, 6756.91, 7550);

    $this->artisan('pricing:review-ceiling-blocks')
        ->expectsOutputToContain('1 review')
        ->assertExitCode(0);
});

it('still hides no-upside blocks by default after the split', function (): void {
    ceilingBlock('ALREADY-THERE', 100.00, 400.00, 400.00, 6000);

    $this->artisan('pricing:review-ceiling-blocks')
        ->doesntExpectOutputToContain('ALREADY-THERE')
        ->expectsOutputToContain('hidden')
        ->assertExitCode(0);
});

it('accepts --severity=data_fault as an alias for BOTH fault types', function (): void {
    // Documented compatibility: operators already have this in their history,
    // so it matches cost_fault, competitor_fault and any unattributed legacy row
    // rather than failing or silently returning nothing.
    ceilingBlock('CP4', 24.96, 1517.99, 1748.39, 573730);
    ceilingBlock('92L53AA#ABU', 667.19, 976.77, 5781.30, 62210);
    ceilingBlock('FW-98BZ30L', 3209.11, 5149.68, 6756.91, 7550);

    $this->artisan('pricing:review-ceiling-blocks --severity=data_fault')
        ->expectsOutputToContain('CP4')
        ->expectsOutputToContain('92L53AA#ABU')
        ->doesntExpectOutputToContain('FW-98BZ30L')
        ->assertExitCode(0);
});

it('can filter to cost faults alone, which is the fixable pile', function (): void {
    ceilingBlock('CP4', 24.96, 1517.99, 1748.39, 573730);
    ceilingBlock('92L53AA#ABU', 667.19, 976.77, 5781.30, 62210);

    $this->artisan('pricing:review-ceiling-blocks --severity=cost_fault')
        ->expectsOutputToContain('CP4')
        ->doesntExpectOutputToContain('92L53AA#ABU')
        ->assertExitCode(0);
});
