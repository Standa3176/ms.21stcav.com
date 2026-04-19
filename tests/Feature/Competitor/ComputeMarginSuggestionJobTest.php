<?php

declare(strict_types=1);

use App\Domain\Competitor\Events\MarginSuggestionCreated;
use App\Domain\Competitor\Jobs\ComputeMarginSuggestionJob;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 03 Task 2 — ComputeMarginSuggestionJob (3-threshold gate)
|--------------------------------------------------------------------------
|
| Three thresholds ALL must pass for a margin_change Suggestion to be created:
|   1. consecutive_scrapes_required — at least N CompetitorPrice rows (default 3)
|      AND all N must be consistently above/below our sell price (direction
|      consistency guard).
|   2. sales_threshold_90d — products.last_sales_count_90d >= N (default 10).
|   3. margin_delta_threshold_bps — abs(current_margin - proposed_margin) >= N
|      basis points (default 800 = 8%).
|
| On success: Suggestion(kind='margin_change') is created with payload +
| evidence matching D-07 shape; fires MarginSuggestionCreated event.
*/

/**
 * Helper: set up a ready-to-fire fixture that PASSES all 3 thresholds.
 * Returns [competitor, product, pricingRule, ingestRun].
 */
function seedHappyPathFixture(array $overrides = []): array
{
    config(['competitor.margin_delta_threshold_bps' => 800]);
    config(['competitor.consecutive_scrapes_required' => 3]);
    config(['competitor.sales_threshold_90d' => 10]);
    config(['competitor.min_margin_floor_bps' => 500]);
    config(['competitor.beat_by_pennies' => 1]);

    $competitor = Competitor::factory()->create();
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);

    $product = Product::factory()->create([
        'sku' => 'POP-SKU',
        'buy_price' => $overrides['buy_price'] ?? 40.00,      // supplier ex-VAT = 4000p
        'sell_price' => $overrides['sell_price'] ?? 100.00,  // our sell = 10000p gross
        'last_sales_count_90d' => $overrides['sales'] ?? 15,
        'brand_id' => null,
        'category_id' => null,
    ]);

    // Default-tier pricing rule so RuleResolver resolves cleanly (buy_price=4000p fits tier 0..9999p+)
    $pricingRule = PricingRule::factory()->defaultTier()->create([
        'margin_basis_points' => 5000,           // current 50% margin
        'tier_min_pennies' => 0,
        'tier_max_pennies' => 1_000_000,
        'priority' => 100,
    ]);

    // Three consecutive CompetitorPrice rows — all below our sell_price_pennies (10000p).
    // gross=8999p → stripVat=7499p ex-VAT, consistently undercutting
    for ($i = 0; $i < 3; $i++) {
        CompetitorPrice::factory()->create([
            'competitor_id' => $competitor->id,
            'sku' => 'POP-SKU',
            'price_pennies_ex_vat' => 7499,
            'price_pennies_gross' => 8999,
            'recorded_at' => now()->subDays(3 - $i),
            'ingest_run_id' => $run->id,
        ]);
    }

    return [$competitor, $product, $pricingRule, $run];
}

