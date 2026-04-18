---
phase: 01-foundation
plan: 02-rbac
subsystem: auth
tags: [filament-shield, spatie-permission, rbac, filament-3, roles, policies]

requires:
  - phase: 01-01-scaffold
    provides: "filament/filament ^3.3, bezhansalleh/filament-shield ^3.3, spatie/laravel-permission ^6.0 installed + migrated; AdminPanelProvider auto-generated; permission tables created"

provides:
  - "Filament Shield registered on the `admin` panel via FilamentShieldPlugin + config/filament-shield.php"
  - "User model implementing FilamentUser with HasRoles trait and canAccessPanel()"
  - "RolePolicy with all 12 Shield permission prefixes wired (fixed 4 unrendered {{ Placeholder }} strings in Shield's stub)"
  - "Exactly 4 seeded roles: admin / pricing_manager / sales / read_only (Shield's super_admin + panel_user auto-roles disabled)"
  - "Idempotent RolePermissionSeeder with LIKE-pattern permission queries (forward-compatible with Resources added by Plans 04, 05, and Phase 2/3/4)"
  - "Seeded admin user admin@meetingstore.co.uk (password: password) with admin role — login-ready"
  - "DatabaseSeeder wiring RolePermissionSeeder → admin user creation (deploy-time idempotent)"
  - "Per-domain Filament Resource discovery paths on AdminPanelProvider (Suggestions + Alerting — populated in Plans 04, 05)"
  - "9 Pest tests proving FOUND-01 (3 ShieldInstallation + 4 running + 2 skipped RoleGatedNavigation guards)"

affects:
  - "01-03 (consumes Shield + permission seeder pattern for AlertRecipient Policy)"
  - "01-04 (consumes RolePermissionSeeder — adds Suggestion permissions via shield:generate re-run)"
  - "01-05 (consumes RolePermissionSeeder — adds AlertRecipient permissions via shield:generate re-run)"
  - "02-* (Product Resource permissions auto-matched by pricing_manager `%_product` LIKE pattern)"
  - "03-* (PricingRule Resource permissions auto-matched by `%_pricing_rule` pattern)"
  - "04-* (CrmPushLog permissions auto-matched by sales role whereIn list)"

tech-stack:
  added:
    - "config/filament-shield.php — published (super_admin + panel_user disabled; permission_prefixes defaults kept)"
    - "Pattern: `{action}_{resource_snake_singular}` permission naming (underscore separator, NOT `::`) — verified against Shield 3.9.10 output"
  patterns:
    - "RolePermissionSeeder uses LIKE-pattern permission queries so new Resources added in later plans auto-include in pricing_manager/read_only roles after re-running shield:generate"
    - "Deploy order: `php artisan migrate --force && php artisan shield:generate --all --panel=admin && php artisan db:seed --force` (D-03)"
    - "RolePolicy template placeholders must be manually replaced — Shield 3.9.10 stub generator leaks `{{ ForceDelete }}`/`{{ Restore }}`/`{{ Replicate }}`/`{{ Reorder }}` literal strings"

key-files:
  created:
    - "database/seeders/RolePermissionSeeder.php — 4-role seeder with D-02 permission split"
    - "tests/Feature/ShieldInstallationTest.php — 3 tests (plugin registration, HasRoles trait, permission tables)"
    - "tests/Feature/RoleGatedNavigationTest.php — 6 tests (4 running, 2 skipped until Phase 3/4 Resources land)"
    - "app/Policies/RolePolicy.php — Shield-generated, manually repaired (see deviations)"
    - "config/filament-shield.php — published from vendor, super_admin/panel_user disabled"
  modified:
    - "app/Providers/Filament/AdminPanelProvider.php — FilamentShieldPlugin registered via ->plugins([]); added per-domain Resource discovery paths (Suggestions, Alerting)"
    - "app/Models/User.php — implements FilamentUser, uses HasRoles, canAccessPanel() returns true for Phase 1"
    - "database/seeders/DatabaseSeeder.php — calls RolePermissionSeeder + creates admin@meetingstore.co.uk user"
    - "tests/Pest.php — fixed double-registration of TestCase on Feature suite"

