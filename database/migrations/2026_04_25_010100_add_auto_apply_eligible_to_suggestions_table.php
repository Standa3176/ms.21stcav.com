<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 Plan 01 — Claude's Discretion: prepare suggestions table for v2.1
 * per-kind auto-apply opt-in.
 *
 * Column ships nullable (default NULL = manual approval) — v2.0 logic does
 * NOT consult this column. AGENT_AUTO_APPLY_ENABLED stays false in v2.0.
 * v2.1 may flip per-kind for low-stakes flows by setting this column TRUE
 * on suggestions whose kind has been operator-approved for auto-apply.
 *
 * NOTE: proposed_by_type / proposed_by_id morph columns are NOT touched —
 * Phase 1 D-14 already shipped them via the original create_suggestions_table
 * migration (database/migrations/2026_04_18_180100_create_suggestions_table.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suggestions', function (Blueprint $t): void {
            $t->boolean('auto_apply_eligible')
                ->nullable()
                ->after('evidence');
        });
    }

    public function down(): void
    {
        Schema::table('suggestions', function (Blueprint $t): void {
            $t->dropColumn('auto_apply_eligible');
        });
    }
};
