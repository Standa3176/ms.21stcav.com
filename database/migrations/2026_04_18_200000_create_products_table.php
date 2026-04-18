<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Plan 01 — Products table (D-01 variable-product support).
 *
 * Mirrors the WooCommerce product identity. For simple products, products.sku
 * holds the unique SKU; for variable products, sku is nullable (each variation
 * carries its own SKU in the product_variants table).
 *
 * woo_product_id is the cross-system identity key — Laravel's id is purely local
 * and must NEVER be exposed to Woo writes.
 *
 * is_custom_ms + exclude_from_auto_update are cached from Woo tags/meta so
 * SyncChunkJob can decide to skip without re-fetching the tag list per SKU.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('woo_product_id')->unique(); // per D-01: Woo source of identity
            $t->string('sku', 100)->nullable()->index();        // per D-01: null for variable parents
            $t->string('name');
            $t->enum('type', ['simple', 'variable', 'grouped', 'external'])
                ->default('simple')->index();                   // per D-02: branch-at-sync-time enum
            $t->enum('status', ['publish', 'pending', 'draft', 'private'])
                ->default('publish')->index();                  // per SYNC-06: missing-simple → pending
            $t->enum('stock_status', ['instock', 'outofstock', 'onbackorder'])
                ->default('instock');
            $t->decimal('buy_price', 12, 4)->nullable();        // from supplier (ex-VAT)
            $t->decimal('sell_price', 12, 4)->nullable();       // computed (Phase 3 overwrites via listener)
            $t->decimal('cost_price', 12, 4)->nullable();       // historical — kept for auditing
            $t->boolean('is_custom_ms')->default(false)->index();              // cached from tags
            $t->boolean('exclude_from_auto_update')->default(false)->index();  // cached from meta
            $t->json('tags')->nullable();                       // full Woo tag array for future reuse
            $t->timestamp('last_synced_at')->nullable()->index();
            $t->unsignedBigInteger('last_sync_run_id')->nullable();  // cross-ref SyncRun
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
