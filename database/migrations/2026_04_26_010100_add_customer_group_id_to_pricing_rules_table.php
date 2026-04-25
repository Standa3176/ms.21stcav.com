<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 Plan 01 — add nullable customer_group_id FK to pricing_rules (D-04).
 *
 * Single column add — no parallel trade_pricing_rules table. Existing v1 rules
 * remain customer_group_id=null = retail default (byte-identical golden-fixture
 * behaviour preserved). New trade rules set customer_group_id to the matching
 * customer_groups.id.
 *
 * FK ON DELETE RESTRICT: cannot delete a customer_groups row with active rules.
 * Filament's CustomerGroupResource surfaces the IntegrityConstraintViolation as
 * an actionable error so admin knows to deactivate or migrate rules first
 * (T-09-01-03 mitigation).
 *
 * Composite index (customer_group_id, brand_id, category_id) named
 * pricing_rules_group_brand_category_idx covers the TradeRuleResolver
 * Plan 09-02 query path: `WHERE customer_group_id = ? AND brand_id = ?
 * AND category_id = ? AND active = 1` (Layer 1 most-specific match).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_rules', function (Blueprint $t) {
            $t->foreignId('customer_group_id')
                ->nullable()
                ->after('scope')
                ->constrained('customer_groups')
                ->restrictOnDelete();
            $t->index(
                ['customer_group_id', 'brand_id', 'category_id'],
                'pricing_rules_group_brand_category_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('pricing_rules', function (Blueprint $t) {
            $t->dropIndex('pricing_rules_group_brand_category_idx');
            $t->dropConstrainedForeignId('customer_group_id');
        });
    }
};
