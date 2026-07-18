# 260719-wth — Woo-write throttle — SUMMARY

**One-liner:** Added a two-layer safety throttle so the app can never again flood the SHARED
box's WooCommerce store with writes — an app-level `woo:write` serialization lock + rate/pace
at the `WooClient` chokepoint (covers ALL callers), plus a dedicated single-worker `woo-writes`
Horizon queue that keeps live writes off the shared worker pool.

**Status:** COMPLETE. `WOO_WRITE_ENABLED` untouched (stays `false`). Behaviourally inert while
paused / in shadow mode. Re-enabling live writes remains a separate, deliberate, later step.

---

## Task 1 — Write-surface map (investigation)

**The chokepoint (confirmed single point):** every write verb on `WooClient`
(`put`/`post`/`patch`/`delete`) funnels through **`writeOrShadow()`**:

```
put/post/patch/delete → writeOrShadow()
                          ├─ write_enabled=false → recordDiff()  (SyncDiff row, NO external call)  ← shadow gate (FOUND-08)
                          └─ write_enabled=true  → writeLive() → dispatchWrite() → Automattic SDK   ← the ONLY live HTTP write path
```

`writeLive()` already owns the 429 exponential backoff (500/1500/4500/13500ms cap 30s + jitter,
`RateLimitExceededException` after 5 tries). `dispatchWrite()` maps verbs to the SDK (POST-for-updates
and POST+`?_method=DELETE` WAF tunnels preserved). Because **every** live write passes through
`writeLive()`, gating there covers every caller regardless of the queue it runs on.

**Live-write jobs / listeners / commands (who calls WooClient writes):**

| Caller | Type | Old queue | New queue | Notes |
|---|---|---|---|---|
| `PushPriceChangeToWoo` | queued listener | `sync-woo-push` (`viaQueue()`) | **`woo-writes`** | The incident's main offender (222 concurrent price pushes). |
| `PublishProductJob` | queued job | `sync-woo-push` (`onQueue`) | **`woo-writes`** | Auto-create publish; invokes the WooGtin/Gallery/Brand publishers **synchronously** (they inherit this queue). |
| `PushProductFieldsToWoo` | queued listener | `sync-woo-push` (`$queue`) | **`woo-writes`** | Auto-create field pushes via `WooProductWriter`. |
| `CreateWooProductJob` | queued job | `sync-woo-push` (`onQueue`) | **`woo-writes`** | Creates the product on Woo (live POST). |
| `SyncChunkJob` | queued job | `sync-woo-push` (`onQueue`) | **`woo-writes`** | Supplier price/stock chunk writes. |
| `products:push-status-to-woo` | sync CLI command | n/a (runs in the console process) | n/a | Not queued — covered by the **app-level lock** (Task 2), not the queue routing. |

**Publishers** (`WooGtinPublisher`, `WooGalleryPublisher`, `WooBrandPublisher`, `WooProductWriter`)
are **services**, not queued jobs — they run inside the two auto-create jobs/listeners above, so
routing those parents to `woo-writes` covers the publishers.

**`config/horizon.php` precedent:** `sync-bulk-supervisor` (`balance=simple`, `minProcesses=1`,
`maxProcesses=1`, `tries=2`, `timeout=1800`, `memory=512`) — the single-worker template mirrored
for `woo-writes-supervisor`.

**`AbortGuard`** (DB-backed tiered abort: 20% error-rate / 50 consecutive failures / JWT-broken)
is the **post-hoc backstop** and is left entirely unchanged — the throttle *prevents* the storm;
AbortGuard trips *after* one is already underway.

---

## Task 2 — Throttle at the WooClient chokepoint

`writeOrShadow()`'s live branch now calls **`throttledWriteLive()`** instead of `writeLive()` directly.
Shadow branch is untouched (returns `recordDiff()` before any throttle).

**Admission sequence for a LIVE write:**

1. **Serialization lock** — `Cache::lock('woo:write', $lockSeconds)->block($waitSeconds)`. Guarantees
   **≤1 concurrent live write across all workers/queues**. Released in a `finally`. If it can't be
   acquired within `write_lock_wait_seconds`, throws **`WooWriteThrottleException`** (retryable →
   queued jobs requeue; the sync command records a per-row error) rather than writing un-serialised.
