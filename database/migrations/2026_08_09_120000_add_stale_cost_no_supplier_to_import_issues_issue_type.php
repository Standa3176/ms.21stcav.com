<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260809-uza PART 2 — extend import_issues.issue_type ENUM with
 * 'stale_cost_no_supplier'.
 *
 * Phase 2 created issue_type as a MySQL native ENUM (see
 * 2026_04_18_200400_create_import_issues_table.php lines 30-35 — a bare
 * `->enum(...)->index()`, a SINGLE-column index, NOT composite). Adding a new
 * value REQUIRES `ALTER TABLE ... MODIFY COLUMN ... ENUM(...)` on MySQL to
 * preserve the DB-level enum guarantee.
 *
 * MySQL: ALTER TABLE MODIFY COLUMN preserves the DB-level enum guarantee.
 *
 * SQLite (test DB): Phase 2's `$t->enum(...)` emits a CHECK constraint that does
 * NOT include 'stale_cost_no_supplier'. SQLite cannot ALTER an existing CHECK
 * constraint — the column must be dropped + re-added. RefreshDatabase test DBs
 * are empty when this runs, so no row migration is needed. This is exactly the
 * SQLite↔MariaDB strict trap that has bitten prod before; without this migration
 * the SQLite CHECK constraint WILL reject the new value and the Part-2 test fails.
 *
 * The new value is written by SupplierDbSyncCommand's unmatched branch when a
 * previously-costed product has no fresh in-stock supplier offer. Surfaces in the
 * existing Filament ImportIssueResource — the badge/filter arms are added there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                'ALTER TABLE import_issues MODIFY COLUMN issue_type ENUM('
                ."'missing_at_supplier','unknown_sku','missing_cost_price',"
                ."'exclude_flag_no_metadata','stale_cost_no_supplier'"
                .') NOT NULL'
            );

            return;
        }

        if ($driver === 'sqlite') {
            // SQLite: rebuild the column so the CHECK constraint accepts the new value.
            // Drop the single-column issue_type index first — SQLite refuses to drop a
            // column referenced by an index. (Laravel's default name for the create
            // migration's bare ->enum()->index() is import_issues_issue_type_index.)
            Schema::table('import_issues', function ($t): void {
                $t->dropIndex('import_issues_issue_type_index');
            });
            Schema::table('import_issues', function ($t): void {
                $t->dropColumn('issue_type');
            });
            Schema::table('import_issues', function ($t): void {
                $t->enum('issue_type', [
                    'missing_at_supplier',
                    'unknown_sku',
                    'missing_cost_price',
                    'exclude_flag_no_metadata',
                    'stale_cost_no_supplier',
                ])->after('woo_variation_id');
            });
            // Restore the single-column index Phase 2 created.
            Schema::table('import_issues', function ($t): void {
                $t->index('issue_type');
            });
        }
        // Other drivers (pgsql, sqlsrv): no-op — this app runs on MySQL (prod) / SQLite (tests).
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                'ALTER TABLE import_issues MODIFY COLUMN issue_type ENUM('
                ."'missing_at_supplier','unknown_sku','missing_cost_price',"
                ."'exclude_flag_no_metadata'"
                .') NOT NULL'
            );

            return;
        }

        if ($driver === 'sqlite') {
            Schema::table('import_issues', function ($t): void {
                $t->dropIndex('import_issues_issue_type_index');
            });
            Schema::table('import_issues', function ($t): void {
                $t->dropColumn('issue_type');
            });
            Schema::table('import_issues', function ($t): void {
                $t->enum('issue_type', [
                    'missing_at_supplier',
                    'unknown_sku',
                    'missing_cost_price',
                    'exclude_flag_no_metadata',
                ])->after('woo_variation_id');
            });
            Schema::table('import_issues', function ($t): void {
                $t->index('issue_type');
            });
        }
    }
};
