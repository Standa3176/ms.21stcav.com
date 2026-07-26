---
quick_id: 260726-slw
description: brands:retag-products-on-woo --slow — unattended, self-pacing, flaky-endpoint-hardened
date: 2026-07-26
commits:
  - 30baf87  # test(RED): failing tests + Sleeper seam
  - 9f39e52  # feat(GREEN): --slow driver + discovery retry
  - fb7484f  # test(RED): shadow-mode write guard
  - 788cc64  # fix(GREEN): refuse/label shadow writes
status: completed
---

# Quick Task 260726-slw — Summary

Harden `brands:retag-products-on-woo` so the remaining brand merge (Yealink ~145,
Samsung ~163, Logitech ~57) can finish **unattended** despite the shared box's
flaky Woo REST endpoint (intermittent non-JSON "JSON ERROR: Syntax error" on
discovery reads, product GETs, and PUTs; WP taxonomy recount degrading after
~120 rapid saves). Operator kicks it off once at a quiet hour and it grinds
through gently.

## What changed

### 1. Discovery retry-with-backoff (BOTH modes)

- The single `finder->discover()` (the flaky brands-list read) is now wrapped in
  `discoverWithRetry()` — up to `--discovery-retries` (default **4**) attempts
  with exponential backoff from `--discovery-backoff-ms` (default **3000** →
  3s, 6s, 12s, 24s). All attempts exhausted → the existing FAILURE path
  (`brands.retag_discovery_failed` audit, exit 1).
- In `--slow` mode this is the **only** discovery call — the source→canonical map
  is built once and reused for every batch. Discovery is **NOT** re-run per batch
  (proven by Case P asserting `brandsListCalls === 1` across a 3-batch drain).

### 2. `--slow` self-pacing multi-batch driver

New flags (non-slow path ignores them; defaults sane):
`--slow`, `--batch-size=40`, `--batch-pause=120`, `--max-pause=600`,
`--max-batches=60`.

- Round-robins one batch per active source per pass. Each batch reuses the
  EXISTING always-page-1 + `processedIds` drain logic (extracted verbatim into
  `drainSourceBatch()`), bounded to `--batch-size` new products; `per_page` is
  set to `--batch-size` to keep payloads small on the flaky endpoint.
- **Drained-vs-error (the subtle correctness point):** a source is marked
  `drained` **only** when a *successful* read returns no new products
  (`STATUS_DRAINED`) or the term 404s (`STATUS_NO_PRODUCTS`). If the products GET
  itself errors (JSON/timeout → `STATUS_READ_ERROR`), the source stays **active**
  and a later pass retries it — a transient read failure can never falsely finish
  a source that still has products on it. Covered explicitly by **Case Q**.
- **Adaptive backoff:** after each batch, `error_rate = errors / max(1, scanned)`.
  If `>= 0.5` OR the batch read failed → next pause = `min(pause × 2, --max-pause)`
  (logged). A clean batch resets pause to `--batch-pause`.
- **Sleeping is isolated** behind a new injectable `App\Console\Support\Sleeper`
  (mirrors `WooClient::sleepMicros()`). Both the inter-batch pause AND the
  existing 200ms per-PUT throttle route through it — production behaviour is
  byte-identical, but tests bind a recording no-op so they never wait and can
  assert the exact pause durations.
- Stops when all in-scope sources drain OR `--max-batches` is hit; prints a
  cumulative summary + per-source drained state, and on the cap warns
  ("stopped on --max-batches batch cap …") + audits `brands.retag_slow_batch_cap`.

The per-PUT throttle, the shadow-gate (`WOO_WRITE_ENABLED`), and the entire
non-slow code path are unchanged — `--slow` only orchestrates repeated batches.

## Files

- `app/Console/Support/Sleeper.php` — **new** injectable sleep seam.
- `app/Console/Commands/RetagProductsOnWooCommand.php` — options, `discoverWithRetry()`,
  `drainSourceBatch()`, `runSinglePass()`, `runSlow()`, `printSummary()`, throttle
  routed through `Sleeper`.
- `tests/Feature/Console/RetagProductsOnWooCommandTest.php` — +5 cases (N–R),
  recording-sleeper helper, `beforeEach` binds it.

## Tests / gates

