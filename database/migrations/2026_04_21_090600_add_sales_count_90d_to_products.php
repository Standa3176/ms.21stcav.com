<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Plan 01 — denormalised 90-day sales cache on products (D-05).
 *
 * `MarginAnalyser` needs to know if a SKU has ≥10 sales in the last 90 days
 * before firing a margin-change suggestion (noise-suppression gate). Live
 * aggregate queries against Woo order history per-SKU-per-ingest would
 * hammer the DB — SalesCounterService (Plan 05-03) populates these columns
 * daily via `competitor:sales-recache` and the analyser reads them in O(1).
 *
 * - `last_sales_count_90d` unsignedInteger nullable — NULL = uncomputed yet.
 * - `last_sales_count_computed_at` timestamp nullable — when recache last ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->unsignedInteger('last_sales_count_90d')->nullable()->after('sell_price');
            $t->timestamp('last_sales_count_computed_at')->nullable()->after('last_sales_count_90d');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->dropColumn(['last_sales_count_90d', 'last_sales_count_computed_at']);
        });
    }
};
