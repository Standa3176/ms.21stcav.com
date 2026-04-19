<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 Plan 02 — Additive columns for the RuleResolver's brand+category filter path.
 *
 * Pitfall 7 — nullable-column forward-compat: Phase 3 v1 leaves brand_id and
 * category_id NULL for most products. RuleResolver's read paths (ProductVariant
 * + Product getters) are null-safe, so products without a mapped brand/category
 * simply fall through to the default_tier layer (which the
 * DefaultPricingTierSeeder guarantees is populated).
 *
 * Phase 6 auto-create populates these from Woo taxonomy when a NewSupplierSku
 * is materialised; Phase 5 competitor-enrichment can also set them. No FK
 * constraints here — the brand / category tables don't exist yet (taxonomy
 * arrives in a later phase).
 *
 * Indexes cover the RuleResolver query shape:
 *   PricingRule::where('scope', 'brand_category')->where('brand_id', X)->where('category_id', Y)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedBigInteger('brand_id')->nullable()->after('type');
            $t->unsignedBigInteger('category_id')->nullable()->after('brand_id');
            $t->index('brand_id', 'products_brand_id_idx');
            $t->index('category_id', 'products_category_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropIndex('products_brand_id_idx');
            $t->dropIndex('products_category_id_idx');
            $t->dropColumn(['brand_id', 'category_id']);
        });
    }
};
