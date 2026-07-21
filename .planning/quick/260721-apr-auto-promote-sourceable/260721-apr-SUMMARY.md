# 260721-apr — Auto-promote products that become sourceable again — SUMMARY

**Status:** COMPLETE · **Not pushed, not deployed.**
**Commits (atomic, TDD RED → GREEN per task):**

| # | SHA | What |
|---|-----|------|
| 1 | `1694ba2` | `test(260721-apr)` — failing tests for `--push-to-woo` |
| 2 | `4cc122b` | `feat(260721-apr)` — `--push-to-woo` on `products:restore-sourceable-pending` |
| 3 | `90cac32` | `test(260721-apr)` — failing tests for the daily schedule |
| 4 | `b8c947f` | `feat(260721-apr)` — daily 07:25 London schedule entry |

## The gap this closes

`supplier:db-sync --flag-obsolete` demotes `publish → pending` any product with no current supplier
listing. That is **CORRECT** per the operator's business rule and is **completely unchanged** by this
task. The problem was that it is a **one-way door**: nothing promoted a product back when a supplier
listed it again, so the catalogue slowly under-sold (7 such products on prod today).

## The design correction that mattered

`products:restore-sourceable-pending` (260713-rsp) restored the **local** status only, built on the
assumption that demoted products were still `publish` on Woo. **That assumption was wrong** — Woo
mirrors the local status (Woo admin Pending 1,568 ≈ local pending 1,561). A local-only restore
therefore leaves the product **hidden on the storefront**. To be effective the promote must PUSH the
status to Woo, which is what `--push-to-woo` adds.

## Task 1 — `--push-to-woo` (default OFF)

`app/Console/Commands/RestoreSourceablePendingCommand.php`

| Aspect | Behaviour |
|---|---|
| Default (no flag) | **Zero Woo calls** — byte-identical 260713-rsp local-only behaviour |
| Requires `--live` | Dry-run (the default) makes no Woo call even with `--push-to-woo` |
| The write | `WooClient::put("products/{woo_product_id}", ['status' => 'publish'])` |
| Selection signal | **Unchanged** — only products with a CURRENT fresh in-stock supplier offer (`LiveSupplierStockResolver`), so the next `--flag-obsolete` run won't re-demote them (churn-safe) |
| Carve-outs | Unchanged — `is_custom_ms`, `exclude_from_auto_update`, `custom-ms` tag |
| Migration | None |

**Every Woo interaction goes through `WooClient` — there is no raw HTTP anywhere in this change.**
The command holds a single constructor-injected `WooClient` and calls only `put()`. That inherits,
for free:

- the **260719-wth throttle** — `woo:write` serialisation lock + per-minute rate ceiling +
  min-interval pacing (all live writes admitted single-file);
- the **shadow gate** — `WOO_WRITE_ENABLED=false` ⇒ `recordDiff()` writes a `sync_diffs` row and
  performs **no** live write;
- the **AbortGuard** and the `integration_events` audit trail;
- the 429 backoff / Retry-After / POST-for-updates WAF routing.

`WOO_WRITE_ENABLED` was **not** changed by this task.

### Counts reported

Table rows (push rows appear only when `--push-to-woo` is passed) plus a single grep-friendly line:

```
Woo push — live_pushed=N shadowed=N skipped_no_woo_id=N push_failed=N
```

| Counter | Meaning |
|---|---|
| `restored → publish (local)` | Local status realigned (existing counter, label clarified) |
| `live_pushed` | Real Woo write completed (`WOO_WRITE_ENABLED=true`) |
| `shadowed` | Gate off ⇒ `SyncDiff` recorded, no live write |
| `skipped_no_woo_id` | Restored locally but has no usable `woo_product_id` — push skipped |
| `push_failed` | Push threw; the local restore was **rolled back** to `pending` |

## Deviations from plan (documented)

1. **Failed push rolls the local restore back to `pending`** (Rule 2 — missing critical
   correctness). Not specified by the plan. Without it, a failed push leaves the product `publish`
   locally and `pending` on Woo **permanently and silently** — a `publish` row is never a restore
   candidate again, so nothing retries. Rolling back keeps the row in tomorrow's cohort, prints a
   `✗` warning and logs `restore_sourceable_pending.woo_push_failed`. Covered by a test.
2. **Schedule slot 07:25, not the plan's 07:30.** `routes/console.php` documents 07:30 as a
   contended slot (`suggestions:auto-apply` Mon-Fri 07:30 — see the `suppliers:check-stale` comment
   that explicitly says "DO NOT move to 07:30"). 07:25 preserves the file's staggered-minute
   convention and still satisfies the ordering requirement (see below).
3. **`skipped_no_woo_id` triggers on `woo_product_id <= 0`, not `NULL`.** The 260713-rsp candidate
   cohort already filters `whereNotNull('woo_product_id')` (and an existing test locks that in), so
   a NULL-id product is never restored at all. The counter therefore guards the reachable case — a
   `0`/placeholder id — and is defensive against any future cohort widening.

## Task 2 — schedule

```php
Schedule::command('products:restore-sourceable-pending', ['--live' => true, '--push-to-woo' => true])
    ->dailyAt('07:25')->withoutOverlapping()->onOneServer()->timezone('Europe/London')
```