it('happy path: creates a margin_change Suggestion with D-07 evidence when all 3 thresholds pass', function (): void {
    Event::fake([MarginSuggestionCreated::class]);

    [$competitor, $product, $rule] = seedHappyPathFixture();

    ComputeMarginSuggestionJob::dispatchSync($competitor->id, 'POP-SKU');

    $suggestion = Suggestion::where('kind', 'margin_change')->first();
    expect($suggestion)->not->toBeNull();
    expect($suggestion->kind)->toBe('margin_change');
    expect($suggestion->status)->toBe(Suggestion::STATUS_PENDING);

    // Payload carries pricing_rule_id + new_margin_basis_points
    $payload = (array) $suggestion->payload;
    expect($payload)->toHaveKey('pricing_rule_id');
    expect($payload)->toHaveKey('new_margin_basis_points');
    expect($payload['pricing_rule_id'])->toBe($rule->id);

    // Evidence JSON shape (D-07)
    $evidence = (array) $suggestion->evidence;
    expect($evidence)->toHaveKeys([
        'competitor_id',
        'sku',
        'last_3_competitor_prices',
        'our_sell_price_pennies',
        'our_supplier_price_pennies',
        'our_current_margin_bps',
        'proposed_margin_bps',
        'margin_delta_bps',
        'sales_count_90d',
        'pricing_rule',
        'beat_by_pennies',
    ]);
    expect($evidence['sku'])->toBe('POP-SKU');
    expect($evidence['last_3_competitor_prices'])->toBeArray();
    expect(count($evidence['last_3_competitor_prices']))->toBe(3);
    expect($evidence['sales_count_90d'])->toBe(15);
    expect($evidence['pricing_rule'])->toHaveKey('id');
    expect($evidence['pricing_rule']['id'])->toBe($rule->id);
    expect($evidence['beat_by_pennies'])->toBe(1);

    Event::assertDispatched(MarginSuggestionCreated::class, function (MarginSuggestionCreated $event) use ($competitor) {
        return $event->competitorId === $competitor->id
            && $event->sku === 'POP-SKU';
    });
});

it('does NOT create a suggestion when sales_count_90d is below the threshold', function (): void {
    Event::fake([MarginSuggestionCreated::class]);

    [$competitor] = seedHappyPathFixture(['sales' => 5]);

    ComputeMarginSuggestionJob::dispatchSync($competitor->id, 'POP-SKU');

    expect(Suggestion::where('kind', 'margin_change')->count())->toBe(0);
    Event::assertNotDispatched(MarginSuggestionCreated::class);
});

it('does NOT create a suggestion when fewer than consecutive_scrapes_required rows exist', function (): void {
    Event::fake([MarginSuggestionCreated::class]);

    config(['competitor.consecutive_scrapes_required' => 3]);
    config(['competitor.sales_threshold_90d' => 10]);
    config(['competitor.min_margin_floor_bps' => 500]);
    config(['competitor.margin_delta_threshold_bps' => 800]);

    $competitor = Competitor::factory()->create();
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);

    Product::factory()->create([
        'sku' => 'POP-SKU',
        'buy_price' => 40.00,
        'sell_price' => 100.00,
        'last_sales_count_90d' => 20,
    ]);

    PricingRule::factory()->defaultTier()->create([
        'margin_basis_points' => 5000,
        'tier_min_pennies' => 0,
        'tier_max_pennies' => 1_000_000,
    ]);

    // Only 2 rows — below the 3-consecutive threshold
    for ($i = 0; $i < 2; $i++) {
        CompetitorPrice::factory()->create([
            'competitor_id' => $competitor->id,
            'sku' => 'POP-SKU',
            'price_pennies_ex_vat' => 7499,
            'price_pennies_gross' => 8999,
            'recorded_at' => now()->subDays(2 - $i),
            'ingest_run_id' => $run->id,
        ]);
    }

    ComputeMarginSuggestionJob::dispatchSync($competitor->id, 'POP-SKU');

    expect(Suggestion::where('kind', 'margin_change')->count())->toBe(0);
});

