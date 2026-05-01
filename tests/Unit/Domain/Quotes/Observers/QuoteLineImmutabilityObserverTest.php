<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Products\Models\Product;
use App\Domain\Quotes\Exceptions\QuoteLineImmutableException;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteLine;
use App\Domain\Quotes\Services\QuoteLineWriter;
use App\Domain\TradePricing\Models\CustomerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Phase 11 Plan 02 — D-13 immutability observer (T-11-02-01 mitigation)
|--------------------------------------------------------------------------
|
| Five-branch coverage of the saving() hook:
|   1. draft + quantity_int change ALLOWED + recomputes line_total.
|   2. sent + quantity_int change THROWS QuoteLineImmutableException.
|   3. sent + unit_price_pence_at_quote change THROWS.
|   4. sent + product_snapshot change THROWS.
|   5. creation (! $line->exists) ALLOWED in any quote status.
|
| Skip-on-MySQL-offline parity with Phase 9 / Plan 11-01.
*/

function skipIfMySqlOfflineImmutability(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    skipIfMySqlOfflineImmutability();
    config(['pricing.rounding_mode' => PHP_ROUND_HALF_UP]);
});

/**
 * Build a real persisted Quote + a single line via the legitimate
 * QuoteLineWriter path — mirrors how Plan 11-03 Filament Resource creates lines.
 */
function makeDraftQuoteWithLine(int $marginBps = 2500): array
{
    $group = CustomerGroup::factory()->create();
    $product = Product::factory()->create([
        'sku' => 'IMM-'.uniqid(),
        'buy_price' => 100.0000,
        'brand_id' => 100,
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'customer_group_id' => $group->id,
        'brand_id' => 100,
        'margin_basis_points' => $marginBps,
        'priority' => 100,
        'active' => true,
    ]);

    $quote = Quote::factory()->create([
        'customer_group_id' => $group->id,
        'status' => Quote::STATUS_DRAFT,
    ]);
    $line = app(QuoteLineWriter::class)->add($quote, $product->sku, 1);

    return ['quote' => $quote, 'line' => $line, 'product' => $product];
}

it('allows quantity_int change in draft and recomputes line_total_pence_at_quote', function (): void {
    ['quote' => $quote, 'line' => $line] = makeDraftQuoteWithLine();
    $unit = $line->unit_price_pence_at_quote;

    $line->quantity_int = 7;
    $line->save();

    $line->refresh();
    expect($line->quantity_int)->toBe(7);
    expect($line->line_total_pence_at_quote)->toBe($unit * 7);
})->uses(RefreshDatabase::class);

it('throws QuoteLineImmutableException on quantity_int change after status=sent', function (): void {
    ['quote' => $quote, 'line' => $line] = makeDraftQuoteWithLine();

    // Transition to sent — bypass any approval flow we haven't built.
    $quote->status = Quote::STATUS_SENT;
    $quote->sent_at = now();
    $quote->saveQuietly();

    $line->quantity_int = 99;
    expect(fn () => $line->save())->toThrow(QuoteLineImmutableException::class);
})->uses(RefreshDatabase::class);

it('throws on unit_price_pence_at_quote change after status=sent', function (): void {
    ['quote' => $quote, 'line' => $line] = makeDraftQuoteWithLine();
    $quote->status = Quote::STATUS_SENT;
    $quote->saveQuietly();

    $line->unit_price_pence_at_quote = 1;
    expect(fn () => $line->save())->toThrow(
        QuoteLineImmutableException::class,
        'unit_price_pence_at_quote',
    );
})->uses(RefreshDatabase::class);

it('throws on product_snapshot change after status=sent', function (): void {
    ['quote' => $quote, 'line' => $line] = makeDraftQuoteWithLine();
    $quote->status = Quote::STATUS_SENT;
    $quote->saveQuietly();

    $line->product_snapshot = ['name' => 'tampered'];
    expect(fn () => $line->save())->toThrow(QuoteLineImmutableException::class);
})->uses(RefreshDatabase::class);

it('also forbids unit_price_pence_at_quote mutation in DRAFT (set ONCE on creation)', function (): void {
    ['quote' => $quote, 'line' => $line] = makeDraftQuoteWithLine();
    expect($quote->status)->toBe(Quote::STATUS_DRAFT);

    $line->unit_price_pence_at_quote = 99999;
    expect(fn () => $line->save())->toThrow(QuoteLineImmutableException::class);
})->uses(RefreshDatabase::class);

it('allows initial creation regardless of parent quote status (no exists check)', function (): void {
    // Even a 'sent' quote can have its first lines added via QuoteLineWriter
    // because the observer only blocks on UPDATE — creation flows are the
    // sole legitimate writer (PriceSnapshotter). The Filament Resource
    // separately gates the create button via QuoteLinePolicy.
    $group = CustomerGroup::factory()->create();
    Product::factory()->create([
        'sku' => 'IMM-FRESH',
        'buy_price' => 50.0000,
        'brand_id' => 200,
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'customer_group_id' => $group->id,
        'brand_id' => 200,
        'margin_basis_points' => 2000,
        'priority' => 100,
        'active' => true,
    ]);

    $quote = Quote::factory()->create([
        'customer_group_id' => $group->id,
        'status' => Quote::STATUS_SENT,
    ]);
    // Direct ::create — should not throw on fresh row insert.
    $line = QuoteLine::create([
        'quote_id' => $quote->id,
        'sku' => 'IMM-FRESH',
        'quantity_int' => 1,
        'unit_price_pence_at_quote' => 6000,
        'line_total_pence_at_quote' => 6000,
        'product_snapshot' => ['name' => 'fresh', 'matched_rule_id' => null],
        'sort_order' => 0,
    ]);

    expect($line->exists)->toBeTrue();
})->uses(RefreshDatabase::class);
