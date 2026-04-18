---
phase: 01-foundation
plan: 02
type: execute
wave: 2
depends_on: [01-01-scaffold]
files_modified:
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Models/User.php
  - app/Policies/ActivityPolicy.php
  - database/migrations/2026_04_18_101000_create_permission_tables.php
  - database/seeders/RolePermissionSeeder.php
  - database/seeders/DatabaseSeeder.php
  - config/filament-shield.php
  - tests/Feature/RoleGatedNavigationTest.php
autonomous: true
requirements:
  - FOUND-01
user_setup: []

must_haves:
  truths:
    - "`php artisan shield:install admin` has run and registered the Shield plugin on the `admin` Filament panel"
    - "`php artisan shield:generate --all --panel=admin` produces permissions for every Filament Resource discovered (Phase 1: none yet — Plan 04 adds Suggestion + AlertRecipient)"
    - "4 roles exist in the DB after seeder: `admin`, `pricing_manager`, `sales`, `read_only`"
    - "Admin role has ALL permissions (sync'd via `Permission::all()`)"
    - "read_only role has ONLY `view_*` and `view_any_*` permissions (zero create/update/delete)"
    - "pricing_manager role has CRUD on Product+PricingRule (Phase 2/3 Resources) and view-only on CompetitorPrice+SyncRun"
    - "sales role has view-only on CrmPushLog"
    - "Running the seeder twice produces no duplicate rows or errors (idempotent per D-03)"
    - "User model has `HasRoles` trait from spatie/permission"
    - "Filament admin panel at `/admin/login` is reachable and renders without Shield errors"
    - "A user assigned `read_only` role cannot see the create/edit buttons on any future Resource (enforced automatically by Shield via policies)"
    - "A feature test asserts role→permission-count for each of the 4 roles"
  artifacts:
    - path: "app/Providers/Filament/AdminPanelProvider.php"
      provides: "Filament admin panel at /admin with Shield plugin registered"
      contains: "FilamentShieldPlugin::make()"
    - path: "app/Models/User.php"
      provides: "User auth model with HasRoles trait"
      contains: "use Spatie\\Permission\\Traits\\HasRoles;"
    - path: "database/seeders/RolePermissionSeeder.php"
      provides: "Idempotent 4-role seeder (D-03)"
      contains: "firstOrCreate"
    - path: "database/migrations/2026_04_18_101000_create_permission_tables.php"
      provides: "spatie/laravel-permission tables (roles, permissions, model_has_*, role_has_permissions)"
      contains: "Schema::create('permissions'"
    - path: "tests/Feature/RoleGatedNavigationTest.php"
      provides: "Pest feature test for Success Criterion 1"
      contains: "admin, pricing_manager, sales, read_only"
  key_links:
    - from: "app/Providers/Filament/AdminPanelProvider.php"
      to: "BezhanSalleh\\FilamentShield\\FilamentShieldPlugin"
      via: "->plugin() chain"
      pattern: "FilamentShieldPlugin::make\\(\\)"
    - from: "database/seeders/DatabaseSeeder.php"
      to: "RolePermissionSeeder"
      via: "call()"
      pattern: "\\$this->call\\(RolePermissionSeeder::class\\)"
    - from: "app/Models/User.php"
      to: "spatie/permission HasRoles trait"
      via: "use statement + trait use in class body"
      pattern: "HasRoles"
---

<objective>
Wire Filament Shield as the RBAC source of truth per user decisions D-01 through D-03: install Shield on the admin panel, add the `HasRoles` trait to User, run `shield:generate` to produce per-Resource permissions, and ship an idempotent seeder that creates the 4 roles (admin / pricing_manager / sales / read_only) with the exact permission split from D-02. Seeder MUST run cleanly on every deploy.

Purpose: This is FOUND-01. Success Criterion 1 ("admin logs in and sees role-gated nav") is blocked until Shield + seeder + Filament panel exist. Getting permission naming wrong here (Pitfall B) silently mis-gates the UI.

