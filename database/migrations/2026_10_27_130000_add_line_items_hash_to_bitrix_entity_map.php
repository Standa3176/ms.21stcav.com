<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12.1 Plan 03 (D-04) — hash of the line items last pushed as deal
 * product rows. Rows are re-set on order.updated only when this changes.
 * Nullable: adopted/legacy deals have no baseline until first seen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bitrix_entity_map', function (Blueprint $t): void {
            $t->string('line_items_hash', 64)->nullable()->after('last_payload_hash');
        });
    }

    public function down(): void
    {
        Schema::table('bitrix_entity_map', function (Blueprint $t): void {
            $t->dropColumn('line_items_hash');
        });
    }
};
