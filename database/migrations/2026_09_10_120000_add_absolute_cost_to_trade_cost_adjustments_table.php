<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260910-rsv — absolute trade cost alongside the percentage.
 *
 * 260910-lwv shipped `adjustment_pct` only, because the operator described
 * these arrangements as "% extra off the price being fed". Measuring the
 * August Yealink platinum list against the feed showed why that is not enough:
 *
 *   - the gap is BIMODAL, not uniform — 41 of 70 matched products cluster at
 *     11.1-11.2%, but 22 sit in a distinct 5-9% tier
 *   - a manufacturer price list is a FIXED price for the month, whereas a
 *     percentage silently drifts every time the supplier feed moves
 *
 * So a published list should be loaded as the price it actually is. The
 * percentage stays for the genuine "N% off whatever they quote" arrangements
 * where no per-line list exists.
 *
 * `absolute_cost` is ex-VAT, matching products.buy_price, and exactly one of
 * absolute_cost / adjustment_pct is set per row (enforced in the model —
 * MySQL 5.7 compatibility rules out a CHECK constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_cost_adjustments', function (Blueprint $t): void {
            $t->decimal('absolute_cost', 12, 4)->nullable()->after('adjustment_pct');
        });

        // adjustment_pct becomes optional now that a row may carry a price instead.
        Schema::table('trade_cost_adjustments', function (Blueprint $t): void {
            $t->decimal('adjustment_pct', 6, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('trade_cost_adjustments', function (Blueprint $t): void {
            $t->dropColumn('absolute_cost');
        });
    }
};
