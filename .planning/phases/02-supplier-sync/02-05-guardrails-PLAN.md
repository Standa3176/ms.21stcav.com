---
phase: 02-supplier-sync
plan: 05
type: execute
wave: 5
depends_on:
  - 02-01
  - 02-02
  - 02-03
  - 02-04
files_modified:
  - depfile.yaml
  - deptrac.yaml
  - app/Console/Commands/PruneSyncErrorsCommand.php
  - routes/console.php
  - tests/Architecture/DeptracSyncLayerTest.php
  - tests/Architecture/PolicyTemplateIntegrityTest.php
  - tests/Feature/PruneSyncErrorsTest.php
  - .planning/phases/02-supplier-sync/02-VERIFICATION.md
autonomous: true
requirements:
  - SYNC-04

must_haves:
  truths:
    - "Deptrac has a new `WpDirectDb` layer; the Sync domain has a `-WpDirectDb` deny rule (SYNC-04)"
    - "A deliberate violator importing Illuminate\\Support\\Facades\\DB from app/Domain/Sync/* causes `vendor/bin/deptrac analyse` to exit non-zero"
    - "`grep -rn '{{ ' app/Policies/ app/Domain/*/Policies/` returns empty — regression-protected by PolicyTemplateIntegrityTest"
    - "`php artisan sync-errors:prune --days=90` deletes sync_errors rows older than 90 days AND writes an Auditor meta-audit row"
    - "routes/console.php schedules sync-errors:prune at 03:20 daily with withoutOverlapping(30) + onOneServer() — replaces the Phase 1 TODO marker"
    - "Phase 2 is complete — VERIFICATION.md documents which success criteria map to which plans + how to verify end-to-end"
  artifacts:
    - path: "depfile.yaml"
      provides: "New `WpDirectDb` Deptrac layer collecting Illuminate\\Support\\Facades\\DB + mysql_woo connection references"
    - path: "deptrac.yaml"
      provides: "Mirror of depfile.yaml so `vendor/bin/deptrac analyse` without args also picks up the new rule"
    - path: "tests/Architecture/DeptracSyncLayerTest.php"
      provides: "Positive (current zero-violation) + negative (deliberate violator) tests — exit-code assertions"
    - path: "tests/Architecture/PolicyTemplateIntegrityTest.php"
      provides: "Permanent guardrail — fails build if any Policy file contains '{{ ' template literal"
    - path: "app/Console/Commands/PruneSyncErrorsCommand.php"
      provides: "extends BaseCommand; signature `sync-errors:prune {--days=90}`; uses Auditor::record"
    - path: "routes/console.php"
      provides: "Schedule for sync-errors:prune at 03:20 (between 03:10 integration_events and 03:30 sync_diffs)"
    - path: ".planning/phases/02-supplier-sync/02-VERIFICATION.md"
      provides: "Phase 2 success criteria ↔ plan mapping ↔ verification commands"
  key_links:
    - from: "depfile.yaml → WpDirectDb layer"
      to: "Sync layer ruleset"
      via: "-WpDirectDb deny rule"
      pattern: "- '-WpDirectDb'"
    - from: "tests/Architecture/PolicyTemplateIntegrityTest.php"
      to: "all Policy files in app/Policies/ and app/Domain/*/Policies/"
      via: "file-system glob + str_contains assertion"
      pattern: "PolicyTemplateIntegrity"
    - from: "app/Console/Commands/PruneSyncErrorsCommand.php"
      to: "app/Foundation/Audit/Services/Auditor.php"
      via: "Auditor::record('sync-errors.pruned', [...])"
      pattern: "Auditor.*sync-errors\\.pruned"
    - from: "routes/console.php"
      to: "app/Console/Commands/PruneSyncErrorsCommand.php"
      via: "Schedule::command('sync-errors:prune')->dailyAt('03:20')"
      pattern: "sync-errors:prune"
---

<objective>
Close Phase 2 with three guardrails + one retention prune + phase verification:

1. **SYNC-04 Deptrac enforcement** — new `WpDirectDb` layer with `-` deny rule on Sync; positive + negative architecture tests. Build fails if anyone ever adds a direct `DB::connection('mysql_woo')->table(...)` call in Sync domain.
2. **Pitfall P2-H permanent guardrail** — PolicyTemplateIntegrityTest greps every Policy file for `{{ ` template literals after every build; fails CI if Shield regenerates a stub and nobody notices.
3. **Retention prune** — PruneSyncErrorsCommand (90 days per Phase 1 D-07 convention) scheduled at 03:20 daily. Replaces the TODO marker in routes/console.php left by Phase 1 Plan 05.
4. **Phase 2 VERIFICATION.md** — consolidates the 6 success criteria with pointers to the specific plans + automated verification commands.

This plan is intentionally small — it's the safety net. The heavy lifting shipped in P01-P04.

