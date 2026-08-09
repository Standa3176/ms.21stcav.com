<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260809-jie — Guard 2a: feed-jump quarantine flag on
 * competitor_prices (2026-08-09 incident response).
 *
 * A single competitor-price row that moves too far vs. its own prior value
 * (same competitor_id + sku) is quarantined: is_price_anomaly=true. The row
 * still persists (audit trail intact, COMP-07 "never truncated" mandate) —
 * only CompetitorUndercutPricingCommand's "lowest current competitor" query
 * excludes flagged rows (wired separately).
 *
 * Additive-only — safe to run against the live prod DB with
 * WOO_WRITE_ENABLED=true and a bulk price push in flight (mirrors the
 * 2026_07_08_020000_add_pin_price_to_product_overrides_table.php pattern:
 * nullable-safe boolean/string + explicit backfill, no table lock beyond the
 * ALTER TABLE itself, no FK, no new index needed — the existing
 * UNIQUE(competitor_id, sku, recorded_at) index already services the
 * "most recent prior row" lookup the writer needs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitor_prices', function (Blueprint $t): void {
            $t->boolean('is_price_anomaly')->default(false)->after('price_pennies_gross');
            $t->string('price_anomaly_reason', 255)->nullable()->after('is_price_anomaly');
        });

        // Belt-and-braces explicit backfill (mirrors the pin_price migration
        // precedent) — default(false) already covers this on MySQL/MariaDB,
        // but keep it explicit for driver-portability.
        DB::table('competitor_prices')->update(['is_price_anomaly' => false]);
    }

    public function down(): void
    {
        Schema::table('competitor_prices', function (Blueprint $t): void {
            $t->dropColumn(['is_price_anomaly', 'price_anomaly_reason']);
        });
    }
};
