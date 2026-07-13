# Phase quick-260713-add: De-duplicate scheduled ad_optimisation advice + dial back cadence — Summary

**One-liner:** Stopped the scheduled `AdOptimisationAgent` piling up near-identical pending advice by adding a skip-if-pending pre-flight to `agents:run-ad-optimisation` (no Claude spend, no new row when unactioned advice already exists) and reducing its cadence from every-6-hours to daily 07:00 London — the manual "Review with Claude" dashboard action stays always-on.

## What changed

### Task 1 — Skip-if-pending guard on the scheduled command (TDD)
- `app/Domain/Agents/Console/Commands/RunAdOptimisationCommand.php`: after the existing no-data guard, added a pre-flight — if a **pending** `ad_optimisation` Suggestion already exists, log `ad_optimisation.skip_pending_exists`, print an operator hint, and `return SUCCESS` **without** dispatching `RunAdOptimisationJob`. No LLM call, no new row.
  - Only `status = pending` blocks; `approved`/`rejected` (and other kinds) do **not**.
  - Driver-portable: plain `kind` + `status` column predicate via `->exists()` — no JSON expressions (SQLite tests / MariaDB prod agree).
  - Guard is scheduled-path-only. `MarketingDashboardPage::dispatchReview` ("Review with Claude") was left untouched and still dispatches on explicit operator intent.

### Task 2 — Daily cadence (TDD/verify)
- `routes/console.php`: changed the `agents:run-ad-optimisation` schedule from `->everySixHours()` to `->dailyAt('07:00')` (Europe/London). `withoutOverlapping()`, `onOneServer()`, `timezone('Europe/London')` and the `config('agents.ad_optimisation_schedule_enabled')` gate all retained. Comment block updated to explain the dedup rationale.
- Combined with Task 1: at most one new suggestion per day, and only once the previous is actioned.

## Guard behaviour confirmation
- **skip-when-pending:** GA4 data present + a pending `ad_optimisation` Suggestion seeded → exit 0, `Queue::assertNothingPushed()`. TESTED (green).
- **dispatch-when-none:** GA4 data present + no pending `ad_optimisation` → dispatches exactly one `RunAdOptimisationJob`. TESTED (green).
- **no-data still no-ops:** zero recent GA4 rows → exit 0, nothing dispatched. TESTED (green, pre-existing).
- **non-blocking states:** approved / rejected / different-kind pending suggestions do NOT block dispatch. TESTED (green).
- **manual action unaffected:** `MarketingReviewWithClaudeActionTest` — still dispatches regardless. GREEN (untouched).

## New cadence
- Was: `everySixHours()` (`0 */6 * * *`).
- Now: `dailyAt('07:00')` Europe/London — canonical cron `0 7 * * *` (asserted via the Schedule facade). `schedule:list` renders it as `0 6 * * *` during BST because it prints in server/UTC time — same TZ-rendering caveat documented in the SEO `ScheduleWiringTest`.

## Tests
- Extended `tests/Feature/Agents/Marketing/RunAdOptimisationCommandTest.php` with skip-if-pending + non-blocking (approved/rejected/other-kind) cases.
- New `tests/Feature/Agents/Marketing/AdOptimisationScheduleTest.php` pinning the daily cron via the Schedule facade + `routes/console.php` source literal.
- Marketing agents suite + manual dashboard action test: **50 passed (156 assertions)** — no regression.

## Verify results
- `pest` (guard cases + schedule + wider Agents/Marketing suite + MarketingReviewWithClaudeActionTest): **50 passed**.
- `php artisan schedule:list`: shows the ad-optimisation daily entry (`0 6 * * *` UTC = 07:00 London BST).
- `php artisan route:list --path=admin`: exit **0**.
- `php artisan vendor/bin/pint`: **pass** (touched files formatted).
- `vendor/bin/deptrac analyse`: **0 errors, 0 warnings**.

## Deviations from Plan
None — plan executed exactly as written. No architectural changes, no migration, no suggestion status changes.

## Out of scope / untouched (per guardrails)
- Agent, tools, mapper, and `MarketingDashboardPage::dispatchReview` unchanged.
- No existing suggestions deleted or status-changed (the operator dismisses the 3 debugging dupes manually).
- Pre-existing working-tree noise NOT staged: `storage/app/research/supplier-probe.json` (deletion), `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php` (modification), untracked `.claude/`.

## Redeploy note
Code + config change only. Redeploy = pull + `config:cache` rebuild via `deploy.sh`. No `composer install`, no migration.

## Commits
- `9e65be8` test(260713-add): failing skip-if-pending guard cases
- `b056b7e` feat(260713-add): skip-if-pending guard on scheduled command
- `7222d6f` test(260713-add): failing daily-cadence schedule assertions
- `64b396e` feat(260713-add): dial schedule everySixHours → daily 07:00 London
- `62b6157` style(260713-add): pint single-quote fix in schedule test

## Self-Check: PASSED
- `app/Domain/Agents/Console/Commands/RunAdOptimisationCommand.php` — FOUND (guard present)
- `routes/console.php` — FOUND (`dailyAt('07:00')`)
- `tests/Feature/Agents/Marketing/RunAdOptimisationCommandTest.php` — FOUND
- `tests/Feature/Agents/Marketing/AdOptimisationScheduleTest.php` — FOUND
- Commits 9e65be8, b056b7e, 7222d6f, 64b396e, 62b6157 — all present in `git log`.
