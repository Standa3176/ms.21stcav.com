<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 Plan 02 — Variant-level brand+category overrides.
 *
 * Most variations inherit the parent Product's brand / category via
 * ProductVariant::getPricingBrandId() / getPricingCategoryId() falling back
 * to $this->product. Per-variant overrides remain possible for edge cases
 * (e.g. the Blue variation of a chair re-branded by a distributor).
 *
 * Same null-safe forward-compat pattern as products migration
 * (2026_04_19_090200): NULL rows fall through to the parent's value then to
 * default_tier. No FK constraints (brand / category tables not yet created).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $t) {
            $t->unsignedBigInteger('brand_id')->nullable()->after('name');
            $t->unsignedBigInteger('category_id')->nullable()->after('brand_id');
            $t->index('brand_id', 'product_variants_brand_id_idx');
            $t->index('category_id', 'product_variants_category_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $t) {
            $t->dropIndex('product_variants_brand_id_idx');
            $t->dropIndex('product_variants_category_id_idx');
            $t->dropColumn(['brand_id', 'category_id']);
        });
    }
};
