<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 Plan 01 — D-02 dashboard_snapshots table.
 *
 * One row per widget metric (identified by metric_key). The scheduled
 * `dashboard:refresh` command (Plan 07-02) upserts each row in place — the
 * unique index on metric_key enforces one-row-per-metric semantics so widgets
 * read a fixed small rowset instead of running live aggregations.
 *
 * Schema:
 *   - metric_key            varchar(128) UNIQUE — e.g. 'last_sync_run',
 *                           'crm_push_success_rate_24h', 'divergence_parity_pct'
 *   - metric_value_json     JSON payload — structure varies per widget
 *   - computed_at           timestamp — when the snapshot was computed; indexed
 *                           for freshness queries and retention prune
 *   - timestamps            standard Laravel created_at / updated_at
 *
 * Retention: `config('dashboard.snapshot_retention_days')` (30d default) —
 * Plan 07-06 wires a `snapshots:prune` scheduled command.
 *
 * Row count: ≤20 rows at any time (one per metric), so table never grows
 * unbounded — retention prune is future-proofing for optional history-table
 * splits (sparklines) deferred per CONTEXT.md §deferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('metric_key', 128);
            $table->json('metric_value_json');
            $table->timestamp('computed_at')->useCurrent();
            $table->timestamps();

            // D-02 upsert-by-metric_key semantics — one row per metric.
            $table->unique('metric_key');
            // Freshness queries + retention prune walk this index.
            $table->index('computed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_snapshots');
    }
};
