<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 Plan 01 — quotes table (QUOT-01).
 *
 * Foundation schema for the v2 B2B quote-flow on top of Phase 4 CRM seam +
 * Phase 9 trade-pricing decorator. Snapshot intent: every column suffixed
 * `_at_quote` is set ONCE at quote creation and NEVER mutates after status
 * leaves `draft` (D-13 line snapshot immutability + Plan 11-02 observer).
 *
 * Column dispositions (CONTEXT.md decisions):
 *   - id CHAR(26) ULID PK (Phase 1 D-16 + Phase 8 AGNT-03 ULID precedent)
 *   - user_id NULLABLE FK → users.id ON DELETE SET NULL (D-01 dual-mode
 *     customer model — anonymous-lead path supports Phase 13 WhatsApp +
 *     Phase 14 chatbot + cold sales calls)
 *   - customer_group_id NULLABLE FK → customer_groups.id ON DELETE RESTRICT
 *     (D-02 — snapshotted at creation, NOT joined; deleting a group with
 *     historical quotes is forbidden — admin must deactivate or migrate)
 *   - customer_group_name_at_quote VARCHAR(255) NULLABLE (CONTEXT.md
 *     Claude's Discretion — denormalised string preserves the group label
 *     even if FK is later renamed; A9 RESOLVED)
 *   - customer_email/customer_name VARCHAR(255) (D-01 denormalised contact
 *     fields — populated from User on D-03 toggle ON; free-text on toggle OFF)
 *   - billing_address JSON NULLABLE (D-01 PII — protected by Filament
 *     QuotePolicy view/viewAny gates; T-11-01-04 mitigation)
 *   - status VARCHAR(32) — NOT native ENUM for SQLite test compat; validated
 *     by App\Domain\Quotes\Enums\QuoteStatus 7-case enum. Default 'draft'.
 *   - total_pence_at_quote INTEGER UNSIGNED — RESEARCH OQ-1 RESOLVED
 *     (cached SUM(quote_lines.line_total_pence_at_quote); recompute observer
 *     ships in Plan 11-02; locked alongside lines after status=sent).
 *   - expires_at + 4 status timestamps (sent_at/accepted_at/rejected_at/
 *     expired_at) — capture transition moments without scanning activity_log.
 *   - rejection_metadata JSON NULLABLE (D-08 — structured reject reason +
 *     optional free-text note; parallels Phase 10 D-09 agent_rejection_feedback).
 *   - correlation_id VARCHAR(64) — threads through QuoteApproved event →
 *     PushQuoteToBitrix listener → PushQuoteToBitrixDealJob (Phase 1 D-04 +
 *     cross-cutting invariant 6).
 *
 * Indexes:
 *   - (status, expires_at) — powers `quotes:expire` query (Plan 11-05)
 *   - customer_email — lookup for Phase 14 chatbot "your quotes" handoff
 *   - correlation_id — forensic join with audit_log + integration_events
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('customer_group_id')->nullable()->constrained('customer_groups')->restrictOnDelete();
            $t->string('customer_group_name_at_quote', 255)->nullable();
            $t->string('customer_email', 255);
            $t->string('customer_name', 255)->nullable();
            $t->json('billing_address')->nullable();
            $t->string('status', 32)->default('draft');
            $t->unsignedBigInteger('total_pence_at_quote')->default(0);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('rejected_at')->nullable();
            $t->timestamp('expired_at')->nullable();
            $t->json('rejection_metadata')->nullable();
            $t->string('correlation_id', 64)->nullable()->index();
            $t->timestamps();

            $t->index(['status', 'expires_at'], 'quotes_status_expires_idx');
            $t->index('customer_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
