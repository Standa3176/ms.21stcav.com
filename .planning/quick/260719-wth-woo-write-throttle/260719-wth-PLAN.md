# 260719-wth — Woo-write throttle (make live writes safe on the shared box)

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Born from:** 2026-07-19 prod incident — `WOO_WRITE_ENABLED=true` + a burst of concurrent Woo-write jobs
(222 `PushPriceChangeToWoo` + auto-create + retries) saturated the SHARED server's WP php-fpm pool,
spiked load to 55, and took meetingstore.co.uk **down for customers**. `WooClient` currently fires live
writes with no concurrency cap or rate limit; on a box shared with the storefront that's a self-DoS.

## Goal
Add a throttle so the app can NEVER again flood the Woo store with writes: **≤1 concurrent Woo write**,
**paced/rate-limited**, on a **dedicated low-concurrency queue**. Conservative defaults, fully config-
driven, thoroughly tested. **This does NOT re-enable live writes** — `WOO_WRITE_ENABLED` stays `false`;
the throttle is the safety layer that must exist BEFORE writes are ever turned back on. Deploying it
while Horizon is paused / shadow mode is a no-op behaviourally (safe).

## Task 1 — Investigate (map the write surface)
- Read `app/Domain/Sync/Services/WooClient.php` — the `writeOrShadow → writeLive → dispatchWrite` path
  (the shadow gate is at `writeOrShadow`; the live HTTP call is in `dispatchWrite`/`writeLive`). Confirm
  the single chokepoint every live write passes through.
- Enumerate the jobs/listeners that trigger live Woo writes: `PushPriceChangeToWoo` (the incident's
  main offender), `PublishProductJob`, the ProductAutoCreate publishers (WooGtin/Gallery/Brand,
  PushProductFieldsToWoo), supplier `SyncChunkJob` price/stock writes, `products:push-status-to-woo`.
  Note which queues they run on today.
- Read `config/horizon.php` — the existing supervisors + the `sync-bulk` maxProcesses=1 precedent — and
  the `AbortGuard` (keep it; the throttle PREVENTS the storm, AbortGuard is the post-hoc backstop).
- Record findings in the SUMMARY.

## Task 2 — Throttle at the WooClient chokepoint (TDD)
In `WooClient` (the point ALL live writes pass through — so it covers every caller regardless of queue),
gate each live write with, in order:
1. **Serialization lock** — `Cache::lock('woo:write', $lockSeconds)->block($waitSeconds)` so at most ONE
   live Woo write executes at a time across all workers. Release in a `finally`.
2. **Rate limit + pacing** — enforce a configurable ceiling (e.g. Redis-backed token bucket via
   `Illuminate\Support\Facades\RateLimiter` or `Redis::throttle`) AND a minimum interval between writes
   (track last-write timestamp in Redis; `usleep` the remainder — safe because writes are serialized).
   Result: writes go out single-file, no faster than the configured pace.
Config (add to `config/services.php` `woo`):
- `write_max_concurrency` (default 1),
- `write_max_per_minute` (default 60 — conservative for a shared box; env `WOO_WRITE_MAX_PER_MINUTE`),
- `write_min_interval_ms` (default 250; env `WOO_WRITE_MIN_INTERVAL_MS`),
- `write_lock_wait_seconds` (default 30).
Behaviour: only applies on the LIVE path (shadow mode unaffected — no lock/limit when recording a
SyncDiff). Preserve the existing shadow gate + AbortGuard + 429 backoff. Keep it resilient: if the lock
can't be acquired within the wait, throw a retryable exception (so the job requeues) rather than writing
un-serialized.

## Task 3 — Dedicated low-concurrency write queue (TDD)
- Add a `woo-writes` Horizon supervisor in `config/horizon.php` with **maxProcesses=1** (mirror the
  `sync-bulk` precedent), for prod + local.
- Route the live-write jobs/listeners (Task 1 list — at minimum `PushPriceChangeToWoo` + `PublishProductJob`
  + the publishers + supplier write chunks) to `->onQueue('woo-writes')`. This serializes writes at the
  queue level too and — crucially — keeps them OFF the shared worker pool so a write backlog can't starve
  other queues (sync/agents/etc.).
- Belt-and-braces with the Task-2 app-level lock: even if queue config is wrong, the lock still serializes.

## Verify
- `pest`: (a) two concurrent `writeLive` calls do NOT overlap (lock serializes — assert via the lock);
  (b) the rate limiter/pacing delays/limits the Nth write within the window (assert with a faked
  clock/limiter, no real sleeping in tests — inject the interval or assert the limiter is consulted);
  (c) shadow mode (`write_enabled=false`) takes NEITHER lock nor limiter (writes still route to SyncDiff);
  (d) write jobs are dispatched onto `woo-writes`; (e) existing WooClient behaviour (429 backoff, POST-for-
  updates, delete routing) unchanged. Wider Sync/WooClient suites green (no regression).
- `php artisan horizon:list` / config sanity: the `woo-writes` supervisor present with maxProcesses=1.
- `route:list --path=admin` exit 0; `pint`; `deptrac 0`.

## Guardrails / out of scope
- **Do NOT flip `WOO_WRITE_ENABLED`** — this task only ADDS the throttle; re-enabling live writes is a
  separate deliberate step later, after this is deployed + verified and the box proven stable.
- Do NOT change the pricing engine (RuleResolver/PriceCalculator byte-locks), the shadow-gate semantics,
  or the AbortGuard. Additive safety only.
- Conservative defaults (better too slow than another outage). Driver-portable tests (fake Redis/cache;
  no real network, no real sleeps).
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP/composer via Herd (~/.config/herd/bin/php84/php.exe). No push, no deploy. Atomic commits. Write
  `260719-wth-SUMMARY.md` (the write-surface map, the throttle mechanism + config defaults, tests, and an
  explicit note: deploy is behaviourally inert while paused/shadow; **re-enabling writes is a separate
  gated step**; new `woo-writes` supervisor means the prod Horizon/supervisor must pick up the new queue
  on deploy — flag that horizon restart is required).
