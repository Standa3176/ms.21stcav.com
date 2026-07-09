---
phase: 260709-gov-harden-runautocreatepipelinejob-move-to-
plan: 01
subsystem: ProductAutoCreate
tags: [reliability, horizon, queue, uniqueness, tdd]
requires:
  - config/horizon.php (sync-bulk supervisor: 512MB, 1800s, 1 worker)
provides:
  - RunAutoCreatePipelineJob on sync-bulk with bounded uniqueFor
affects:
  - Filament Suggestions "Auto-create" bulk action (its queued backend)
key-files:
  created:
    - tests/Feature/ProductAutoCreate/RunAutoCreatePipelineJobHardeningTest.php
  modified:
    - app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php
decisions:
  - "uniqueFor=1800 (= sync-bulk worker timeout = real max runtime) so a crashed lock expires at ~the job's own ceiling"
  - "timeout aligned 3600 → 1800 (the 3600 was never honoured on the 120s default worker)"
  - "tries stays 1 — never re-spend Claude money; per-SKU idempotency lives in the child commands"
metrics:
  duration: ~5m
  completed: 2026-07-09
---

# Phase 260709-gov Plan 01: Harden RunAutoCreatePipelineJob (move to sync-bulk + bounded uniqueFor) Summary

Reliability fix moving the auto-create-from-Suggestions job off the wrong Horizon queue and bounding its ShouldBeUnique lock, mirroring the codebase's own RecomputePriceJob.

## What changed

**`app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php`**

1. Added `public int $uniqueFor = 1800;` — bounds the `ShouldBeUnique` lock so a crashed/OOM'd/SIGKILL'd worker's lock auto-expires. Previously there was **no** `uniqueFor`, so a stalled job held its lock forever and silently swallowed every re-dispatch of the same SKU set (the reported bug). 1800 matches the sync-bulk worker timeout — the job's real maximum runtime — so a legitimately-running batch holds the lock for its duration and a worker killed at the 1800s ceiling has its lock expire at ~the same point.
2. Changed `public int $timeout = 3600;` → `1800` — aligned to the sync-bulk worker timeout. The old 3600 was never honoured because the job ran on `default`, whose worker SIGKILLed it at 120s.
3. Changed the constructor `$this->onQueue('default')` → `$this->onQueue('sync-bulk')`. `default` is 256MB / 120s worker timeout / 3 workers — it silently killed any auto-create batch running past 2 minutes. `sync-bulk` is 512MB / 1800s / 1 worker: the memory + time an hour-scale Claude+Woo batch actually needs, serialized so it can't compete with other bulk work.
4. Rewrote the class docblock to explain the sync-bulk move (why default's 120s killed long batches) and the bounded-uniqueFor rationale (ShouldBeUnique needs uniqueFor or a crashed lock never releases).

**Unchanged (deliberately):** `implements ShouldBeUnique, ShouldQueue`, `tries=1`, `uniqueId()`, `handle()`, `failed()`, and the `OperatorJobCompletedNotification` path. This is a queue/uniqueness reliability fix only — no behaviour change to what the pipeline does or the operator feedback.

**`tests/Feature/ProductAutoCreate/RunAutoCreatePipelineJobHardeningTest.php`** (new, mirrors `tests/Feature/Pricing/RecomputePriceJobTest.php` J2/J3/J4/J6)

- H1: implements ShouldQueue + ShouldBeUnique.
- H2: `$job->queue === 'sync-bulk'`.
- H3: `$job->uniqueFor === 1800`.
- H4: `$job->timeout === 1800` and `$job->tries === 1`.
- H5: `uniqueId()` is stable + SKU-keyed — same SKUs (even with different flags/actor) share a uniqueId; different SKUs differ.

Constructor was read before writing the test — actual signature is `__construct(array $skus, bool $sourceImages, bool $autoPublish, int $triggeredByUserId)` (the plan example's loose `actorId:` label maps to `triggeredByUserId`). The test does NOT run the pipeline or assert `handle()`.

## TDD gate compliance

RED: wrote the test first — 3 failed (queue, uniqueFor, timeout), 2 passed (interfaces + uniqueId, which were already correct). GREEN: after the job edits, 5 passed / 8 assertions. Committed as a single atomic commit (test + implementation together for this quick plan).

## Verification

- `pest RunAutoCreatePipelineJobHardeningTest` → **Tests: 5 passed (8 assertions)**.
- `pest tests/Feature/ProductAutoCreate` → **Tests: 190 passed (697 assertions)** — 0 failed, baseline held.
- `pint` on both files → **pass**.

## Deviations from Plan

None — plan executed exactly as written. (The plan's example arg label `actorId:` was a loose alias for the real ctor param `triggeredByUserId`; the test uses the actual name.)

## Operator notes / deploy

- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration). Horizon picks up the new queue on restart (deploy already runs `horizon:terminate`). **The human pushes — not done here.**
- Effect: the Suggestions "Auto-create" batch now runs on sync-bulk (512MB, up to 1800s, one at a time) instead of default (256MB, killed at 120s). A crashed/stalled run no longer blocks re-dispatching the same SKUs forever — the unique lock now expires after 1800s.

## Follow-ups (optional, not in this task)

- Skip-reason visibility was already delivered by 260707-gsy (toast + 30s-poll + breakdown bell notification) — not touched here.
- A durable/browsable "recent auto-create runs" page + per-SKU fan-out remain OPTIONAL future work.

## Self-Check: PASSED

- FOUND: app/Domain/ProductAutoCreate/Jobs/RunAutoCreatePipelineJob.php (uniqueFor=1800, timeout=1800, onQueue('sync-bulk'))
- FOUND: tests/Feature/ProductAutoCreate/RunAutoCreatePipelineJobHardeningTest.php
- Commit hash recorded below.
