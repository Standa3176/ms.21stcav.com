<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add products.category_ids (nullable JSON) — the FULL set of Woo category term
 * IDs a product belongs to.
 *
 * WooCommerce categories are many-to-many (a product lives in several), as the
 * live meetingstore.co.uk pages show. The scalar products.category_id stays as
 * the PRIMARY category (drives the Phase 3 pricing-rule resolver, which is
 * single-valued); category_ids holds every suitable category (including the
 * primary) so a later Woo push can send the complete `categories: [{id}, …]`
 * array. Mirrors the image_url (primary) + gallery_image_urls (all) split.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->json('category_ids')->nullable()->after('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->dropColumn('category_ids');
        });
    }
};
