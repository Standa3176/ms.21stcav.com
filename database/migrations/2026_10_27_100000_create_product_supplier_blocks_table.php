<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 261027-duz — per-(sku, supplier) offer blocks.
 *
 * Incident 2026-10-27: Yealink T74U (local SKU 1301222, product 5969) was costed
 * at £7.20 because Midwich's supplier code 1301222 is an unrelated item; the
 * real item (Nimans) is £88.20. A row here makes that supplier's offer for that
 * match key invisible to cost AND stock in supplier:db-sync and
 * products:hydrate-stock-from-offers, while the same supplier still counts for
 * every other key.
 *
 * `sku` is the normalised (lowercase + trim) MATCH KEY — the buildBestOfferMap /
 * supplier_offer_snapshots key — not necessarily the product's own SKU (an
 * alternative-SKU alias key can be blocked too). `product_id` is display-only.
 * `supplier_id` has no FK: feed supplier ids may not all be in `suppliers`.
 *
 * Every index / FK is explicitly named (MySQL 64-char identifier cap).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_supplier_blocks', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 191);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('supplier_id', 16);
            $table->text('reason');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['sku', 'supplier_id'], 'psb_sku_supplier_unique');
            $table->index('product_id', 'psb_product_id_index');

            $table->foreign('product_id', 'psb_product_id_fk')
                ->references('id')->on('products')
                ->nullOnDelete();
            $table->foreign('created_by_user_id', 'psb_created_by_fk')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_supplier_blocks');
    }
};
