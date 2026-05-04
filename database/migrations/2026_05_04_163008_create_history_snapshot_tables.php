<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260504-muq — 90-day price + stock history snapshots.
 *
 * Two tables:
 *
 *   product_price_snapshots — one row per Product per day. Tracks the canonical
 *   "what we sold this for / paid this for / had in stock" trio over time.
 *   Written by woo:import-products (publish/draft/private + sell_price + stock)
 *   then OVERWRITTEN same-day by supplier:db-sync with supplier-current
 *   buy_price + stock (supplier feed is the more authoritative source for
 *   buy_price). Unique (product_id, recorded_at) so the day's row is the only
 *   row, regardless of how many sync passes touch it.
 *
 *   supplier_offer_snapshots — one row per (sku, supplier_id, date). Captures
 *   the FULL price + stock + rrp matrix from feeds_products on stcav_dash
 *   so operators can answer "which supplier was cheapest for SKU X on
 *   2026-05-03" + "how did supplier Y's price drift over 90 days." sku is
 *   stored lowercase-trimmed (matchKey) so the UI search doesn't have to
 *   case-fold on every read; product_id FK is nullable to survive supplier
 *   feed entries for SKUs we don't yet stock locally.
 *
 * 90-day rolling retention enforced by history:prune scheduled daily 04:00.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->string('sku', 128)->index();
            $t->string('woo_status', 32)->nullable()->comment('publish/draft/private');
            $t->decimal('sell_price', 12, 4)->nullable();
            $t->decimal('buy_price', 12, 4)->nullable();
            $t->integer('stock_quantity')->nullable();
            $t->date('recorded_at');
            $t->timestamps();

            // One snapshot per Product per day — overwrite on the second sync pass.
            $t->unique(['product_id', 'recorded_at'], 'pps_product_date_unique');
            $t->index(['recorded_at']);
        });

        Schema::create('supplier_offer_snapshots', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 128)->index();
            $t->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $t->string('supplier_id', 16)->nullable();
            $t->string('supplier_name', 100)->nullable();
            $t->decimal('price', 12, 4)->nullable();
            $t->integer('stock')->nullable();
            $t->decimal('rrp', 12, 4)->nullable();
            $t->date('recorded_at');
            $t->timestamps();

            // One row per (sku, supplier, day). supplier_id may be empty string
            // for offers without a feeds.id linkage; that case still uniques.
            $t->unique(['sku', 'supplier_id', 'recorded_at'], 'sos_sku_supplier_date_unique');
            $t->index(['recorded_at']);
            $t->index(['sku', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_offer_snapshots');
        Schema::dropIfExists('product_price_snapshots');
    }
};
