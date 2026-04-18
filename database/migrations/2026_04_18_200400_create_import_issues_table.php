<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Plan 01 — ImportIssue catalogue-health table (SYNC-12 + D-09).
 *
 * Four issue types:
 *   - missing_at_supplier: Woo product with no supplier match (SYNC-06 pending transition)
 *   - unknown_sku:         Supplier SKU Woo doesn't know (D-09 — Phase 6 producer via event)
 *   - missing_cost_price:  Product in DB but buy_price is NULL
 *   - exclude_flag_no_metadata: Has _exclude_from_auto_update but no rationale in notes
 *
 * Pricing_manager role triages via Filament ImportIssueResource (Plan 02-04).
 * resolved_at nullable → unresolved queries filter WHERE resolved_at IS NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_issues', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 100)->index();
            $t->unsignedBigInteger('woo_product_id')->nullable();
            $t->unsignedBigInteger('woo_variation_id')->nullable();
            $t->enum('issue_type', [
                'missing_at_supplier',
                'unknown_sku',
                'missing_cost_price',
                'exclude_flag_no_metadata',
            ])->index();
            $t->timestamp('detected_at')->index();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('resolved_at')->nullable()->index();
            $t->text('notes')->nullable();
            $t->uuid('correlation_id')->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_issues');
    }
};
