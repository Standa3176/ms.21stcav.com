<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Plan 03 — D-09 narrow-patch note de-duplication.
 *
 * OrderNoteSynchroniser needs per-Deal persistence of "which Woo note IDs have
 * already been posted into COMMENTS" so re-deliveries of order.updated don't
 * double-post the same note. Stored as a JSON array of sha256(note_id|body)
 * hashes on the deal's bitrix_entity_map row.
 *
 * Nullable so existing adopted_legacy rows stay valid without a backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bitrix_entity_map', function (Blueprint $t): void {
            $t->json('notes_hash_set')->nullable()->after('last_status_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('bitrix_entity_map', function (Blueprint $t): void {
            $t->dropColumn('notes_hash_set');
        });
    }
};