2. **Rate ceiling** — `RateLimiter::tooManyAttempts('woo:write', $max)` then `hit(..., 60)`. Over the
   per-minute ceiling → retryable `WooWriteThrottleException` (requeue, don't pile on).
3. **Min-interval pacing** — reads `woo:write:last_ts` from cache; sleeps the remainder of
   `write_min_interval_ms` (via the existing `sleepMicros()` seam), then records the new timestamp.
   Safe to sleep because we hold the lock (single-file).

Then delegates to the **unchanged** `writeLive()` — 429 backoff / POST-for-updates / delete tunnel /
audit logging all intact.

**Config (`config/services.php` → `services.woo.*`):**

| Key | Default | Env | Purpose |
|---|---|---|---|
| `write_max_concurrency` | `1` | `WOO_WRITE_MAX_CONCURRENCY` | Documented target; the `woo:write` lock enforces ≤1 regardless. |
| `write_max_per_minute` | `60` | `WOO_WRITE_MAX_PER_MINUTE` | Hard per-minute ceiling (conservative for a shared box). |
| `write_min_interval_ms` | `250` | `WOO_WRITE_MIN_INTERVAL_MS` | Min spacing between writes (≤240/min even before the ceiling). |
| `write_lock_wait_seconds` | `30` | `WOO_WRITE_LOCK_WAIT_SECONDS` | How long to block for the lock before requeueing. |
| `write_lock_seconds` | `120` | `WOO_WRITE_LOCK_SECONDS` | Lock hold TTL (auto-release if a worker dies mid-write). Added on top of the 4 named-in-plan keys — a lock TTL is required by `Cache::lock($ttl)`. |

New exception: `App\Domain\Sync\Exceptions\WooWriteThrottleException` (retryable, extends `RuntimeException`).

---

## Task 3 — Dedicated low-concurrency `woo-writes` queue

- **New `woo-writes-supervisor`** in `config/horizon.php` production env: `balance=simple`,
  `minProcesses=1`, **`maxProcesses=1`**, `tries=2`, `timeout=1800`, `memory=512` (mirrors
  `sync-bulk-supervisor`). Production supervisor count 8 → **9**.
- **Local `all-in-one`** supervisor queue list gains `woo-writes`.
- **`waits`** gains `redis:woo-writes => 1800` (matches sync-bulk; a paced backlog is expected).
- All 5 live-write jobs/listeners re-pointed from `sync-woo-push` to `woo-writes` (see the map above).

**Why both layers:** the queue keeps writes off the shared `sync-woo-push` pool so a write backlog
can't starve sync/crm/agents; the Task-2 app-level lock is belt-and-braces — even if a future job
lands on the wrong queue, the lock still serialises it.

**Queue-routing coverage:** YES — all five enumerated live-write jobs/listeners now dispatch onto
`woo-writes`. The one non-queued caller (`products:push-status-to-woo`, a sync CLI command) is
covered by the app-level lock instead of the queue.

---

## Deviations from Plan

### Auto-fixed / adjustments

**1. [Rule 3 — blocking] Added `write_lock_seconds` config (5th key).**
- The plan named 4 config keys but `Cache::lock($name, $ttl)` requires a hold TTL. Added
  `write_lock_seconds` (default 120s, comfortably exceeds a worst-case `writeLive()` 429 backoff
  chain) so a crashed worker can't hold the lock forever. Purely additive, documented in config.
- **Files:** `config/services.php`. **Commit:** `4d7ede4`.

**2. [Rule 1 — test alignment] Updated pre-existing queue/supervisor assertions.**
- Re-routing the 5 jobs to `woo-writes` broke tests that hard-asserted `sync-woo-push`
  (`SyncChunkJobTest`, `PublishProductJobTest`, `CreateWooProductJobTest`,
  `PushProductFieldsToWooTest`) and the supervisor-count/queue-coverage assertions in
  `HorizonSupervisorTest` (8→9 supervisors, added `woo-writes`). These are directly caused by this
  task's intended change; updated to the new queue name + added a woo-writes single-worker assertion.
- **Commit:** `73a40d1`.

**3. [Behavioural note, by design] `SyncChunkJob` supplier sync is now single-file.**
- Moving `SyncChunkJob` from `sync-woo-push` (maxProcesses 2–3) to `woo-writes` (maxProcesses 1)
  means supplier sync chunks run one-at-a-time. In **live** mode this is correct/necessary (all
  writes serialise on the lock anyway — running 3 workers would just thrash on lock contention).
  In **shadow** mode (current state) it makes the parity/diff sync single-worker and therefore
  slower — an accepted, safety-first throughput trade-off explicitly in scope per the task brief
  ("conservative defaults, better too slow than another outage").

No other deviations. `WOO_WRITE_ENABLED` NOT flipped. Pricing engine (RuleResolver/PriceCalculator),
shadow-gate semantics, and AbortGuard all unchanged.

---

## Verification

| Check | Result |
|---|---|
| `pest` — throttle (lock serializes / rate consulted / ceiling refused / min-interval paced / shadow bypasses both) | 7/7 PASS |
| `pest` — queue routing (5 jobs → woo-writes) + horizon supervisor sanity | 7/7 PASS |
| `pest` — WooClient/Sync regression (WooRateLimit, ShadowMode, Delete, Get, SyncChunk, SyncChunkFailure, AbortGuard, MissingSku) | 57/57 PASS |
| `pest` — Pricing + ProductAutoCreate suites (regression) | 333/333 PASS |
| Horizon config sanity — `woo-writes-supervisor` present, `queue=["woo-writes"]`, `maxProcesses=1` | PASS |
| `route:list --path=admin` | exit 0 |
| `pint --test` (changed files) | pass |
| `deptrac analyse` | 0 violations, 0 errors |

Tests use faked cache/RateLimiter (array store, `QUEUE_CONNECTION=sync`) — NO real network, NO real
sleeps (pacing asserted via the captured `sleepMicros()` seam). Driver-portable.

---

## DEPLOY FLAG (read before deploying)

- **Behaviourally inert while paused / shadow.** `WOO_WRITE_ENABLED=false` → every write records a
  `SyncDiff` and takes NEITHER the lock NOR the limiter. Deploying this changes nothing observable
  until live writes are re-enabled (a separate, later, gated step).
- **HORIZON RESTART REQUIRED.** This adds a new `woo-writes` queue + `woo-writes-supervisor`.
  The prod Horizon / Supervisor process must be restarted (`php artisan horizon:terminate` and let
  Supervisor respawn) so the new supervisor is picked up. Until then, jobs dispatched to `woo-writes`
  will **queue but not process**. Since `WOO_WRITE_ENABLED=false`, the currently-dispatched work is
  shadow SyncDiff recording (via `SyncChunkJob` etc.) — those will also route to `woo-writes` on
  deploy, so **restart Horizon promptly** to avoid a shadow-sync backlog.
- **Re-enabling writes is out of scope** for this task and must not be done as part of this deploy.

---

## Commits

- `b4ba173` test(260719-wth): failing tests for the live-write throttle (RED)
- `4d7ede4` feat(260719-wth): throttle live Woo writes at the WooClient chokepoint (GREEN)
- `d272a73` test(260719-wth): failing tests for woo-writes queue routing (RED)
- `73a40d1` feat(260719-wth): dedicated woo-writes queue + route live-write jobs (GREEN)
- `a51fd93` style(260719-wth): pint formatting on WooClient throttle additions

## Self-Check: PASSED
- `app/Domain/Sync/Exceptions/WooWriteThrottleException.php` — FOUND
- `app/Domain/Sync/Services/WooClient.php` (throttledWriteLive/throttlePace) — FOUND
- `config/services.php` (write_* keys) — FOUND
- `config/horizon.php` (woo-writes-supervisor) — FOUND
- `tests/Feature/WooWriteThrottleTest.php` — FOUND
- `tests/Feature/WooWriteQueueRoutingTest.php` — FOUND
- Commits b4ba173, 4d7ede4, d272a73, 73a40d1, a51fd93 — all present in `git log`
