<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add products.gallery_image_urls (nullable JSON).
 *
 * The Phase 6 auto-create pipeline stored a single primary image in
 * products.image_url. The image-sourcing pass (products:source-images) sources
 * and Claude-vision-validates up to N images per product (Icecat by EAN +
 * supplier feed), so it needs somewhere to keep the full validated set.
 *
 * image_url stays the PRIMARY (first/IsMain) image — unchanged contract for
 * ImagePayloadBuilder + the existing review UI. gallery_image_urls holds the
 * ordered list of ALL validated public URLs (including the primary) so a later
 * Woo push can send the complete images[] array.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->json('gallery_image_urls')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->dropColumn('gallery_image_urls');
        });
    }
};
