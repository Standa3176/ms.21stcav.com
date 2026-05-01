<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 Plan 01 — extend bitrix_entity_map for quote_deal entity type
 * (RESEARCH OQ-2 RESOLVED — Option a).
 *
 * Adds:
 *   - quote_id CHAR(26) NULLABLE (ULID) — separate from existing
 *     unsignedBigInteger woo_id; the two columns coexist (orders use
 *     woo_id; quote-deals use quote_id).
 *   - composite UNIQUE INDEX (entity_type, quote_id) named
 *     bitrix_entity_map_entity_type_quote_id_unique — coexists with the
 *     existing UNIQUE(entity_type, woo_id) index for orders. Plan 11-04
 *     queries via scopeForQuote() / scopeForWooOrder() which key off the
 *     two indexes individually.
 *
 * SCHEMA DEVIATION (Rule 1 — bug fix, plan A2 assumed VARCHAR but found
 * ENUM):
 *   The original Phase 4 migration declared entity_type as MySQL ENUM
 *   (deal | contact | company). Plan 11 needs to add 'quote_deal' as a
 *   fourth value. Two options:
 *     (a) ALTER COLUMN to add 'quote_deal' to the ENUM allow-list — keeps
 *         the strict-validation guarantee but couples future entity types
 *         to a schema migration each time.
 *     (b) ALTER COLUMN to convert ENUM → VARCHAR(20) — relaxes the DB-
 *         level enum validation (model-level constants enforce instead)
 *         but no future schema churn for new entity_types.
 *   Chose (a) — preserve the DB-level enum guarantee. Plan A2 was wrong
 *   about the existing type but correct about intent: add quote_deal to
 *   the accepted set. We MODIFY the enum to include 'quote_deal' on MySQL;
 *   on SQLite (test DB), the ENUM is silently a string column and any
 *   value is already accepted, so the DB::statement is a no-op.
 *
 * down(): drops new index + column, then reverts the entity_type ENUM
 * back to its original 3-value set. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL: extend the ENUM allow-list to accept 'quote_deal'.
        // SQLite (testing): ENUMs are stored as TEXT — any value is already
        // accepted and the MODIFY COLUMN syntax doesn't apply.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `bitrix_entity_map` MODIFY COLUMN `entity_type` "
                ."ENUM('deal', 'contact', 'company', 'quote_deal') NOT NULL"
            );
        }

        Schema::table('bitrix_entity_map', function (Blueprint $t): void {
            // ULID column nullable — orders rows have woo_id set + quote_id null;
            // quote_deal rows have quote_id set + woo_id=0 sentinel.
            $t->ulid('quote_id')->nullable()->after('woo_id');

            // Composite UNIQUE — coexists with the existing
            // bitrix_entity_map_type_woo_id_unique index. Plan 11-04
            // EntityDeduper.findDealByQuoteId queries via this index.
            $t->unique(
                ['entity_type', 'quote_id'],
                'bitrix_entity_map_entity_type_quote_id_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('bitrix_entity_map', function (Blueprint $t): void {
            $t->dropUnique('bitrix_entity_map_entity_type_quote_id_unique');
            $t->dropColumn('quote_id');
        });

        // Revert ENUM allow-list to the original Phase 4 set.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `bitrix_entity_map` MODIFY COLUMN `entity_type` "
                ."ENUM('deal', 'contact', 'company') NOT NULL"
            );
        }
    }
};
