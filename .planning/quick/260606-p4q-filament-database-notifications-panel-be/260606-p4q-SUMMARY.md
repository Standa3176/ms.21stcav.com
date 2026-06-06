---
phase: quick-260606-p4q
plan: 01
subsystem: notifications
status: complete
tags: [filament, notifications, autocreate, retry-missing-images, bell-icon, database-channel, queue-driver-redis]
requirements:
  - quick-260606-p4q
provides:
  - filament-database-notifications-bell-icon
  - operator-job-completed-notification-generic
  - autocreate-pipeline-completion-signal
  - retry-missing-images-cli-completion-signal
requires:
  - laravel-12-notifications-table
  - filament-3-database-notifications-api
  - user-model-notifiable-trait
affects:
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php
  - app/Console/Commands/RetryMissingImagesCommand.php
tech_stack_added: []
key_files_created:
  - database/migrations/2026_06_06_171032_create_notifications_table.php
  - app/Notifications/OperatorJobCompletedNotification.php
  - tests/Unit/Notifications/OperatorJobCompletedNotificationTest.php
key_files_modified:
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php
  - app/Console/Commands/RetryMissingImagesCommand.php
commits:
  - 995ca67: feat(notifications) publish notifications table migration
  - 9a80993: feat(admin) enable Filament database notifications + 30s polling
  - 3637a18: feat(notifications) OperatorJobCompletedNotification (database channel)
  - 62c49ab: feat(autocreate) notify triggering user when pipeline finishes
  - 2bc8563: feat(retry-missing-images) notify triggering user on completion
decisions:
  - Generic reusable notification (no per-caller subclass) — future ops commands wire `$user->notify(new OperatorJobCompletedNotification(...))` directly
  - ShouldQueue + database channel — persistence runs on Horizon, not on the dispatching job
  - try/catch on every notify() call — missing notifications table on prod (pre-migrate) MUST NOT break pipeline/command exit code
  - 30s polling — Filament-recommended default; T-p4q-03 accepted (one query per authed user)
  - RetryMissingImagesCommand notifies on LIVE SUCCESS path ONLY (not dry-run, not failure, not cron) — `$triggeringUser = auth()->user()` at perform() start gives null for cron → silent skip
  - Idempotent success-count: `Product::whereIn('sku', $skus)->count()` — re-runs of the same SKU set still count as success
metrics:
  duration: ~25 min
  tasks_completed: 6
  files_changed: 6
  commits_landed: 5
  pest_baseline_delta: "+7 passed / 0 new fails"
completed_date: 2026-06-06
---

# Quick 260606-p4q: Filament Database Notifications + Pipeline Wiring — Summary

One-liner: Bell-icon database notifications on the Filament admin chrome + generic `OperatorJobCompletedNotification` wired to `RunAutoCreatePipelineJob` (success + failed) and `RetryMissingImagesCommand` (CLI-only) so long-running ops no longer require operators to tail `/horizon`.

## Per-task outcomes

