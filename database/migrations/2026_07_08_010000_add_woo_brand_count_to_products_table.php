<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260708-dyy — brand reconciliation Pass A.
 *
 * Adds one nullable column, `woo_brand_count`, recording each live product's
 * REAL product_brand term count on Woo/WordPress. product_brand is the
 * taxonomy that drives meetingstore.co.uk's clickable Brand: link and is the
 * one gap NOT present in the WC `/products` response — so it needs a separate
 * WP REST pass in `products:reconcile-woo-maintenance` (which populates it).
 *
 * Nullable → the migration is safe/fast on the ~6k-row table (no default
 * backfill, no lock-heavy operation). Sits after `woo_category_count` alongside
 * the other 260708-b4f woo_* reconciliation columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('woo_brand_count')->nullable()->after('woo_category_count');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('woo_brand_count');
        });
    }
};