key-decisions:
  - "Shield's auto-created `super_admin` and `panel_user` roles DISABLED in config/filament-shield.php — D-02 locks the role set to exactly 4; allowing Shield to add extras would break the idempotency contract and the 'creates exactly 4 roles' test"
  - "Permission name format is `{action}_{resource_snake_singular}` with underscore separator (NOT `::`) — verified against `php artisan permission:show` output after shield:generate — plan's example `::`-separated names were speculative; seeder patterns adjusted accordingly"
  - "Admin test user seeded via DatabaseSeeder (not user_setup) — password `password` explicitly flagged in DatabaseSeeder.php docblock for production rotation"
  - "Plan 02 does NOT re-run `php artisan migrate` — asserts 4 permission tables exist via `php artisan db:table` checks (Plan 01 already ran migrate in its own commit)"

patterns-established:
  - "Pattern (01-02-a): Shield seeder idempotency — firstOrCreate for roles + syncPermissions with LIKE-pattern queries means new Resources auto-register after `shield:generate`, no seeder edits needed"
  - "Pattern (01-02-b): Shield RolePolicy stub repair — after `shield:generate` any policy generated with Shield 3.9.10 must be manually audited for `{{ Placeholder }}` literal strings in `can()` calls"
  - "Pattern (01-02-c): Role-scope-leak test pairs — every role gets a negative test (`hasPermissionTo('view_%_other_domain') = false`) skipped-until the other domain's Resource exists; keeps scope-leak regression coverage without blocking CI"

requirements-completed:
  - FOUND-01

duration: ~35 min
completed: 2026-04-18
---

# Phase 01 Plan 02: RBAC Summary

**Filament Shield wired on admin panel with 4 deploy-idempotent roles (admin / pricing_manager / sales / read_only); D-02 permission split shipped via LIKE-pattern seeder (forward-compatible with later-phase Resources); test admin user seeded for immediate /admin/login access.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-04-18T16:36Z
- **Completed:** 2026-04-18T17:11Z
- **Tasks:** 2
- **Files created:** 5 (RolePermissionSeeder, 2 test files, RolePolicy, filament-shield.php)
- **Files modified:** 4 (AdminPanelProvider, User, DatabaseSeeder, tests/Pest.php)

## Accomplishments

- Ran `php artisan shield:install admin` successfully; Shield plugin registered on panel via `->plugins([FilamentShieldPlugin::make()])`
- Added `HasRoles` trait + `FilamentUser` contract + `canAccessPanel()` to `User` model preserving default `$fillable` (Pitfall C mitigation — see 01-CONTEXT.md)
- Ran `php artisan shield:generate --all --panel=admin` — produced exactly 6 permissions for the `RoleResource` (view_role, view_any_role, create_role, update_role, delete_role, delete_any_role)
- Captured **real permission name format** via `php artisan permission:show`: `{action}_{resource_snake_singular}` with underscore (NOT `::`) — adjusted the seeder's LIKE patterns to match
- Wrote idempotent `RolePermissionSeeder` with LIKE-pattern queries so future Resources (Suggestion, AlertRecipient, Product, PricingRule, CompetitorPrice, SyncRun, CrmPushLog) auto-attach on next `shield:generate` run without seeder edits
- Wired `DatabaseSeeder` to create admin@meetingstore.co.uk user with admin role — Success Criterion #1 ("admin logs in and sees role-gated nav") now achievable
- Disabled Shield's auto-created `super_admin` + `panel_user` roles (config change) so role count stays at exactly 4 per D-02
- 9 Pest tests pass (2 skipped-until-later-phase guards); full suite + Deptrac remain green

## Task Commits

1. **Task 1: Shield install + HasRoles on User + RolePolicy repair** — `35f3c44` (feat)
2. **Task 2: Idempotent 4-role seeder + test admin user + feature tests** — `cb803c9` (feat)

## Files Created/Modified

### Created

- `app/Policies/RolePolicy.php` — Generated by `shield:install`; 4 `{{ Placeholder }}` can() strings manually fixed to real permission names (force_delete_role, force_delete_any_role, restore_role, restore_any_role, replicate_role, reorder_role)
- `config/filament-shield.php` — Published via `vendor:publish --tag=filament-shield-config`; super_admin + panel_user disabled
- `database/seeders/RolePermissionSeeder.php` — 4-role idempotent seeder with LIKE-pattern permission queries
- `tests/Feature/ShieldInstallationTest.php` — 3 passing tests (plugin registered, HasRoles on User, permission tables present)
- `tests/Feature/RoleGatedNavigationTest.php` — 6 Pest tests (4 running + 2 skipped-until-Phase-3/4 scope-leak guards)

