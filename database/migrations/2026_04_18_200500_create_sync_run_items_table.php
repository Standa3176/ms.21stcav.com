<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Plan 01 — SyncRunItem append-only per-SKU log.
 *
 * Feeds the D-10 11-column CSV report:
 *   sku, woo_product_id, woo_variation_id, action, reason,
 *   old_price, new_price, old_stock, new_stock, error_message, correlation_id
 *
 * action enum matches D-10 exactly. Prices stored as VARCHAR(32) so we can
 * pass through supplier's 2dp string representation without float drift
 * (per Claude's Discretion in 02-CONTEXT.md: "exact string match on stock;
 * price treated as 2dp strings").
 *
 * Append-only — NO updated_at. sync_run_id FK cascadeOnDelete so retention
 * prune on SyncRun sweeps items too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_run_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();
            $t->string('sku', 100);
            $t->unsignedBigInteger('woo_product_id')->nullable();
            $t->unsignedBigInteger('woo_variation_id')->nullable();
            $t->enum('action', ['updated', 'skipped', 'failed', 'missing', 'unknown_sku']);
            $t->string('reason', 255)->nullable();
            $t->string('old_price', 32)->nullable();   // 2dp string per D-discretion
            $t->string('new_price', 32)->nullable();
            $t->integer('old_stock')->nullable();
            $t->integer('new_stock')->nullable();
            $t->text('error_message')->nullable();
            $t->uuid('correlation_id')->index();
            $t->timestamp('created_at')->useCurrent();
            // NO updated_at — append-only.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_run_items');
    }
};
