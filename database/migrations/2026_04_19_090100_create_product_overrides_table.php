<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 Plan 01 — product_overrides table (D-08, D-09).
 *
 * D-08: override = margin % override, NOT a direct final-price override.
 *       margin_basis_points replaces the rule's margin in the calculator
 *       input; everything else in the formula stays the same. Keeps the
 *       golden-fixture shape identical (same single pure function).
 *
 * D-09: parent-only. One row per Woo parent product — variations inherit.
 *       UNIQUE(product_id) enforces this at the DB level.
 *
 * Pitfall 7 (nullable columns need null-safe code): variant_id is deliberately
 * ABSENT from v1. The schema is forward-compatible with adding a nullable
 * variant_id column in a v2 migration without breaking existing rows — resurface
 * only if ops asks for it after v1 parity is proven (CONTEXT.md Deferred).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_overrides', function (Blueprint $t) {
            $t->id();

            // One override per product (D-08 + D-09). Cascade delete follows
            // the parent — if a product is hard-deleted the override goes too.
            $t->foreignId('product_id')->unique()
                ->constrained('products')->cascadeOnDelete();

            // Legacy buy_price_percentage_to_add equivalent, in basis points.
            // Signed so v2 loss-leader overrides are possible without schema change.
            $t->integer('margin_basis_points');

            // Audit trail — why was this override set? Free-text for triage.
            $t->text('reason')->nullable();

            $t->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_overrides');
    }
};