it('does NOT create a suggestion when direction flips across the last 3 scrapes', function (): void {
    Event::fake([MarginSuggestionCreated::class]);

    config(['competitor.consecutive_scrapes_required' => 3]);
    config(['competitor.sales_threshold_90d' => 10]);
    config(['competitor.min_margin_floor_bps' => 500]);
    config(['competitor.margin_delta_threshold_bps' => 800]);

    $competitor = Competitor::factory()->create();
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);

    Product::factory()->create([
        'sku' => 'POP-SKU',
        'buy_price' => 40.00,
        'sell_price' => 100.00,                // 10000p gross
        'last_sales_count_90d' => 20,
    ]);

    PricingRule::factory()->defaultTier()->create([
        'margin_basis_points' => 5000,
        'tier_min_pennies' => 0,
        'tier_max_pennies' => 1_000_000,
    ]);

    // Row 1: below (7499 < 10000), Row 2: above (11000 > 10000), Row 3: below → NOT consecutive
    $rowData = [
        ['price_pennies_ex_vat' => 7499, 'recorded_at' => now()->subDays(3)],
        ['price_pennies_ex_vat' => 11000, 'recorded_at' => now()->subDays(2)],
        ['price_pennies_ex_vat' => 7499, 'recorded_at' => now()->subDays(1)],
    ];
    foreach ($rowData as $data) {
        CompetitorPrice::factory()->create(array_merge([
            'competitor_id' => $competitor->id,
            'sku' => 'POP-SKU',
            'price_pennies_gross' => 8999,
            'ingest_run_id' => $run->id,
        ], $data));
    }

    ComputeMarginSuggestionJob::dispatchSync($competitor->id, 'POP-SKU');

    expect(Suggestion::where('kind', 'margin_change')->count())->toBe(0);
});

it('does NOT create a suggestion when margin delta is below the threshold', function (): void {
    Event::fake([MarginSuggestionCreated::class]);

    config(['competitor.consecutive_scrapes_required' => 3]);
    config(['competitor.sales_threshold_90d' => 10]);
    config(['competitor.min_margin_floor_bps' => 500]);
    config(['competitor.margin_delta_threshold_bps' => 20000]);  // wildly high threshold → cannot pass

    [$competitor] = seedHappyPathFixture();

    ComputeMarginSuggestionJob::dispatchSync($competitor->id, 'POP-SKU');

    expect(Suggestion::where('kind', 'margin_change')->count())->toBe(0);
});

it('does NOT create a suggestion when proposed margin is below the min-margin floor', function (): void {
    Event::fake([MarginSuggestionCreated::class]);

    // High supplier relative to competitor → below-floor proposal
    config(['competitor.min_margin_floor_bps' => 500]);
    config(['competitor.consecutive_scrapes_required' => 3]);
    config(['competitor.sales_threshold_90d' => 10]);
    config(['competitor.margin_delta_threshold_bps' => 100]);

    $competitor = Competitor::factory()->create();
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);

    Product::factory()->create([
        'sku' => 'LOWMARGIN-SKU',
        'buy_price' => 45.00,        // supplier 4500p
        'sell_price' => 50.00,       // sell 5000p
        'last_sales_count_90d' => 50,
    ]);

    PricingRule::factory()->defaultTier()->create([
        'margin_basis_points' => 5000,
        'tier_min_pennies' => 0,
        'tier_max_pennies' => 1_000_000,
    ]);

    // Competitor gross 5000p → stripVat=4167 → target=4166 → margin < 0 → null
    for ($i = 0; $i < 3; $i++) {
        CompetitorPrice::factory()->create([
            'competitor_id' => $competitor->id,
            'sku' => 'LOWMARGIN-SKU',
            'price_pennies_ex_vat' => 4167,
            'price_pennies_gross' => 5000,
            'recorded_at' => now()->subDays(3 - $i),
            'ingest_run_id' => $run->id,
        ]);
    }

    ComputeMarginSuggestionJob::dispatchSync($competitor->id, 'LOWMARGIN-SKU');

    expect(Suggestion::where('kind', 'margin_change')->count())->toBe(0);
});

it('no-ops silently when the product SKU is not found (orphan handled upstream)', function (): void {
    Event::fake([MarginSuggestionCreated::class]);

    $competitor = Competitor::factory()->create();

    ComputeMarginSuggestionJob::dispatchSync($competitor->id, 'UNKNOWN-SKU');

    expect(Suggestion::count())->toBe(0);
    Event::assertNotDispatched(MarginSuggestionCreated::class);
});
