<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOTFIX 260712-pbi — widen suggestions.proposed_by_id for AgentRun ULIDs.
 *
 * PROD FAILURE: every agent-generated Suggestion fails to write on MariaDB with
 *   SQLSTATE[22001]/1265 "Data truncated for column 'proposed_by_id'".
 *
 * ROOT CAUSE: the suggestions table defined `proposed_by` via
 * `nullableMorphs('proposed_by')` (migration 2026_04_18_180100) → `proposed_by_id`
 * is an UNSIGNED BIGINT. But `AgentRun` uses ULID primary keys, and every agent
 * suggestion writer (AdOptimisationResultMapper, SeoAgentResultMapper,
 * PricingAgentResultMapper, AgentSuggestionWriter) sets
 * `proposed_by_id = $run->id` — a 26-char ULID string. Inserting a ULID into an
 * integer column is rejected by MariaDB strict mode. SQLite (tests) ignores
 * column typing, so every mapper test passed while prod always failed
 * (memory: sqlite-mariadb-strict-trap).
 *
 * FIX: change `proposed_by_id` to a nullable CHAR(26) — the canonical ULID width
 * used elsewhere (integration_events.subject_id is nullableUlidMorphs / CHAR(26)).
 * CHAR(26) under utf8mb4 is 104 bytes; alongside the VARCHAR(255) `proposed_by_type`
 * (1020 bytes) the composite index stays well under the InnoDB 3072-byte limit.
 *
 * DATA-SAFE: the column is all-NULL in prod (no agent Suggestion ever wrote, and
 * non-agent proposers set proposed_by_type=NULL), so there is no value conversion.
 *
 * MARIADB-SAFE INDEX HANDLING: `nullableMorphs` created the composite index
 * `suggestions_proposed_by_type_proposed_by_id_index` on
 * (proposed_by_type, proposed_by_id). To be bulletproof across MySQL/MariaDB
 * versions we DROP that index → change the column → RE-CREATE the index, rather
 * than modifying a column while it is part of a live index. The default index
 * name is identical on MySQL/MariaDB and SQLite, so a single Schema-builder path
 * is driver-portable; on SQLite Laravel rebuilds the table for `change()`.
 *
 * The morph relation is in active use (Suggestion::proposedBy(), and
 * ViewAgentRun queries `where proposed_by_type = AgentRun::class`), so the correct
 * fix is to make the column hold ULIDs — NOT to null the morph out.
 */
return new class extends Migration
{
    private const MORPH_INDEX = ['proposed_by_type', 'proposed_by_id'];

    public function up(): void
    {
        // 1. Drop the morph composite index so the column is not modified in place
        //    while indexed (bulletproof across MySQL/MariaDB versions).
        Schema::table('suggestions', function (Blueprint $t): void {
            $t->dropIndex(self::MORPH_INDEX);
        });

        // 2. Widen the column: UNSIGNED BIGINT -> nullable CHAR(26) (ULID width).
        Schema::table('suggestions', function (Blueprint $t): void {
            $t->char('proposed_by_id', 26)->nullable()->change();
        });

        // 3. Re-create the composite morph index with the default name.
        Schema::table('suggestions', function (Blueprint $t): void {
            $t->index(self::MORPH_INDEX);
        });
    }

    public function down(): void
    {
        // Best-effort restore. The column is nullable/empty so narrowing back to
        // an integer is data-safe; any future ULID rows would be lost, hence
        // down() should only ever run before agent writes resume.
        Schema::table('suggestions', function (Blueprint $t): void {
            $t->dropIndex(self::MORPH_INDEX);
        });

        Schema::table('suggestions', function (Blueprint $t): void {
            $t->unsignedBigInteger('proposed_by_id')->nullable()->change();
        });

        Schema::table('suggestions', function (Blueprint $t): void {
            $t->index(self::MORPH_INDEX);
        });
    }
};
