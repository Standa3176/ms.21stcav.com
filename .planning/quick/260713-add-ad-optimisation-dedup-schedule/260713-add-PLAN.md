# 260713-add — De-duplicate scheduled ad_optimisation advice + dial back cadence

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Why:** the 6-hourly scheduled AdOptimisationAgent writes a fresh `ad_optimisation` Suggestion every
run, piling up near-identical pending advice (and spending a Claude call each time) even while prior
advice sits unactioned. Guard the SCHEDULED path + reduce cadence. Leave the manual "Review with Claude"
button always-on (explicit operator intent).

## Scope (minimal, audit-respecting — no deleting/superseding of existing suggestions)
1. **Scheduled command skip-if-pending.** In `agents:run-ad-optimisation` (RunAdOptimisationCommand,
   15b-01), ADD a pre-flight after the existing no-data guard: if a **pending** `ad_optimisation`
   Suggestion already exists, log `ad_optimisation.skip_pending_exists` + exit 0 WITHOUT dispatching
   (no Claude spend, no new row). Rationale: there's already unactioned advice; generating more is noise.
   Once the operator approves/rejects the pending one, the next scheduled run produces fresh advice.
2. **Reduce cadence.** In `routes/console.php`, change the ad-optimisation schedule from `everySixHours()`
   to **daily** (e.g. `->dailyAt('07:00')` London, `withoutOverlapping()` retained). Combined with #1 this
   means at most one new suggestion per day, only when the previous is actioned.

Do NOT touch the manual dashboard action (`MarketingDashboardPage::dispatchReview`) — an operator clicking
"Review with Claude" explicitly wants a fresh review on demand. Do NOT delete/modify existing suggestions
(audit-everything); the operator dismisses the current 3 debugging dupes in the inbox manually.

## Tasks
### Task 1 — Skip-if-pending guard on the scheduled command (TDD)
Add the guard to `RunAdOptimisationCommand`. Order: no-data guard first (existing), then skip-if-pending.
Only PENDING `ad_optimisation` suggestions count (approved/rejected do not block). Test (Bus::fake or
Queue::fake): with a pending `ad_optimisation` Suggestion seeded → command exits 0, dispatches NOTHING,
logs the skip; with none (but GA4 data present) → dispatches exactly one RunAdOptimisationJob; no-data
case still no-ops as before. Confirm the manual dashboard action is unchanged (still dispatches
regardless — a focused assertion or leave its existing test untouched).

### Task 2 — Daily schedule (TDD/verify)
Change the `agents:run-ad-optimisation` schedule to daily in `routes/console.php`. Verify
`php artisan schedule:list` shows the daily cron (e.g. `0 7 * * *`) without error, and the existing
schedule test (if any) is updated to the new cadence.

## Verify
- `pest`: the command guard (skip-if-pending / dispatch-when-none / no-data) + any schedule test — GREEN.
  Wider Agents suite: no regression (esp. the existing RunAdOptimisationCommand + dashboard action tests).
- `php artisan schedule:list` shows the daily entry; `route:list --path=admin` exit 0.
- `pint` pass; `vendor/bin/deptrac analyse` → 0 violations.

## Guardrails / out of scope
- No changes to the agent, tools, mapper, or the manual "Review with Claude" action. No deleting or
  status-changing of existing suggestions. No migration.
- Driver-portable (SQLite tests / MariaDB prod). Do NOT stage the pre-existing working-tree noise
  (`storage/app/research/supplier-probe.json`, `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`,
  untracked `.claude/`).
- PHP/composer via Herd (~/.config/herd/bin/php84/php.exe). No push, no deploy. Atomic commits. Write
  `260713-add-SUMMARY.md` (guard behaviour, new cadence, tests, verify; redeploy = code + config:cache
  rebuild via deploy.sh, no composer/migrate).
