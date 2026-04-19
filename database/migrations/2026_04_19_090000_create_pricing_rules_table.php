<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 Plan 01 — pricing_rules table (D-06, D-07).
 *
 * Schema shape for the four rule-scope types in Phase 3 v1:
 *   - brand               : brand_id set, category_id NULL
 *   - category            : category_id set, brand_id NULL
 *   - brand_category      : both set
 *   - default_tier        : both NULL; tier_min_pennies/tier_max_pennies set,
 *                           is_default_tier = true
 *
 * Tie-breaker (D-07): RuleResolver sorts by specificity DESC, then priority
 * DESC, then id ASC. `priority` defaults to 100 so a rule created without a
 * tiebreak still sorts predictably.
 *
 * Pitfall 7 (nullable columns need null-safe code): brand_id + category_id are
 * nullable bigints with NO FK constraints — no brands/categories tables exist
 * in Phase 3 (forward-compat shape for Phase 5+ catalogue taxonomy).
 * RuleResolver read paths (Plan 02) MUST handle NULL correctly.
 *
 * Deferred (explicitly NOT in this migration per CONTEXT.md Deferred):
 *   - min_margin_basis_points floor  (v2 Pricing Engine)
 *   - valid_from / valid_until windows (v2 promotional pricing)
 *   - variant_id per ProductOverride  (v2 per-variation overrides)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $t) {
            $t->id();

            // Scope enum (D-06). default_tier is exclusive-set with brand/category NULL.
            $t->enum('scope', ['brand', 'category', 'brand_category', 'default_tier'])->index();

            // Forward-compat FK targets (no constraint — tables arrive in Phase 5+).
            $t->unsignedBigInteger('brand_id')->nullable()->index();
            $t->unsignedBigInteger('category_id')->nullable()->index();

            // Margin expressed in basis points (2200 = 22.00%). Signed so v2
            // can add loss-leader promos without a schema change.
            $t->integer('margin_basis_points');

            // D-07 tiebreaker. Default 100 so any rule sorts predictably even
            // without an explicit priority.
            $t->unsignedSmallInteger('priority')->default(100);

            // Default-tier fallback flag. When true, tier_min/tier_max define
            // the price bucket this rule covers.
            $t->boolean('is_default_tier')->default(false)->index();

            // Tier bounds — only meaningful when is_default_tier = true.
            // tier_max_pennies NULL = open-ended upper (the £500+ bucket).
            $t->unsignedInteger('tier_min_pennies')->nullable();
            $t->unsignedInteger('tier_max_pennies')->nullable();

            // D-07 soft-toggle. Setting active=false keeps the row for audit
            // instead of deleting (LogsActivity would otherwise lose the history).
            $t->boolean('active')->default(true)->index();

            $t->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $t->timestamps();

            // Composite indexes for RuleResolver sort path (Plan 02).
            $t->index(['scope', 'priority'], 'pricing_rules_scope_priority_idx');
            $t->index(['brand_id', 'category_id'], 'pricing_rules_brand_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};
