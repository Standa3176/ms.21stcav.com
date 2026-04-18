<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent role + permission seeder (D-03).
 *
 * Per 01-CONTEXT.md D-02 role scope:
 *   - admin           — all permissions
 *   - pricing_manager — CRUD on Product + PricingRule; view-only on CompetitorPrice + SyncRun
 *   - sales           — view-only on CrmPushLog
 *   - read_only       — all view_* / view_any_* permissions, no mutations
 *
 * Safe to re-run on every deploy: uses firstOrCreate + syncPermissions().
 * Queries permissions by name PATTERN so new Resources added in later plans
 * (Suggestion in Plan 04, AlertRecipient in Plan 05, Product in Phase 2, etc.)
 * are auto-included once `shield:generate` produces their permissions.
 *
 * Permission name format verified against Shield 3.9.10 output
 * (01-02 execution): `{action}_{resource_snake_singular}` — e.g. `view_any_role`,
 * `create_pricing_rule`. NOT `::` separated. The LIKE patterns below assume
 * this format. See 01-02-SUMMARY.md for the verification record.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Clear the Spatie permission cache so newly-created rows are visible
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 2. Create (or fetch) the 4 roles (idempotent)
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $pricingManager = Role::firstOrCreate(['name' => 'pricing_manager', 'guard_name' => 'web']);
        $sales = Role::firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);
        $readOnly = Role::firstOrCreate(['name' => 'read_only', 'guard_name' => 'web']);

        // 3. admin → ALL permissions (idempotent sync)
        $admin->syncPermissions(Permission::all());

        // 4. read_only → only view_* and view_any_* permissions across every Resource.
        //    T-02-01 mitigation: explicitly filter to view prefixes only.
        $readOnly->syncPermissions(
            Permission::query()
                ->where('name', 'like', 'view_%')
                ->get()
        );

        // 5. pricing_manager → CRUD on product + pricing_rule; view-only on competitor_price + sync_run
        //    Permissions arrive in later plans (Product in Phase 2, PricingRule in Phase 3, etc.)
        //    — seeder is defensive: queries by name pattern, no-ops if the Resource doesn't exist yet.
        $pricingManagerPermissions = Permission::query()
            ->where(function ($q) {
                // CRUD on product + product_variant + import_issue + pricing_rule (all actions).
                //
                // MySQL LIKE gotcha (Plan 02-04): `%_product` does NOT match
                // `view_product_variant` — the trailing `_product` anchors to the END
                // of the string. Same for `%_import_issue` vs `%_import_issue_*`. So
                // we MUST add `%_product_variant` and `%_import_issue` patterns
                // unconditionally; the broader `%_product` catches only the Product
                // resource's permissions (view_product, update_product, etc.).
                $q->where('name', 'like', '%_product')
                    ->orWhere('name', 'like', '%_product_variant')   // Phase 2 — D-01 variant edit access
                    ->orWhere('name', 'like', '%_import_issue')      // Phase 2 — SYNC-12 / D-09 triage
                    ->orWhere('name', 'like', '%_pricing_rule');
            })
            ->orWhere(function ($q) {
                // View-only on competitor_price + sync_run
                $q->whereIn('name', [
                    'view_competitor_price',
                    'view_any_competitor_price',
                    'view_sync_run',
                    'view_any_sync_run',
                ]);
            })
            ->pluck('name')
            ->all();

        $pricingManager->syncPermissions($pricingManagerPermissions);

        // 6. sales → view-only on crm_push_log (D-02; Resource lands in Phase 4)
        $salesPermissions = Permission::query()
            ->whereIn('name', [
                'view_crm_push_log',
                'view_any_crm_push_log',
            ])
            ->pluck('name')
            ->all();

        $sales->syncPermissions($salesPermissions);

        // 7. Forget cache again so role changes take effect immediately
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            'Roles synced: admin=%d perms, pricing_manager=%d, sales=%d, read_only=%d',
            $admin->permissions()->count(),
            $pricingManager->permissions()->count(),
            $sales->permissions()->count(),
            $readOnly->permissions()->count()
        ));
    }
}
