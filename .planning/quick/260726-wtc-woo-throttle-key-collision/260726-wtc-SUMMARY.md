# 260726-wtc — Fix Woo-write throttle cache-key collision — Summary

**One-liner:** Gave the Woo-write RateLimiter its own cache key (`woo-write-rate`),
distinct from the `Cache::lock('woo:write')` name, ending the Redis-only
`unserialize(): Error at offset 0 of 16 bytes` that killed every live Woo write.

**Type:** GSD quick task (BLOCKER prod fix). Executor did NOT push/deploy.
**Branch:** `main` · **Completed:** 2026-07-26

## Root cause (confirmed live on prod 2026-07-26)

`app/Domain/Sync/Services/WooClient.php` (260719-wth throttle) used the SAME cache
key `woo:write` for two incompatible Redis structures:

- `throttledWriteLive()`: `Cache::lock('woo:write', …)` writes a RAW ~16-byte lock
  **owner token** at key `woo:write` (locks are not serialized).
- `throttlePace()`: `RateLimiter::tooManyAttempts/availableIn/hit('woo:write', …)`
  reads key `woo:write` expecting a **serialized numeric attempts counter**, hits the
  lock's raw owner token, and `unserialize()`s it → fatal.

Ordering guaranteed it fired on the very first write: the lock is acquired (owner
token written) BEFORE `throttlePace()` reads the limiter. Discovered during a
supervised `brands:retag-products-on-woo --limit=25` canary → 25/25 errors, 0
retagged (shop unharmed; `WOO_WRITE_ENABLED` returned to false).

## Fix (surgical — no behaviour change beyond the key)

- Added `private const WRITE_RATE_LIMITER_KEY = 'woo-write-rate';` on `WooClient`,
  with a docblock naming the 2026-07-26 collision.
- Replaced the three `RateLimiter::…('woo:write', …)` calls in `throttlePace()`
  with `self::WRITE_RATE_LIMITER_KEY`.
- Left the lock name `'woo:write'` and pacing key `'woo:write:last_ts'` unchanged.
  Added a collision-warning comment at both the lock and the limiter so the keys
  are never re-unified.

## Final four-key map (all mutually distinct at the bare-key level)

| Purpose        | Key                    | Structure                     |
| -------------- | ---------------------- | ----------------------------- |
| Serialization lock | `woo:write`        | raw lock owner token          |
| Rate limiter   | `woo-write-rate`       | serialized attempts counter   |
| Limiter timer  | `woo-write-rate:timer` | limiter window reset ts       |
| Min-interval pacing | `woo:write:last_ts` | last-write microtimestamp     |

None is an exact-key collision of another. (`woo:write` is a lexical prefix of
`woo:write:last_ts`, but Redis GET/SET is exact-key, not prefix-based — no collision.)

## Tests (extended `tests/Feature/WooWriteThrottleTest.php`)

- **Regression guard (d):** reflects on `WRITE_RATE_LIMITER_KEY`, asserts it is NOT
  `'woo:write'` and IS `'woo-write-rate'`, plus asserts all four keys are mutually
  distinct. This is the invariant whose violation caused the bug.
- **Behavioural (e):** seeds a raw 16-byte owner token at cache key `woo:write`, runs
  a live write, and asserts the write completes (SDK reached, returns `['id'=>1234]`),
  the limiter incremented its OWN key (`woo-write-rate` attempts = 1), and the lock
  key's raw token is left untouched.
- Updated the three existing assertions that referenced the old shared `woo:write`
  limiter key to the new `woo-write-rate` key. All pre-existing throttle behaviour
  preserved green: lock-held requeue, rate-ceiling throw over `write_max_per_minute`,
  min-interval pacing via `woo:write:last_ts`, shadow-mode bypass.

**Why tests can't reproduce the raw-token unserialize:** the fatal is **Redis-only**.
The array test store neither serializes cache values nor stores locks at the cache
key (locks live in a separate `locks` array; the db store keeps locks in a separate
`cache_locks` table). This is the cache-layer twin of the known SQLite↔MariaDB prod
trap. **The true integration proof is the prod re-canary** (`brands:retag-products-on-woo`
with `WOO_WRITE_ENABLED=true`).

## Guard-test negative check (performed)

Temporarily re-unified the constant to `'woo:write'` and ran the guard test → it
FAILED at the `->not->toBe('woo:write')` assertion, then reverted. Confirms the guard
fails if a future edit re-unifies the keys.

## Verification

- **pest** `tests/Feature/WooWriteThrottleTest.php`: **9 passed (31 assertions)**.
- **pint** `WooClient.php` + `WooWriteThrottleTest.php`: `{"result":"pass"}`.
- **deptrac analyse**: **0 violations** (0 skipped, 0 errors; deprecation lines are
  pre-existing deptrac-shim phar noise, unrelated).
- `php artisan config:clear`: OK.
- PHP: Herd php84 (PHP 8.4.22).

## Deviations from Plan

**1. [Rule 1 — faithful-to-intent adjustment] Behavioural test seeds the lock key's
   raw token rather than literally holding the lock.**
- The plan wording said "the `woo:write` lock PRE-ACQUIRED in the test". A literally
  held lock makes the client's own `->block()` time out → `WooWriteThrottleException`
  (that is exactly existing test (a)), so the write could never complete.
- The real collision is between the client's OWN lock and the limiter at the same key.
  The faithful reproduction is a raw owner-token value parked at cache key `woo:write`
  while the limiter runs — so the test seeds `Cache::put('woo:write', Str::random(16))`
  and asserts the limiter never touches it. Proves the exact invariant the plan wants.

## Out of scope / left unstaged (per plan guardrails)

- `storage/app/research/supplier-probe.json` (deleted) — left unstaged.
- `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php` (modified) — left unstaged.
- untracked `.claude/` — left unstaged.

No lock-name change, no throttle-semantics change, no `WOO_WRITE_ENABLED` change, no
migration, no other files/commands touched. No push, no deploy.

## Known Stubs

None.