| Task | Commit | Outcome |
| ---- | ------ | ------- |
| 1. Publish + migrate notifications table | `995ca67` | `php artisan notifications:table` worked (Laravel 12 alias to `make:notifications-table`); migration `2026_06_06_171032_create_notifications_table.php` created with canonical shape (UUID PK, polymorphic `notifiable`, `text data`, nullable `read_at`, timestamps). Local migrate applied cleanly. |
| 2. Enable Filament database notifications + polling | `9a80993` | `->databaseNotifications()` + `->databaseNotificationsPolling('30s')` chained on `AdminPanelProvider::panel()` between `->plugins()` and `->authMiddleware()`. `php artisan filament:cache-components` runs clean — no syntax error, no panel discovery break. |
| 3. OperatorJobCompletedNotification + Pest unit test | `3637a18` | TDD: 7 tests went RED (class missing) → wrote class → all 7 GREEN (12 assertions, 4.85s). Implements `ShouldQueue`, uses `Queueable` trait, `via()` returns `['database']`, `toDatabase()` returns exactly `[title, body, level, url, icon]`, `iconForLevel()` match handles success/danger/warning/info + default → `heroicon-o-bell`. |
| 4. Wire RunAutoCreatePipelineJob (success + failed) | `62c49ab` | `handle()` captures `$exitCode`, computes `$successCount` via `Product::whereIn('sku', $skus)->count()`, dispatches success (level=`success` if all SKUs landed else `warning`) inside try/catch, returns `$exitCode` unchanged. New `failed()` method dispatches `danger` level with `class_basename($e).': '.Str::limit($e->getMessage(), 200)` inside its own try/catch. URL deep-link routes to `/admin/products` (auto-published) or `/admin/auto-create-reviews`. |
| 5. Wire RetryMissingImagesCommand (CLI-only) | `2bc8563` | `private ?User $triggeringUser = null;` property declared; captured at very first line of `perform()` via `auth()->user()` (before any nested `Artisan::call` chain). Notification dispatched only on LIVE SUCCESS path (after the "Done. {N} processed" line), level=`success`, URL=`/admin/auto-create-health`. Dry-run, failure, and cron paths intentionally skip (cron has null auth user → block bypassed silently). |
| 6. Verification (no commit) | — | Focused test 7/7 GREEN; `EnvUsageTest` 3/3 GREEN; `AutoCreatedPredicateTest` 2/2 GREEN; migration `Ran`; full suite +7 / 0 new failures; tinker probe PASS (see below). |

## Migration

- File: `database/migrations/2026_06_06_171032_create_notifications_table.php`
- Generator: `php artisan notifications:table` worked first try (Laravel 12 retains the command — exposed as alias for `make:notifications-table`).
- Local DB: `migrate:status` shows the migration as `Ran` (batch 14).
- Fallback path NOT exercised (the artisan generator produced the canonical shape).

## Pest results

| Run | Before (260606-o63 baseline) | After (this quick) | Delta |
| --- | ---------------------------- | ------------------ | ----- |
| Focused: `tests/Unit/Notifications/OperatorJobCompletedNotificationTest.php` | — (file did not exist) | **7 passed / 12 assertions / 4.85s** | +7 new tests |
| Architectural regressions: `EnvUsage` + `AutoCreatedPredicate` | 3 + 2 passed | 3 + 2 passed | unchanged |
| Full suite | **1819 passed / 219 failed / 3 skipped** | **1826 passed / 219 failed / 3 skipped** (9289 assertions, 1092.81s) | **+7 passed / 0 new failures / 0 skipped change** |

The +7 in the full-suite pass count matches exactly the 7 new tests added in the OperatorJobCompletedNotificationTest file. **Zero new failures introduced.** Pre-existing 219 failures match the 260606-o63 baseline (test-infra rot tracked as a separate milestone per CLAUDE.md).

## Tinker probe result

PASS.

Initial probe returned `MISSING_ROW` because the queue driver in dev is `redis` (not `sync`) — the ShouldQueue notification was correctly enqueued but the row had not yet been persisted. Drained one job with `php artisan queue:work --once --stop-when-empty` (1 job processed, 290ms), then re-probed.

Final row `data` column output:

```json
{"title":"probe","body":"tinker probe","level":"info","url":"\/admin","icon":"heroicon-o-information-circle"}
```

All 5 contract keys present (`title`, `body`, `level`, `url`, `icon`); `level: info` correctly mapped to `heroicon-o-information-circle` by `iconForLevel()`.

**Prod implication:** prod already runs Horizon supervisors on the same `default` Redis queue, so notifications enqueued by `RunAutoCreatePipelineJob::handle()` / `RetryMissingImagesCommand::perform()` will drain within the normal worker poll interval. No additional supervisor config needed.

## Prod deploy steps

