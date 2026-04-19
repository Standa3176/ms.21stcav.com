<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Plan 01 — Competitor CSV ingest runs (mirrors Phase 2 sync_runs shape).
 *
 * One row per dispatched IngestCompetitorCsvJob. Carries correlation_id so the
 * whole chain (watcher → job → chunk → error rows → suggestions) is traceable.
 * `started` → `completed` | `failed` state machine (simpler than sync_runs —
 * competitor ingest is idempotent-on-replay per COMP-07 so no `aborted` mid-state).
 *
 * - `correlation_id` VARCHAR(36) — matches Phase 1 schema (Plan 02-02 lesson).
 * - `(competitor_id, started_at)` composite index for per-competitor run history.
 * - `rows_orphaned` — counted orphan-SKU rows for D-09 cross-competitor dedup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_ingest_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('competitor_id')
                ->nullable()
                ->constrained('competitors')
                ->nullOnDelete();
            $t->string('filename');

            // Aggregate counters
            $t->unsignedInteger('rows_total')->default(0);
            $t->unsignedInteger('rows_written')->default(0);
            $t->unsignedInteger('rows_errored')->default(0);
            $t->unsignedInteger('rows_orphaned')->default(0);

            $t->enum('status', ['started', 'completed', 'failed'])->default('started');
            $t->timestamp('started_at');
            $t->timestamp('completed_at')->nullable();
            $t->string('correlation_id', 36)->index();
            $t->text('error_message')->nullable();
            $t->timestamps();

            // Per-competitor run history + stale-feed queries
            $t->index(['competitor_id', 'started_at'], 'competitor_ingest_runs_comp_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_ingest_runs');
    }
};
