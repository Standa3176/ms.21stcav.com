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
 * Permission name format (verified 02-04 execution after shield:generate for
 * Phase 2 Resources): Shield emits TWO styles depending on resource class name:
 *   - Single-word: `{action}_{resource_snake_singular}` — e.g. `view_any_role`,
 *     `view_product`, `create_suggestion`
 *   - Multi-word (PascalCase): `{action}_{word1}::{word2}` — e.g. `view_sync::run`,
 *     `update_import::issue`, `view_any_alert::recipient`
 *
 * The seeder's LIKE patterns cover BOTH styles so a future Shield separator
 * change (or a Resource class rename) does not silently drop permissions.
 *
 * MySQL LIKE gotcha: `_` in a LIKE pattern is a single-char wildcard. So
 * `%_product` matches `view_product`, `create_product`, etc. (underscore-sep).
 * To match `sync::run`-style names we add explicit `%sync::run` patterns.
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

        // 2b. Phase 8 Plan 04 — manually create the AgentRunResource Shield
        // permissions because shield:safe-regenerate doesn't ship until Plan 05.
        // The admin sync below picks them up via Permission::all(); read_only
        // sync picks them up via the `view_%` LIKE pattern. AgentRunPolicy
        // (Plan 01) is the load-bearing auth layer either way — Shield perms
        // are belt; the policy is braces.
        foreach (['view_any_agent_run', 'view_agent_run'] as $agentPerm) {
            Permission::firstOrCreate(['name' => $agentPerm, 'guard_name' => 'web']);
        }

        // 2c. Phase 10 Plan 05 — run_pricing_agent permission (PRCAGT-05).
        //
        // Authorises the "Run pricing agent" Filament action on margin_change
        // Suggestions (Plan 10-04 RunPricingAgentAction) AND access to the
        // /admin/agent-runs/rejection-inbox triage page (Plan 10-05 Task 2
        // AgentRunRejectionInboxPage::canAccess soft-checks role; this perm
        // is the Shield-side gate paired with that role check).
        //
        // Assignment matrix per CONTEXT Claude's Discretion §"Admin permission":
        //   admin           — yes (covered by Permission::all() sync at step 3)
        //   pricing_manager — yes (explicit givePermissionTo at step 5b below)
        //   sales           — no  (NOT in the LIKE-pattern + NOT in the
        //                          explicit whereIn whitelist at step 6)
        //   read_only       — no  (NOT a `view_%` perm — outside the LIKE
        //                          pattern at step 4)
        Permission::firstOrCreate(['name' => 'run_pricing_agent', 'guard_name' => 'web']);

        // ── Phase 9 Plan 05 — Customer Group permissions (TRDE-04 D-10) ──
        // W-05: findByName matches v1 RolePermissionSeeder pattern;
        // brittleness is accepted v1-parity. CI fails loudly if roles are
        // missing, which is the desired signal — silent role-permission
        // drift is worse than a fail-fast Throwable on seed.
        //
        // 5 perms scaffolded by shield:safe-regenerate --allow-new=
        // CustomerGroupPolicy on first install (Plan 05 Task 2 step). The
        // explicit Permission::firstOrCreate below ensures the perms exist
        // even on cold-start tests / installations where shield:generate
        // hasn't run yet (mirrors the AgentRunResource pattern at 2b above).
        $tradePricingPermissions = [
            'view_any_customer_group',
            'view_customer_group',
            'create_customer_group',
            'update_customer_group',
            'delete_customer_group',
        ];

        foreach ($tradePricingPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        // Assignment matrix (D-10):
        //   - admin           — all 5 perms (covered by Permission::all() sync below)
        //   - pricing_manager — all 5 perms (explicit givePermissionTo)
        //   - sales           — view_any + view only (explicit givePermissionTo)
        //   - read_only       — view_any + view via the view_% LIKE pattern below
        //
        // Using givePermissionTo (additive) instead of syncPermissions on
        // the per-role level so we don't trample the LIKE-pattern role
        // syncs that follow. Idempotent because Spatie's givePermissionTo
        // is no-op on already-attached permissions.
        Role::findByName('pricing_manager')->givePermissionTo($tradePricingPermissions);
        Role::findByName('sales')->givePermissionTo([
            'view_any_customer_group',
            'view_customer_group',
        ]);
        // read_only: NO customer_group permissions; the view_% LIKE pattern
        // at step 4 below WILL pick up view_customer_group + view_any_customer_group
        // — D-10 says read_only is "locked out entirely", so we explicitly
        // revoke them after the LIKE-pattern sync. See step 4b below.
        // (admin gets all 5 via Permission::all() at step 3 below.)

        // 3. admin → ALL permissions (idempotent sync).
        //
        // Phase 4 Plan 04 — admin auto-attaches:
        //   %_crm_field_mapping  %_crm_status_mapping  %_crm_push_log
        //   %_crm_pipeline_setting  %_bitrix_entity_map  %_bitrix_backfill_run
        //   replay_suggestion   (plus every :: separator variant)
        // via the Permission::all() grant below — no LIKE-pattern edits needed.
        $admin->syncPermissions(Permission::all());

        // 4. read_only → only view_* and view_any_* permissions across every Resource.
        //    T-02-01 mitigation: explicitly filter to view prefixes only.
        $readOnly->syncPermissions(
            Permission::query()
                ->where('name', 'like', 'view_%')
                ->get()
        );

        // 4b. Phase 9 Plan 05 (TRDE-04 D-10) — read_only is "locked out
        // entirely" from customer_groups. The view_% LIKE pattern at step 4
        // above sweeps in view_any_customer_group + view_customer_group;
        // explicitly revoke them here so the D-10 role matrix matches.
        // Idempotent: revokePermissionTo is a no-op when not attached.
        $readOnly->revokePermissionTo([
            'view_any_customer_group',
            'view_customer_group',
        ]);

        // 5. pricing_manager → CRUD on product + pricing_rule; view-only on competitor_price + sync_run
        //    Permissions arrive in later plans (Product in Phase 2, PricingRule in Phase 3, etc.)
        //    — seeder is defensive: queries by name pattern, no-ops if the Resource doesn't exist yet.
        $pricingManagerPermissions = Permission::query()
            ->where(function ($q) {
                // CRUD on product + product_variant + import_issue + pricing_rule (all actions).
                //
                // Phase 1 style (underscore separator): `%_product`, `%_pricing_rule`.
                // Phase 2 post-shield:generate style (:: separator): `%import::issue`,
                // `%product::variant`.
                //
                // MySQL LIKE gotcha: `_` in a LIKE pattern is a single-char wildcard.
                // `%_product` matches `view_product` (last char + "product"), so it
                // catches all 12 Product perms. But `%_product_variant` WILL NOT match
                // Shield's `view_product::variant` output (different separator); the
                // `%product::variant` line below catches that second style.
                $q->where('name', 'like', '%_product')
                    ->orWhere('name', 'like', '%_product_variant')       // underscore style (forward-compat)
                    ->orWhere('name', 'like', '%product::variant')       // Shield :: style (Phase 2 observed)
                    ->orWhere('name', 'like', '%_import_issue')          // underscore style (forward-compat)
                    ->orWhere('name', 'like', '%import::issue')          // Shield :: style (Phase 2 observed)
                    ->orWhere('name', 'like', '%_pricing_rule')          // underscore style
                    ->orWhere('name', 'like', '%pricing::rule')          // Shield :: style (Phase 3 forward-compat)
                    ->orWhere('name', 'like', '%_product_override')      // underscore style
                    ->orWhere('name', 'like', '%product::override');     // Shield :: style (Phase 3 forward-compat)
            })
            ->orWhere(function ($q) {
                // Explicit whitelist (NOT LIKE) for Phase 5 resources with
                // action-level scope. D-04 + Plan 05-04a: pricing_manager gets
                // view + update on csv_parse_error and competitor_csv_mapping
                // — NOT create / delete / force_delete / restore / replicate /
                // reorder. `%_csv_parse_error` LIKE would catch every action
                // because MySQL `_` is a single-char wildcard, so we enumerate.
                //
                // View-only on competitor_price + competitor_ingest_run + sync_run
                // (trend + margin intel; "did the CSV arrive?" operational insight).
                $q->whereIn('name', [
                    // --- Competitor CSV mapping (D-04) — view + update only ---
                    'view_competitor_csv_mapping',
                    'view_any_competitor_csv_mapping',
                    'update_competitor_csv_mapping',
                    'view_competitor::csv::mapping',
                    'view_any_competitor::csv::mapping',
                    'update_competitor::csv::mapping',
                    // --- CSV parse error — view + update (mark resolved) only ---
                    'view_csv_parse_error',
                    'view_any_csv_parse_error',
                    'update_csv_parse_error',
                    'view_csv::parse::error',
                    'view_any_csv::parse::error',
                    'update_csv::parse::error',
                    // --- Competitor price — view only (Phase 5) ---
                    'view_competitor_price',
                    'view_any_competitor_price',
                    'view_competitor::price',
                    'view_any_competitor::price',
                    // --- Competitor ingest run — view only (Phase 5) ---
                    'view_competitor_ingest_run',
                    'view_any_competitor_ingest_run',
                    'view_competitor::ingest::run',
                    'view_any_competitor::ingest::run',
                    // --- Sync run — view only (Phase 2) ---
                    'view_sync_run',
                    'view_any_sync_run',
                    'view_sync::run',
                    'view_any_sync::run',
                    // ═══════════════════════════════════════════════════════════
                    // Phase 6 Plan 04 — ProductAutoCreate (EXPLICIT whereIn — NOT
                    // LIKE; Phase 5 Plan 04a MySQL `_` wildcard bug lesson).
                    // ═══════════════════════════════════════════════════════════
                    // --- Auto-create review — view + update (no create/delete) ---
                    //     (backing model is Product; these perms appear only if the
                    //     team chooses to Shield-generate the Review Resource as a
                    //     distinct model. Currently the Resource reuses the
                    //     existing Product permissions + AutoCreateReviewPolicy
                    //     scoping — forward-compat whitelist in case Shield emits
                    //     standalone perms in a future generation.)
                    'view_auto_create_review',
                    'view_any_auto_create_review',
                    'update_auto_create_review',
                    'view_auto::create::review',
                    'view_any_auto::create::review',
                    'update_auto::create::review',
                    // --- Auto-create skip rule — view only for pricing_manager ---
                    //     (rule catalogue is triage intel; CRUD is admin-only).
                    'view_auto_create_skip_rule',
                    'view_any_auto_create_skip_rule',
                    'view_auto::create::skip::rule',
                    'view_any_auto::create::skip::rule',
                    // --- Auto-create rejection — view + create (reject action writes) ---
                    'view_auto_create_rejection',
                    'view_any_auto_create_rejection',
                    'create_auto_create_rejection',
                    'view_auto::create::rejection',
                    'view_any_auto::create::rejection',
                    'create_auto::create::rejection',
                    // NOTE: NO Settings page perm for pricing_manager — draft vs
                    // immediate-publish governance stays admin-only (AUTO-07).
                    // ═══════════════════════════════════════════════════════════
                    // Phase 7 Plan 01 — Dashboard domain (D-02 + D-07).
                    // pricing_manager gets view-only on dashboard_snapshot
                    // (ambient ops intel) + full CRUD on their OWN
                    // user_saved_filter rows (policy scopes ownership).
                    // Using explicit whereIn (not LIKE) per Phase 5 Plan 04a
                    // MySQL `_` single-char wildcard lesson.
                    // ═══════════════════════════════════════════════════════════
                    // --- Dashboard snapshot — view-only for pricing_manager ---
                    'view_dashboard_snapshot',
                    'view_any_dashboard_snapshot',
                    'view_dashboard::snapshot',
                    'view_any_dashboard::snapshot',
                    // --- User saved filter — per-user CRUD (policy scopes
                    //     ownership; admin override on delete is policy-level).
                    'view_user_saved_filter',
                    'view_any_user_saved_filter',
                    'create_user_saved_filter',
                    'update_user_saved_filter',
                    'delete_user_saved_filter',
                    'view_user::saved::filter',
                    'view_any_user::saved::filter',
                    'create_user::saved::filter',
                    'update_user::saved::filter',
                    'delete_user::saved::filter',
                    // ═══════════════════════════════════════════════════════════
                    // Phase 10 Plan 05 — pricing_manager gets run_pricing_agent
                    // (PRCAGT-05). Authorises the "Run pricing agent" Filament
                    // action on margin_change Suggestions + the /admin/agent-runs/
                    // rejection-inbox triage page. Sales + read_only do NOT
                    // get this perm (CONTEXT Claude's Discretion §"Admin permission").
                    // ═══════════════════════════════════════════════════════════
                    'run_pricing_agent',
                ]);
            })
            ->pluck('name')
            ->all();

        $pricingManager->syncPermissions($pricingManagerPermissions);

        // 6. sales → view-only on crm_push_log (Phase 4) + competitor_price +
        //    competitor_ingest_run (Phase 5 Plan 04a — sales team uses competitor
        //    intel when building quotes, COMP-10 trend charts visibility; NO
        //    access to csv_parse_error or competitor_csv_mapping — those are
        //    pricing_manager + admin triage surfaces).
        $salesPermissions = Permission::query()
            ->where(function ($outer) {
                // ───── Read-only branch ─────
                // CRM push log + competitor intel + Phase 7 dashboard widgets.
                // Wrapped in an outer `view_%` AND clause so only view / view_any
                // perms attach (sales cannot mutate these resources).
                $outer->where(function ($q) {
                    $q->where(function ($inner) {
                        $inner->where('name', 'like', '%_crm_push_log')
                            ->orWhere('name', 'like', '%crm_push::log')
                            ->orWhere('name', 'like', '%crm::push_log')
                            ->orWhere('name', 'like', '%crm::push::log');
                    })
                        ->orWhereIn('name', [
                            // Phase 5 — competitor price visibility for quote-building.
                            'view_competitor_price',
                            'view_any_competitor_price',
                            'view_competitor::price',
                            'view_any_competitor::price',
                            // Phase 5 — ingest-run visibility ("is today's data in?").
                            'view_competitor_ingest_run',
                            'view_any_competitor_ingest_run',
                            'view_competitor::ingest::run',
                            'view_any_competitor::ingest::run',
                            // Phase 7 Plan 01 — dashboard widgets are ambient ops
                            // intel; sales sees CRM-centric tiles + global freshness.
                            'view_dashboard_snapshot',
                            'view_any_dashboard_snapshot',
                            'view_dashboard::snapshot',
                            'view_any_dashboard::snapshot',
                        ])
                        ->where('name', 'like', 'view_%');
                })
                // ───── Owner-scoped CRUD branch (Phase 7 Plan 01) ─────
                // Sales also needs create/update/delete on THEIR OWN user_saved_filter
                // rows (policy enforces ownership + admin override). This branch sits
                // OUTSIDE the view_% read-only gate so the CRUD perms actually land.
                ->orWhereIn('name', [
                    'view_user_saved_filter',
                    'view_any_user_saved_filter',
                    'create_user_saved_filter',
                    'update_user_saved_filter',
                    'delete_user_saved_filter',
                    'view_user::saved::filter',
                    'view_any_user::saved::filter',
                    'create_user::saved::filter',
                    'update_user::saved::filter',
                    'delete_user::saved::filter',
                ]);
            })
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