```bash
# On prod (ms.21stcav.com under per-user stcav account — see memory:meetingstore-prod-hosting):
cd /home/stcav/public_html/meetingstore-ops-app   # or whatever the symlink-targeted .git path resolves to
git pull                                          # pulls main with 5 new commits + docs commit
php artisan migrate --force                       # CRITICAL — creates notifications table BEFORE first pipeline fires
php artisan filament:cache-components             # rebuild panel component cache for bell-icon UI
php artisan optimize:clear && php artisan optimize  # rebuild route/config/event cache
# Horizon supervisor restart (deploy.sh already does this; included for completeness):
# php artisan horizon:terminate
```

**MUST happen in this order.** Without `migrate --force` before the next operator triggers the auto-create pipeline or `retry-missing-images`, the `try/catch` in `RunAutoCreatePipelineJob::handle()` and `RetryMissingImagesCommand::perform()` will catch + `Log::warning('auto_create_pipeline.notify_failed', …)` — the pipeline/command still completes and the operator still gets the artisan-output completion line, but no bell-icon badge will appear until prod runs the migration.

`filament:cache-components` is the same step the `feat(admin)` Task-2 commit's success criteria verified locally — it ensures the panel's component metadata is rebuilt so the bell-icon dropdown renders correctly on first /admin load.

## Deviations from plan

**None.** Plan executed exactly as written.

Notes:

1. The tinker probe needed an extra `queue:work --once` step in the local environment because the dev queue driver is `redis` (matches prod). This was anticipated by the plan ("if the queue is not draining locally, run `php artisan queue:work --once` and re-probe") so it is not a deviation — just documenting the actual path taken.
2. `notifications:table` is exposed in Laravel 12 as an alias for `make:notifications-table` (both surface under `php artisan list`). The fallback path documented in Task 1 (hand-write the migration) was not needed.
3. No existing Pest test exists for `RunAutoCreatePipelineJob` — the `--filter=RunAutoCreatePipeline` verification step from Task 4 returned `No tests found.` (already flagged in the plan's `<done>` section as acceptable). Future coverage gap for a separate quick if desired.

## Authentication gates

None encountered. All work was local file edits + local DB migration + local Pest. No external auth surfaces (no Anthropic, no Woo, no Bitrix) touched.

## Known stubs

None. All payloads, URLs, and icons are hard-coded literals (T-p4q-01 mitigation) — no user input flows into notification data.

## Future-caller hint

`OperatorJobCompletedNotification` is intentionally generic. Future ops commands can adopt it without subclassing:

```php
use App\Notifications\OperatorJobCompletedNotification;
// inside a command's perform() or a job's handle(), after capturing the user:
$user->notify(new OperatorJobCompletedNotification(
    title: 'PruneOrphanSuggestions complete',
    body: "{$pruned} orphans rejected",
    level: 'success',                  // or 'warning' if --dry-run produced no actual prunes
    url: '/admin/suggestions',
));
```

Candidates flagged in the plan: `PruneOrphanSuggestionsCommand`, `products:resync-to-woo`, and any other long-running ops command where the operator currently has to refresh `/horizon` to confirm completion.

## Self-Check: PASSED

- Files created: `database/migrations/2026_06_06_171032_create_notifications_table.php` FOUND; `app/Notifications/OperatorJobCompletedNotification.php` FOUND; `tests/Unit/Notifications/OperatorJobCompletedNotificationTest.php` FOUND.
- Files modified: `app/Providers/Filament/AdminPanelProvider.php` (chained calls present); `app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php` (handle + failed wired); `app/Console/Commands/RetryMissingImagesCommand.php` (triggeringUser + notify present).
- Commits: 995ca67 FOUND; 9a80993 FOUND; 3637a18 FOUND; 62c49ab FOUND; 2bc8563 FOUND.
- Migration: `Ran` per `php artisan migrate:status`.
- Tinker probe: row landed with 5-key payload (after queue:work drain).
- Pest delta: +7 passed / 0 new failures vs baseline.
