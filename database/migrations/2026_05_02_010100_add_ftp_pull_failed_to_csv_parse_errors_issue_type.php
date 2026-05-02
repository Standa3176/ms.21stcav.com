<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11.1 Plan 01 — D-10 extend csv_parse_errors.issue_type ENUM.
 *
 * Phase 5 created issue_type as a MySQL native ENUM (verified in
 * 2026_04_21_090400_create_csv_parse_errors_table.php lines 45-52).
 * Adding a new value REQUIRES `ALTER TABLE ... MODIFY COLUMN ... ENUM(...)`
 * to preserve the DB-level enum guarantee.
 *
 * MySQL: ALTER TABLE MODIFY COLUMN preserves the DB-level enum guarantee.
 *
 * SQLite (test DB): Phase 5's `$t->enum(...)` emits a CHECK constraint that
 * does NOT include 'ftp_pull_failed'. SQLite cannot ALTER an existing CHECK
 * constraint — the column must be dropped + re-added. We use Laravel's
 * Schema::table to drop the column then re-add it with the extended enum
 * list so feature tests against SQLite (RefreshDatabase + DB_DATABASE=:memory:)
 * can insert `ftp_pull_failed` rows.
 *
 * The new value `ftp_pull_failed` is fired by CompetitorFtpPullCommand's
 * handleSourceFailure() path. Surfaces in the existing Phase 5
 * CsvIngestIssuesPage Filament tab — no new UI needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE csv_parse_errors MODIFY COLUMN issue_type ENUM("
                ."'ambiguous_mapping','encoding_failure','unparseable_price',"
                ."'invalid_sku_format','invalid_filename','orphan_sku','ftp_pull_failed'"
                .") NOT NULL"
            );

            return;
        }

        if ($driver === 'sqlite') {
            // SQLite: rebuild the column so the CHECK constraint accepts the new value.
            // RefreshDatabase test DBs are empty when this runs, so no row migration needed.
            // Drop the (issue_type, resolved_at) composite index first — SQLite refuses
            // to drop a column referenced by an index.
            Schema::table('csv_parse_errors', function ($t): void {
                $t->dropIndex('csv_parse_errors_type_resolved_idx');
            });
            Schema::table('csv_parse_errors', function ($t): void {
                $t->dropColumn('issue_type');
            });
            Schema::table('csv_parse_errors', function ($t): void {
                $t->enum('issue_type', [
                    'ambiguous_mapping',
                    'encoding_failure',
                    'unparseable_price',
                    'invalid_sku_format',
                    'invalid_filename',
                    'orphan_sku',
                    'ftp_pull_failed',
                ])->after('filename');
            });
            // Restore the composite index so Phase 5's CsvIngestIssuesPage tabs stay covered.
            Schema::table('csv_parse_errors', function ($t): void {
                $t->index(['issue_type', 'resolved_at'], 'csv_parse_errors_type_resolved_idx');
            });
        }
        // Other drivers (pgsql, sqlsrv): no-op — Phase 5 doesn't run there.
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE csv_parse_errors MODIFY COLUMN issue_type ENUM("
                ."'ambiguous_mapping','encoding_failure','unparseable_price',"
                ."'invalid_sku_format','invalid_filename','orphan_sku'"
                .") NOT NULL"
            );

            return;
        }

        if ($driver === 'sqlite') {
            Schema::table('csv_parse_errors', function ($t): void {
                $t->dropIndex('csv_parse_errors_type_resolved_idx');
            });
            Schema::table('csv_parse_errors', function ($t): void {
                $t->dropColumn('issue_type');
            });
            Schema::table('csv_parse_errors', function ($t): void {
                $t->enum('issue_type', [
                    'ambiguous_mapping',
                    'encoding_failure',
                    'unparseable_price',
                    'invalid_sku_format',
                    'invalid_filename',
                    'orphan_sku',
                ])->after('filename');
            });
            Schema::table('csv_parse_errors', function ($t): void {
                $t->index(['issue_type', 'resolved_at'], 'csv_parse_errors_type_resolved_idx');
            });
        }
    }
};
