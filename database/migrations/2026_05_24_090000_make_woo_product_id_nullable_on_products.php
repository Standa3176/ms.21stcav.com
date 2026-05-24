<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make products.woo_product_id NULLABLE.
 *
 * The original table (2026_04_18_200000) declared woo_product_id as
 * unsignedBigInteger()->unique() — NOT NULL, no default — under the "Woo is the
 * source of identity" (D-01) assumption that every local row mirrors a live Woo
 * product. That holds for sync-imported rows, but NOT for review-first creation:
 *
 *   - CreateWooProductJob inserts the local Product BEFORE the Woo POST and only
 *     back-fills woo_product_id from the response (job line ~192). Its
 *     needs_brand_or_category_assignment short-circuit (line ~146) returns with
 *     no POST at all — so that row legitimately has no Woo id yet.
 *   - GenerateProductDraftsCommand (products:generate-drafts) is review-first by
 *     design: it writes local draft Products for the Auto-Create Review inbox and
 *     NEVER posts to Woo. The Woo id is assigned later by PublishProductJob.
 *
 * Both paths were failing the INSERT with
 *   SQLSTATE[HY000] 1364 Field 'woo_product_id' doesn't have a default value.
 *
 * Making the column nullable lets a draft exist before it has a Woo identity.
 * The UNIQUE index is untouched (->change() preserves it) and MySQL treats each
 * NULL as distinct, so multiple draft rows coexist while real Woo ids stay unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->unsignedBigInteger('woo_product_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Backfilling NULLs would require fabricating Woo ids; leave reversible
        // only at the schema level. Rows created while nullable must be resolved
        // (published to Woo) before re-tightening, else this will fail.
        Schema::table('products', function (Blueprint $t): void {
            $t->unsignedBigInteger('woo_product_id')->nullable(false)->change();
        });
    }
};
