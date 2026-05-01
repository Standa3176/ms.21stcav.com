<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Products\Models\Product;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteLine;
use App\Domain\Quotes\Services\QuoteLineWriter;
use App\Domain\TradePricing\Models\CustomerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Phase 11 Plan 02 — Quote.total_pence_at_quote derived recompute observer
|--------------------------------------------------------------------------
|
| Four-branch coverage of saved() + deleted() hooks:
|   1. saving line in draft updates parent Quote.total_pence_at_quote
|   2. deleting line in draft updates parent Quote.total_pence_at_quote
|   3. status==sent → NO recompute attempted (immutability gate already
|      blocks line saves; this observer additionally short-circuits as
|      defensive depth)
|   4. recompute uses saveQuietly to avoid LogsActivity noise on the
|      derived total (T-11-02-04 mitigation — only meaningful status
|      transitions log)
|
| Skip-on-MySQL-offline parity with Phase 9 / Plan 11-01.
*/

function skipIfMySqlOfflineRecompute(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    skipIfMySqlOfflineRecompute();
    config(['pricing.rounding_mode' => PHP_ROUND_HALF_UP]);
});

function seedProductAndRule(string $sku, int $brandId, int $marginBps, ?int $groupId): void
{
    Product::factory()->create([
        'sku' => $sku,
        'buy_price' => 100.0000,
        'brand_id' => $brandId,
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'customer_group_id' => $groupId,
        'brand_id' => $brandId,
        'margin_basis_points' => $marginBps,
        'priority' => 100,
        'active' => true,
    ]);
}

it('updates Quote.total_pence_at_quote when QuoteLine saved in draft', function (): void {
    $group = CustomerGroup::factory()->create();
    seedProductAndRule('TOT-A', 301, 2500, $group->id);
    seedProductAndRule('TOT-B', 302, 3000, $group->id);

    $quote = Quote::factory()->create([
        'customer_group_id' => $group->id,
        'status' => Quote::STATUS_DRAFT,
        'total_pence_at_quote' => 0,
    ]);

    $writer = app(QuoteLineWriter::class);
    $line1 = $writer->add($quote, 'TOT-A', 2);
    $line2 = $writer->add($quote, 'TOT-B', 1);

    $quote->refresh();
    expect($quote->total_pence_at_quote)->toBe(
        $line1->line_total_pence_at_quote + $line2->line_total_pence_at_quote
    );
})->uses(RefreshDatabase::class);

it('updates Quote.total_pence_at_quote when QuoteLine deleted in draft', function (): void {
    $group = CustomerGroup::factory()->create();
    seedProductAndRule('TOT-DEL-A', 401, 2500, $group->id);
    seedProductAndRule('TOT-DEL-B', 402, 3000, $group->id);

    $quote = Quote::factory()->create([
        'customer_group_id' => $group->id,
        'status' => Quote::STATUS_DRAFT,
    ]);

    $writer = app(QuoteLineWriter::class);
    $line1 = $writer->add($quote, 'TOT-DEL-A', 1);
    $line2 = $writer->add($quote, 'TOT-DEL-B', 1);

    $quote->refresh();
    $afterAdd = $quote->total_pence_at_quote;
    expect($afterAdd)->toBeGreaterThan(0);

    $line1->delete();
    $quote->refresh();

    expect($quote->total_pence_at_quote)->toBe($line2->line_total_pence_at_quote);
})->uses(RefreshDatabase::class);

it('does NOT recompute Quote.total_pence_at_quote when status==sent (defensive short-circuit)', function (): void {
    $group = CustomerGroup::factory()->create();
    seedProductAndRule('TOT-SENT', 501, 2500, $group->id);

    $quote = Quote::factory()->create([
        'customer_group_id' => $group->id,
        'status' => Quote::STATUS_DRAFT,
    ]);
    $line = app(QuoteLineWriter::class)->add($quote, 'TOT-SENT', 1);
    $quote->refresh();
    $totalBeforeSent = $quote->total_pence_at_quote;
    expect($totalBeforeSent)->toBeGreaterThan(0);

    // Transition to sent.
    $quote->status = Quote::STATUS_SENT;
    $quote->saveQuietly();

    // Trigger a saved() event by force-saving the line WITHOUT changing any
    // forbidden columns (only updated_at) — line.save() bypasses the
    // immutability gate because no forbidden column is dirty. The recompute
    // observer must short-circuit per the status check.
    //
    // We mutate Quote.total_pence_at_quote to a sentinel manually and assert
    // the observer's saved() does NOT overwrite it. Since the immutability
    // gate would block a real line save, we directly call the observer's
    // saved() entry on a fresh resolution.
    $sentinel = 99999999;
    $quote->total_pence_at_quote = $sentinel;
    $quote->saveQuietly();

    // Re-fetch the line so its parent relation reflects status=sent.
    $line->refresh();

    $observer = new \App\Domain\Quotes\Observers\QuoteTotalRecomputeObserver();
    $observer->saved($line);

    $quote->refresh();
    expect($quote->total_pence_at_quote)->toBe($sentinel);  // unchanged
})->uses(RefreshDatabase::class);

it('uses saveQuietly to avoid LogsActivity noise on derived total recompute', function (): void {
    $group = CustomerGroup::factory()->create();
    seedProductAndRule('TOT-Q-A', 601, 2500, $group->id);

    $quote = Quote::factory()->create([
        'customer_group_id' => $group->id,
        'status' => Quote::STATUS_DRAFT,
    ]);
    // Capture the activity row count for THIS quote BEFORE adding lines.
    $before = Activity::where('subject_id', $quote->id)
        ->where('subject_type', Quote::class)
        ->count();

    app(QuoteLineWriter::class)->add($quote, 'TOT-Q-A', 1);

    $after = Activity::where('subject_id', $quote->id)
        ->where('subject_type', Quote::class)
        ->count();

    // Quote.LogsActivity (Plan 11-01) DOES include total_pence_at_quote in
    // logOnly, so a non-quiet save would have written an activity row.
    // saveQuietly suppresses that — count must be unchanged after a
    // single line add.
    expect($after)->toBe($before);
})->uses(RefreshDatabase::class);
