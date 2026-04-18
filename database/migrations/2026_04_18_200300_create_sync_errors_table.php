<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Plan 01 — SyncError append-only table.
 *
 * Per-item failure log written by SyncChunkJob when a SKU write throws.
 * Append-only: NO updated_at. correlation_id indexed for cross-table trace.
 *
 * FK cascadeOnDelete ensures SyncRun retention prune sweeps child errors
 * (T-02-01-05 DoS mitigation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_errors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();
            $t->string('sku', 100)->index();
            $t->unsignedBigInteger('woo_product_id')->nullable();
            $t->unsignedBigInteger('woo_variation_id')->nullable();
            $t->string('error_class', 255);
            $t->text('error_message');
            $t->uuid('correlation_id')->index();
            $t->timestamp('created_at')->useCurrent();
            // NO updated_at — append-only log.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_errors');
    }
};
