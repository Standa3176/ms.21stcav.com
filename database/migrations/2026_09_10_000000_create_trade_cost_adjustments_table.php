<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260910-lwv — TRADE-ONLY cost adjustments.
 *
 * The daily supplier feed does not carry the cost we actually pay. Operator,
 * 2026-09-09: the feed quotes SILVER-level Yealink while we buy at platinum,
 * and a 2-month Logitech deal is absent from it entirely.
 *
 * Rows here express "our real trade cost is N% below what the feed says", and
 * are consumed ONLY by the trade storefront pricer. `products.buy_price` keeps
 * holding the feed value untouched, so:
 *   - retail pricing, health-check, divergence scan and supplier comparison
 *     all keep reading one unchanged column
 *   - the better cost is spent on trade customers, not given away at retail
 *   - nothing in the byte-locked v1 pricing path changes
 *
 * `valid_until` is the point: a manufacturer deal expires on its own date
 * rather than relying on somebody remembering. A hand-edited buy_price has no
 * expiry, no audit, and gets overwritten by the next supplier:db-sync anyway.
 *
 * Scope is brand OR sku, never both — mirroring pricing_rules, where brand_id
 * is a Woo term id held as a plain nullable bigint with NO FK (no local brands
 * table exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_cost_adjustments', function (Blueprint $t): void {
            $t->id();

            // Exactly one of these is set. Guarded in the model, not the DB —
            // MySQL 5.7 compatibility rules out a CHECK constraint here.
            $t->unsignedBigInteger('brand_id')->nullable();
            $t->string('sku', 128)->nullable();

            // Percent off the FEED cost, e.g. 12.500 = we pay 12.5% less than
            // the feed says. Deliberately not an absolute cost: the operator
            // describes these as "% extra off the price being fed".
            $t->decimal('adjustment_pct', 6, 3);

            $t->date('valid_from')->nullable();
            $t->date('valid_until')->nullable();

            $t->string('reason', 255)->nullable();
            $t->boolean('is_active')->default(true);

            $t->timestamps();

            $t->index('brand_id');
            $t->index('sku');
            $t->index(['is_active', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_cost_adjustments');
    }
};
