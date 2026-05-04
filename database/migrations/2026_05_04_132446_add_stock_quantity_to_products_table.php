<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260504-imk — add products.stock_quantity (numeric).
 *
 * The products table already has stock_status (instock/outofstock/onbackorder
 * string) but no numeric quantity. WooCommerce returns both — stock_status is
 * the high-level "is this orderable" flag and stock_quantity is the literal
 * count when manage_stock=true. Ops needs the count for "how many do we have
 * in the warehouse" decisions; the string isn't enough.
 *
 * Nullable because a large fraction of Woo products run with manage_stock=false
 * (services, drop-ship, virtual). For those the count is meaningless and we
 * must distinguish "0 in stock" from "stock isn't tracked."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->integer('stock_quantity')->nullable()->after('stock_status');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn('stock_quantity');
        });
    }
};