Output: A Filament admin panel gated by Shield, 4 seeded roles with correct permission sets, Pitfall B mitigation (permission names verified against `shield:generate` output), and a Pest feature test proving each role sees the right nav.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/phases/01-foundation/01-CONTEXT.md
@.planning/phases/01-foundation/01-RESEARCH.md
@.planning/phases/01-foundation/01-01-scaffold-PLAN.md
@.planning/research/STACK.md

<interfaces>
<!-- Shield-generated permission name format (Pitfall B). -->
<!-- shield:generate produces names shaped as {action}_{resource_slug} where resource_slug comes from the Filament Resource's model. -->
<!-- Example for a future PricingRule model/Resource: view_any_pricing::rule, view_pricing::rule, create_pricing::rule, etc. -->
<!-- Verify exact names with `php artisan permission:show` after running shield:generate. -->

<!-- spatie/permission HasRoles trait API used by this plan: -->
<!-- $user->assignRole('admin')            -> attaches role -->
<!-- $user->hasRole('pricing_manager')     -> bool -->
<!-- $role->syncPermissions(Permission::all()) -> idempotent sync -->
<!-- Permission::where('name', 'like', 'view_%')->get() -> query builder style -->

<!-- Filament Shield plugin signature (from vendor/bezhansalleh/filament-shield/src/FilamentShieldPlugin.php): -->
<!-- use BezhanSalleh\FilamentShield\FilamentShieldPlugin; -->
<!-- FilamentShieldPlugin::make() — chainable, adds panel resources -->
</interfaces>
</context>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Unauthenticated visitor → Filament `/admin` | Must redirect to login; no content leaks |
| Authenticated non-admin user → admin-only Resources | Shield policies enforce 403 |
| Seeder idempotency | Must survive re-run on every deploy without state drift |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-02-01 | E | read_only user gaining write access | mitigate | Seeder explicitly filters to `view_%` and `view_any_%` permissions ONLY via `Permission::where('name', 'like', 'view_%')`; integration test asserts `$readOnly->permissions()->pluck('name')` contains zero `create_/update_/delete_` entries |
| T-02-02 | T | Shield permission names drifting from seeder assumptions (Pitfall B) | mitigate | Task 2 runs `shield:generate` first then captures real names via `php artisan permission:show` before writing the seeder — names are copied, not guessed |
| T-02-03 | E | Admin-role assignment via mass-assignment on User create form | mitigate | Shield's generated Role Resource is admin-gated by default via `getShieldPermissionPrefixes`; additionally the seeder grants `update_user` / `view_any_user` permissions to `admin` role only |
| T-02-04 | S | Filament login page accepting weak passwords | accept | Out of Phase 1 scope; Filament uses Laravel's default password rules. Add bcrypt cost increase in Phase 7 hardening. |
| T-02-05 | I | Exposing Filament panel on non-HTTPS | mitigate | `APP_URL=https://ops.meetingstore.co.uk` + Laravel `APP_ENV=production` enforces `secure cookies`; VPS Nginx config terminates TLS (documented in Plan 05 deploy runbook) |
| T-02-06 | R | Seeder failures leaving DB in partial state | mitigate | Seeder wraps role creation + syncPermissions in try/catch; uses `firstOrCreate` (no duplicate inserts); `PermissionRegistrar::forgetCachedPermissions()` called before and after so Filament sees the new state |
</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Run Shield install, wire FilamentShieldPlugin on AdminPanelProvider, add HasRoles trait to User, migrate permission tables</name>
  <files>app/Providers/Filament/AdminPanelProvider.php, app/Models/User.php, config/filament-shield.php</files>
  <read_first>
    - app/Providers/Filament/AdminPanelProvider.php (auto-generated by `filament:install --panels` in Plan 01)
    - app/Models/User.php (Laravel default scaffold)
    - .planning/phases/01-foundation/01-RESEARCH.md §1 (Filament 3.3 admin panel + Shield wiring)
    - .planning/phases/01-foundation/01-CONTEXT.md D-01 (Shield mandatory), D-02 (role scope), D-03 (idempotent seeder)
    - .planning/research/PITFALLS.md — Pitfall B (Shield permission naming), Pitfall C (fillable on pivot tables), Pitfall D (Tailwind 4 trap already avoided)
    - .planning/research/STACK.md Filament 3.3 + Shield 3.3 compatibility matrix
  </read_first>
  <behavior>
    - Test: `tests/Feature/ShieldInstallationTest.php` — assert `\BezhanSalleh\FilamentShield\FilamentShieldPlugin::class` appears in the admin panel's plugin list after boot
    - Test: `User::class` uses `Spatie\Permission\Traits\HasRoles` trait (reflection check)
    - Test: `Schema::hasTable('roles')` and `Schema::hasTable('permissions')` and `Schema::hasTable('model_has_roles')` all return true after migration
  </behavior>
  <action>
    **Step A — Run spatie/permission migration** (tables published by Plan 01 via `vendor:publish`):

    ```bash
    php artisan migrate
    ```

    This creates `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` tables. Confirm with:

    ```bash
    php artisan db:table permissions
    php artisan db:table roles
    ```

    **Step B — Add `HasRoles` trait to `App\Models\User`** (Pitfall C mitigation — ensure default `$fillable` is preserved):

    Edit `app/Models/User.php` to add the trait. Full expected file:

    ```php
    <?php

    namespace App\Models;

    use Illuminate\Contracts\Auth\MustVerifyEmail;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Foundation\Auth\User as Authenticatable;
    use Illuminate\Notifications\Notifiable;
    use Laravel\Sanctum\HasApiTokens; // only if present from Laravel default
    use Spatie\Permission\Traits\HasRoles;
    use Filament\Models\Contracts\FilamentUser;
    use Filament\Panel;

    class User extends Authenticatable implements FilamentUser
    {
        /** @use HasFactory<\Database\Factories\UserFactory> */
        use HasFactory, Notifiable, HasRoles;

        protected $fillable = [
            'name',
            'email',
            'password',
        ];

        protected $hidden = [
            'password',
            'remember_token',
        ];

        protected function casts(): array
        {
            return [
                'email_verified_at' => 'datetime',
                'password' => 'hashed',
            ];
        }

        /** Filament panel access guard — Phase 1: any authenticated user can access /admin,
         *  Resource-level permissions are enforced by Shield-generated policies.
         */
        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }
    }
    ```

    Remove the `HasApiTokens` import and trait use if the default Laravel 12 User model does NOT include Sanctum (it does not by default in Laravel 12 per `laravel new`). Only include `HasApiTokens` if `vendor/laravel/sanctum` exists.

    **Step C — Run `shield:install admin`** (registers the plugin on the `admin` panel, publishes config):

    ```bash
    php artisan shield:install admin --generate=false --minimal=false
    # --generate=false because we want to control seeder ourselves in Task 2
    # --minimal=false so config/filament-shield.php is fully populated
    ```

    Expected output: creates `config/filament-shield.php`, modifies `app/Providers/Filament/AdminPanelProvider.php` to add `->plugin(FilamentShieldPlugin::make())`, publishes a `RoleResource`.

    **Step D — Verify `AdminPanelProvider` includes Shield + correct panel config**. The file should contain (edit to ensure these chains exist — Shield's installer adds the plugin line but you MUST ensure `->login()` and resource discovery paths):

    ```php
    <?php

    namespace App\Providers\Filament;

    use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
    use Filament\Http\Middleware\Authenticate;
    use Filament\Http\Middleware\AuthenticateSession;
    use Filament\Http\Middleware\DisableBladeIconComponents;
    use Filament\Http\Middleware\DispatchServingFilamentEvent;
    use Filament\Pages;
    use Filament\Panel;
    use Filament\PanelProvider;
    use Filament\Support\Colors\Color;
    use Filament\Widgets;
    use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
    use Illuminate\Cookie\Middleware\EncryptCookies;
    use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
    use Illuminate\Routing\Middleware\SubstituteBindings;
    use Illuminate\Session\Middleware\StartSession;
    use Illuminate\View\Middleware\ShareErrorsFromSession;

    class AdminPanelProvider extends PanelProvider
    {
        public function panel(Panel $panel): Panel
        {
            return $panel
                ->default()
                ->id('admin')
                ->path('admin')
                ->login()
                ->colors([
                    'primary' => Color::Blue,
                ])
                ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
                ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
                ->pages([
                    Pages\Dashboard::class,
                ])
                ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
                ->widgets([
                    Widgets\AccountWidget::class,
                    Widgets\FilamentInfoWidget::class,
                ])
                // Per-domain Resource discovery (modules populate in later plans):
                ->discoverResources(in: app_path('Domain/Suggestions/Filament/Resources'), for: 'App\\Domain\\Suggestions\\Filament\\Resources')
                ->discoverResources(in: app_path('Domain/Alerting/Filament/Resources'),   for: 'App\\Domain\\Alerting\\Filament\\Resources')
                ->middleware([
                    EncryptCookies::class,
                    AddQueuedCookiesToResponse::class,
                    StartSession::class,
                    AuthenticateSession::class,
                    ShareErrorsFromSession::class,
                    VerifyCsrfToken::class,
                    SubstituteBindings::class,
                    DisableBladeIconComponents::class,
                    DispatchServingFilamentEvent::class,
                ])
                ->authMiddleware([
                    Authenticate::class,
                ])
                ->plugin(FilamentShieldPlugin::make());
        }
    }
    ```

    **Step E — Verify Shield config** — `config/filament-shield.php` must exist. The default values are fine; no edits needed in Phase 1.

    **Step F — Write `tests/Feature/ShieldInstallationTest.php`:**

    ```php
    <?php

    use App\Models\User;
    use App\Providers\Filament\AdminPanelProvider;
    use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
    use Spatie\Permission\Traits\HasRoles;

    it('registers FilamentShieldPlugin on the admin panel', function () {
        $provider = new AdminPanelProvider(app());
        $panel = $provider->panel(\Filament\Panel::make());
        $plugins = $panel->getPlugins();
        expect(collect($plugins)->contains(fn ($p) => $p instanceof FilamentShieldPlugin))->toBeTrue();
    });

    it('User model uses HasRoles trait', function () {
        expect(in_array(HasRoles::class, class_uses_recursive(User::class)))->toBeTrue();
    });

    it('creates the permission tables', function () {
        expect(\Schema::hasTable('roles'))->toBeTrue()
            ->and(\Schema::hasTable('permissions'))->toBeTrue()
            ->and(\Schema::hasTable('model_has_roles'))->toBeTrue()
            ->and(\Schema::hasTable('role_has_permissions'))->toBeTrue();
    });
    ```

    Run: `vendor/bin/pest --filter=ShieldInstallation` — all three tests must pass.
  </action>
  <verify>
    <automated>test -f config/filament-shield.php &amp;&amp; grep -q "HasRoles" app/Models/User.php &amp;&amp; grep -q "FilamentShieldPlugin" app/Providers/Filament/AdminPanelProvider.php &amp;&amp; php artisan db:table roles &amp;&amp; php artisan db:table permissions &amp;&amp; vendor/bin/pest --filter=ShieldInstallation</automated>
  </verify>
  <done>
    Shield plugin registered on admin panel; User has HasRoles trait; permission tables migrated; ShieldInstallationTest passes; `config/filament-shield.php` exists.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Run shield:generate, capture real permission names, write idempotent RolePermissionSeeder (4 roles with D-02 permission split), wire DatabaseSeeder, write role→permission-count feature test</name>
  <files>database/seeders/RolePermissionSeeder.php, database/seeders/DatabaseSeeder.php, tests/Feature/RoleGatedNavigationTest.php</files>
  <read_first>
    - .planning/phases/01-foundation/01-CONTEXT.md D-01, D-02, D-03 (Shield mandatory, role split, seeder-on-every-deploy)
    - .planning/phases/01-foundation/01-RESEARCH.md §1 (idempotent seeder pattern — exact code block)
    - .planning/research/PITFALLS.md Pitfall B (Shield permission names — MUST run `shield:generate` first and copy real names)
    - .planning/research/PITFALLS.md Pitfall K (Suggestions Resource reachable without Shield gate — seeder MUST NOT grant suggestion perms to sales/pricing_manager)
    - database/seeders/DatabaseSeeder.php (current state after laravel/laravel install)
    - app/Providers/Filament/AdminPanelProvider.php (to confirm plugin registration so shield:generate has a panel target)
  </read_first>
  <behavior>
    - Test: Running RolePermissionSeeder creates exactly 4 roles: admin, pricing_manager, sales, read_only
    - Test: admin role has count(permissions) == total Permission::count()
    - Test: read_only role has ONLY permissions whose name matches `view_%` or `view_any_%` — zero create/update/delete
    - Test: Running the seeder twice produces identical row counts (firstOrCreate + syncPermissions = idempotent)
    - Test: After seeding, `PermissionRegistrar::forgetCachedPermissions()` has been called (cache is fresh)
  </behavior>
  <action>
    **Step A — Run `shield:generate` on Phase 1 Resources** (currently: `RoleResource` from shield install, that's it):

    ```bash
    php artisan shield:generate --all --panel=admin --ignore-existing-policies=false
    ```

    Expected: creates permissions for `Role` Resource only at this stage. Plan 04 re-runs `shield:generate` after adding Suggestion + AlertRecipient Resources; the seeder is written defensively to query permissions by name pattern so it works regardless of when resources are added.

    **Step B — Capture real permission names** (Pitfall B mitigation):

    ```bash
    php artisan permission:show
    # Record the exact output — permission names are typically:
    # view_role, view_any_role, create_role, update_role, delete_role, delete_any_role, restore_role, restore_any_role, force_delete_role, force_delete_any_role
    # (depending on config/filament-shield.php `permission_prefixes`)
    ```

    The default Shield config uses single-action prefixes: `view`, `view_any`, `create`, `update`, `delete`, `delete_any`, `force_delete`, `force_delete_any`, `replicate`, `reorder`. Resource suffix is `snake_case_singular` of the model (e.g., `role`, `suggestion`, `alert_recipient`).

    **Step C — Write `database/seeders/RolePermissionSeeder.php`** — based on 01-RESEARCH.md §1 with the Pitfall B-safe name pattern matching:

    ```php
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
     * Safe to re-run on every deploy: uses firstOrCreate + syncPermissions.
     * Queries permissions by name PATTERN so new Resources added in later plans
     * (Suggestion in Plan 04, AlertRecipient in Plan 05) are auto-included.
     */
    class RolePermissionSeeder extends Seeder
    {
        public function run(): void
        {
            // 1. Clear the Spatie permission cache so newly-created rows are visible
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            // 2. Create (or fetch) the 4 roles (idempotent)
            $admin          = Role::firstOrCreate(['name' => 'admin',           'guard_name' => 'web']);
            $pricingManager = Role::firstOrCreate(['name' => 'pricing_manager', 'guard_name' => 'web']);
            $sales          = Role::firstOrCreate(['name' => 'sales',           'guard_name' => 'web']);
            $readOnly       = Role::firstOrCreate(['name' => 'read_only',       'guard_name' => 'web']);

            // 3. admin → ALL permissions (idempotent sync)
            $admin->syncPermissions(Permission::all());

            // 4. read_only → only view_* and view_any_* permissions across every Resource
            $readOnly->syncPermissions(
                Permission::query()
                    ->where(fn ($q) => $q
                        ->where('name', 'like', 'view_%')
                        ->orWhere('name', 'like', 'view_any_%')
                    )
                    ->get()
            );

            // 5. pricing_manager → CRUD on product + pricing_rule; view-only on competitor_price + sync_run
            //    Permissions arrive in later plans (Product in Phase 2, PricingRule in Phase 3, etc.)
            //    — seeder is defensive: queries by name pattern, no-ops if the Resource doesn't exist yet.
            $pricingManagerPermissionNames = Permission::query()
                ->where(function ($q) {
                    // CRUD on product + pricing_rule (all actions)
                    $q->where('name', 'like', '%_product')
                      ->orWhere('name', 'like', '%_pricing_rule')
                      ->orWhere('name', 'like', '%_any_product')
                      ->orWhere('name', 'like', '%_any_pricing_rule');
                })
                ->orWhere(function ($q) {
                    // View-only on competitor_price + sync_run
                    $q->where('name', 'like', 'view_competitor_price')
                      ->orWhere('name', 'like', 'view_any_competitor_price')
                      ->orWhere('name', 'like', 'view_sync_run')
                      ->orWhere('name', 'like', 'view_any_sync_run');
                })
                ->pluck('name')
                ->all();

            $pricingManager->syncPermissions($pricingManagerPermissionNames);

            // 6. sales → view-only on crm_push_log
            $salesPermissionNames = Permission::query()
                ->where('name', 'like', 'view_crm_push_log')
                ->orWhere('name', 'like', 'view_any_crm_push_log')
                ->pluck('name')
                ->all();

            $sales->syncPermissions($salesPermissionNames);

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
    ```

    **Note on permission-name suffixes:** if `shield:generate` produces names with `::` separator instead of `_` (e.g., `view_any_pricing::rule`), update the `%_pricing_rule` patterns to `%_pricing::rule`. Verify by running `php artisan permission:show` after a future Resource is added. For Phase 1, only `role` resource exists — confirm format now by running `php artisan permission:show` and adjust the LIKE patterns in the seeder to match. **If patterns don't match, this seeder silently grants zero permissions** — the feature test in Step E catches this regression.

    **Step D — Wire `DatabaseSeeder` to call `RolePermissionSeeder`:**

    Edit `database/seeders/DatabaseSeeder.php`:

    ```php
    <?php

    declare(strict_types=1);

    namespace Database\Seeders;

    use Illuminate\Database\Seeder;

    class DatabaseSeeder extends Seeder
    {
        public function run(): void
        {
            $this->call([
                RolePermissionSeeder::class,
                // TestSuggestionSeeder added in Plan 04
            ]);
        }
    }
    ```

    **Step E — Write `tests/Feature/RoleGatedNavigationTest.php`** (Pest feature test proving D-02 scope):

    ```php
    <?php

    use Database\Seeders\RolePermissionSeeder;
    use Spatie\Permission\Models\Permission;
    use Spatie\Permission\Models\Role;

    beforeEach(function () {
        $this->seed(RolePermissionSeeder::class);
    });

    it('creates exactly 4 roles', function () {
        expect(Role::count())->toBe(4);
        expect(Role::pluck('name')->sort()->values()->all())
            ->toBe(['admin', 'pricing_manager', 'read_only', 'sales']);
    });

    it('admin role has every permission', function () {
        $admin = Role::where('name', 'admin')->first();
        expect($admin->permissions()->count())->toBe(Permission::count());
    });

    it('read_only role has only view_ and view_any_ permissions', function () {
        $readOnly = Role::where('name', 'read_only')->first();
        $names = $readOnly->permissions()->pluck('name');

        // Every permission on read_only starts with view_ or view_any_
        foreach ($names as $name) {
            expect(str_starts_with($name, 'view_') || str_starts_with($name, 'view_any_'))
                ->toBeTrue("Permission '{$name}' on read_only role is not a view permission");
        }

        // No create/update/delete/restore/force_delete on read_only
        $forbidden = $readOnly->permissions()
            ->where(function ($q) {
                $q->where('name', 'like', 'create_%')
                  ->orWhere('name', 'like', 'update_%')
                  ->orWhere('name', 'like', 'delete_%')
                  ->orWhere('name', 'like', 'restore_%')
                  ->orWhere('name', 'like', 'force_delete_%');
            })->count();
        expect($forbidden)->toBe(0);
    });

    it('pricing_manager role does NOT have view permission on crm_push_log (D-02 scope leak guard)', function () {
        $pricingManager = Role::where('name', 'pricing_manager')->first();
        expect($pricingManager->hasPermissionTo('view_crm_push_log'))->toBeFalse()
            ->and($pricingManager->hasPermissionTo('view_any_crm_push_log'))->toBeFalse();
    })->skip(fn () => ! Permission::where('name', 'view_crm_push_log')->exists(), 'CrmPushLog Resource not created yet — skip until Phase 4');

    it('sales role does NOT have view permission on pricing_rule (D-02 scope leak guard)', function () {
        $sales = Role::where('name', 'sales')->first();
        expect($sales->hasPermissionTo('view_any_pricing_rule'))->toBeFalse();
    })->skip(fn () => ! Permission::where('name', 'view_any_pricing_rule')->exists(), 'PricingRule Resource not created yet — skip until Phase 3');

    it('is idempotent — running twice produces no duplicate rows', function () {
        $initialRoleCount = Role::count();
        $initialPermissionCount = Permission::count();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        expect(Role::count())->toBe($initialRoleCount);
        expect(Permission::count())->toBe($initialPermissionCount);
    });
    ```

    Run: `vendor/bin/pest --filter=RoleGatedNavigation` — all tests pass (pending ones auto-skip until later-plan Resources exist).
  </action>
  <verify>
    <automated>test -f database/seeders/RolePermissionSeeder.php &amp;&amp; grep -q "RolePermissionSeeder::class" database/seeders/DatabaseSeeder.php &amp;&amp; php artisan db:seed --class=RolePermissionSeeder --force &amp;&amp; php artisan db:seed --class=RolePermissionSeeder --force &amp;&amp; vendor/bin/pest --filter=RoleGatedNavigation</automated>
  </verify>
  <done>
    Seeder creates exactly 4 roles, admin has all permissions, read_only has only view_*, pricing_manager/sales scoped per D-02; seeder is idempotent (two consecutive runs produce no errors or dupes); RoleGatedNavigationTest passes all non-skipped cases.
  </done>
</task>

</tasks>

<threat_model>
(See plan-level threat model above — no task-specific additions.)
</threat_model>

<verification>
- `php artisan migrate:fresh --seed --force` exits 0 and seeds 4 roles
- `php artisan db:seed --class=RolePermissionSeeder --force` run twice consecutively produces no errors, no duplicate role rows
- `vendor/bin/pest --filter=ShieldInstallation` passes (3 tests)
- `vendor/bin/pest --filter=RoleGatedNavigation` passes (idempotency + admin all-permissions + read_only view-only + forbidden-mutations-on-readonly)
- `vendor/bin/pest` (full suite) passes — no regression
- `vendor/bin/deptrac analyse --no-progress` exits 0 — Plan 02 doesn't cross module boundaries (only touches app/Models, app/Providers, app/Policies, database/, tests/)
- `php artisan permission:show` lists permissions for `role` Resource (Phase 1 only; Plans 04/05 extend)
- `grep -q "FilamentShieldPlugin" app/Providers/Filament/AdminPanelProvider.php` exits 0
- `grep -q "HasRoles" app/Models/User.php` exits 0
</verification>

<success_criteria>
- **FOUND-01 (Filament + RBAC):** admin panel boots at `/admin/login`, Shield plugin registered, 4 roles seeded with exact D-02 permission split, seeder idempotent on re-run
- **Success Criterion 1 from ROADMAP** partially satisfied: role-gated navigation logic in place (full Resource-level proof arrives as Plans 04/05 add Suggestion + AlertRecipient Resources)
- **D-03:** deploy-time seeder runs without manual intervention (`php artisan db:seed --class=RolePermissionSeeder --force` in deploy script)
- **Pitfall B:** permission names verified via `php artisan permission:show` before seeder finalised; seeder uses LIKE patterns that match actual Shield output
- **Pitfall K:** sales/pricing_manager roles explicitly NOT granted `view_%_suggestion` (Plan 04 feature test enforces — skipped here until Resource exists)
</success_criteria>

<output>
After completion, create `.planning/phases/01-foundation/01-02-SUMMARY.md` documenting: the exact permission-name format produced by `shield:generate` (resource suffix `_role` vs `::role`), any LIKE-pattern adjustments made to the seeder to match, and the post-seed role→permission counts for the `role` Resource (Phase 1 only).
</output>
