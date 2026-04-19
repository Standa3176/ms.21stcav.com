<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Plan 05 Task 2 — gdpr_erasure_log (CRM-13).
 *
 * Indefinite-retention audit table for GDPR right-to-erasure events.
 *
 * The parallel `audit_log` entry (written by Auditor::record('gdpr_erasure'))
 * is subject to the Phase 1 D-04 365-day prune; this table is NOT. Keeping
 * erasure records indefinitely satisfies ICO regulator-query windows and
 * survives the retention cap in a deliberate, separately-policied way.
 *
 * Write path is append-only — rows are never updated or deleted by app code.
 * Rollback is an explicit ops action (migration down() drops the table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gdpr_erasure_log', function (Blueprint $t): void {
            $t->id();
            $t->string('email_hash', 64)->index();
            $t->string('contact_bitrix_id', 64)->nullable();
            $t->json('deal_bitrix_ids')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('correlation_id', 36)->nullable()->index();
            $t->unsignedInteger('fields_scrubbed_count')->default(0);
            $t->string('status', 20)->default('applied');   // 'applied' | 'no_match' | 'failed'
            $t->text('notes')->nullable();
            $t->timestamp('erased_at')->useCurrent();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gdpr_erasure_log');
    }
};