**Ordering rationale (churn safety):** runs after `supplier:db-sync` (Mon-Fri 07:00) so today's
supplier offers and `buy_price` are fresh, **and** after `products:flag-missing-buy-price` (07:15) so
a product promoted here cannot be re-demoted by the same morning's run. Daily rather than Mon-Fri: a
weekend run is a harmless no-op (supplier feeds don't refresh at weekends) and promoting a genuinely
sourceable product on a Saturday is still correct. A test asserts the ordering invariant numerically
against the two upstream entries, so a future re-time of either job fails the suite.

## Deploy posture — INERT while shadow

- **The deploy is behaviourally inert.** With `WOO_WRITE_ENABLED=false` every push records a
  `SyncDiff` and performs no live write. The scheduled run does its local restore (as 260713-rsp
  already did on demand) and produces reviewable shadow diffs — nothing reaches the storefront.
- **The promote only becomes effective once `WOO_WRITE_ENABLED=true`.** No code change is needed at
  that point; the same schedule entry starts pushing for real.
- **The first live run is an ideal canary for the new 260719-wth throttle.** The cohort is ~7
  products, i.e. ~7 serialised, rate-limited, paced Woo writes — large enough to exercise the lock,
  the per-minute ceiling and the min-interval pacing end-to-end, small enough that a mistake is
  trivially reversible. Review `sync_diffs` (endpoint `products/*`, payload `{"status":"publish"}`)
  before the flip to see exactly which SKUs will be promoted.

## Verification (all run via Herd PHP 8.4.22)

| Check | Result |
|---|---|
| `pest tests/Feature/Products/RestoreSourceablePendingCommandTest.php` | **17 passed** (50 assertions) — 11 pre-existing 260713-rsp cases + 6 new |
| `pest tests/Feature/Console/ScheduledRestoreSourceablePendingTest.php` | **5 passed** (12 assertions) |
| `pest tests/Feature/{Products,Console,Cutover} tests/Architecture` | **454 passed** (1941 assertions) |
| `pest` on the 5 WooClient files (Shadow/Throttle/DryRun/Delete/RateLimit) | **27 passed** (115 assertions) |
| `artisan schedule:list` | shows `25 6 * * *  products:restore-sourceable-pending --live="1" --push-to-woo="1"` (06:25 UTC = 07:25 BST, same rendering as its 07:xx London neighbours) |
| `artisan route:list --path=admin` | exit **0** |
| `pint` (files touched) | **pass** |
| `deptrac analyse` | **0 violations**, 0 errors, 0 warnings |

**No real network, no sleeps in tests** — the Woo seam is a `WooClient` double (or the real client in
shadow mode, which never reaches the SDK), and `Http::preventStrayRequests()` + `Http::fake()` remain
as a second belt.

## Test coverage added

1. `--push-to-woo` PUTs `{"status":"publish"}` to `products/{wooId}` via `WooClient`, output shows
   `live_pushed=1`.
2. **No flag ⇒ zero Woo calls** (backward compatibility) — the spy records nothing.
3. `--push-to-woo` without `--live` (dry-run) makes no Woo call and changes nothing.
4. A restored product with an unusable `woo_product_id` is skipped and counted
   (`skipped_no_woo_id=1`) while a sibling with a real id is still pushed.
5. Shadow mode: real `WooClient`, `WOO_WRITE_ENABLED=false` ⇒ a `sync_diffs` row
   (`woo_id=605`, payload `{"status":"publish"}`), `live_pushed=0 shadowed=1`, no live write.
6. Failed push ⇒ local status rolled back to `pending`, `push_failed=1`.
7. Schedule: cron `25 7 * * *`, Europe/London, `--live` + `--push-to-woo`, `withoutOverlapping`,
   runs after 07:00 + 07:15, description documents the shadow no-op.

All 11 pre-existing 260713-rsp tests (in-stock-only selection, carve-outs, dry-run default,
idempotency, "no Woo call on a mixed batch") remain green, unmodified in intent.

## Guardrails honoured

- `WOO_WRITE_ENABLED` untouched; demotion / `--flag-obsolete` logic untouched; additive only.
- All Woo interaction through `WooClient` — no raw HTTP, no gate bypass. No migration.
- Selection signal unchanged from 260713-rsp (current fresh in-stock supplier offer only).
- Driver-portable (no raw SQL added; SQLite tests / MariaDB prod).
- Pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`) was **not**
  staged or touched — see `deferred-items.md`.
- No push, no deploy.

## Follow-ups (not done here — see `deferred-items.md`)

1. A whole-repo `pest` run fatals on a **pre-existing** duplicate global helper (`seedGaRow()` in
   `ReadMarketingToolsTest.php` vs `MarketingOverviewStatsTest.php`); suites must be run
   per-directory until one is renamed.
2. Repo-wide `pint --test` has ~100 pre-existing violations in untouched files.
3. At cutover: after flipping `WOO_WRITE_ENABLED=true`, watch the first 07:25 run
   (`live_pushed`/`push_failed` counts + `integration_events`) as the throttle canary.

## Self-Check: PASSED

All 4 changed source files, both planning artefacts, and all 4 commit SHAs verified present
(`1694ba2`, `4cc122b`, `90cac32`, `b8c947f`).