- **pest** — `RetagProductsOnWooCommandTest`: **22 passed** (13 existing A–M kept
  green + 5 new N–R + 4 shadow-guard S–V), 112 assertions, ~14s (was ~177s — the
  throttle now routes through the no-op sleeper in tests, no real waiting).
  - Case N: discovery throws twice then succeeds ⇒ proceeds; backoff = [3s, 6s].
  - Case O: discovery throws on all `--discovery-retries=3` tries ⇒ FAILURE.
  - Case P: 90-product source, `--batch-size=40` drains in 3 batches; `discover()`
    called ONCE; cumulative retagged = 90; pauses = [120, 120, 120].
  - Case Q: first products GET throws ⇒ source NOT drained, retried later, all 40
    retagged; pause doubles to 240 then resets to 120 (`[240, 120, 120]`);
    `brands.retag_pagination_failed` audited once.
  - Case R: non-draining source + `--max-batches=3` stops after 3 batches, summary
    warns, `brands.retag_slow_batch_cap` audited.
  - Case S: not-dry-run + `write_enabled=false` + no `--allow-shadow` ⇒ FAILURE
    with `brandsListCalls === 0` (refused BEFORE discovery), no writes,
    `brands.retag_refused_shadow_mode` audited.
  - Case T: `write_enabled=false` + `--allow-shadow` ⇒ proceeds, output contains
    `[SHADOW` and `would_retag_shadow`, and NEVER `pushed woo=` / `products_retagged`.
  - Case U: `write_enabled=true` ⇒ live path unchanged (`pushed`, no `[SHADOW`).
  - Case V: `--dry-run` + `write_enabled=false` ⇒ no guard trip, dry-run unchanged.
- **pint** — pass (3 changed files).
- **vendor/bin/deptrac analyse** — 0 violations.
- **php artisan route:list --path=admin** — exit 0.

No real network, no real sleeping — stubbed WooClient + injected recording Sleeper.

### 3. Shadow-mode write guard (post-review bug fix)

**Bug found in live use:** with `WOO_WRITE_ENABLED=false`, `WooClient` shadows
every write (records a `SyncDiff`, changes nothing on Woo) — but the command
still printed `pushed` and counted `products_retagged++`. Operators ran it with
the flag off and got fake "40 retagged" reports while nothing changed on the
storefront (the same 40 products reappeared every batch).

- New `--allow-shadow` option (default false) for intentional shadow testing.
- `perform()` reads `config('services.woo.write_enabled')` **once up front**
  (before any discovery or writes). When NOT `--dry-run` and writes are shadowed
  and `--allow-shadow` was NOT passed → prominent `error()` explaining the flag is
  off (writes would be shadowed, nothing changes on Woo), audit
  `brands.retag_refused_shadow_mode`, and `return SymfonyCommand::FAILURE`
  immediately.
- With `--allow-shadow` + `write_enabled=false`: run proceeds but is unmistakably
  shadow — header shows `[SHADOW - no live writes]`, each product logs
  `shadow_write …` (never `pushed`), audit event is `brands.product_retag_shadowed`,
  and the summary counter is labelled `would_retag_shadow` (not `products_retagged`).
- Applies in BOTH normal and `--slow` modes (checked once in `perform()`).
- `--dry-run` is exempt (already writes nothing and is clearly labelled).

## Operator run instructions

Set `WOO_WRITE_ENABLED=true` for the run, then run at a quiet hour under
`nohup` (or `screen`) so it survives an SSH disconnect:

```bash
nohup php artisan brands:retag-products-on-woo --slow --source-ids=13430,13434,13432 --batch-size=40 --batch-pause=120 > storage/logs/brand-retag-slow.log 2>&1 &
tail -f storage/logs/brand-retag-slow.log
```

After it drains all sources, set `WOO_WRITE_ENABLED=false` again, then verify and
finish the merge:

```bash
php artisan brands:audit-woo-membership          # source counts should be → 0
php artisan brands:dedupe --delete-empty-woo-terms
```

## Guardrails honoured

- Did NOT touch `BrandDuplicateFinder`, `DedupeBrandsCommand`, or `WooClient`.
- No migration; all Woo I/O still via `WooClient`; driver-portable.
- No push, no deploy, no live prod command run.
- Atomic commits on `main` (RED → GREEN).
- Left pre-existing working-tree noise UNSTAGED: `storage/app/research/supplier-probe.json`
  (deleted), `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php` (modified),
  untracked `.claude/`.

## Deviations from plan

- **[Test-infra] Recording `Sleeper` bound in the shared `beforeEach`.** The plan
  said "update only the discovery-failure test." To honour "no real sleeping" for
  the whole file AND keep the existing A–M cases green, the injectable sleeper is
  bound for every case (not just the new ones). This also collapsed the suite from
  ~177s → ~12s because the 200ms per-PUT throttle no longer really sleeps in tests.
  Production behaviour is unchanged (real `Sleeper` still `usleep()`s).
- **[Test refinement] Case N asserts the first two `sleptMicros` entries.** The
  discovery backoffs and the per-PUT throttle share the microsecond sleeper, so
  the raw array is `[3_000_000, 6_000_000, 200_000, 200_000]`; the assertion slices
  off the two discovery backoffs (which are recorded first, before any PUT).

There was no pre-existing "discovery-failure test" to update — the retag suite had
no discovery-failure case, so Cases N/O add that coverage fresh.

- **[Test env] `beforeEach` now defaults `services.woo.write_enabled` to true.**
  The config default is `false`, and the pre-existing live-write cases (A–R) only
  passed because the stub `WooClient` bypasses the parent constructor (never
  checks the flag). The new shadow guard reads the config directly, so without
  this default every live-write case would trip the guard and fail. Shadow-guard
  cases S/T/V override to `false` explicitly. This matches the coordinator's
  instruction to default the test env to `write_enabled=true` for live-write cases.
