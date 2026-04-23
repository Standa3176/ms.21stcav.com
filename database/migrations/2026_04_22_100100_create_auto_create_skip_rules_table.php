<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 Plan 01 — auto_create_skip_rules table (D-04).
 *
 * Evaluated at NewSupplierSkuDetected event-handler entry: ANY active rule
 * matching the candidate SKU skips the auto-create silently (event is logged
 * to integration_events with outcome='auto_skipped' + rule_id).
 *
 * Scope enum resolves against:
 *   - brand        → case-insensitive match against supplier row's brand
 *   - category     → case-insensitive match against supplier row's category
 *   - sku_pattern  → preg_match('/' . $value . '/', $sku) (admin-only write;
 *                    ReDoS mitigated by 256-char value cap in Policy layer)
 *   - price_range  → parses '<N' / '>N' / 'N-M' against supplier GBP price
 *
 * reason enum mirrors auto_create_rejections.reason — same 8 values for
 * "why did this SKU get rejected/skipped" analytics continuity.
 *
 * D-04 defaults seeded by AutoCreateSkipRuleSeeder: brand=SparesPlus,
 * sku_pattern=^TEST-, price_range=<25.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_create_skip_rules', function (Blueprint $t): void {
            $t->id();
            $t->enum('scope', ['brand', 'category', 'sku_pattern', 'price_range']);
            $t->string('value', 255);
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
            $t->boolean('is_active')->default(true)->index();
            $t->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['scope', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_create_skip_rules');
    }
};
