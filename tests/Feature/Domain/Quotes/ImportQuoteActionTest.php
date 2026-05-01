<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Products\Models\Product;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Services\ImportQuoteAction;
use App\Domain\TradePricing\Models\CustomerGroup;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Phase 11 Plan 05 Task 1 — ImportQuoteAction (Phase 14 forward-compat)
|--------------------------------------------------------------------------
|
| Service-only entry point — Phase 14 chatbot's `propose_quote(customer_email,
| line_items)` agent tool wraps this. Phase 13 WhatsApp inbound `quote_request`
| can also reuse this surface. Coverage:
|
|   1. Creates draft Quote with denormalised customer_group_name_at_quote
|      (Pitfall 6 + A9 — survives FK rename)
|   2. Creates one QuoteLine per input.line_items via QuoteLineWriter
|      (observer chain runs — total_pence_at_quote recomputed)
|   3. Copies customer_group_id from User when user_id provided (D-02 priority)
|   4. Leaves user_id null for anonymous flow (D-01 dual-mode)
|   5. expires_at = now() + config('quote.default_expiry_days')
|
| Skip-on-MySQL-offline parity with Phase 11 Plan 02 PriceSnapshotter (the
| product/rule/customer_group fixtures need real MySQL).
*/

function skipIfMySqlOfflineImportQuoteAction(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    skipIfMySqlOfflineImportQuoteAction();
    config(['pricing.rounding_mode' => PHP_ROUND_HALF_UP]);
    config(['quote.default_expiry_days' => 14]);
});

it('creates draft Quote with denormalised customer_group_name_at_quote', function (): void {
    skipIfMySqlOfflineImportQuoteAction();

    $group = CustomerGroup::factory()->create(['name' => 'Trade Tier 1']);
    $product = Product::factory()->create(['sku' => 'IMP-001', 'buy_price' => 100.0]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_GLOBAL,
        'customer_group_id' => $group->id,
        'margin_basis_points' => 2500,
        'priority' => 200,
        'active' => true,
    ]);

    /** @var ImportQuoteAction $action */
    $action = app(ImportQuoteAction::class);

    $quote = $action->execute([
        'customer_email' => 'lead@example.com',
        'customer_name' => 'Anonymous Lead',
        'customer_group_id' => $group->id,
        'line_items' => [
            ['sku' => 'IMP-001', 'quantity_int' => 1],
        ],
    ]);

    expect($quote->status)->toBe(Quote::STATUS_DRAFT);
    expect($quote->customer_group_id)->toBe($group->id);
    expect($quote->customer_group_name_at_quote)->toBe('Trade Tier 1');
});

it('creates one QuoteLine per input.line_items via QuoteLineWriter (observer chain runs)', function (): void {
    skipIfMySqlOfflineImportQuoteAction();

    Product::factory()->create(['sku' => 'IMP-A', 'buy_price' => 50.0]);
    Product::factory()->create(['sku' => 'IMP-B', 'buy_price' => 75.0]);
    Product::factory()->create(['sku' => 'IMP-C', 'buy_price' => 120.0]);

    /** @var ImportQuoteAction $action */
    $action = app(ImportQuoteAction::class);

    $quote = $action->execute([
        'customer_email' => 'multi@example.com',
        'line_items' => [
            ['sku' => 'IMP-A', 'quantity_int' => 2],
            ['sku' => 'IMP-B', 'quantity_int' => 3],
            ['sku' => 'IMP-C', 'quantity_int' => 1],
        ],
    ]);

    expect($quote->lines)->toHaveCount(3);

    // Recompute observer fired — total > 0
    expect($quote->total_pence_at_quote)->toBeGreaterThan(0);
});

it('copies customer_group_id from User when user_id provided (D-02 priority)', function (): void {
    skipIfMySqlOfflineImportQuoteAction();

    $group = CustomerGroup::factory()->create(['name' => 'NHS']);
    $user = User::factory()->create();
    $user->customer_group_id = $group->id;
    $user->save();

    Product::factory()->create(['sku' => 'IMP-U', 'buy_price' => 200.0]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_GLOBAL,
        'customer_group_id' => $group->id,
        'margin_basis_points' => 2000,
        'priority' => 200,
        'active' => true,
    ]);

    /** @var ImportQuoteAction $action */
    $action = app(ImportQuoteAction::class);

    $quote = $action->execute([
        'user_id' => $user->id,
        'customer_email' => $user->email,
        // intentionally NO customer_group_id — should resolve from User.
        'line_items' => [
            ['sku' => 'IMP-U', 'quantity_int' => 1],
        ],
    ]);

    expect($quote->user_id)->toBe($user->id);
    expect($quote->customer_group_id)->toBe($group->id);
    expect($quote->customer_group_name_at_quote)->toBe('NHS');
});

it('leaves user_id null for anonymous flow (D-01 dual-mode)', function (): void {
    skipIfMySqlOfflineImportQuoteAction();

    Product::factory()->create(['sku' => 'IMP-ANON', 'buy_price' => 25.0]);

    /** @var ImportQuoteAction $action */
    $action = app(ImportQuoteAction::class);

    $quote = $action->execute([
        'customer_email' => 'walk-in@example.com',
        'line_items' => [
            ['sku' => 'IMP-ANON', 'quantity_int' => 1],
        ],
    ]);

    expect($quote->user_id)->toBeNull();
    expect($quote->customer_email)->toBe('walk-in@example.com');
    expect($quote->customer_group_id)->toBeNull();
});

it('uses default_expiry_days from config for expires_at', function (): void {
    skipIfMySqlOfflineImportQuoteAction();
    config(['quote.default_expiry_days' => 7]);

    Product::factory()->create(['sku' => 'IMP-EXPIRY', 'buy_price' => 30.0]);

    /** @var ImportQuoteAction $action */
    $action = app(ImportQuoteAction::class);

    $now = now();
    $quote = $action->execute([
        'customer_email' => 'exp@example.com',
        'line_items' => [
            ['sku' => 'IMP-EXPIRY', 'quantity_int' => 1],
        ],
    ]);

    // expires_at should be ~7 days from now (allow 1-min tolerance)
    expect($quote->expires_at)->not->toBeNull();
    $diffDays = $quote->expires_at->diffInDays($now);
    expect((int) round((float) $diffDays))->toBe(7);
});
