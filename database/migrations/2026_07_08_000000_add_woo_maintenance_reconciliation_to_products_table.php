<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260708-b4f — Woo maintenance reconciliation (Pass 1).
 *
 * Adds five nullable columns that mirror each live product's REAL state on Woo
 * (image count, GTIN/EAN, category count, stock status) so the Maintenance
 * dashboard (Pass 2) can report TRUE shop-wide gaps across ALL ~4,612 live
 * products — including the 3,614 legacy WC-migrated ones whose media/EAN were
 * never mirrored into the local product record.
 *
 * All columns are nullable → the migration is safe/fast on the ~6k-row table
 * (no default backfill, no lock-heavy operation). Populated by the read-only
 * `products:reconcile-woo-maintenance` command (nightly 04:30).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('woo_image_count')->nullable()->after('gallery_image_urls');
            $table->string('woo_gtin')->nullable()->after('woo_image_count');
            $table->unsignedInteger('woo_category_count')->nullable()->after('woo_gtin');
            $table->string('woo_stock_status')->nullable()->after('woo_category_count');
            $table->timestamp('woo_reconciled_at')->nullable()->index()->after('woo_stock_status');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'woo_image_count',
                'woo_gtin',
                'woo_category_count',
                'woo_stock_status',
                'woo_reconciled_at',
            ]);
        });
    }
};
