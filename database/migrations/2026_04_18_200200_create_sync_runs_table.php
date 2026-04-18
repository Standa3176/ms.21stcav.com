<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Plan 01 — SyncRun state table.
 *
 * One row per `php artisan sync:supplier` invocation; status progresses
 * queued → running → completed | aborted | failed.
 *
 * cursor_page + cursor_sku persist so an aborted/crashed run can resume from
 * the last-processed page (SYNC-03 + D-07).
 *
 * `consecutive_failures` (unsigned int, default 0) is the D-06(b) Checker-blocker
 * shared-state counter: incremented/reset atomically by App\Domain\Sync\Services\AbortGuard
 * (Plan 02-03) via SyncRun::increment so multi-worker supervisors on sync-woo-push
 * share state across processes. Without a DB column the counter was per-process
 * and 50-consecutive-failures triggers were silently unreachable under multi-worker
 * balancing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $t) {
            $t->id();
            $t->timestamp('started_at')->index();
            $t->timestamp('completed_at')->nullable();
            $t->enum('status', ['queued', 'running', 'completed', 'aborted', 'failed'])
                ->default('queued')->index();
            $t->boolean('dry_run')->default(true);                 // per D-04

            // Aggregate counters (denormalised for report speed)
            $t->integer('total_skus')->default(0);
            $t->integer('updated_count')->default(0);
            $t->integer('skipped_count')->default(0);
            $t->integer('failed_count')->default(0);
            $t->integer('missing_count')->default(0);
            $t->integer('unknown_sku_count')->default(0);

            // D-06(b) shared-state counter — per Checker blocker fix.
            // AbortGuard (Plan 02-03) reads/writes this atomically via
            // SyncRun::increment/update so multi-worker supervisors share state
            // for the "50+ consecutive failures → abort" trigger.
            $t->unsignedInteger('consecutive_failures')->default(0);

            // Abort metadata (D-06)
            $t->enum('abort_reason', ['error_rate', 'consecutive_failures', 'jwt_refresh', 'manual'])
                ->nullable();
            $t->text('abort_message')->nullable();

            // Cursor persistence (SYNC-03 + D-07)
            $t->integer('cursor_page')->default(0);
            $t->string('cursor_sku', 100)->nullable();

            $t->uuid('correlation_id')->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
