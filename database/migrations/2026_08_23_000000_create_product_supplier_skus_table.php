<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260823-clp — alternative supplier SKUs.
 *
 * Replaces the legacy Stock Updater plugin's single "alternative SKU" field.
 * A table rather than a column because the plugin's one alternate could not
 * express the real shape: one product, many suppliers, each with its own code.
 *
 * `normalised_sku` is STORED rather than computed per query. products.sku
 * already carries a documented LOWER(TRIM()) index-miss note (ProductMatcher),
 * and this table is read inside the add-candidate scan and the auto-create
 * duplicate gate — both hot paths that must not full-table-scan.
 *
 * unique(normalised_sku, supplier_id) — the same code may legitimately be used
 * by two different suppliers for different parts, so identity is the PAIR.
 * A NULL supplier_id means "this code, whoever quotes it", which MySQL treats
 * as distinct per row; the application guards that case explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_supplier_skus', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // Nullable: operators often know the alternate code but not which
            // supplier quotes it, and derived_mpn proposals are supplier-scoped
            // only when the feed says so.
            $t->unsignedBigInteger('supplier_id')->nullable()->index();
            $t->string('supplier_sku', 100);
            $t->string('normalised_sku', 100)->index();
            // manual        — operator typed it (the legacy plugin's field)
            // derived_mpn   — proposed from a normalised manufacturer part match
            // derived_ean   — proposed from an EAN match
            $t->string('source', 32)->default('manual');
            // 0-100. Manual entries are 100 by definition; derived rows carry
            // the matcher's confidence so a later pass can re-rank them.
            $t->unsignedTinyInteger('confidence')->default(100);
            $t->string('notes', 255)->nullable();
            $t->timestamps();

            $t->unique(['normalised_sku', 'supplier_id'], 'uniq_alias_supplier');
            $t->index(['product_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_supplier_skus');
    }
};
