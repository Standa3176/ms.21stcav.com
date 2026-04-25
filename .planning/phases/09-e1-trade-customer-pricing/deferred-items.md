# Phase 09 — Deferred Items

Out-of-scope discoveries during Phase 9 execution. Logged here for follow-up plans / phases (per GSD scope-boundary rule — only auto-fix issues directly caused by current task changes).

## Plan 09-05

### shield:safe-regenerate `--force` flag mismatch (pre-existing Phase 8 wrapper bug)

**Discovered during:** Plan 09-05 Task 2 verification (`php artisan shield:safe-regenerate --allow-new=CustomerGroupPolicy`)

**Issue:** The Phase 8 `ShieldSafeRegenerateCommand` (commit shipped in Phase 8 Plan 05) calls `shield:generate --all --force`, but Filament Shield 3.x in this codebase does NOT accept `--force` on `shield:generate`. Available flags: `--all`, `--option`, `--resource`, `--page`, `--widget`, `--exclude`, `--ignore-config-exclude`, `--minimal`, `--ignore-existing-policies`, `--panel`, `--relationships`.

**Symptom:** `shield:safe-regenerate` exits early at Step 2 with `"The "--force" option does not exist."` from Symfony Console — the wrapper's restore step (P5-F) and PolicyTemplateIntegrityTest gate never run.

**Workaround applied for Plan 09-05:**
- The 5 `*_customer_group` permissions are already created defensively by `RolePermissionSeeder` (Permission::firstOrCreate, mirroring the AgentRunResource pattern from Phase 8 Plan 04).
- `CustomerGroupPolicy` is hand-written and registered in `AppServiceProvider::boot()` (Gate::policy binding).
- Net effect: the runtime auth chain is correct; CI tests verify the role/permission matrix; `PolicyTemplateIntegrityTest` (Phase 2 Plan 05) protects PricingRulePolicy from any future shield:generate run.

**Fix scope:** Edit `app/Domain/Agents/Console/Commands/ShieldSafeRegenerateCommand.php` line 56 — replace `'--force' => true` with `'--ignore-existing-policies' => true` (or remove `--force` entirely; `shield:generate --all` is non-interactive when a non-TTY is detected, which is already the case in CI / artisan invocation).

**Why deferred:**
- Pre-existing Phase 8 issue, not caused by Plan 09-05.
- Out-of-scope per GSD scope-boundary rule (only auto-fix DIRECTLY caused by current task changes).
- The runtime correctness intent (permissions seeded + policies preserved) is already satisfied by Plan 09-05's manual seeder + AppServiceProvider Gate binding.
- Belongs in a Phase 8 Plan 05 follow-up or a dedicated `/gsd-quick` patch — not Phase 09 territory.
