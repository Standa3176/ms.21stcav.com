# 260726-wtc — Fix Woo-write throttle cache-key collision (unserialize error on every live write)

**Type:** GSD quick task (TDD-ish, atomic commits). Executor does NOT push/deploy.
**Severity:** BLOCKER for cutover. With `WOO_WRITE_ENABLED=true` on prod (Redis cache), EVERY live Woo
write throws `unserialize(): Error at offset 0 of 16 bytes` and writes nothing. Discovered 2026-07-26
during a supervised brand-retag canary (`brands:retag-products-on-woo --limit=25` → 25/25 errors, 0
retagged; shop unharmed, flag returned to false).

## Root cause (confirmed)
In `app/Domain/Sync/Services/WooClient.php` the 260719-wth throttle uses the SAME cache key `woo:write`
for two different Redis structures:
- `throttledWriteLive()` L239: `Cache::lock('woo:write', ...)` — stores a RAW ~16-byte lock-owner token
  at key `woo:write` (locks are not serialized).
- `throttlePace()` L273/274/282: `RateLimiter::tooManyAttempts('woo:write', ...)` /
  `RateLimiter::availableIn('woo:write')` / `RateLimiter::hit('woo:write', 60)` — the limiter reads key
  `woo:write` expecting a serialized/numeric attempts counter, hits the lock's raw owner token, and
  `unserialize()`s it → fatal. Order guarantees it: the lock is acquired (owner written) BEFORE
  throttlePace reads the limiter, so it fires on the very first write.

Redis-specific: the array cache store (tests) doesn't serialize, and the database store keeps locks in a
separate `cache_locks` table, so neither reproduces it — this is the cache-layer twin of the known
SQLite↔MariaDB prod trap. It stayed hidden because the live throttle path had never actually run (shadow
since 260719).

## Fix (surgical, no behaviour change beyond the key)
- Introduce `private const WRITE_RATE_LIMITER_KEY = 'woo-write-rate';` on `WooClient`.
- Replace the three `RateLimiter::…('woo:write', …)` calls in `throttlePace()` with the new constant.
- LEAVE the lock name `'woo:write'` (L239, exception text, docblocks) and the pacing key
  `'woo:write:last_ts'` UNCHANGED — verify all four resulting keys are mutually distinct:
  lock `woo:write` · limiter `woo-write-rate` · limiter-timer `woo-write-rate:timer` · pacing
  `woo:write:last_ts`. None is a prefix-collision of another at the bare-key level.
- Add a short comment at the lock + at the limiter naming the 2026-07-26 collision so no future edit
  re-unifies the keys.

## Verify
- `pest`: extend the existing WooClient/throttle test.
  - **Guard/regression test:** assert (via reflection on the constant + the lock name literal) that the
    rate-limiter key is NOT equal to the lock name `'woo:write'` — this is the invariant whose violation
    caused the bug; it must fail if someone re-unifies them.
  - Behavioural: with `services.woo.write_enabled=true`, a stubbed inner SDK, and the `woo:write` lock
    PRE-ACQUIRED by the test (simulating "lock held"), a live write still completes throttlePace without
    error and performs the write (proving the limiter no longer reads the lock key). Use the default test
    cache store; document that the *raw-token unserialize* is Redis-only and the true integration proof is
    the prod re-canary.
  - Preserve existing throttle behaviour: rate-ceiling throw when over `write_max_per_minute`, min-interval
    pacing via `woo:write:last_ts`, lock-timeout requeue — all still green.
- `php artisan config:clear` sanity; `pint`; `vendor/bin/deptrac analyse` → 0 violations.

## Guardrails / out of scope
- Do NOT change the lock name, the throttle semantics, `WOO_WRITE_ENABLED`, or any other command. No
  migration. All Woo writes still via WooClient. Driver-portable.
- Do NOT stage pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP/composer via Herd (~/.config/herd/bin/php84/php.exe). Atomic commits on `main`. No push, no deploy.
  Write `260726-wtc-SUMMARY.md` noting: root cause, the key map, why tests can't reproduce the raw-token
  unserialize (Redis-only), and that the prod re-canary is the integration check.
