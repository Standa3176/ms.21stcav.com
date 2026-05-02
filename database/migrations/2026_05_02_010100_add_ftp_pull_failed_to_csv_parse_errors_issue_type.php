<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 11.1 Plan 01 — D-10 extend csv_parse_errors.issue_type ENUM.
 *
 * Phase 5 created issue_type as a MySQL native ENUM (verified in
 * 2026_04_21_090400_create_csv_parse_errors_table.php lines 45-52).
 * Adding a new value REQUIRES `ALTER TABLE ... MODIFY COLUMN ... ENUM(...)`
 * to preserve the DB-level enum guarantee.
 *
 * SQLite test DB (if used) has no native ENUM constraint — driver guard
 * makes the migration a no-op there. This mirrors Phase 11 Plan 01 A2's
 * driver-guarded ENUM extension precedent (bitrix_entity_map.entity_type).
 *
 * The new value `ftp_pull_failed` is fired by CompetitorFtpPullCommand's
 * handleSourceFailure() path (Plan 11.1-01 Task 2). Surfaces in the existing
 * Phase 5 CsvIngestIssuesPage Filament tab — no new UI needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // SQLite has no native ENUM — silent no-op.
        }

        DB::statement(
            "ALTER TABLE csv_parse_errors MODIFY COLUMN issue_type ENUM("
            ."'ambiguous_mapping','encoding_failure','unparseable_price',"
            ."'invalid_sku_format','invalid_filename','orphan_sku','ftp_pull_failed'"
            .") NOT NULL"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Restore Phase 5 enum (without ftp_pull_failed).
        DB::statement(
            "ALTER TABLE csv_parse_errors MODIFY COLUMN issue_type ENUM("
            ."'ambiguous_mapping','encoding_failure','unparseable_price',"
            ."'invalid_sku_format','invalid_filename','orphan_sku'"
            .") NOT NULL"
        );
    }
};
