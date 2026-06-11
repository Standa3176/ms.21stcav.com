---
quick_id: 260611-rl4
slug: auto-chain-cutover-divergence-scan-push
type: quick
created: 2026-06-11
completed: 2026-06-11
mode: quick
commits:
  - hash: fc3ee90
    type: feat(cutover)
    message: auto-sync command chains scan→push→re-scan with parity-regression detection (260611-rl4)
  - hash: 5bbd98b
    type: chore(schedule)
    message: cutover:auto-sync daily 23:00 London cron (260611-rl4)
  - hash: 78d2485
    type: test(cutover)
    message: auto-sync Pest cases A-H + parity-regression detector (260611-rl4)
files_created:
  - app/Console/Commands/Cutover/AutoSyncDivergenceCommand.php
  - tests/Feature/Console/Cutover/AutoSyncDivergenceCommandTest.php
files_modified:
  - app/Providers/AppServiceProvider.php
  - routes/console.php
  - .planning/STATE.md
tests_added: 8
tests_passing: 8
---

# Quick task 260611-rl4 — `cutover:auto-sync` Summary

## One-liner

Nightly 23:00 London chain command that orchestrates `cutover:divergence-scan → products:push-divergence-to-woo → cutover:divergence-scan` via Artisan::call, with a parity-regression alarm that exits 1 when post-push parity_percent drops below pre-push.

## What shipped

| Component | Detail |
|---|---|
| New command | `App\Console\Commands\Cutover\AutoSyncDivergenceCommand` (extends `BaseCommand`, constructor DI on `Auditor`) |
| Signature | `cutover:auto-sync {--field=stock_quantity,buy_price,category_id} {--max-products=500} {--skip-scan} {--skip-rescan} {--dry-run}` |
| Grep-discoverability | `private const CHAINED_COMMANDS = ['cutover:divergence-scan', 'products:push-divergence-to-woo']` |
| Schedule | `cron('0 23 * * *')`, `Europe/London`, `onOneServer()`, `withoutOverlapping(120)`, `name('cutover:auto-sync')` |
| Audit events | `cutover.auto_sync_completed` / `cutover.auto_sync_failed` (with `phase=scan|push|rescan`) / `cutover.auto_sync_parity_regression` |
| Test file | `tests/Feature/Console/Cutover/AutoSyncDivergenceCommandTest.php` (8 cases A-H) |

## The chain

```
Phase 1 SCAN     → cutover:divergence-scan --live              (writes sync_diffs + sync_diffs_parity snapshot)
Phase 2 PUSH     → products:push-divergence-to-woo             (consumes sync_diffs, PUTs MS-truth to Woo)
Phase 3 RE-SCAN  → cutover:divergence-scan --live              (fresh parity_percent, different correlation_id)
Phase 4 REPORT   → counters table + audit log + parity-regression alarm
```

## Decisions made

- **Phase 3 re-scan SKIPPED on phase 2 push failure** (planner correction from brief which said "phase 3 still runs"). Cleaner contract: partial-push parity_after would mix unfixed + half-fixed diffs into a misleading number; audit log + exit 1 already alarm ops.
- **Parity-regression exits 1 (NOT 0)** so cron logs + Horizon visibly mark the run as failed.
- **Does NOT roll back** pushed changes on regression — detection alarm only; operator investigates the next morning.
- **`--dry-run` propagation:** phase 1 scan LIVE (read-only against Woo); phase 2 push `--dry-run`; phase 3 SKIPPED; parity-regression check SKIPPED.
- **`Kernel::getArtisan()` Reflection access in tests** (Rule 3 auto-fix folded): protected in Laravel 12; pierced via `ReflectionMethod` so anonymous-subclass stub commands can override the registered chain commands via `Symfony\Console\Application::add()`.
- **Hydrate NOT chained** — stays on its Mon-Fri 07:20 cron (260611-qcq).
- **No new config keys** — re-uses `cutover.parity_threshold_percent` indirectly via the snapshot row that DivergenceScanner writes.

## Test coverage (8/8 GREEN)

| Case | What it covers |
|---|---|
| A | Happy path — scan, push, rescan run; parity 80→95; exit 0; no regression event |
| B | `--skip-scan` reuses latest sync_diffs; only push + rescan invoked |
| C | `--dry-run` runs scan live, push dry-run, rescan SKIPPED |
| D | Phase 1 scan fails → push + rescan SKIPPED; audit phase=scan; exit 1 |
| E | Phase 2 push fails → rescan SKIPPED (planner fail-fast); audit phase=push; exit 1 |
| F | `--field=stock_quantity` routes single-field csv to push command |
| G | `--max-products=10` propagates to push command `--limit` |
| H | Parity regression 95→80 → audit `cutover.auto_sync_parity_regression` (delta=-15); exit 1 |

