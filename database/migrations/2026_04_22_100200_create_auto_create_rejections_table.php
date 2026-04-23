<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 Plan 01 — auto_create_rejections table (D-06).
 *
 * Append-only rejection history. Captures the 8-value reason enum + free-text
 * notes (mandatory at Form-Request layer when reason='other') + the admin user
 * who rejected. Rows survive hard-delete of the underlying product: rejection
 * history is retention-indefinite per Phase 1 D-04 audit convention.
 *
 * Downstream use (post-Phase-6): MarginAnalyser / suggestion engine scans
 * grouped counts — "Ops rejected 12 Brand X products as
 * spare_part_or_accessory in the last 90d — suggest an auto_skip_rule for
 * brand=Brand X".
 *
 * No updated_at — rejections are append-only; edits would obscure the audit
 * trail. Only created_at is captured.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_create_rejections', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $t->enum('reason', [
                'not_a_real_product',
                'duplicate_of_existing',
                'discontinued_by_supplier',
                'spare_part_or_accessory',
                'poor_quality_data',
                'misclassified_brand_or_category',
                'below_viability_threshold',
                'other',
            ]);
            $t->text('notes')->nullable();
            $t->foreignId('rejected_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index('product_id');
            $t->index('reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_create_rejections');
    }
};