### Modified

- `app/Models/User.php` — implements FilamentUser, uses HasRoles, canAccessPanel() returns true (Phase 1; Resource-level gates enforced by Shield policies)
- `app/Providers/Filament/AdminPanelProvider.php` — FilamentShieldPlugin registered via `->plugins([])`; added `->discoverResources()` calls for `app/Domain/Suggestions/Filament/Resources` and `app/Domain/Alerting/Filament/Resources` per 01-RESEARCH.md §1
- `database/seeders/DatabaseSeeder.php` — calls RolePermissionSeeder; creates admin@meetingstore.co.uk user (password `password`) and assigns admin role
- `tests/Pest.php` — removed conflicting `uses(TestCase::class)->in('Feature', 'Unit')` that collided with the subsequent `uses(TestCase::class, RefreshDatabase::class)->in('Feature')` declaration

## Decisions Made

See frontmatter `key-decisions` for the full list. Headline items:

1. **Shield's `super_admin` + `panel_user` disabled.** Shield's default config auto-creates two extra roles on `shield:generate`. D-02 locks role set to exactly {admin, pricing_manager, sales, read_only}. Left enabled, the plan's "creates exactly 4 roles" assertion would fail on every fresh deploy. Disabled in `config/filament-shield.php` and leftover rows deleted from the dev DB.

2. **Permission name format: underscore, NOT `::`.** Plan text speculated names might be `view_any_pricing::rule`. `php artisan permission:show` after `shield:generate` produced `view_any_role`, `view_role`, `create_role`, etc. — underscore-separated. Seeder LIKE patterns adjusted: `%_pricing_rule`, `%_product`, `view_crm_push_log`, etc.

3. **Admin user seeded in DatabaseSeeder (not deferred to operator).** Success Criterion #1 requires an admin to log in immediately after `db:seed`. The executor's permission_to_deviate block explicitly authorised this; password `password` is flagged in the DatabaseSeeder docblock for production rotation.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Shield's RolePolicy stub contained unrendered template placeholders**

- **Found during:** Task 1, after `shield:install admin` generated `app/Policies/RolePolicy.php`
- **Issue:** Shield 3.9.10's RolePolicy stub contains 4 `$user->can('{{ ForceDelete }}')`, `$user->can('{{ Restore }}')`, `$user->can('{{ Replicate }}')`, `$user->can('{{ Reorder }}')` literal strings (plus their `*Any` variants — 6 total). These are unrendered template placeholders that Shield 3.9.10's generator fails to substitute, meaning those 6 policy methods would always return `false` and silently block force_delete/restore/replicate/reorder actions forever.
- **Fix:** Replaced with real permission names: `force_delete_role`, `force_delete_any_role`, `restore_role`, `restore_any_role`, `replicate_role`, `reorder_role`.
- **Files modified:** `app/Policies/RolePolicy.php`
- **Verification:** File grep shows zero `{{ ` literals; ShieldInstallationTest + RoleGatedNavigationTest both pass.
- **Committed in:** `35f3c44` (Task 1)

**2. [Rule 3 — Blocking] `shield:install admin --generate=false --minimal=false` failed — flags don't exist**

- **Found during:** Task 1
- **Issue:** Plan prescribed `php artisan shield:install admin --generate=false --minimal=false`. Shield 3.9.10's `shield:install` command only accepts `--tenant` + standard Symfony options (`--silent`, `--no-interaction`, etc.) — the `--generate` and `--minimal` options do not exist. Command exited with code 1 and message `The "--generate" option does not exist.`
- **Fix:** Ran `php artisan shield:install admin --no-interaction` instead. Shield registered the plugin successfully. Config was NOT auto-published by install (confirmed by `ls config/filament-shield.php` returning "No such file or directory"), so ran `php artisan vendor:publish --provider="BezhanSalleh\\FilamentShield\\FilamentShieldServiceProvider" --tag="filament-shield-config"` to publish it.
- **Files modified:** None (invocation-level fix)
- **Verification:** `test -f config/filament-shield.php` passes after publish.
- **Committed in:** `35f3c44` (Task 1)

