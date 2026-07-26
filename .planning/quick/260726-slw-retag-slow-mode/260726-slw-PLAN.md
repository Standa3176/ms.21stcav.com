# 260726-slw — `brands:retag-products-on-woo --slow`: unattended, self-pacing, flaky-endpoint-hardened

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Why:** finishing the brand merge (Yealink ~145, Samsung ~163, Logitech ~57 left) by hand keeps failing:
the shared box's Woo REST endpoint returns non-JSON intermittently ("JSON ERROR: Syntax error") on
discovery reads, product GETs, and PUTs — worse under sustained write pressure — and WP's synchronous
taxonomy recount degrades after ~120 rapid saves, once making the storefront slow for customers. Manual
batch-and-wait works but needs babysitting and every batch re-hits the flaky discovery read. This task
adds a hardened `--slow` mode so the operator kicks it off ONCE at a quiet hour and it grinds through
gently and unattended.

**DO NOT touch:** `BrandDuplicateFinder` (keep its contract), `DedupeBrandsCommand`, `WooClient`,
`WOO_WRITE_ENABLED`. No migration. All Woo I/O still via WooClient. The existing default (non-slow)
behaviour must stay backward-compatible except for the additive discovery-retry below.

## Task 1 — Discovery retry-with-backoff (both modes) + discover-once in slow mode
- Wrap the single `$this->finder->discover()` call in a retry helper: up to `--discovery-retries`
  (default **4**) attempts, exponential backoff starting `--discovery-backoff-ms` (default **3000** →
  3s,6s,12s,24s), then give up → the existing FAILURE path. This alone removes most of tonight's friction
  (a flaky brands-list read no longer aborts the whole batch). Applies in BOTH modes.
- In `--slow` mode, discover the source→canonical map **ONCE up front** (with the retries) and REUSE it
  for every batch — do NOT re-discover per batch (currently each invocation re-hits the flaky endpoint).

## Task 2 — `--slow` self-pacing multi-batch driver (TDD)
New flags (all with sane defaults; non-slow path ignores them):
- `--slow` — enable the internal loop.
- `--batch-size=40` — products processed per inner batch (per source).
- `--batch-pause=120` — base seconds slept BETWEEN batches (let WP recount settle).
- `--max-pause=600` — cap for adaptive backoff.
- `--max-batches=60` — hard safety cap on total batches (runaway backstop).

Loop semantics (reuse the EXISTING per-source page-1 + processedIds drain logic for one batch of
`batch-size`; do not reimplement it):
1. For each source in the (filtered) map not yet marked **drained**, run one batch.
2. **Drained detection MUST distinguish empty-vs-error:** mark a source drained ONLY when its batch read
   succeeds and returns no new products (genuinely empty). If the products GET itself errored (JSON/
   timeout), the source is NOT drained — keep it active and let the backoff + next pass retry it.
   (Prevents a transient read failure from falsely "finishing" a source with products still on it.)
3. **Adaptive backoff:** after each batch compute error rate = errors / max(1, scanned). If ≥ 0.5 (or the
   batch's read failed), set next pause = min(pause × 2, `--max-pause`) and log the back-off; on a clean
   batch (<0.5 and no read error) reset pause to `--batch-pause`.
4. Sleep the current pause between batches via an **injectable/overridable sleeper** (so tests don't
   actually wait — mirror how the code already isolates `usleep`; do NOT call `sleep()` directly in a way
   the test can't stub).
5. Stop when all in-scope sources are drained OR `--max-batches` reached; print a CUMULATIVE summary
   (total scanned / retagged / errors / batches run / per-source drained state) and warn if it stopped on
   the batch cap rather than draining.
- The per-PUT throttle/pacing and the shadow-gate (WOO_WRITE_ENABLED) are UNCHANGED — slow mode only
  orchestrates repeated batches.

## Verify (TDD, no real network, no real sleeping)
- `pest` (extend the existing retag test with a stubbed WooClient + injected sleeper):
  - discovery retry: finder throws twice then succeeds ⇒ command proceeds (assert it retried, did not
    abort); finder throws on all attempts ⇒ FAILURE after `--discovery-retries` tries.
  - slow drain: a source with 90 fake products + `--batch-size=40` drains in 3 batches then stops;
    assert discover() called ONCE (not per batch); assert cumulative retagged=90.
  - drained-vs-error: a batch whose products GET throws does NOT mark the source drained (asserts it is
    retried on a later pass), and triggers the longer adaptive pause.
  - adaptive backoff: a high-error batch doubles the next pause (assert via the injected sleeper's
    recorded durations); a clean batch resets it.
  - `--max-batches` cap stops the loop and the summary warns.
  - backward-compat: WITHOUT `--slow`, behaviour matches today (one pass, existing counters) — existing
    tests stay green (update only the discovery-failure test for the new retry count).
- `php artisan route:list --path=admin` exit 0; `pint`; `vendor/bin/deptrac analyse` → 0 violations.

## Guardrails / SUMMARY
- Driver-portable; all Woo I/O via WooClient; no raw HTTP; no migration; no push/deploy; no live prod run.
- Do NOT stage pre-existing noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP via Herd (~/.config/herd/bin/php84/php.exe). Atomic commits on `main`. Write `260726-slw-SUMMARY.md`
  incl. the exact quiet-hour invocation and the note to run it under `nohup`/`screen` so it survives an
  SSH disconnect, e.g.:
  `nohup php artisan brands:retag-products-on-woo --slow --source-ids=13430,13434,13432 --batch-size=40 --batch-pause=120 > storage/logs/brand-retag-slow.log 2>&1 &`
  then `tail -f storage/logs/brand-retag-slow.log`. Reiterate: WOO_WRITE_ENABLED=true for the run, back to
  false after; then `brands:audit-woo-membership` (source counts → 0) → `brands:dedupe --delete-empty-woo-terms`.