Purpose: Phase 2 cannot ship without SYNC-04 enforcement (hard roadmap requirement) or the PolicyTemplateIntegrityTest (persistent risk per Phase 1's 3 regressions). Retention + verification are standard phase-closeout items.

Output: 2 Deptrac config updates + 2 architecture tests + 1 command + 1 routes/console schedule entry + 1 VERIFICATION.md document.

Scope additions beyond REQUIREMENTS.md:
- Pitfall P2-H permanent architecture test (Phase 1's 3 regressions motivate)
- `sync-errors:prune` command + schedule (Phase 1 Plan 05 routes/console.php TODO → Phase 2 deliverable)
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/02-supplier-sync/02-CONTEXT.md
@.planning/phases/02-supplier-sync/02-RESEARCH.md
@.planning/phases/02-supplier-sync/02-01-SUMMARY.md
@.planning/phases/02-supplier-sync/02-02-SUMMARY.md
@.planning/phases/02-supplier-sync/02-03-SUMMARY.md
@.planning/phases/02-supplier-sync/02-04-SUMMARY.md
@.planning/phases/01-foundation/01-05-SUMMARY.md

<interfaces>
<!-- Phase 1 contracts this plan consumes. -->

From Phase 1 Plan 01 `depfile.yaml` (pre-existing 11-layer Deptrac ruleset):
- Layers: Products, Pricing, Competitor, Sync, Webhooks, CRM, Suggestions, Alerting, Feeds (9 Domain) + Foundation + Http
- Current Sync ruleset: Sync → Foundation only (no Products allowance yet — P01 may have added +Products)
- File format: YAML with `deptrac.layers:` + `deptrac.ruleset:`

From Phase 1 Plan 05 `routes/console.php` (existing TODO):
```php
// TODO: Phase 2 adds `sync-errors:prune --days=90` (D-07) once Phase 2 ships the sync_errors table.
```
Replace with actual scheduling.

From Phase 1 Plan 05 retention prune command pattern (01-05-SUMMARY Pattern 01-05-b):
- Extends Illuminate\\Console\\Command (NOT BaseCommand — Phase 1 pattern uses plain Command for prunes to keep them simple)
- Actually — Phase 1 Plan 05 Pattern says "extends Illuminate\\Console\\Command"
- But 02-RESEARCH.md §Extra says "extends BaseCommand (correlation-id + LogBatch threading)"
- RESOLVE: extend BaseCommand for correlation_id threading; matches the other Phase 2 commands (SyncSupplierCommand)

From Phase 1 Plan 05 `tests/Architecture/DeptracTest.php` pattern:
- Positive + negative shape already exists
- Negative test imports a real class (not unresolvable) so Deptrac resolves + flags the violation
- After test, violator file is unlinked

From Phase 1 Plan 04 shield:generate handoff:
- 3 policies already damaged/fixed: RolePolicy, SuggestionPolicy, AlertRecipientPolicy
- P01 ships 4 more: ProductPolicy, ProductVariantPolicy, SyncRunPolicy, ImportIssuePolicy
- P04 runs shield:generate AGAIN; restores whichever got damaged
- Total Policies under test: 7 (3 Phase 1 + 4 Phase 2)

From Phase 2 P03 — retention rationale:
- sync_errors grows unbounded if not pruned; Phase 1 D-07 sets 90-day retention for analogous audit data
- routes/console.php already has 3 prune entries at 03:00/03:10/03:30; 03:20 slot is open
</interfaces>

</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Add WpDirectDb Deptrac layer + Sync deny rule + DeptracSyncLayerTest (positive + negative) — SYNC-04</name>
  <files>
    depfile.yaml,
    deptrac.yaml,
    tests/Architecture/DeptracSyncLayerTest.php
  </files>
  <read_first>
    - 02-RESEARCH.md §12 Architectural Test (lines 1059-1107 — exact YAML + test shape + key design point on resolvable imports)
    - 02-CONTEXT.md — SYNC-04 architectural test (lines 157-158)
    - 01-05-SUMMARY.md (DeptracTest negative-test pattern; "DeptracTest negative test didn't fire because unresolvable imports get marked 'uncovered' not 'violating'" — MUST use a real class in the violator file)
    - depfile.yaml (current — existing 11 layers + Sync → Foundation ruleset)
    - deptrac.yaml (should be a mirror of depfile.yaml per Phase 1 P01 deviation #8)
    - tests/Architecture/DeptracTest.php (current pattern for positive + negative)
  </read_first>
  <behavior>
    Tests in tests/Architecture/DeptracSyncLayerTest.php (2 tests):
    - Test D1 (positive): `passthru('vendor/bin/deptrac analyse --no-progress', $exitCode)` on the current codebase → $exitCode === 0 (assumes Phase 2 P01-P04 have zero violations baseline).
    - Test D2 (negative): Write a deliberate violator at `app/Domain/Sync/Services/__SyncDeptracViolator.php` that imports `Illuminate\\Support\\Facades\\DB` and uses `DB::connection('mysql_woo')->table('wp_posts')->update([...])`. Run deptrac; assert $exitCode > 0 AND the output contains the word "WpDirectDb" or "violates". After-hook unlinks the file even on failure.
  </behavior>
  <action>
**1. Update `depfile.yaml`** — add `WpDirectDb` layer to the `layers:` block AND a `-WpDirectDb` deny rule under `Sync:` in the `ruleset:` block:

```yaml
# (existing) — depfile.yaml structure
deptrac:
  paths:
    - app
  layers:
    # ... existing layers (Products, Pricing, Competitor, Sync, Webhooks, CRM, Suggestions, Alerting, Feeds, Foundation, Http) ...

    # SYNC-04 — Phase 2 Plan 05 — ban direct WP DB writes from Sync domain
    - name: WpDirectDb
      collectors:
        - type: classLike
          regex: '^Illuminate\\\\Support\\\\Facades\\\\DB$'

  ruleset:
    # ... existing rulesets ...

    Sync:
      - '+Foundation'
      - '+Products'   # Phase 2 P01 addition (if not already in depfile)
      - '-WpDirectDb' # SYNC-04 — architectural enforcement per Plan 05
```

**CRITICAL:** 
- The `+Products` line may already exist from P01. If so, keep it; do NOT duplicate.
- The `WpDirectDb` collector uses classLike regex `^Illuminate\\\\Support\\\\Facades\\\\DB$` (double-escaped backslashes in YAML).
- DB facade usage in OTHER layers (Alerting, Webhooks, Suggestions — all of which use DB::transaction etc.) is still allowed because the `-WpDirectDb` rule is ONLY on Sync.

**2. Mirror `deptrac.yaml` from `depfile.yaml`** — full file copy (Phase 1 Plan 01 deviation #8 established this pattern so `vendor/bin/deptrac analyse` without args also picks up the new rule).

**3. Verify the change positively:**
```bash
vendor/bin/deptrac analyse --no-progress --config-file=depfile.yaml
# exit 0 — current codebase has NO direct DB facade use in app/Domain/Sync/*
```

Possible surprise: `WooClient::writeOrShadow()` uses `SyncDiff::create(...)` (Eloquent) — that's fine. If there's ANY `DB::` usage in Sync, you must remove it or refactor.

Check via grep:
```bash
grep -rn "Illuminate\\\\Support\\\\Facades\\\\DB" app/Domain/Sync/ 2>/dev/null
# Expected: empty (or the one DB::transaction in SyncChunkJob if you chose that pattern — see below)
```

**Surprise resolution:** SyncChunkJob (P03 Task 3) uses `DB::transaction(fn() => ...)` for per-SKU atomic writes. This will now trigger a WpDirectDb violation. 

**Options:**
- **(a)** Remove the transaction from SyncChunkJob — acceptable because per-SKU writes are already idempotent via last_synced_at. Each SKU is its own transaction implicitly (Eloquent save).
- **(b)** Refactor transactional code to a new Foundation service `TransactionRunner` — but that's out of P05 scope.
- **(c)** Narrow the WpDirectDb collector to only ban `DB::connection('mysql_woo')` specifically via regex `^Illuminate\\\\Support\\\\Facades\\\\DB::connection$` — but Deptrac collectors work on class-level, not method-level.

**RECOMMENDED: option (a)** — remove DB::transaction from SyncChunkJob. Each SKU write's atomicity is already covered by: (1) Woo PUT is atomic at Woo's end, (2) Eloquent model saves are atomic per row, (3) the cursor is updated last. No transaction needed.

Edit `app/Domain/Sync/Jobs/SyncChunkJob.php`:
- Remove `use Illuminate\\Support\\Facades\\DB;` import.
- Remove `DB::transaction(function () use (...) { ... });` wrapper.
- Call the 3 inner operations (woo->put, writeRunItem, upsertLocalMirror) sequentially.

Re-run `grep -rn "Illuminate\\\\Support\\\\Facades\\\\DB" app/Domain/Sync/` — expected empty.

Re-run `vendor/bin/deptrac analyse --no-progress` — exit 0.

Re-run `vendor/bin/pest --filter=SyncChunkJob` — all P03 tests still green (transactions weren't load-bearing for correctness; they were belt-and-braces that can be removed without regression).

**4. Write `tests/Architecture/DeptracSyncLayerTest.php`:**
```php
<?php declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class)->in('Architecture');

test('Sync domain has zero Deptrac violations (positive)')
    ->group('architecture')
    ->expect(function () {
        $exitCode = 0;
        passthru('vendor/bin/deptrac analyse --no-progress --config-file=' . base_path('depfile.yaml'), $exitCode);
        return $exitCode;
    })
    ->toBe(0);

test('Deptrac fails when Sync imports Illuminate\\Support\\Facades\\DB (negative)')
    ->group('architecture')
    ->after(function () {
        @unlink(base_path('app/Domain/Sync/Services/__SyncDeptracViolator.php'));
    })
    ->test(function () {
        $violatorPath = base_path('app/Domain/Sync/Services/__SyncDeptracViolator.php');
        file_put_contents($violatorPath, <<<'PHP'
<?php declare(strict_types=1);

namespace App\Domain\Sync\Services;

use Illuminate\Support\Facades\DB;

final class __SyncDeptracViolator
{
    public function bad(): void
    {
        DB::connection('mysql_woo')->table('wp_posts')->update([]);
    }
}
PHP
        );

        $exitCode = 0;
        ob_start();
        passthru('vendor/bin/deptrac analyse --no-progress --config-file=' . base_path('depfile.yaml'), $exitCode);
        $output = ob_get_clean();

        expect($exitCode)->toBeGreaterThan(0);
        // Belt-and-braces: output mentions the layer we denied
        expect(strtolower($output))->toContain('wpdirectdb');
    });
```

**Self-check:**
```bash
vendor/bin/pest --filter=DeptracSyncLayer
vendor/bin/pest  # full suite — MUST stay green
```
  </action>
  <verify>
    <automated>vendor/bin/pest --filter=DeptracSyncLayer &amp;&amp; vendor/bin/deptrac analyse --no-progress</automated>
  </verify>
  <done>
    - depfile.yaml + deptrac.yaml both contain the `WpDirectDb` layer block
    - `Sync:` ruleset contains `- '-WpDirectDb'`
    - `grep -rn 'Illuminate\\Support\\Facades\\DB' app/Domain/Sync/` returns empty (SyncChunkJob's DB::transaction removed if present)
    - DeptracSyncLayerTest — 2 tests green (positive + negative)
    - `vendor/bin/deptrac analyse` exits 0 on the current codebase
    - Full Pest suite ≥ 229 passing (previous 227 + 2)
    - If SyncChunkJob was edited: its P03 test suite (SyncChunkJobTest, SyncChunkFailureTest) still green
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Ship PolicyTemplateIntegrityTest (Pitfall P2-H permanent guardrail) + PruneSyncErrorsCommand + routes/console.php scheduling + 02-VERIFICATION.md</name>
  <files>
    tests/Architecture/PolicyTemplateIntegrityTest.php,
    app/Console/Commands/PruneSyncErrorsCommand.php,
    routes/console.php,
    tests/Feature/PruneSyncErrorsTest.php,
    .planning/phases/02-supplier-sync/02-VERIFICATION.md
  </files>
  <read_first>
    - 02-RESEARCH.md §Extra (lines 1109-1119 — PruneSyncErrorsCommand signature), Pitfall P2-H lines 1207-1217 (policy integrity test guardrail)
    - 02-CONTEXT.md lines 157 (sync-errors:prune command scope, references Phase 1 Plan 05 TODO)
    - 01-05-SUMMARY.md — retention prune pattern (Pattern 01-05-b): extends Command, signature {domain}:prune, Auditor::record; 03:00/03:10/03:30 slots taken, 03:20 open
    - app/Console/Commands/PruneIntegrationEventsCommand.php (template)
    - app/Console/Commands/BaseCommand.php (extend this for correlation_id threading — 02-RESEARCH §Extra)
    - routes/console.php (existing — 3 prunes scheduled, TODO marker for sync-errors)
    - app/Foundation/Audit/Services/Auditor.php (Auditor::record signature)
    - app/Domain/Sync/Models/SyncError.php (P01 — append-only with created_at)
  </read_first>
  <behavior>
    Tests in tests/Architecture/PolicyTemplateIntegrityTest.php (permanent Pitfall P2-H guardrail):
    - Test T1: Walk `app/Policies/*.php` + `app/Domain/*/Policies/*.php` via Glob; for each file, assert `str_contains(file_get_contents($file), '{{ ') === false`. Fails with a message listing the offending files if any contain template literals.
    - Test T2: Positive control — asserts at least 7 Policy files exist (RolePolicy + SuggestionPolicy + AlertRecipientPolicy + ProductPolicy + ProductVariantPolicy + SyncRunPolicy + ImportIssuePolicy). Prevents false-green from a glob pattern that matches nothing.

    Tests in tests/Feature/PruneSyncErrorsTest.php:
    - Test P1: `php artisan sync-errors:prune --days=90` deletes sync_errors rows where `created_at < now()->subDays(90)`; keeps rows ≥ 90 days. Seed 10 rows at various ages; assert the expected subset survives.
    - Test P2: Writes an `Auditor::record('sync-errors.pruned', [...])` row with properties.deleted_count + properties.cutoff_date + properties.days.
    - Test P3: Invocation with `--days=0` deletes nothing (guard against accidental wipe).
    - Test P4: Command extends BaseCommand — invoking via artisan threads correlation_id from Context through the Auditor row (properties.correlation_id non-null).
    - Test P5: `php artisan schedule:list` output includes "sync-errors:prune" with "Daily at 03:20" (assert via command output grep or via `Schedule::events` introspection).
  </behavior>
  <action>
**1. Write `tests/Architecture/PolicyTemplateIntegrityTest.php`:**
```php
<?php declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class)->in('Architecture');

test('no Policy file contains {{ template literal — Pitfall P2-H permanent guardrail')
    ->group('architecture')
    ->test(function () {
        $policyPaths = array_merge(
            glob(base_path('app/Policies/*.php')) ?: [],
            glob(base_path('app/Domain/*/Policies/*.php')) ?: [],
        );

        $offenders = [];
        foreach ($policyPaths as $path) {
            $content = file_get_contents($path);
            if (str_contains($content, '{{ ')) {
                $offenders[] = $path;
            }
        }

        expect($offenders)->toBeEmpty(
            'Policy files contain unrendered Shield template literals (Pitfall P2-H): ' . implode(', ', $offenders)
        );
    });

test('at least 7 Policy files exist (positive control against false-green glob)')
    ->group('architecture')
    ->expect(function () {
        $paths = array_merge(
            glob(base_path('app/Policies/*.php')) ?: [],
            glob(base_path('app/Domain/*/Policies/*.php')) ?: [],
        );
        return count($paths);
    })
    ->toBeGreaterThanOrEqual(7);
```

**2. Create `app/Console/Commands/PruneSyncErrorsCommand.php`** — extends BaseCommand per 02-RESEARCH §Extra:
```php
namespace App\Console\Commands;

use App\Domain\Sync\Models\SyncError;
use App\Foundation\Audit\Services\Auditor;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Retention prune for sync_errors — D-07 90-day convention (matches Phase 1).
 *
 * Scheduled at 03:20 in routes/console.php (between 03:10 integration_events and 03:30 sync_diffs).
 * Replaces the Phase 1 Plan 05 TODO marker for this slot.
 */
final class PruneSyncErrorsCommand extends BaseCommand
{
    protected $signature = 'sync-errors:prune {--days=90}';
    protected $description = 'Delete sync_errors rows older than --days=N (default 90).';

    public function __construct(private readonly Auditor $auditor)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->warn("sync-errors:prune aborted: --days must be >= 1 (got {$days}).");
            return SymfonyCommand::SUCCESS;  // graceful no-op
        }

        $cutoff = now()->subDays($days);
        $deleted = SyncError::query()->where('created_at', '<', $cutoff)->delete();

        $this->auditor->record('sync-errors.pruned', [
            'deleted_count' => $deleted,
            'cutoff_date' => $cutoff->toIso8601String(),
            'days' => $days,
        ]);

        $this->info("Pruned {$deleted} sync_errors rows older than {$cutoff->toDateString()} ({$days} days).");
        return SymfonyCommand::SUCCESS;
    }
}
```

**3. Update `routes/console.php`** — replace the Phase 1 TODO marker (if present as a comment) with the real schedule. Pattern matches Phase 1's 03:00/03:10/03:30 prunes:
```php
// Phase 2 Plan 05 — sync_errors 90-day retention (replaces Phase 1 Plan 05 TODO)
Schedule::command('sync-errors:prune', ['--days' => 90])
    ->dailyAt('03:20')
    ->onOneServer()
    ->withoutOverlapping(30)
    ->timezone('Europe/London')
    ->description('Prune sync_errors older than 90 days (Phase 2 D-07 convention)');
```

Verify via:
```bash
php artisan schedule:list | grep sync-errors
# Should show: sync-errors:prune ... Daily at 03:20 ...
```

**4. Write `tests/Feature/PruneSyncErrorsTest.php`:**
```php
use App\Domain\Sync\Models\SyncError;
use App\Domain\Sync\Models\SyncRun;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => Context::add('correlation_id', 'test-' . \Illuminate\Support\Str::uuid()));

it('deletes sync_errors older than --days and retains newer rows', function () {
    $run = SyncRun::factory()->create();
    SyncError::create([
        'sync_run_id' => $run->id, 'sku' => 'OLD-1',
        'error_class' => 'Test', 'error_message' => 'old',
        'correlation_id' => Context::get('correlation_id'),
        'created_at' => now()->subDays(120),  // older than 90 days
    ]);
    SyncError::create([
        'sync_run_id' => $run->id, 'sku' => 'NEW-1',
        'error_class' => 'Test', 'error_message' => 'new',
        'correlation_id' => Context::get('correlation_id'),
        'created_at' => now()->subDays(10),  // within 90 days
    ]);

    $exit = $this->artisan('sync-errors:prune', ['--days' => 90])->run();

    expect($exit)->toBe(0);
    expect(SyncError::where('sku', 'OLD-1')->exists())->toBeFalse();
    expect(SyncError::where('sku', 'NEW-1')->exists())->toBeTrue();
});

it('writes an Auditor meta-audit row with deleted_count and correlation_id', function () {
    $cid = 'test-prune-' . \Illuminate\Support\Str::uuid();
    Context::add('correlation_id', $cid);

    $this->artisan('sync-errors:prune', ['--days' => 30])->run();

    $activity = Activity::where('log_name', 'system')->where('event', 'sync-errors.pruned')->latest()->first();
    expect($activity)->not->toBeNull();
    expect($activity->properties['correlation_id'])->toBe($cid);
    expect($activity->properties)->toHaveKey('deleted_count');
    expect($activity->properties)->toHaveKey('cutoff_date');
});

it('--days=0 does nothing (guard against accidental wipe)', function () {
    $run = SyncRun::factory()->create();
    SyncError::create([
        'sync_run_id' => $run->id, 'sku' => 'A',
        'error_class' => 'T', 'error_message' => 'x',
        'correlation_id' => Context::get('correlation_id'),
        'created_at' => now()->subDays(10),
    ]);

    $this->artisan('sync-errors:prune', ['--days' => 0]);

    expect(SyncError::count())->toBe(1);
});

it('schedule:list includes sync-errors:prune at 03:20', function () {
    $output = \Illuminate\Support\Facades\Artisan::output();
    \Illuminate\Support\Facades\Artisan::call('schedule:list');
    $output = \Illuminate\Support\Facades\Artisan::output();
    expect($output)->toContain('sync-errors:prune');
});
```

**5. Write `.planning/phases/02-supplier-sync/02-VERIFICATION.md`** — phase-level verification index:
```markdown
# Phase 02 — Supplier Sync — Verification

**Phase goal (ROADMAP.md):** The daily supplier sync from 21stcav.com replaces the legacy
Stock Updater plugin — crashed runs resume cleanly, per-item failures captured, Woo written
only through REST, emailed CSV report on completion.

## Success Criteria Coverage

| # | Success Criterion | Plan | Verification Command |
|---|-------------------|------|---------------------|
| 1 | `php artisan sync:supplier --dry-run` → emailed CSV, zero Woo writes, diffs in sync_diffs | P03 (command) + P04 (CSV+mail) | `php artisan sync:supplier` → assert `SyncRun::latest()->first()->dry_run === true` AND `SyncDiff::where('created_at', '>=', $run->started_at)->count() > 0` |
| 2 | Worker killed mid-run + `--resume={id}` continues from cursor | P03 (SyncSupplierCommand + SyncChunkJob idempotency) | `vendor/bin/pest --filter=SyncResume` (4 tests) |
| 3 | Architectural test fails on direct WP DB access | P05 (Deptrac WpDirectDb) | `vendor/bin/pest --filter=DeptracSyncLayer` (2 tests — positive + negative) |
| 4 | Missing SKU → `pending` unless `custom-ms`; `_exclude_from_auto_update` skipped + counted | P03 (MarkMissingSkusJob + SyncDiffEngine) | `vendor/bin/pest --filter=MissingSkuHandling --filter=ExcludeFromAutoUpdate` |
| 5 | Filament "Supplier Sync Status" + "Import Issues" pages with drill-down | P04 (SyncRunResource + ImportIssueResource) | Visit `/admin/sync-runs` + `/admin/import-issues` as admin; `vendor/bin/pest --filter=SyncRunResource --filter=ImportIssueResource` |
| 6 | Domain events fire observably in integration_events with matching correlation_id | P03 (4 events + ShouldDispatchAfterCommit retrofit) | `vendor/bin/pest --filter=DomainEventAfterCommit --filter=SupplierEventDispatch` |

## Scope Additions Verification (beyond REQUIREMENTS.md)

| Item | Source | Plan | Verified by |
|------|--------|------|-------------|
| `products` + `product_variants` tables | D-01 | P01 | `Schema::hasTable('products') && Schema::hasTable('product_variants')` |
| `import_issues` table | D-09 / SYNC-12 | P01 | `Schema::hasTable('import_issues')` + ImportIssueResourceTest |
| `receives_sync_reports` column | D-08 | P04 | `Schema::hasColumn('alert_recipients', 'receives_sync_reports')` |
| `NewSupplierSkuDetected` event + stub listener | D-09 | P03 | `vendor/bin/pest --filter=SupplierEventDispatch` test E4 + E6 |
| `--live` + flag conflict validation | D-04 | P03 | `vendor/bin/pest --filter=SyncSupplierCommandFlags` |
| `sync-errors:prune` command | RESEARCH §Extra | P05 | `vendor/bin/pest --filter=PruneSyncErrors` |
| ShouldDispatchAfterCommit retrofit on DomainEvent | Pitfall P2-I | P03 | `vendor/bin/pest --filter=DomainEventAfterCommit` + Phase 1 regression green |
| PolicyTemplateIntegrityTest | Pitfall P2-H | P05 | `vendor/bin/pest --filter=PolicyTemplateIntegrity` |
| `sync_run_items` append-only CSV source | RESEARCH §9 | P01 | Schema + SyncReportCsvGeneratorTest |

## Requirement ID Coverage

| ID | Covered in Plan(s) |
|----|-------------------|
| SYNC-01 | P02 (SupplierClient), P03 (SyncSupplierCommand) |
| SYNC-02 | P02 (JWT lifecycle + refresh-once) |
| SYNC-03 | P01 (cursor schema), P03 (--resume + findResumable) |
| SYNC-04 | P05 (Deptrac WpDirectDb) |
| SYNC-05 | P01 (sync_errors schema), P03 (SyncChunkJob failure path) |
| SYNC-06 | P01 (sync_run_items action enum), P03 (MarkMissingSkusJob) |
| SYNC-07 | P03 (SyncDiffEngine exclude_from_auto_update) |
| SYNC-08 | P04 (SupplierSyncReportMail + SyncReportCsvGenerator) |
| SYNC-09 | P01 (dry_run column), P03 (default-dry-run + flag handling) |
| SYNC-10 | P02 (WooClient writeLive 429 backoff) |
| SYNC-11 | P04 (SyncRunResource + RelationManagers) |
| SYNC-12 | P01 (import_issues schema), P04 (ImportIssueResource) |
| SYNC-13 | P03 (4 domain events + ShouldDispatchAfterCommit) |

## Full Phase Verification Script

```bash
# 1. Dependencies
composer show automattic/woocommerce
composer show spatie/simple-excel

# 2. Schema
php artisan db:table products && php artisan db:table product_variants && \
    php artisan db:table sync_runs && php artisan db:table sync_errors && \
    php artisan db:table import_issues && php artisan db:table sync_run_items

# 3. Architecture
vendor/bin/deptrac analyse --no-progress

# 4. Policies
grep -rn '{{ ' app/Policies/ app/Domain/*/Policies/ && echo "LEAK FOUND" || echo "OK"

# 5. Full test suite
vendor/bin/pest

# 6. Lint + static analysis
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G

# 7. Schedules
php artisan schedule:list | grep -E '(sync-errors:prune|sync:supplier)'

# 8. CLI smoke
php artisan sync:supplier --live --dry-run  # should fail with mutually-exclusive error
php artisan sync:supplier --help  # shows --live, --dry-run, --resume options
```

## Known Deviations / Deferred

- Phase 2 P01-P04 deviations: see each plan's SUMMARY.md `Deviations from Plan` section.
- Pitfall 18 stock race: ACCEPTED for v1 — Meeting Store B2B traffic at 02:00-03:00 UTC is near-zero. Delta-apply pattern documented in RESEARCH §Pitfall P2-J for future tightening.
- A1/A2 supplier API shape: MEDIUM confidence; P02 SupplierClient may need field-name adjustments after first live run.
- Horizon worker timeout 90→120s bump (Pitfall P2-E) applied in P03 at job level (SyncChunkJob::$timeout); did NOT modify config/horizon.php supervisor timeout unless P03 executor made that decision.

## VPS Operator Handoff (from Phase 1 P01-P05 + Phase 2)

Before first live sync (`--live`):
1. Populate `.env` on VPS: `SUPPLIER_API_URL=https://21stcav.com`, `SUPPLIER_API_USERNAME=...`, `SUPPLIER_API_PASSWORD=...`
2. Populate `WOO_URL`, `WOO_CONSUMER_KEY`, `WOO_CONSUMER_SECRET` (currently blank per Phase 1 P04 handoff)
3. Populate real ops emails via Filament `/admin/alert-recipients`; the seeded `ops@meetingstore.co.uk` fallback can be deactivated but should remain as a row
4. Verify Horizon running via `supervisorctl status horizon` (Phase 1 P05 runbook)
5. Test dry-run first: `php artisan sync:supplier` — check the emailed CSV before enabling the scheduled cron
6. Enable the cron only after Phase 7 parity runbook: uncomment the `Schedule::command('sync:supplier --live')` in `routes/console.php` (D-05 kill-switch)

*Phase 02 verification: 2026-04-18*
```

**Self-check:**
```bash
vendor/bin/pest --filter=PolicyTemplateIntegrity --filter=PruneSyncErrors
vendor/bin/pest  # full suite
```
  </action>
  <verify>
    <automated>vendor/bin/pest --filter=PolicyTemplateIntegrity &amp;&amp; vendor/bin/pest --filter=PruneSyncErrors &amp;&amp; vendor/bin/pest</automated>
  </verify>
  <done>
    - tests/Architecture/PolicyTemplateIntegrityTest.php exists with 2 tests green
    - `grep -rn '{{ ' app/Policies/ app/Domain/*/Policies/` returns empty
    - `php artisan sync-errors:prune --days=90` runs cleanly and writes an activity_log row with event='sync-errors.pruned'
    - `php artisan schedule:list` includes sync-errors:prune at 03:20
    - routes/console.php TODO marker replaced with the real schedule
    - 5 new PruneSyncErrorsTest cases green; 2 PolicyTemplateIntegrityTest green
    - .planning/phases/02-supplier-sync/02-VERIFICATION.md exists with the full success-criteria + requirement-ID coverage table
    - Full Pest suite ≥ 236 passing (previous 229 + 7)
    - `vendor/bin/pint --test` clean on new files
    - `vendor/bin/phpstan analyse` clean on new files
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| CI runner → test suite | PolicyTemplateIntegrityTest protects against accidental shield:generate regressions in future plans |
| depfile.yaml → deptrac runtime | Deptrac layer regex is the architectural enforcement — ambiguous/weak regex allows bypass |
| Scheduled cron → PruneSyncErrorsCommand | Runs as the `www-data` user on the VPS; command deletes DB rows |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-02-05-01 | Elevation of Privilege | SYNC-04 architectural bypass — future dev imports DB facade in Sync | mitigate | Deptrac WpDirectDb deny rule + negative test (T1/T2 in DeptracSyncLayerTest). Build fails on PR before merge. |
| T-02-05-02 | Tampering | Shield regenerates a hand-edited policy; damage goes unnoticed | mitigate | PolicyTemplateIntegrityTest runs on every PR; detects `{{ ` literals in <1 ms. Permanent guardrail. |
| T-02-05-03 | Denial of Service | PruneSyncErrorsCommand mass-deletes sync_errors (operator misconfigures --days) | mitigate | `--days=0` → graceful no-op (guard clause); `--days=1` deletes only 1-day-old rows. Auditor::record writes before delete so operator can see what the prune command was going to do. |
| T-02-05-04 | Information Disclosure | Auditor meta-audit row contains deleted_count — operationally useful but not secret | accept | activity_log table is admin-only readable; this is the intended audit trail. |
| T-02-05-05 | Tampering | Scheduled prune runs as www-data without further auth | accept | Server-level concern — if www-data is compromised, the whole Laravel app is compromised. Out of Phase 2 scope; Phase 7 cutover runbook covers VPS hardening. |
</threat_model>

<verification>
1. **Deptrac enforcement:**
   ```bash
   vendor/bin/deptrac analyse --no-progress  # exit 0
   vendor/bin/pest --filter=DeptracSyncLayer  # 2 tests — positive + negative
   ```

2. **Policy integrity:**
   ```bash
   grep -rn '{{ ' app/Policies/ app/Domain/*/Policies/  # empty
   vendor/bin/pest --filter=PolicyTemplateIntegrity  # 2 tests green
   ```

3. **Retention prune:**
   ```bash
   php artisan sync-errors:prune --days=90  # writes audit row
   php artisan schedule:list | grep sync-errors  # shows 03:20 entry
   vendor/bin/pest --filter=PruneSyncErrors  # 5 tests green
   ```

4. **VERIFICATION.md completeness:**
   ```bash
   test -f .planning/phases/02-supplier-sync/02-VERIFICATION.md && echo OK
   grep -c 'SYNC-' .planning/phases/02-supplier-sync/02-VERIFICATION.md  # ≥ 13 (all 13 requirements referenced)
   ```

5. **Full suite:**
   ```bash
   vendor/bin/pest && vendor/bin/deptrac analyse --no-progress && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G
   ```
   All four green.
</verification>

<success_criteria>
- SYNC-04 enforced via Deptrac WpDirectDb layer + negative test
- Pitfall P2-H permanent guardrail: PolicyTemplateIntegrityTest catches any future Shield regeneration damage
- `sync-errors:prune` command + 03:20 schedule replacing Phase 1 P05's TODO marker
- 02-VERIFICATION.md ships with full 13-requirement mapping + 6-success-criteria coverage table + operator handoff checklist
- Full Pest suite ≥ 236 passing with ZERO regressions
- `vendor/bin/deptrac analyse` exits 0
- `vendor/bin/pint --test && vendor/bin/phpstan analyse` clean
- Every requirement ID (SYNC-01..SYNC-13) appears in ≥ 1 plan's `requirements` frontmatter field
- Phase 2 is SHIP-READY
</success_criteria>

<output>
Create `.planning/phases/02-supplier-sync/02-05-SUMMARY.md` after completion with:
- Deptrac WpDirectDb layer YAML snippet + any SyncChunkJob DB::transaction removal
- PolicyTemplateIntegrityTest scope (7 policies protected)
- sync-errors:prune schedule verbatim
- 02-VERIFICATION.md location + how Phase 3 / Phase 7 should consume it
- Final Pest test count + Deptrac violation count + Pint/PHPStan status
- Any deviations during shield:generate post-audit re-encounters
</output>