**3. [Rule 3 — Blocking] `tests/Pest.php` double-registered TestCase on Feature suite**

- **Found during:** Task 1 after writing ShieldInstallationTest
- **Issue:** Plan 01's `tests/Pest.php` contained BOTH `uses(TestCase::class)->in('Feature', 'Unit');` AND `uses(TestCase::class, RefreshDatabase::class)->in('Feature');`. Pest rejected this with `Test case Tests\TestCase can not be used. The folder ... already uses the test case Tests\TestCase`.
- **Fix:** Changed first line to `uses(TestCase::class)->in('Unit');` so only the Unit suite gets the plain TestCase and Feature gets TestCase + RefreshDatabase.
- **Files modified:** `tests/Pest.php`
- **Verification:** Full `vendor/pestphp/pest/bin/pest` suite runs green.
- **Committed in:** `35f3c44` (Task 1 commit, as it blocked Task 1's verification)

**4. [Rule 2 — Missing Critical] Shield auto-created `super_admin` + `panel_user` roles violate D-02 role set**

- **Found during:** Task 2, after running `shield:generate --all --panel=admin` — `php artisan permission:show` revealed 5 roles (expected 4).
- **Issue:** Shield's default `config/filament-shield.php` has `super_admin.enabled = true` and `panel_user.enabled = true`, which cause `shield:generate` to auto-create those two roles. D-02 locks the role set to exactly {admin, pricing_manager, sales, read_only}. Leaving Shield's auto-roles in place would (a) fail the plan's "exactly 4 roles" assertion, (b) create a parallel `super_admin` permission bypass (via Shield's `intercept_gate` mechanism) that drifts from the seeder's canonical split, and (c) pollute the Filament nav with an uncontrolled role.
- **Fix:** In `config/filament-shield.php`, set `super_admin.enabled = false` and `panel_user.enabled = false` with inline comments citing D-02. Deleted leftover `super_admin` + `panel_user` rows from the dev DB via tinker.
- **Files modified:** `config/filament-shield.php`
- **Verification:** Final `php artisan permission:show` shows exactly 4 roles (admin/pricing_manager/read_only/sales); RoleGatedNavigationTest "creates exactly 4 roles" passes.
- **Committed in:** `cb803c9` (Task 2)

**5. [Rule 2 — Missing Critical] No admin user seeded — Success Criterion #1 unachievable on fresh deploy**

- **Found during:** Task 2 seeder wiring
- **Issue:** Plan defined the seeder but not an admin user. FOUND-01 Success Criterion requires an admin to log in at `/admin/login` after a fresh deploy — without a seeded user there's no credential to use.
- **Fix:** Extended `DatabaseSeeder` to `firstOrCreate` a user `admin@meetingstore.co.uk` (password `password`) and assign the `admin` role. Password change required in production — documented in the DatabaseSeeder docblock.
- **Files modified:** `database/seeders/DatabaseSeeder.php`
- **Verification:** `php artisan tinker` confirms user exists, has admin role, can view_role and create_role.
- **Committed in:** `cb803c9` (Task 2)
- **Note:** Executor's `<permission_to_deviate>` block explicitly authorized this. Not creeping scope.

---

**Total deviations:** 5 auto-fixed (1 bug, 2 blocking, 2 missing-critical)
**Impact on plan:** All 5 are correctness/completeness-driven. Plan-contract invariants (FOUND-01 shipped, 4 roles only, D-02 split, idempotent seeder, immediate login) all preserved. Plan-level `must_haves.truths` checklist satisfied in full.

## Issues Encountered

1. **Pest RefreshDatabase truncates real DB across runs.** Because tests/Pest.php declares `uses(..., RefreshDatabase::class)->in('Feature')` and Laravel's default connection resolves to the dev MySQL DB (no separate `testing` connection), running the full Pest suite wipes permissions/roles/users. Recovery: re-run `php artisan shield:generate --all --panel=admin && php artisan db:seed --force`. **Not fixed in this plan** — Plan 03 or a later hardening pass should add a dedicated `testing` database connection to `phpunit.xml` (env `DB_DATABASE=meetingstore_ops_testing`). Tracked for deferred-items.

