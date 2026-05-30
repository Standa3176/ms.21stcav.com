<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add products.attributes_json (nullable JSON) — curated key/value attributes
 * that drive WooCommerce's "Additional Information" tab + the Flatsome theme's
 * in-page spec table.
 *
 * Storefront context: existing meetingstore.co.uk products were created with
 * curated wp_postmeta._product_attributes (Colour, Compatibility, Material,
 * Connection, etc.). The Flatsome theme renders these as visible product
 * specs — drop them and the product page renders visibly thinner (the
 * Additional Info tab is empty or hidden). Auto-create products POSTed via WC
 * REST didn't carry attributes, so the storefront layout didn't match.
 *
 * Shape (canonical example):
 *   [
 *     {"name": "Brand",        "value": "Huddly"},
 *     {"name": "Resolution",   "value": "1080p Full HD"},
 *     {"name": "Field of View","value": "120°"},
 *     ...
 *   ]
 *
 * Produced by GenerateProductDraftsCommand (Claude returns the array per the
 * augmented schema). Consumed by PublishProductJob::buildCreatePayload, which
 * maps each entry to the WC REST `attributes[]` shape
 * (name + options:[value] + visible:true + variation:false + position).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->json('attributes_json')->nullable()->after('category_ids');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->dropColumn('attributes_json');
        });
    }
};
