<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Plan 01 — Variation-level table (per D-01 + D-03).
 *
 * Each variation of a variable parent has its own SKU (unique, always present in
 * Woo), its own stock/price, and its own status. Missing variations flip to
 * `private` (Woo's hidden-from-shop state; variations don't support `pending`).
 *
 * old_buy_price / old_sell_price / old_stock_quantity provide 1-sync lookback
 * for the diff engine; longer history lives in activity_log via LogsActivity.
 *
 * FK cascadeOnDelete — killing a parent drops its variations (hard delete only;
 * the soft-delete on parents does NOT cascade, which is intentional: soft-deleted
 * parents retain their variation history for audit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('woo_variation_id')->unique();  // per D-01
            $t->string('sku', 100)->unique();                      // variation SKUs always unique in Woo
            $t->string('name')->nullable();                        // e.g. "Red, Large"
            $t->decimal('buy_price', 12, 4)->nullable();
            $t->decimal('sell_price', 12, 4)->nullable();
            $t->decimal('old_buy_price', 12, 4)->nullable();       // 1-sync lookback
            $t->decimal('old_sell_price', 12, 4)->nullable();
            $t->integer('stock_quantity')->default(0);
            $t->integer('old_stock_quantity')->nullable();
            $t->enum('status', ['publish', 'private'])
                ->default('publish')->index();                     // per D-03: private on missing
            $t->json('attributes')->nullable();                    // [{name: "Colour", option: "Red"}, ...]
            $t->timestamp('last_synced_at')->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