2. **Windows dev: `php` not on PATH.** The PHP binary lives at `C:\Users\sonny.tanda\.config\herd\bin\php.bat`. `vendor/bin/pest` shebang fails because it needs `php`. Workaround: invoke via `php.bat vendor/pestphp/pest/bin/pest`. Ops deploy runbook on Linux VPS is unaffected.

## User Setup Required

- **Change admin password in production** before go-live: `php artisan tinker` → `User::where('email','admin@meetingstore.co.uk')->first()->update(['password' => bcrypt('strong-password-here')])`
- Deploy runbook step (add to Plan 05's deploy docs): ensure order is `php artisan migrate --force && php artisan shield:generate --all --panel=admin --no-interaction && php artisan db:seed --force`

## Next Phase Readiness

### Plan 03 (Foundation) can assume
- 4 roles seeded with D-02 permission split
- Admin user exists; `$user->hasRole('admin')` works
- Activity log + permission tables fully migrated
- FilamentShieldPlugin registered; per-Resource permissions auto-generate via `shield:generate`

### Plan 04 (Seams / Suggestions Resource) can assume
- `app/Domain/Suggestions/Filament/Resources/` directory is on the panel's Resource discovery path
- Running `shield:generate` after adding SuggestionResource will create `view_any_suggestion`, `view_suggestion`, etc. permissions, which RolePermissionSeeder's LIKE patterns will NOT grant to any non-admin role (admin-only by default — Pitfall K mitigation)

### Plan 05 (Horizon + Alerting) can assume
- `app/Domain/Alerting/Filament/Resources/` is on the discovery path
- Same Shield auto-attach pattern applies for AlertRecipient — admin-only until seeder is extended if needed

### Known concerns for later phases
1. **Shield RolePolicy stub defect.** If any later plan runs `shield:generate` for a soft-deletable Resource, audit the generated Policy for `{{ ForceDelete }}` / `{{ Restore }}` / `{{ Replicate }}` / `{{ Reorder }}` literal strings — Shield 3.9.10's stub template has a known leak. Add to deferred-items / PITFALLS.md as "Pitfall B+" (Shield policy stub leaks template placeholders — grep `\\{\\{ ` in Policies/ after every `shield:generate`).
2. **Pest suite truncates production-shaped DB.** Plan 03 should add a dedicated `DB_DATABASE_TESTING=meetingstore_ops_testing` entry in `phpunit.xml` so feature tests use an isolated MySQL database.
3. **pricing_manager + sales roles currently seed with 0 permissions.** Correct for Phase 1 — Resources (Product, PricingRule, CompetitorPrice, SyncRun, CrmPushLog) don't exist yet. After those land in Phases 2–4, running `shield:generate` then `db:seed --class=RolePermissionSeeder` will auto-populate per D-02.

## Self-Check: PASSED

- Created files verified:
  - `app/Policies/RolePolicy.php` FOUND
  - `config/filament-shield.php` FOUND
  - `database/seeders/RolePermissionSeeder.php` FOUND
  - `tests/Feature/ShieldInstallationTest.php` FOUND
  - `tests/Feature/RoleGatedNavigationTest.php` FOUND
- Commits verified via `git log --oneline`:
  - `35f3c44` FOUND (Task 1 commit)
  - `cb803c9` FOUND (Task 2 commit)
- `php artisan db:table roles && permissions && model_has_roles && role_has_permissions` all exit 0
- `php artisan db:seed --class=RolePermissionSeeder --force` run twice produces identical output (`admin=6 perms, pricing_manager=0, sales=0, read_only=2` both times)
- `vendor/bin/pest --filter=ShieldInstallation` — 3 tests pass
- `vendor/bin/pest --filter=RoleGatedNavigation` — 4 running tests pass, 2 skipped as designed
- `vendor/bin/pest` full suite — 9 passed, 2 skipped, 0 failed
- `vendor/bin/deptrac analyse --no-progress` — 0 violations
- Admin user confirmed: `admin@meetingstore.co.uk` / `password`, has admin role, `can('view_role')=true`, `can('create_role')=true`

---
*Phase: 01-foundation*
*Plan: 02-rbac*
*Completed: 2026-04-18*
