<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 261027-duz — extend import_issues.issue_type ENUM with
 * 'supplier_cost_outlier'.
 *
 * Structure copied from 2026_08_09_120000 (stale_cost_no_supplier). MySQL:
 * ALTER TABLE MODIFY COLUMN listing ALL six values. SQLite (tests): the enum is
 * a CHECK constraint that cannot be altered, so the column is dropped + re-added
 * (RefreshDatabase test DBs are empty here — no row migration needed).
 *
 * Written by SupplierDbSyncCommand::resolveBuyForProduct when the cheapest
 * supplier offer is < 1/ratio of the lower median of the other suppliers'
 * prices — buy_price is HELD and this issue raised for operator review.
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
                ."'exclude_flag_no_metadata','stale_cost_no_supplier',"
                ."'supplier_cost_outlier'"
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
                    'stale_cost_no_supplier',
                    'supplier_cost_outlier',
                ])->after('woo_variation_id');
            });
            Schema::table('import_issues', function ($t): void {
                $t->index('issue_type');
            });
        }
        // Other drivers: no-op — this app runs on MySQL (prod) / SQLite (tests).
    }

    public function down(): void
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
            Schema::table('import_issues', function ($t): void {
                $t->index('issue_type');
            });
        }
    }
};
