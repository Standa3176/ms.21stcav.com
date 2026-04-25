<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 Plan 01 — AgentRun canonical schema (D-06 + plan-checker iter 1).
 *
 * 15 columns: 14 D-06 mandated + `guardrail_failures` JSON nullable for
 * ROADMAP success criterion #4. `triggering_correlation_id` is varchar(36)
 * for v1 correlation_id parity (suggestions.correlation_id same shape).
 *
 * Indexes:
 *   - (kind, status)             — Filament list view default filter
 *   - triggering_correlation_id  — cross-domain forensic join with v1 audit_log + integration_events
 *   - triggering_suggestion_id   — Phase 10 PricingAgent enrichment lookup
 *   - started_at                 — chronological list view + 5y retention prune (D-07)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('kind', 32);
            $t->string('status', 32)->default('running');

            $t->ulid('triggering_suggestion_id')->nullable();
            $t->string('triggering_correlation_id', 36)->nullable();

            $t->char('system_prompt_hash', 64);
            $t->json('tool_calls');
            $t->text('agent_reasoning_summary')->nullable();
            $t->string('finish_reason', 32)->nullable();

            $t->unsignedInteger('prompt_token_count')->default(0);
            $t->unsignedInteger('completion_token_count')->default(0);
            $t->unsignedInteger('cost_pence')->default(0);

            $t->string('langfuse_trace_id', 64)->nullable();

            $t->timestamp('started_at');
            $t->timestamp('completed_at')->nullable();

            // Plan-checker iter 1 — 15th column captures guardrail violation reason
            // for ROADMAP success criterion #4. Each entry shape:
            //   {guardrail: '\\App\\Domain\\Agents\\Guardrails\\OutboundRegexFilterGuardrail',
            //    message: 'pattern matched: /cost_price\\s*[:=]\\s*\\d+/i',
            //    when: 'pre' | 'post',
            //    occurred_at: '2026-04-25T12:34:56+01:00'}
            $t->json('guardrail_failures')->nullable();

            $t->timestamps();

            $t->index(['kind', 'status']);
            $t->index('triggering_correlation_id');
            $t->index('triggering_suggestion_id');
            $t->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