## Verification (Task 5)

| Check | Result |
|---|---|
| `php artisan list \| findstr cutover:auto-sync` | GREEN — command resolves with description |
| Focused suite (`AutoSyncDivergenceCommandTest.php`) | 8/8 PASS (41 assertions, 7.21s) |
| `tests/Feature/Cutover/DivergenceScanCommandTest.php` (regression) | 9/9 PASS (23 assertions) |
| `tests/Feature/Console/PushDivergenceToWooCommandTest.php` (regression) | 10/10 PASS (87 assertions) |
| `tests/Feature/Console/HydrateProductStockFromOffersCommandTest.php` (regression) | 10/10 PASS (81 assertions) |
| `tests/Feature/Console/PushVisibilityToWooCommandTest.php` (regression) | 6/6 PASS (29 assertions) |
| `php artisan schedule:list \| findstr cutover:auto-sync` | GREEN — entry shows `0 23 *` London (UTC display = `0 22 *` during BST, correct) |

## Probe disagreements with plan (documented)

1. Plan Task 5 path `tests/Feature/Console/Cutover/DivergenceScanCommandTest.php` — actual is `tests/Feature/Cutover/DivergenceScanCommandTest.php` (no `Console/` subfolder). Used actual path.
2. Plan Task 5 regression target `tests/Feature/Console/ResyncProductsToWooCommandTest.php` does not exist in this repo. The `products:resync-to-woo` command itself IS referenced from BackfillCategoryFromWoo / BackfillMerchantFeed / StockDivergencePage / AutoCreateHealthPage, but no dedicated test file exists. Skipped per scope-boundary rule — adding a missing test file is out of scope for this quick task.

## Constraints honoured

- CHAINED_COMMANDS const present + grep-discoverable.
- All work via `Artisan::call` — NO duplicated scan/push logic.
- Phase 3 (re-scan) SKIPPED on phase 2 push failure (planner correction).
- Parity-regression alarm exits 1 (NOT 0).
- `--dry-run` propagation: phase 1 live, phase 2 dry, phase 3 skipped, parity check skipped.
- `--skip-scan` + `--skip-rescan` as operator escape hatches.
- NO chaining hydrate (it stays on its 07:20 cron from 260611-qcq).
- No new config keys.
- `env()` guardrail respected (no env() reads added).
- DivergenceScanner / PushDivergenceToWooCommand / OverridePopulator / HydrateProductStockFromOffersCommand UNTOUCHED.
- No `git stash` or destructive git commands used during verification.

## Post-deploy operator action

1. Deploy all 4 commits.
2. `php artisan cutover:auto-sync --dry-run` — should print phase 1 scan results + phase 2 dry-run plan; phase 3 SKIPPED; no parity-regression alarm.
3. `php artisan schedule:list | grep cutover:auto-sync` — confirm `0 23 *` Europe/London entry post-deploy.
4. Wait for 23:00 London tonight — check `activity_log` for `cutover.auto_sync_completed` row, confirm `parity_after >= parity_before`.
5. Pin a calendar reminder: investigate the next `cutover.auto_sync_parity_regression` audit row — that's the alarm this whole task exists to surface.

## What this task explicitly does NOT do

- **Hydrate is NOT chained.** Lands on its own 07:20 Mon-Fri cron (260611-qcq).
- **No rollback** of pushed changes on parity regression. Detection alarm only.
- **No `--field` validation beyond what PushDivergenceToWooCommand already does.** Unknown fields bail loudly inside the chained command; auto-sync passes through.
- **No retry on transient Woo errors.** PushDivergenceToWooCommand handles per-product errors with a counter; if the whole push exits non-zero, that's a structural failure (auth, DB, etc) — operator investigates the next morning.
- **No new config keys.** Re-uses `config('cutover.parity_threshold_percent', 99)` indirectly via DivergenceScanner's own snapshot write.

## Self-Check

- [x] `app/Console/Commands/Cutover/AutoSyncDivergenceCommand.php` exists (FOUND)
- [x] `tests/Feature/Console/Cutover/AutoSyncDivergenceCommandTest.php` exists (FOUND)
- [x] `app/Providers/AppServiceProvider.php` modified (registered new command)
- [x] `routes/console.php` modified (23:00 London schedule entry added)
- [x] `.planning/STATE.md` modified (row + frontmatter)
- [x] Commit fc3ee90 (feat) exists in git log
- [x] Commit 5bbd98b (chore) exists in git log
- [x] Commit 78d2485 (test) exists in git log
- [x] Final docs commit (Task 6) — pending at write time, made immediately after

## Self-Check: PASSED
