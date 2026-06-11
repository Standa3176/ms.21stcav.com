<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260611-f1y — add `is_internal_only` boolean to products.
 *
 * Flag for operator-curated "internal-use" products that should be hidden
 * from the meetingstore.co.uk storefront via Woo catalog_visibility=hidden
 * while remaining accessible via direct URL + custom-quote attach.
 *
 * Companion seed migration flags the 3 known internal woo_product_ids
 * (Credit 167493 / Offer 167492 / Quote Payment (No Vat) 165038).
 *
 * The artisan command `products:push-visibility-to-woo` consumes this
 * column to push catalog_visibility=hidden to Woo idempotently.
 *
 * Column placement: after `exclude_from_auto_update` to keep the two
 * operator-controlled product-level booleans grouped. Falls back to
 * `after('tags')` if the adjacent column ever disappears.
 *
 * Index: `idx_products_is_internal_only` for the
 * `where is_internal_only = true` candidate query in the push command
 * (low-cardinality but fast equality lookup against the publish-products
 * table that is hot for every Woo write path).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // Defensive: if a future migration renames exclude_from_auto_update,
            // fall back to placing the column at the end of the table.
            $column = $table->boolean('is_internal_only')
                ->nullable()
                ->default(false);

            if (Schema::hasColumn('products', 'exclude_from_auto_update')) {
                $column->after('exclude_from_auto_update');
            }

            $table->index('is_internal_only', 'idx_products_is_internal_only');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('idx_products_is_internal_only');
            $table->dropColumn('is_internal_only');
        });
    }
};
