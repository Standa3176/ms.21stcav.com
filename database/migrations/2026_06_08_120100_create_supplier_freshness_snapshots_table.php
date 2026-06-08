<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260608-g8x — `supplier_freshness_snapshots` snapshot table.
 *
 * Backs `suppliers:check-stale` (Mon-Fri 07:45 London) which TRUNCATEs and
 * re-INSERTs every supplier's fresh/amber/stale/unknown classification per run.
 * Snapshot semantics, NOT history — mirrors the 260607-t6w category_audit_findings
 * shape.
 *
 * The dashboard widget + NotificationCentre `staleSuppliers()` bucket read this
 * table (NOT the live SupplierFreshnessResolver) so widget renders stay sub-ms
 * per the D-02 truth: "never live aggregation on page load."
 *
 * supplier_id is VARCHAR(16) string, NOT an FK — the resolver may classify a
 * supplier_id we discovered THIS run before the `suppliers` table has acquired
 * its `updateOrCreate` row. Loose-coupled to survive the fresh-discovery path.
 *
 * Indexes:
 *   - run_id alone: cheap lookup for "rows for the latest run" (sub-select)
 *   - (supplier_id, status, run_id): supports per-supplier history pulls
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_freshness_snapshots', function (Blueprint $table) {
            $table->id();
            // Matches `suppliers.supplier_id` width (16) byte-for-byte.
            // NOT an FK — see class docblock for the loose-coupling rationale.
            $table->string('supplier_id', 16);
            $table->string('supplier_name', 100)->nullable();
            // MAX(recorded_at) from supplier_offer_snapshots for this supplier_id.
            // NULL when the supplier has zero snapshots ever (status='unknown').
            $table->date('latest_recorded_at')->nullable();
            // Derived; NULL when latest_recorded_at is NULL.
            $table->integer('days_since')->nullable();
            // Per-supplier override OR config default at write time.
            $table->integer('threshold_days');
            // Driver-agnostic ENUM via string + check at the model/resolver level.
            // Values: 'fresh' | 'amber' | 'stale' | 'unknown'.
            $table->string('status', 16);
            // ULID per `suppliers:check-stale` invocation. Latest run_id = "current."
            $table->char('run_id', 26);
            // No updated_at — snapshot rows are immutable; only the run that wrote
            // them matters. created_at gives operator the wall-clock for the run.
            $table->timestamp('created_at')->nullable();

            $table->index('run_id');
            $table->index(['supplier_id', 'status', 'run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_freshness_snapshots');
    }
};
