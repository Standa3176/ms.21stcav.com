<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local membership table for sourceable supplier SKUs — backs the
 * "On supplier DB" filter on /admin/suggestions. The remote
 * feeds_products table is ~900k rows; a Laravel cache-array + whereIn
 * blows MySQL's packet limit, so we materialise the keys locally and
 * query via EXISTS subquery instead.
 *
 * Refreshed by supplier:refresh-sku-cache (Mon-Fri 07:05 London) via
 * truncate + bulk insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_sku_cache', function (Blueprint $table) {
            $table->string('sku', 191)->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_sku_cache');
    }
};
