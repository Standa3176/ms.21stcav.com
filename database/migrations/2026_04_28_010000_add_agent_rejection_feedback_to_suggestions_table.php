<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 Plan 05 Task 1 — D-09 structured rejection feedback column.
 *
 * Adds a nullable JSON column `suggestions.agent_rejection_feedback` for the
 * D-09 structured rejection form (misleading radio + notes + audit trail) +
 * subsequent triage metadata (mark_triaged bulk action on the rejection inbox).
 *
 * Storage strategy (Plan 10-05 Step B — column-canonical resolution):
 *   - Top-level dedicated column rather than `evidence.agent_rejection_feedback`
 *     JSON sub-key — chosen so the AgentRunRejectionInboxPage query is a
 *     simple `whereNotNull('agent_rejection_feedback')` (indexable if needed)
 *     instead of a JSON-path scan
 *   - NULL = legacy / non-agent rejection (no structured feedback captured)
 *   - Non-NULL = D-09 form was submitted with misleading flag + notes;
 *     bulk-action mark_triaged appends triaged_at + triage_note + triaged_by_user_id
 *
 * Structure (written by SuggestionResource reject action + mark_triaged bulk):
 *   {
 *     "misleading": "yes" | "no" | "partial",   // D-09 radio
 *     "notes": "<min 10 chars, max 2000>",      // D-09 textarea
 *     "rejected_by_user_id": <int>,             // auth()->id()
 *     "rejected_at": "<ISO 8601>",              // now()->toIso8601String()
 *     "triaged_at": "<ISO 8601>",               // optional — set by inbox bulk action
 *     "triage_note": "<string>",                // optional — set by inbox bulk action
 *     "triaged_by_user_id": <int>               // optional — set by inbox bulk action
 *   }
 *
 * No backfill needed — existing rejected suggestions stay NULL (= "not yet
 * given structured feedback"); admins can re-reject with structured form
 * if they want to capture D-09 metadata after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suggestions', function (Blueprint $table): void {
            // Phase 10 D-09 — structured rejection feedback for prompt iteration triage.
            // NULL = rejected without structured feedback (legacy or non-agent rejections).
            $table->json('agent_rejection_feedback')->nullable()->after('evidence');
        });
    }

    public function down(): void
    {
        Schema::table('suggestions', function (Blueprint $table): void {
            $table->dropColumn('agent_rejection_feedback');
        });
    }
};
