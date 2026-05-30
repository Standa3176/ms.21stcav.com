<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add products.ean (nullable varchar, indexed) — GTIN/EAN/UPC barcode.
 *
 * GenerateProductDraftsCommand already reads `supplier_products.ean` from the
 * supplier feed but didn't persist it. This column stores it so
 * PublishProductJob can push it onto Woo as `global_unique_id` (WC 9.x's
 * structured GTIN slot, used by Google Merchant Center / Open Graph product
 * markup / schema.org). Existing meetingstore.co.uk products carry the same
 * value as `wp_postmeta._global_unique_id` (e.g. MUYHSMFFADW = 663445502631);
 * auto-created products POSTed via WC REST didn't carry it.
 *
 * Indexed (not unique) — supplier feeds occasionally repeat or omit EANs, so
 * a unique constraint would block ingest; we want fast lookup-by-EAN for
 * future reconciliation flows without rejecting writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->string('ean', 32)->nullable()->index()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t): void {
            $t->dropIndex(['ean']);
            $t->dropColumn('ean');
        });
    }
};
